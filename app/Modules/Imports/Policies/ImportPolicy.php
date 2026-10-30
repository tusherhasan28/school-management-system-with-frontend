<?php

namespace App\Modules\Imports\Policies;

use App\Support\Authorization\TenantPolicy;

class ImportPolicy extends TenantPolicy
{
    protected string $viewPermission = 'imports.manage';

    protected string $managePermission = 'imports.manage';
}
