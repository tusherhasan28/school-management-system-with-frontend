<?php

namespace App\Modules\Teaching\Services;

use App\Models\User;
use App\Modules\Academics\Models\Section;
use App\Modules\Homework\Models\Homework;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use App\Modules\Teaching\Models\ClassRoutine;

/**
 * Resource-level rules for attendance, homework and student visibility. All
 * models passed in were loaded through tenant-scoped queries, so they already
 * belong to the current school; these checks add "is it MY class/subject".
 */
class TeachingAccess
{
    public function __construct(private readonly TeachingScope $scope) {}

    // ---- Attendance ------------------------------------------------------

    public function canViewAttendance(User $user, Section $section): bool
    {
        return $user->can('attendance.view_all')
            || ($user->can('attendance.mark') && $this->scope->covers($user, $section->class_id, $section->id));
    }

    /** Daily attendance: class teacher of the section, or full access. */
    public function canTakeDaily(User $user, Section $section): bool
    {
        return $user->can('attendance.mark') && (
            $user->can('attendance.view_all')
            || $this->scope->covers($user, $section->class_id, $section->id, classTeacherOnly: true)
        );
    }

    /** Period attendance: the period's teacher, a teacher of that subject in the section, or full access. */
    public function canTakePeriod(User $user, ClassRoutine $routine): bool
    {
        if (! $user->can('attendance.mark')) {
            return false;
        }

        if ($user->can('attendance.view_all')) {
            return true;
        }

        $staff = $this->scope->staffFor($user);

        return ($staff !== null && $routine->staff_member_id === $staff->id)
            || $this->scope->covers($user, $routine->class_id, $routine->section_id, $routine->subject_id);
    }

    public function canCorrect(User $user, Section $section): bool
    {
        return $user->can('attendance.correct') && (
            $user->can('attendance.view_all')
            || $this->scope->covers($user, $section->class_id, $section->id, classTeacherOnly: true)
        );
    }

    // ---- Homework --------------------------------------------------------

    public function canViewHomework(User $user, Homework $homework): bool
    {
        return $user->can('homework.view_all')
            || ($user->can('homework.manage') && $this->scope->covers($user, $homework->class_id, $homework->section_id, $homework->subject_id, sessionId: $homework->academic_session_id));
    }

    public function canSetHomework(User $user, int $classId, ?int $sectionId, int $subjectId, ?int $sessionId = null): bool
    {
        if (! $user->can('homework.manage')) {
            return false;
        }

        return $user->can('homework.view_all') || $this->scope->covers($user, $classId, $sectionId, $subjectId, sessionId: $sessionId);
    }

    public function canManageHomework(User $user, Homework $homework): bool
    {
        return $this->canSetHomework($user, $homework->class_id, $homework->section_id, $homework->subject_id, $homework->academic_session_id);
    }

    // ---- Students --------------------------------------------------------

    /**
     * Teachers without students.view may open students of the sections they
     * teach in the current session.
     */
    public function teachesStudent(User $user, Student $student): bool
    {
        $enrollment = StudentEnrollment::query()
            ->where('student_id', $student->id)
            ->where('status', 'enrolled')
            ->whereIn('academic_session_id', \App\Modules\Academics\Models\AcademicSession::query()->where('is_current', true)->select('id'))
            ->first();

        return $enrollment !== null && $this->scope->covers($user, $enrollment->class_id, $enrollment->section_id);
    }
}
