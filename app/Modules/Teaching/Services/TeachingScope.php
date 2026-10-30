<?php

namespace App\Modules\Teaching\Services;

use App\Models\User;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\People\Models\StaffMember;
use App\Modules\Teaching\Models\TeacherAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resource-level access for teachers: what a user may see or change is limited
 * to the class/section/subject assignments of the staff record linked to their
 * login. Users with the given "all" permission (principal, admins) bypass it.
 *
 * Results are memoised per request (the service is scoped).
 */
class TeachingScope
{
    /** @var array<string, Collection<int, TeacherAssignment>> */
    private array $cache = [];

    public function staffFor(User $user): ?StaffMember
    {
        return StaffMember::query()->where('user_id', $user->id)->where('status', 'active')->first();
    }

    /**
     * @return Collection<int, TeacherAssignment>
     */
    public function assignments(User $user, ?int $sessionId = null): Collection
    {
        $sessionId ??= AcademicSession::query()->where('is_current', true)->value('id');
        $key = $user->id.':'.$sessionId;

        return $this->cache[$key] ??= ($sessionId === null ? collect() : TeacherAssignment::query()
            ->whereIn('staff_member_id', StaffMember::query()->where('user_id', $user->id)->where('status', 'active')->select('id'))
            ->where('academic_session_id', $sessionId)
            ->get());
    }

    public function isTeacher(User $user, ?int $sessionId = null): bool
    {
        return $this->assignments($user, $sessionId)->isNotEmpty();
    }

    /**
     * Does any assignment cover this class/section (and subject, if given)?
     * A null section on an assignment covers every section of the class.
     * $classTeacherOnly limits the match to class-teacher assignments.
     */
    public function covers(User $user, int $classId, ?int $sectionId, ?int $subjectId = null, bool $classTeacherOnly = false, ?int $sessionId = null): bool
    {
        return $this->assignments($user, $sessionId)->contains(function (TeacherAssignment $a) use ($classId, $sectionId, $subjectId, $classTeacherOnly) {
            if ($a->class_id !== $classId) {
                return false;
            }

            if ($a->section_id !== null && $sectionId !== null && $a->section_id !== $sectionId) {
                return false;
            }

            if ($a->section_id !== null && $sectionId === null) {
                return false; // a section-level assignment does not cover the whole class
            }

            if ($classTeacherOnly) {
                return $a->assignment_type === 'class_teacher';
            }

            // Class teachers cover every subject of their section.
            return $subjectId === null || $a->assignment_type === 'class_teacher' || $a->subject_id === $subjectId;
        });
    }

    /**
     * Restrict a query on a table with class_id / section_id (/ subject_id)
     * columns to what the user's assignments cover.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $user, string $classColumn = 'class_id', string $sectionColumn = 'section_id', ?string $subjectColumn = null, ?int $sessionId = null): Builder
    {
        $assignments = $this->assignments($user, $sessionId);

        if ($assignments->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($assignments, $classColumn, $sectionColumn, $subjectColumn) {
            foreach ($assignments as $a) {
                $q->orWhere(function (Builder $q) use ($a, $classColumn, $sectionColumn, $subjectColumn) {
                    $q->where($classColumn, $a->class_id);

                    if ($a->section_id !== null) {
                        $q->where($sectionColumn, $a->section_id);
                    }

                    if ($subjectColumn !== null && $a->assignment_type === 'subject_teacher') {
                        $q->where($subjectColumn, $a->subject_id);
                    }
                });
            }
        });
    }

    /**
     * Sections the user teaches, for pickers: list of [class_id, section_id|null].
     *
     * @return list<array{class_id: int, section_id: int|null, class_teacher: bool}>
     */
    public function sections(User $user, ?int $sessionId = null): array
    {
        return $this->assignments($user, $sessionId)
            ->map(fn (TeacherAssignment $a) => ['class_id' => $a->class_id, 'section_id' => $a->section_id, 'class_teacher' => $a->assignment_type === 'class_teacher'])
            ->unique(fn ($s) => $s['class_id'].'-'.$s['section_id'].'-'.$s['class_teacher'])
            ->values()
            ->all();
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
