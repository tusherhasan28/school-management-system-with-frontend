<?php

namespace App\Support\Authorization;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy: a permission check plus an explicit same-school check on every
 * record, independent of the global scope.
 */
abstract class TenantPolicy
{
    protected string $viewPermission;

    protected string $managePermission;

    protected ?string $createPermission = null;

    public function viewAny(User $user): bool
    {
        return $user->can($this->viewPermission);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->sameTenant($user, $model) && $user->can($this->viewPermission);
    }

    public function create(User $user): bool
    {
        return $user->can($this->createPermission ?? $this->managePermission);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->sameTenant($user, $model) && $user->can($this->managePermission);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    protected function sameTenant(User $user, Model $model): bool
    {
        $current = app(TenantContext::class)->id();

        return $current !== null
            && (int) $model->getAttribute('school_id') === $current
            && (int) $user->school_id === $current;
    }
}
