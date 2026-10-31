<?php

namespace App\Modules\Portal\Services;

use App\Models\User;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\StaffMember;
use App\Modules\People\Models\Student;
use Illuminate\Support\Collection;

/**
 * Who may see which student in the family portals, and where each login
 * lands after signing in.
 *
 * A parent sees only the students linked to their guardian record; a student
 * sees only their own record. Everything is resolved from the signed-in user,
 * never from an id in the request, and every lookup runs under the tenant
 * scope, so another school's records are never reachable.
 *
 * Stateless on purpose: nothing is cached, so a guardian unlinked a moment
 * ago never keeps access through a long-lived worker or a cached controller.
 */
class PortalAccess
{
    public const AREA_STAFF = 'staff';

    public const AREA_TEACHER = 'teacher';

    public const AREA_PARENT = 'parent';

    public const AREA_STUDENT = 'student';

    /** Roles that are not staff: a login with only these roles never enters /app. */
    public const FAMILY_ROLES = ['Parent', 'Student'];

    /** Staff roles whose home is the teacher dashboard rather than /app. */
    public const TEACHER_ROLES = ['Teacher', 'Class Teacher'];

    public function guardianFor(User $user): ?Guardian
    {
        return Guardian::query()->where('user_id', $user->id)->first();
    }

    public function studentFor(User $user): ?Student
    {
        return Student::query()->where('user_id', $user->id)->first();
    }

    /**
     * Students the user may open in the parent portal, oldest admission first.
     *
     * @return Collection<int, Student>
     */
    public function children(User $user): Collection
    {
        $guardian = $this->guardianFor($user);

        return $guardian ? $guardian->students()->orderBy('students.id')->get() : collect();
    }

    /**
     * The child the parent asked for, or 404. Not 403: whether a student id
     * exists is itself information a parent of another child must not learn.
     */
    public function child(User $user, Student $student): Student
    {
        $child = $user->hasRole('Parent') ? $this->children($user)->firstWhere('id', $student->id) : null;
        abort_if($child === null, 404);

        return $child;
    }

    public function isFamilyOnly(User $user): bool
    {
        $roles = $user->getRoleNames();

        return $roles->isNotEmpty() && $roles->diff(self::FAMILY_ROLES)->isEmpty();
    }

    /** The area a login belongs to; decides the home page and the layout. */
    public function area(User $user): string
    {
        return $this->resolveArea($user);
    }

    public function homeUrl(User $user): string
    {
        return match ($this->area($user)) {
            self::AREA_PARENT => route('parent.dashboard'),
            self::AREA_STUDENT => route('student.dashboard'),
            self::AREA_TEACHER => route('teacher.dashboard'),
            default => route('dashboard'),
        };
    }

    private function resolveArea(User $user): string
    {
        if ($this->isFamilyOnly($user)) {
            return $user->hasRole('Parent') ? self::AREA_PARENT : self::AREA_STUDENT;
        }

        $roles = $user->getRoleNames();
        $teacherOnly = $roles->isNotEmpty() && $roles->diff([...self::TEACHER_ROLES, ...self::FAMILY_ROLES])->isEmpty();

        if ($teacherOnly && StaffMember::query()->where('user_id', $user->id)->where('status', 'active')->exists()) {
            return self::AREA_TEACHER;
        }

        return self::AREA_STAFF;
    }
}
