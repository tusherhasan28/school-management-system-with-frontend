<?php

namespace App\Modules\Imports\Services;

use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Section;
use App\Modules\Imports\Jobs\RunStudentImport;
use App\Modules\Imports\Models\DataImportJob;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use App\Modules\People\Services\GuardianService;
use App\Modules\People\Services\StudentService;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Student bulk import: upload → validate + duplicate check + preview →
 * confirm → queued import → downloadable error report.
 *
 * All rows go into one class (and optional section) of one session, chosen
 * at upload time. Section may be overridden per row by name.
 */
class StudentImportService
{
    public const COLUMNS = [
        'admission_no', 'full_name', 'name_bn', 'date_of_birth', 'gender', 'section', 'roll_no',
        'guardian_name', 'guardian_phone', 'guardian_relationship', 'guardian_email', 'address',
    ];

    public const PREVIEW_ROWS = 25;

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly TenantStorage $storage,
        private readonly StudentService $students,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{academic_session_id: int, class_id: int, section_id?: int|null}  $options
     */
    public function upload(UploadedFile $file, array $options): DataImportJob
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = $this->storage->path('imports/'.Str::uuid().'.'.$extension);
        $this->storage->disk()->putFileAs(dirname($path), $file, basename($path));

        $job = new DataImportJob([
            'import_type' => 'students',
            'original_name' => Str::limit($file->getClientOriginalName(), 180, ''),
            'options' => $options,
        ]);
        $job->user_id = Auth::guard('web')->id();
        $job->source_path = $path;
        $job->status = 'validated';
        $job->save();

        $this->validateFile($job);
        $this->audit->tenant('import.uploaded', $job, new: ['file' => $job->original_name, 'rows' => $job->total_rows]);

        return $job;
    }

    /**
     * Validate every row, mark duplicates, store counts, a preview and the
     * error report. Nothing is written to student tables here.
     */
    public function validateFile(DataImportJob $job): void
    {
        $results = $this->analyse($job);

        $job->total_rows = count($results);
        $job->valid_rows = count(array_filter($results, fn ($r) => $r['status'] === 'valid'));
        $job->duplicate_rows = count(array_filter($results, fn ($r) => $r['status'] === 'duplicate'));
        $job->error_rows = count(array_filter($results, fn ($r) => $r['status'] === 'error'));
        $job->preview = array_slice(array_map(fn ($r) => [
            'line' => $r['line'],
            'status' => $r['status'],
            'full_name' => $r['row']['full_name'] ?? '',
            'guardian_phone' => $r['row']['guardian_phone'] ?? '',
            'messages' => $r['messages'],
        ], $results), 0, self::PREVIEW_ROWS);
        $job->error_report_path = $this->writeReport($job, $results);
        $job->save();
    }

    public function confirm(DataImportJob $job): void
    {
        if ($job->status !== 'validated' || $job->valid_rows === 0) {
            throw new RuntimeException('This import cannot be started.');
        }

        $plan = app(\App\Modules\Billing\Services\Entitlements::class);
        $plan->ensureEnabled('bulk_import');
        $plan->ensureCanAdd('students', $job->valid_rows);

        $job->status = 'queued';
        $job->save();

        RunStudentImport::dispatch($job->id);
    }

    /**
     * Runs inside the queued job (tenant context restored by QueueTenancy).
     * Each row is its own transaction: one bad row never rolls back others.
     */
    public function run(DataImportJob $job): void
    {
        $job->forceFill(['status' => 'processing', 'started_at' => now()])->save();

        try {
            $results = $this->analyse($job);
            $imported = 0;

            foreach ($results as $i => $result) {
                if ($result['status'] !== 'valid') {
                    continue;
                }

                try {
                    $this->importRow($job, $result['row'], $result['section_id']);
                    $imported++;
                } catch (Throwable $e) {
                    $results[$i]['status'] = 'error';
                    $results[$i]['messages'][] = $e instanceof \Illuminate\Validation\ValidationException
                        ? collect($e->errors())->flatten()->implode(' ')
                        : __('imports.row_failed');
                    report($e);
                }
            }

            $job->forceFill([
                'status' => 'completed',
                'imported_rows' => $imported,
                'error_rows' => count(array_filter($results, fn ($r) => $r['status'] === 'error')),
                'error_report_path' => $this->writeReport($job, $results),
                'finished_at' => now(),
            ])->save();

            $this->audit->tenant('import.completed', $job, new: ['imported' => $imported]);
        } catch (Throwable $e) {
            $job->forceFill(['status' => 'failed', 'failure_message' => Str::limit($e->getMessage(), 480), 'finished_at' => now()])->save();

            throw $e;
        }
    }

    /**
     * @return list<array{line: int, row: array<string, string>, status: string, messages: list<string>, section_id: int|null}>
     */
    private function analyse(DataImportJob $job): array
    {
        $options = $job->options ?? [];
        $session = AcademicSession::query()->findOrFail($options['academic_session_id'] ?? 0);
        $class = SchoolClass::query()->findOrFail($options['class_id'] ?? 0);
        $sections = Section::query()->where('class_id', $class->id)->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $defaultSection = $options['section_id'] ?? null;

        $file = $this->storage->disk()->path($job->source_path);
        $extension = pathinfo($job->source_path, PATHINFO_EXTENSION);

        $rows = iterator_to_array($this->reader->rows($file, $extension), true);

        // Existing data for duplicate checks, loaded once (no N+1).
        $admissionNos = array_filter(array_map(fn ($r) => $r['admission_no'] ?? '', $rows));
        $existingAdmission = Student::withTrashed()->whereIn('admission_no', $admissionNos)->pluck('admission_no')->flip();
        $existingRolls = StudentEnrollment::query()
            ->where('academic_session_id', $session->id)->where('class_id', $class->id)
            ->whereNotNull('roll_no')->get(['section_id', 'roll_no'])
            ->map(fn ($e) => ($e->section_id ?? 0).'|'.$e->roll_no)->flip();
        $existingPeople = Student::query()
            ->whereIn('full_name', array_filter(array_map(fn ($r) => $r['full_name'] ?? '', $rows)))
            ->get(['full_name', 'date_of_birth'])
            ->map(fn ($s) => mb_strtolower($s->full_name).'|'.$s->date_of_birth?->toDateString())->flip();

        $seenAdmission = $seenPeople = $seenRolls = [];
        $results = [];

        foreach ($rows as $line => $row) {
            $messages = [];
            $status = 'valid';

            $validator = Validator::make($row, [
                'full_name' => ['required', 'string', 'max:160'],
                'name_bn' => ['nullable', 'string', 'max:160'],
                'admission_no' => ['nullable', 'string', 'max:60'],
                'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
                'gender' => ['nullable', 'in:male,female,other,Male,Female,Other,M,F,m,f'],
                'roll_no' => ['nullable', 'string', 'max:40'],
                'guardian_name' => ['required', 'string', 'max:160'],
                'guardian_phone' => ['required', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
                'guardian_email' => ['nullable', 'email', 'max:190'],
                'guardian_relationship' => ['nullable', 'string', 'max:60'],
                'address' => ['nullable', 'string', 'max:1000'],
            ], [], ['guardian_phone' => 'guardian phone (01XXXXXXXXX)']);

            if ($validator->fails()) {
                $status = 'error';
                $messages = array_merge($messages, $validator->errors()->all());
            }

            $sectionId = $defaultSection;
            if (($row['section'] ?? '') !== '') {
                $sectionId = $sections[mb_strtolower($row['section'])] ?? null;
                if ($sectionId === null) {
                    $status = 'error';
                    $messages[] = __('imports.unknown_section', ['section' => $row['section']]);
                }
            }

            if ($status === 'valid') {
                $admission = $row['admission_no'] ?? '';
                $personKey = mb_strtolower($row['full_name']).'|'.($row['date_of_birth'] ?? '');
                $rollKey = ($sectionId ?? 0).'|'.($row['roll_no'] ?? '');

                if ($admission !== '' && (isset($existingAdmission[$admission]) || isset($seenAdmission[$admission]))) {
                    $status = 'duplicate';
                    $messages[] = __('imports.duplicate_admission', ['no' => $admission]);
                } elseif (($row['date_of_birth'] ?? '') !== '' && (isset($existingPeople[$personKey]) || isset($seenPeople[$personKey]))) {
                    $status = 'duplicate';
                    $messages[] = __('imports.duplicate_person');
                } elseif (($row['roll_no'] ?? '') !== '' && (isset($existingRolls[$rollKey]) || isset($seenRolls[$rollKey]))) {
                    $status = 'error';
                    $messages[] = __('people.roll_taken');
                }

                $seenAdmission[$admission] = true;
                $seenPeople[$personKey] = true;
                $seenRolls[$rollKey] = true;
            }

            $results[] = ['line' => $line + 1, 'row' => $row, 'status' => $status, 'messages' => $messages, 'section_id' => $sectionId];
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importRow(DataImportJob $job, array $row, ?int $sectionId): void
    {
        $gender = match (strtolower($row['gender'] ?? '')) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            'other' => 'other',
            default => 'not_specified',
        };

        $this->students->create(
            [
                'admission_no' => $row['admission_no'] ?: null,
                'full_name' => $row['full_name'],
                'name_bn' => $row['name_bn'] ?: null,
                'date_of_birth' => $row['date_of_birth'] ?: null,
                'gender' => $gender,
                'present_address' => $row['address'] ?: null,
            ],
            [[
                'full_name' => $row['guardian_name'],
                'phone' => $row['guardian_phone'],
                'email' => $row['guardian_email'] ?: null,
                'relationship' => $row['guardian_relationship'] ?: 'guardian',
                'is_primary' => true,
            ]],
            [
                'academic_session_id' => (int) $job->options['academic_session_id'],
                'class_id' => (int) $job->options['class_id'],
                'section_id' => $sectionId,
                'roll_no' => $row['roll_no'] ?: null,
            ],
        );
    }

    /**
     * CSV report of every row that was not imported, with reasons.
     *
     * @param  list<array{line: int, row: array<string, string>, status: string, messages: list<string>}>  $results
     */
    private function writeReport(DataImportJob $job, array $results): ?string
    {
        $problems = array_filter($results, fn ($r) => $r['status'] !== 'valid');

        if ($problems === []) {
            return null;
        }

        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM so Excel shows Bangla correctly
        fputcsv($handle, ['line', 'status', 'problems', ...self::COLUMNS], escape: '\\');

        foreach ($problems as $r) {
            fputcsv($handle, [
                $r['line'],
                $r['status'],
                implode(' | ', $r['messages']),
                ...array_map(fn ($c) => self::safeCell($r['row'][$c] ?? ''), self::COLUMNS),
            ], escape: '\\');
        }

        rewind($handle);
        $path = $this->storage->path('imports/reports/import-'.$job->id.'-'.Str::random(8).'.csv');
        $this->storage->disk()->put($path, stream_get_contents($handle));
        fclose($handle);

        return $path;
    }

    /**
     * Neutralise spreadsheet formula injection (=, +, -, @ at cell start).
     */
    public static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
