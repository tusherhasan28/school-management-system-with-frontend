<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\ClassSubject;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Subject;
use App\Modules\People\Models\StaffMember;
use App\Modules\Teaching\Models\TeacherAssignment;

/**
 * Teachers with logins and assignments, inside the CURRENT tenant context.
 */
trait CreatesTeachers
{
    /**
     * @return array{0: User, 1: StaffMember}
     */
    protected function teacher(string $role = 'Teacher', string $name = 'Teacher'): array
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role);

        $staff = StaffMember::create(['staff_type' => 'teacher', 'employee_no' => 'T-'.$user->id, 'full_name' => $name]);
        $staff->forceFill(['user_id' => $user->id])->save();

        return [$user, $staff];
    }

    protected function assign(StaffMember $staff, AcademicSession $session, SchoolClass $class, ?int $sectionId, ?Subject $subject, string $type = 'subject_teacher'): TeacherAssignment
    {
        return TeacherAssignment::create([
            'academic_session_id' => $session->id,
            'staff_member_id' => $staff->id,
            'class_id' => $class->id,
            'section_id' => $sectionId,
            'subject_id' => $subject?->id,
            'assignment_type' => $type,
        ]);
    }

    protected function subjectFor(AcademicSession $session, SchoolClass $class, string $name): Subject
    {
        $subject = Subject::query()->firstOrCreate(['name' => $name], ['code' => strtoupper(substr($name, 0, 4))]);
        ClassSubject::query()->firstOrCreate(
            ['academic_session_id' => $session->id, 'class_id' => $class->id, 'subject_id' => $subject->id],
            ['full_marks' => 100, 'pass_marks' => 33],
        );

        return $subject;
    }
}
