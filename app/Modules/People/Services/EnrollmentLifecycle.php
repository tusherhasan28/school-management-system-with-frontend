<?php

namespace App\Modules\People\Services;

use App\Modules\Academics\Models\AcademicSession;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promotion, repeat, transfer, withdrawal and graduation. History is never
 * rewritten: the old enrollment is closed and (for promote/repeat) a new one
 * is opened in the target session.
 */
class EnrollmentLifecycle
{
    public function __construct(
        private readonly StudentService $students,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Promote or repeat a batch of students from one session into another.
     *
     * @param  list<int>  $studentIds
     * @return array{moved: int, skipped: list<array{student_id: int, reason: string}>}
     */
    public function move(array $studentIds, AcademicSession $from, AcademicSession $to, int $toClassId, ?int $toSectionId, string $mode): array
    {
        if (! in_array($mode, ['promoted', 'repeated'], true)) {
            throw ValidationException::withMessages(['mode' => 'Invalid mode.']);
        }

        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_session_id' => __('people.same_session')]);
        }

        $moved = 0;
        $skipped = [];

        foreach (array_unique($studentIds) as $studentId) {
            try {
                DB::transaction(function () use ($studentId, $from, $to, $toClassId, $toSectionId, $mode) {
                    $current = StudentEnrollment::query()
                        ->where('student_id', $studentId)
                        ->where('academic_session_id', $from->id)
                        ->lockForUpdate()
                        ->first();

                    if ($current === null || $current->status !== 'enrolled') {
                        throw ValidationException::withMessages(['student' => __('people.not_enrolled_in_session')]);
                    }

                    $current->forceFill([
                        'status' => $mode,
                        'ended_at' => now()->toDateString(),
                    ])->save();

                    $student = Student::query()->findOrFail($studentId);
                    $this->students->enroll($student, [
                        'academic_session_id' => $to->id,
                        'class_id' => $toClassId,
                        'section_id' => $toSectionId,
                        'roll_no' => null,
                    ]);

                    $this->audit->tenant('student.'.$mode, $student, ['session_id' => $from->id, 'class_id' => $current->class_id], ['session_id' => $to->id, 'class_id' => $toClassId]);
                });
                $moved++;
            } catch (ValidationException $e) {
                $skipped[] = ['student_id' => $studentId, 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        return ['moved' => $moved, 'skipped' => $skipped];
    }

    /**
     * End a student's studies: transferred, withdrawn or graduated.
     */
    public function end(Student $student, string $outcome, ?string $reason = null, ?string $date = null): void
    {
        if (! in_array($outcome, ['transferred', 'withdrawn', 'graduated'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Invalid outcome.']);
        }

        if ($student->status !== 'active') {
            throw ValidationException::withMessages(['outcome' => __('people.not_active')]);
        }

        DB::transaction(function () use ($student, $outcome, $reason, $date) {
            $open = StudentEnrollment::query()
                ->where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->lockForUpdate()
                ->get();

            foreach ($open as $enrollment) {
                $enrollment->forceFill([
                    'status' => $outcome,
                    'ended_at' => $date ?? now()->toDateString(),
                    'end_reason' => $reason,
                ])->save();
            }

            $student->forceFill(['status' => $outcome])->save();
            $this->audit->tenant('student.'.$outcome, $student, ['status' => 'active'], ['status' => $outcome, 'reason' => $reason]);
        });
    }
}
