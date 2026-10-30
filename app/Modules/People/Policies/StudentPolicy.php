<?php

namespace App\Modules\People\Policies;

use App\Models\User;
use App\Modules\People\Models\Student;
use App\Support\Authorization\TenantPolicy;

class StudentPolicy extends TenantPolicy
{
    protected string $viewPermission = 'students.view';

    protected string $managePermission = 'students.update';

    protected ?string $createPermission = 'students.create';

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
