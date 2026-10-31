<?php

namespace App\Modules\Exams\Services;

use App\Models\User;
use App\Modules\Exams\Grading\Hundredths;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamMark;
use App\Modules\Exams\Models\ExamMarkSheet;
use App\Modules\People\Models\StudentEnrollment;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks entry for one mark sheet (exam subject × section).
 *
 * - Only while the exam is open for marks and the sheet is a draft.
 * - Every mark is checked against the component's full marks.
 * - Changes to marks already saved are written to the audit log
 *   (old and new total/status per student).
 * - submit → verify; a verifier can return a sheet to draft with a reason.
 */
class MarkEntryService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Students who sit this exam in the section: enrolled in the exam's session,
     * class and section, and not transferred or withdrawn before the exam began.
     *
     * @return Collection<int, StudentEnrollment>
     */
    public function roster(Examination $exam, int $classId, ?int $sectionId = null): Collection
    {
        return StudentEnrollment::query()
            ->with('student:id,school_id,full_name,name_bn,admission_no')
            ->where('academic_session_id', $exam->academic_session_id)
            ->where('class_id', $classId)
            ->when($sectionId !== null, fn ($q) => $q->where('section_id', $sectionId))
            ->where(function ($q) use ($exam) {
                $q->whereIn('status', ['enrolled', 'promoted', 'repeated', 'graduated']);

                if ($exam->starts_on !== null) {
                    $q->orWhereDate('ended_at', '>=', $exam->starts_on->toDateString());
                }
            })
            ->orderBy('section_id')
            ->orderByRaw('CAST(roll_no AS UNSIGNED), roll_no')
            ->get();
    }

    /**
     * @param  array<int|string, array{status: string, parts?: array<int|string, string|null>, remarks?: string|null}>  $rows  keyed by student id
     */
    public function save(ExamMarkSheet $sheet, array $rows, User $user): void
    {
        $subject = $sheet->examSubject()->with(['examination', 'components'])->firstOrFail();
        $exam = $subject->examination;

        if (! $exam->acceptsMarks()) {
            throw ValidationException::withMessages(['marks' => __('exams.marks_closed')]);
        }

        if ($sheet->status !== 'draft') {
            throw ValidationException::withMessages(['marks' => __('exams.sheet_locked')]);
        }

        $roster = $this->roster($exam, $sheet->class_id, $sheet->section_id)->pluck('student_id')->all();
        $components = $subject->components->keyBy('id');
        $errors = [];
        $clean = [];

        foreach ($rows as $studentId => $row) {
            $studentId = (int) $studentId;

            if (! in_array($studentId, $roster, true)) {
                $errors['rows'] = __('exams.student_not_in_sheet');

                continue;
            }

            $status = $row['status'] ?? 'present';

            if (! in_array($status, ExamMark::STATUSES, true)) {
                $errors["rows.{$studentId}.status"] = __('validation.in', ['attribute' => 'status']);

                continue;
            }

            $parts = [];

            if ($status === 'present') {
                foreach ($components as $id => $component) {
                    $raw = $row['parts'][$id] ?? null;

                    if ($raw === null || $raw === '') {
                        $parts[$id] = null; // not entered yet; allowed in a draft

                        continue;
                    }

                    try {
                        $value = Hundredths::parse(trim((string) $raw));
                    } catch (\InvalidArgumentException) {
                        $errors["rows.{$studentId}.parts.{$id}"] = __('exams.mark_format');

                        continue;
                    }

                    if ($value < 0 || $value > Hundredths::parse($component->full_marks)) {
                        $errors["rows.{$studentId}.parts.{$id}"] = __('exams.mark_out_of_range', ['max' => $component->full_marks]);

                        continue;
                    }

                    $parts[$id] = $value;
                }
            }

            $clean[$studentId] = ['status' => $status, 'parts' => $parts, 'remarks' => isset($row['remarks']) ? mb_substr(trim((string) $row['remarks']), 0, 255) ?: null : null];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($sheet, $subject, $clean, $user) {
            $existing = ExamMark::query()->where('exam_mark_sheet_id', $sheet->id)->lockForUpdate()->get()->keyBy('student_id');
            $changes = [];

            foreach ($clean as $studentId => $row) {
                $complete = $row['status'] !== 'present' || ! in_array(null, $row['parts'], true);
                $total = $row['status'] === 'present' && $complete ? Hundredths::format(array_sum($row['parts'])) : null;
                $mark = $existing->get($studentId);

                if ($mark !== null && ($mark->status !== $row['status'] || $mark->total !== $total)) {
                    $changes[] = ['student_id' => $studentId, 'old' => [$mark->status, $mark->total], 'new' => [$row['status'], $total]];
                }

                $mark ??= new ExamMark(['exam_subject_id' => $subject->id, 'exam_mark_sheet_id' => $sheet->id, 'student_id' => $studentId]);
                $mark->fill(['status' => $row['status'], 'total' => $total, 'remarks' => $row['remarks'], 'entered_by' => $user->id])->save();

                $mark->parts()->delete();

                foreach ($row['status'] === 'present' ? $row['parts'] : [] as $componentId => $value) {
                    if ($value !== null) {
                        $mark->parts()->create(['exam_subject_component_id' => $componentId, 'marks' => Hundredths::format($value)]);
                    }
                }
            }

            $this->audit->tenant('exam.marks_saved', $sheet, [], [
                'exam_subject_id' => $subject->id, 'rows' => count($clean), 'changed' => array_slice($changes, 0, 200),
            ]);
        });
    }

    /** Students in the section who still have no complete mark. */
    public function missing(ExamMarkSheet $sheet): int
    {
        $exam = $sheet->examSubject->examination;
        $roster = $this->roster($exam, $sheet->class_id, $sheet->section_id)->pluck('student_id');
        $complete = ExamMark::query()
            ->where('exam_mark_sheet_id', $sheet->id)
            ->where(fn ($q) => $q->where('status', '!=', 'present')->orWhereNotNull('total'))
            ->pluck('student_id');

        return $roster->diff($complete)->count();
    }

    public function submit(ExamMarkSheet $sheet, User $user): void
    {
        $this->assertOpen($sheet);

        if ($sheet->status !== 'draft') {
            throw ValidationException::withMessages(['sheet' => __('exams.sheet_locked')]);
        }

        if ($this->missing($sheet) > 0) {
            throw ValidationException::withMessages(['sheet' => __('exams.sheet_incomplete', ['count' => $this->missing($sheet)])]);
        }

        $sheet->forceFill(['status' => 'submitted', 'submitted_by' => $user->id, 'submitted_at' => now()])->save();
        $this->audit->tenant('exam.sheet_submitted', $sheet);
    }

    public function verify(ExamMarkSheet $sheet, User $user): void
    {
        $this->assertOpen($sheet);

        if ($sheet->status === 'verified') {
            return;
        }

        if ($this->missing($sheet) > 0) {
            throw ValidationException::withMessages(['sheet' => __('exams.sheet_incomplete', ['count' => $this->missing($sheet)])]);
        }

        $sheet->forceFill([
            'status' => 'verified', 'verified_by' => $user->id, 'verified_at' => now(),
            'submitted_by' => $sheet->submitted_by ?? $user->id, 'submitted_at' => $sheet->submitted_at ?? now(),
        ])->save();
        $this->audit->tenant('exam.sheet_verified', $sheet);
    }

    public function returnToDraft(ExamMarkSheet $sheet, string $reason): void
    {
        $this->assertOpen($sheet);

        $old = $sheet->status;
        $sheet->forceFill(['status' => 'draft', 'verified_by' => null, 'verified_at' => null, 'submitted_by' => null, 'submitted_at' => null])->save();
        $this->audit->tenant('exam.sheet_returned', $sheet, ['status' => $old], ['status' => 'draft', 'reason' => $reason]);
    }

    private function assertOpen(ExamMarkSheet $sheet): void
    {
        if (! $sheet->examSubject->examination->acceptsMarks()) {
            throw ValidationException::withMessages(['sheet' => __('exams.marks_closed')]);
        }
    }
}
