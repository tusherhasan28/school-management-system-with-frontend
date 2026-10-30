<?php

namespace App\Modules\Admissions\Policies;

use App\Models\User;
use App\Modules\Admissions\Models\AdmissionApplication;
use App\Support\Authorization\TenantPolicy;

class AdmissionPolicy extends TenantPolicy
{
    protected string $viewPermission = 'admissions.manage';

    protected string $managePermission = 'admissions.manage';

    public function convert(User $user, AdmissionApplication $application): bool
    {
        return $this->sameTenant($user, $application) && $user->can('admissions.manage') && $user->can('students.create');
    }
}
