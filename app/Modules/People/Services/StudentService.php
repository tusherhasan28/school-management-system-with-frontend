<?php

namespace App\Modules\People\Services;

use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\Section;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating a student is one transaction: student + guardians + links + the
 * enrollment for the chosen session. Used by the admin form, admission
 * conversion and imports so all three behave the same.
 */
class StudentService
{
    public function __construct(
        private readonly GuardianService $guardians,
        private readonly TenantSequence $sequence,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $student  Student attributes (+ optional admission_no)
     * @param  list<array<string, mixed>>  $guardians  Each: guardian attributes + relationship, is_primary
     * @param  array{academic_session_id: int, class_id: int, section_id?: int|null, roll_no?: string|null, enrolled_at?: string|null}  $enrollment
     */
    public function create(array $student, array $guardians, array $enrollment): Student
    {
        if ($guardians === []) {
            throw ValidationException::withMessages(['guardians' => __('people.guardian_required')]);
        }

        return DB::transaction(function () use ($student, $guardians, $enrollment) {
            $admissionNo = $student['admission_no'] ?? null;
            unset($student['admission_no']);

            $model = new Student($student);
            $model->admission_no = $admissionNo ?: $this->nextAdmissionNo();
            $model->admission_date ??= now()->toDateString();
            $model->status = 'active';
            $model->save();

            foreach ($guardians as $i => $row) {
                $relationship = $row['relationship'] ?? 'guardian';
                $primary = (bool) ($row['is_primary'] ?? $i === 0);
                unset($row['relationship'], $row['is_primary']);

                $guardian = $this->guardians->findOrCreate($row);
                $this->guardians->link($model, $guardian, $relationship, $primary);
            }

            $this->enroll($model, $enrollment);

            $this->audit->tenant('student.created', $model, new: $model->only(['admission_no', 'full_name', 'name_bn', 'gender']));

            return $model;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Student $student, array $data): Student
    {
        if (array_key_exists('birth_registration_no', $data) && ($data['birth_registration_no'] === null || $data['birth_registration_no'] === '')) {
            unset($data['birth_registration_no']); // blank means "unchanged"; the value is never sent to the browser
        }

        $old = $student->only(array_keys($data));
        $student->update($data);
        $this->audit->tenant('student.updated', $student, $old, $data);

        return $student;
    }

    /**
     * @param  array{academic_session_id: int, class_id: int, section_id?: int|null, roll_no?: string|null, enrolled_at?: string|null}  $data
     */
    public function enroll(Student $student, array $data, string $status = 'enrolled'): StudentEnrollment
    {
        $this->assertSectionBelongsToClass($data['class_id'], $data['section_id'] ?? null);

        if (StudentEnrollment::query()->where('student_id', $student->id)->where('academic_session_id', $data['academic_session_id'])->exists()) {
            throw ValidationException::withMessages(['academic_session_id' => __('people.already_enrolled')]);
        }

        $rollNo = $data['roll_no'] ?? null;

        if ($rollNo !== null && $rollNo !== '' && StudentEnrollment::query()
            ->where('academic_session_id', $data['academic_session_id'])
            ->where('class_id', $data['class_id'])
            ->where('section_id', $data['section_id'] ?? null)
            ->where('roll_no', $rollNo)
            ->exists()) {
            throw ValidationException::withMessages(['roll_no' => __('people.roll_taken')]);
        }

        return StudentEnrollment::create([
            'student_id' => $student->id,
            'academic_session_id' => $data['academic_session_id'],
            'class_id' => $data['class_id'],
            'section_id' => $data['section_id'] ?? null,
            'roll_no' => $rollNo ?: null,
            'status' => $status,
            'enrolled_at' => $data['enrolled_at'] ?? now()->toDateString(),
        ]);
    }

    public function nextAdmissionNo(): string
    {
        return $this->sequence->format('admission_no', '{year}{seq:4}');
    }

    public function currentSessionId(): ?int
    {
        return AcademicSession::query()->where('is_current', true)->value('id');
    }

    private function assertSectionBelongsToClass(int $classId, ?int $sectionId): void
    {
        if ($sectionId === null) {
            return;
        }

        if (! Section::query()->whereKey($sectionId)->where('class_id', $classId)->exists()) {
            throw ValidationException::withMessages(['section_id' => __('people.section_mismatch')]);
        }
    }
}
