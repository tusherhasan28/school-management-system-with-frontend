<?php

namespace App\Modules\Exports\Services;

use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Exports\Models\DataExport;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Imports\Services\StudentImportService;
use App\Modules\Notices\Models\Notice;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\StaffMember;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use App\Support\Tenancy\TenantStorage;
use Generator;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Everything a school needs to leave the platform with its records: one ZIP
 * of UTF-8 CSV files (opens in Excel with Bangla intact). Encrypted ID
 * numbers (NID, birth registration) are left out; documented in the
 * retention policy. Runs in the tenant context, so only this school's rows.
 */
class SchoolDataExporter
{
    public const KEEP_DAYS = 7;

    public function __construct(private readonly TenantStorage $storage) {}

    public function run(DataExport $export): void
    {
        $export->forceFill(['status' => 'processing'])->save();
        $tmp = tempnam(sys_get_temp_dir(), 'export');
        $zip = new ZipArchive;

        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create export archive.');
        }

        $counts = [];

        foreach ($this->tables() as $name => [$header, $rows]) {
            $csv = fopen('php://temp', 'w+');
            fwrite($csv, "\xEF\xBB\xBF"); // BOM: Excel reads UTF-8 (Bangla) correctly
            fputcsv($csv, $header, escape: '');
            $n = 0;

            foreach ($rows as $row) {
                fputcsv($csv, array_map(fn ($v) => StudentImportService::safeCell((string) ($v ?? '')), $row), escape: '');
                $n++;
            }

            rewind($csv);
            $zip->addFromString($name.'.csv', (string) stream_get_contents($csv));
            fclose($csv);
            $counts[$name] = $n;
        }

        $zip->addFromString('README.txt', "School data export\nGenerated: ".now()->toIso8601String()."\nFiles are UTF-8 CSV. National ID and birth registration numbers are not included.\n");
        $zip->close();

        $path = $this->storage->path('exports/school-data-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(8)).'.zip');
        $this->storage->disk()->put($path, (string) file_get_contents($tmp));
        $size = filesize($tmp) ?: 0;
        @unlink($tmp);

        $export->forceFill(['status' => 'ready', 'path' => $path, 'size_bytes' => $size, 'counts' => $counts, 'expires_at' => now()->addDays(self::KEEP_DAYS)])->save();
    }

    /** @return array<string, array{0: list<string>, 1: iterable<list<mixed>>}> */
    private function tables(): array
    {
        return [
            'students' => [['admission_no', 'full_name', 'name_bn', 'date_of_birth', 'gender', 'blood_group', 'religion', 'nationality', 'email', 'present_address', 'permanent_address', 'admission_date', 'status'],
                $this->each(Student::query()->withTrashed()->orderBy('id'), fn (Student $s) => [$s->admission_no, $s->full_name, $s->name_bn, $s->date_of_birth?->toDateString(), $s->gender, $s->blood_group, $s->religion, $s->nationality, $s->email, $s->present_address, $s->permanent_address, $s->admission_date?->toDateString(), $s->status])],
            'enrollments' => [['admission_no', 'session', 'class', 'section', 'roll_no', 'status', 'enrolled_at', 'ended_at'],
                $this->each(StudentEnrollment::query()->with(['student' => fn ($q) => $q->withTrashed()->select(['id', 'school_id', 'admission_no']), 'academicSession:id,school_id,name', 'schoolClass:id,school_id,name', 'section:id,school_id,name'])->orderBy('id'),
                    fn ($e) => [$e->student?->admission_no, $e->academicSession?->name, $e->schoolClass?->name, $e->section?->name, $e->roll_no, $e->status, $e->enrolled_at, $e->ended_at])],
            'guardians' => [['full_name', 'name_bn', 'phone', 'alternate_phone', 'email', 'occupation', 'address', 'children (admission numbers)'],
                $this->each(Guardian::query()->with(['students' => fn ($q) => $q->select(['students.id', 'admission_no'])])->orderBy('id'),
                    fn (Guardian $g) => [$g->full_name, $g->name_bn, $g->phone, $g->alternate_phone, $g->email, $g->occupation, $g->address, $g->students->pluck('admission_no')->implode(' ')])],
            'staff' => [['employee_no', 'full_name', 'name_bn', 'staff_type', 'designation', 'phone', 'email', 'status'],
                $this->each(StaffMember::query()->orderBy('id'), fn (StaffMember $s) => [$s->employee_no, $s->full_name, $s->name_bn, $s->staff_type, $s->designation, $s->phone, $s->email, $s->status])],
            'attendance' => [['date', 'class', 'section', 'period', 'admission_no', 'status', 'remarks'],
                $this->each(StudentAttendance::query()->with(['attendanceSession' => fn ($q) => $q->with(['schoolClass:id,school_id,name', 'section:id,school_id,name']), 'student' => fn ($q) => $q->withTrashed()->select(['id', 'school_id', 'admission_no'])])->orderBy('id'),
                    fn ($a) => [$a->attendanceSession?->attendance_date?->toDateString(), $a->attendanceSession?->schoolClass?->name, $a->attendanceSession?->section?->name, $a->attendanceSession?->period_key, $a->student?->admission_no, $a->status, $a->remarks])],
            'results' => [['exam', 'admission_no', 'obtained', 'full', 'percentage', 'gpa', 'grade', 'status', 'class_position', 'section_position'],
                $this->each(ExamResult::query()->join('examinations', fn ($j) => $j->on('examinations.id', '=', 'exam_results.examination_id')->on('examinations.current_calculation_id', '=', 'exam_results.result_calculation_id'))
                    ->with(['student' => fn ($q) => $q->withTrashed()->select(['id', 'school_id', 'admission_no'])])->select(['exam_results.*', 'examinations.name as exam_name'])->orderBy('exam_results.id'),
                    fn ($r) => [$r->exam_name, $r->student?->admission_no, $r->obtained_marks, $r->full_marks, $r->percentage, $r->gpa, $r->letter_grade, $r->result_status, $r->class_position, $r->section_position])],
            'invoices' => [['invoice_no', 'admission_no', 'period', 'issue_date', 'due_date', 'total', 'paid', 'status'],
                $this->each(FeeInvoice::query()->with(['student' => fn ($q) => $q->withTrashed()->select(['id', 'school_id', 'admission_no'])])->orderBy('id'),
                    fn (FeeInvoice $i) => [$i->invoice_no, $i->student?->admission_no, $i->billing_period, $i->issue_date->toDateString(), $i->due_date->toDateString(), $i->total, $i->paid_total, $i->status])],
            'payments' => [['receipt_no', 'admission_no', 'amount', 'method', 'reference', 'status', 'received_at'],
                $this->each(FeePayment::query()->with(['student' => fn ($q) => $q->withTrashed()->select(['id', 'school_id', 'admission_no'])])->orderBy('id'),
                    fn (FeePayment $p) => [$p->receipt_no, $p->student?->admission_no, $p->amount, $p->method, $p->reference, $p->status, $p->received_at?->toIso8601String()])],
            'notices' => [['title', 'title_bn', 'audiences', 'status', 'publish_at', 'body'],
                $this->each(Notice::query()->orderBy('id'), fn (Notice $n) => [$n->title, $n->title_bn, implode(' ', $n->audiences ?? []), $n->status, $n->publish_at?->toIso8601String(), $n->body])],
        ];
    }

    /** Stream rows in chunks (big schools have hundreds of thousands of attendance marks). */
    private function each($query, callable $map): Generator
    {
        foreach ($query->lazyById(1000, $query->getModel()->getQualifiedKeyName(), $query->getModel()->getKeyName()) as $model) {
            yield $map($model);
        }
    }
}
