<?php

namespace App\Providers;

use App\Models\PlatformUser;
use App\Support\Tenancy\QueueTenancy;
use App\Support\Tenancy\TenantAwarePasswordBrokerManager;
use App\Support\Tenancy\TenantCache;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Auth\Passwords\PasswordResetServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh context per request / per queued job (Octane and queue
        // workers flush scoped instances between lifecycles).
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(TenantCache::class);
        $this->app->scoped(TenantStorage::class);
        $this->app->scoped(\App\Modules\Teaching\Services\TeachingScope::class);

        // Load Laravel's (deferred) password reset provider now so our manager
        // replaces its binding instead of being replaced by it later.
        $this->app->register(PasswordResetServiceProvider::class);
        $this->app->singleton('auth.password', fn ($app) => new TenantAwarePasswordBrokerManager($app));
    }

    public function boot(): void
    {
        $this->registerBlueprintMacros();

        QueueTenancy::register();

        // Platform staff abilities are role based (App\Enums\PlatformRole).
        Gate::before(function ($user, string $ability) {
            if ($user instanceof PlatformUser) {
                return $user->hasPlatformAbility($ability);
            }

            return null;
        });
    }

    private function registerBlueprintMacros(): void
    {
        /**
         * school_id column + FK to schools. Use on every tenant-owned table.
         */
        Blueprint::macro('tenantId', function (): ColumnDefinition {
            /** @var Blueprint $this */
            $column = $this->unsignedBigInteger('school_id');
            $this->foreign('school_id')->references('id')->on('schools')->restrictOnDelete();

            return $column;
        });

        /**
         * UNIQUE (school_id, id): the target of composite tenant foreign keys.
         */
        Blueprint::macro('tenantKeys', function (): void {
            /** @var Blueprint $this */
            $this->unique(['school_id', 'id'], $this->getTable().'_school_id_id_unique');
        });

        /**
         * Composite FK (school_id, $column) → $parent(school_id, id).
         * The database rejects any reference to a row of another school.
         */
        Blueprint::macro('tenantForeign', function (string $column, string $parent, bool $nullable = false): ColumnDefinition {
            /** @var Blueprint $this */
            $definition = $this->unsignedBigInteger($column);

            if ($nullable) {
                $definition->nullable();
            }

            $this->foreign(['school_id', $column], $this->getTable().'_'.$column.'_tenant_fk')
                ->references(['school_id', 'id'])
                ->on($parent)
                ->restrictOnDelete();

            return $definition;
        });
    }
}
