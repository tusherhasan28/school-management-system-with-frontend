<?php

namespace App\Modules\People\Policies;

use App\Models\User;
use App\Modules\People\Models\Student;
use App\Modules\Teaching\Services\TeachingAccess;
use App\Modules\Teaching\Services\TeachingScope;
use App\Support\Authorization\TenantPolicy;

class StudentPolicy extends TenantPolicy
{
    protected string $viewPermission = 'students.view';

    protected string $managePermission = 'students.update';

    protected ?string $createPermission = 'students.create';

    /** Teachers without students.view see the students of the sections they teach. */
    public function viewAny(User $user): bool
    {
        return $user->can('students.view') || app(TeachingScope::class)->isTeacher($user);
    }

    public function view(User $user, \Illuminate\Database\Eloquent\Model $student): bool
    {
        if (! $this->sameTenant($user, $student)) {
            return false;
        }

        return $user->can('students.view') || app(TeachingAccess::class)->teachesStudent($user, $student);
    }

    public function promote(User $user): bool
    {
        return $user->can('students.promote');
    }

    public function changeStatus(User $user, Student $student): bool
    {
        return $this->sameTenant($user, $student) && $user->can('students.promote');
    }

    public function viewSensitive(User $user, Student $student): bool
    {
        return $this->sameTenant($user, $student) && $user->can('students.update');
    }
}
