<?php

namespace App\Services\Tenancy;

use App\Enums\SchoolStatus;
use App\Models\School;
use App\Models\SchoolDomain;
use App\Models\User;
use App\Modules\Setup\Models\Campus;
use App\Support\Permissions\TenantPermissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a school with its subdomain, default roles, main campus and owner
 * account in one transaction. Used by onboarding, seeders and tests.
 */
class SchoolProvisioner
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionRegistrar $permissions,
    ) {}

    /**
     * @param  array{name: string, slug: string, name_bn?: string|null, institution_type?: string, email?: string|null, phone?: string|null, locale?: string}  $school
     * @param  array{name: string, email: string, password: string}  $owner
     */
    public function provision(array $school, array $owner, SchoolStatus $status = SchoolStatus::Trial): School
    {
        $slug = Str::lower($school['slug']);
        $this->assertValidSlug($slug);

        return DB::transaction(function () use ($school, $slug, $owner, $status) {
            $model = new School($school);
            $model->slug = $slug;
            $model->status = $status;
            $model->activated_at = $status === SchoolStatus::Active ? now() : null;
            $model->trial_ends_at = $status === SchoolStatus::Trial ? now()->addDays(30) : null;
            $model->save();

            $domain = new SchoolDomain(['domain' => $slug.'.'.config('tenancy.domain'), 'is_primary' => true]);
            $domain->school()->associate($model);
            $domain->verified_at = now();
            $domain->save();

            $this->tenant->run($model, function () use ($owner) {
                $this->ensurePermissions();

                foreach (TenantPermissions::defaultRoles() as $name => $grants) {
                    $role = Role::findOrCreate($name, 'web');
                    $role->syncPermissions($grants === '*' ? TenantPermissions::all() : $grants);
                }

                Campus::create(['name' => 'Main campus', 'is_main' => true]);

                $user = new User(['name' => $owner['name'], 'email' => Str::lower($owner['email']), 'password' => $owner['password']]);
                $user->status = 'active';
                $user->email_verified_at = now();
                $user->save();
                $user->assignRole('School Owner');
            });

            return $model;
        });
    }

    public function ensurePermissions(): void
    {
        foreach (TenantPermissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->permissions->forgetCachedPermissions();
    }

    public function assertValidSlug(string $slug): void
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/', $slug)) {
            throw new InvalidArgumentException('School slug must be 3–63 lowercase letters, digits or hyphens.');
        }

        if (in_array($slug, config('tenancy.reserved_slugs'), true)) {
            throw new InvalidArgumentException("The slug \"{$slug}\" is reserved.");
        }

        if (School::withTrashed()->where('slug', $slug)->exists()) {
            throw new InvalidArgumentException("The slug \"{$slug}\" is already taken.");
        }
    }
}
