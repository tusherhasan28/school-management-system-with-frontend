<?php

namespace App\Modules\Teaching\Services;

use App\Modules\Academics\Models\ClassSubject;
use App\Modules\People\Models\StaffMember;
use App\Modules\Teaching\Models\TeacherAssignment;
use App\Services\Audit\AuditLogger;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{academic_session_id: int, staff_member_id: int, class_id: int, section_id: int|null, subject_id: int|null, assignment_type: string}  $data
     */
    public function assign(array $data): TeacherAssignment
    {
        $teacher = StaffMember::query()->findOrFail($data['staff_member_id']);

        if ($teacher->staff_type !== 'teacher' || $teacher->status !== 'active') {
            throw ValidationException::withMessages(['staff_member_id' => __('teaching.not_active_teacher')]);
        }

        if ($data['assignment_type'] === 'class_teacher') {
            $data['subject_id'] = null;

            if ($data['section_id'] === null) {
                throw ValidationException::withMessages(['section_id' => __('teaching.class_teacher_needs_section')]);
            }

            $existing = TeacherAssignment::query()
                ->where('academic_session_id', $data['academic_session_id'])
                ->where('section_id', $data['section_id'])
                ->where('assignment_type', 'class_teacher')
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages(['section_id' => __('teaching.class_teacher_exists')]);
            }
        } else {
            if ($data['subject_id'] === null) {
                throw ValidationException::withMessages(['subject_id' => __('teaching.subject_required')]);
            }

            $taught = ClassSubject::query()
                ->where('academic_session_id', $data['academic_session_id'])
                ->where('class_id', $data['class_id'])
                ->where('subject_id', $data['subject_id'])
                ->exists();

            if (! $taught) {
                throw ValidationException::withMessages(['subject_id' => __('teaching.subject_not_in_class')]);
            }
        }

        $duplicate = TeacherAssignment::query()
            ->where('academic_session_id', $data['academic_session_id'])
            ->where('staff_member_id', $data['staff_member_id'])
            ->where('class_id', $data['class_id'])
            ->where('section_id', $data['section_id'])
            ->where('subject_id', $data['subject_id'])
            ->where('assignment_type', $data['assignment_type'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['staff_member_id' => __('teaching.duplicate_assignment')]);
        }

        $assignment = TeacherAssignment::create($data);
        $this->audit->tenant('teacher_assignment.created', $assignment, new: $data);

        return $assignment;
    }

    public function remove(TeacherAssignment $assignment): void
    {
        $this->audit->tenant('teacher_assignment.deleted', $assignment, $assignment->only(['staff_member_id', 'class_id', 'section_id', 'subject_id', 'assignment_type']));
        $assignment->delete();
    }
}
