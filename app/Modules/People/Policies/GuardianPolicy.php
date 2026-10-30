<?php

namespace App\Modules\People\Policies;

use App\Support\Authorization\TenantPolicy;

class GuardianPolicy extends TenantPolicy
{
    protected string $viewPermission = 'guardians.view';

    protected string $managePermission = 'guardians.manage';
}
