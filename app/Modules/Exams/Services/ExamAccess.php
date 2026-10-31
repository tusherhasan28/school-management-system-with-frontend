<?php

namespace App\Modules\Exams\Services;

use App\Models\User;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamMarkSheet;
use App\Modules\Teaching\Services\TeachingScope;

/**
 * Who may do what with exams.
 *
 * - marks.enter: only sheets the teacher is assigned to (subject teacher of
 *   that subject, or class teacher of the section) in the exam's session.
 * - marks.enter_all: any sheet (exam controller).
 * - results.view: every class; class teachers also see their own section.
 */
class ExamAccess
{
    public function __construct(private readonly TeachingScope $scope) {}

    public function canEnter(User $user, ExamMarkSheet $sheet): bool
    {
        if ($user->can('marks.enter_all')) {
            return true;
        }

        $subject = $sheet->examSubject;

        return $user->can('marks.enter')
            && $this->scope->covers($user, $sheet->class_id, $sheet->section_id, $subject->subject_id, false, $subject->examination->academic_session_id);
    }

    public function canViewSheet(User $user, ExamMarkSheet $sheet): bool
    {
        return $user->can('marks.verify') || $user->can('results.view') || $this->canEnter($user, $sheet);
    }

    public function canViewResults(User $user, Examination $exam, int $classId, ?int $sectionId): bool
    {
        return $user->can('results.view')
            || ($sectionId !== null && $this->scope->covers($user, $classId, $sectionId, null, true, $exam->academic_session_id));
    }

    public function canSeeExams(User $user): bool
    {
        return $user->can('exams.manage') || $user->can('results.view') || $user->can('marks.verify')
            || $user->can('marks.enter_all') || $user->can('results.approve') || $user->can('results.publish');
    }
}
