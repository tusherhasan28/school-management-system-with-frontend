<?php

namespace App\Modules\Academics\Policies;

use App\Support\Authorization\TenantPolicy;

/** Sessions, classes, sections, subjects and class-subject mappings. */
class AcademicsPolicy extends TenantPolicy
{
    protected string $viewPermission = 'academics.view';

    protected string $managePermission = 'academics.manage';
}
