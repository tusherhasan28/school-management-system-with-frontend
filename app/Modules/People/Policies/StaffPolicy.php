<?php

namespace App\Modules\People\Policies;

use App\Models\User;
use App\Modules\People\Models\StaffMember;
use App\Support\Authorization\TenantPolicy;

class StaffPolicy extends TenantPolicy
{
    protected string $viewPermission = 'staff.view';

    protected string $managePermission = 'staff.manage';

    public function createLogin(User $user, StaffMember $staff): bool
    {
        return $this->sameTenant($user, $staff) && $user->can('staff.manage') && $user->can('users.manage');
    }
}
