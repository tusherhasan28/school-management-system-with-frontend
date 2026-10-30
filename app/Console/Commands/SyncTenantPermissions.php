<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Tenancy\SchoolProvisioner;
use App\Support\Permissions\TenantPermissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * After TenantPermissions changes: create new permissions and GRANT the default
 * permissions to each school's default roles. Never revokes, so schools'
 * own customisations are kept.
 */
class SyncTenantPermissions extends Command
{
    protected $signature = 'tenancy:sync-permissions';

    protected $description = 'Add new tenant permissions to every school\'s default roles (additive)';

    public function handle(SchoolProvisioner $provisioner, TenantContext $tenant): int
    {
        $provisioner->ensurePermissions();

        School::query()->orderBy('id')->each(function (School $school) use ($tenant) {
            $tenant->run($school, function () {
                foreach (TenantPermissions::defaultRoles() as $name => $grants) {
                    $role = Role::findOrCreate($name, 'web');
                    $role->givePermissionTo($grants === '*' ? TenantPermissions::all() : $grants);
                }
            });

            $this->line("Synced {$school->slug}");
        });

        return self::SUCCESS;
    }
}
