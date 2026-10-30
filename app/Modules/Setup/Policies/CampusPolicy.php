<?php

namespace App\Modules\Setup\Policies;

use App\Models\User;
use App\Modules\Setup\Models\Campus;
use App\Support\Tenancy\TenantContext;

/**
 * Every instance check confirms the record belongs to the current school,
 * independently of the global scope.
 */
class CampusPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('campuses.view');
    }

    public function view(User $user, Campus $campus): bool
    {
        return $this->sameTenant($user, $campus) && $user->can('campuses.view');
    }

    public function create(User $user): bool
    {
        return $user->can('campuses.manage');
    }

    public function update(User $user, Campus $campus): bool
    {
        return $this->sameTenant($user, $campus) && $user->can('campuses.manage');
    }

    public function delete(User $user, Campus $campus): bool
    {
        return $this->sameTenant($user, $campus) && ! $campus->is_main && $user->can('campuses.manage');
    }

    private function sameTenant(User $user, Campus $campus): bool
    {
        $current = app(TenantContext::class)->id();

        return $current !== null
            && $campus->school_id === $current
            && $user->school_id === $current;
    }
}
