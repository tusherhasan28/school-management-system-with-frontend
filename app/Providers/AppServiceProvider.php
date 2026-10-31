<?php

namespace App\Providers;

use App\Modules\Setup\Models\Campus;
use App\Modules\Setup\Policies\CampusPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One registry of installed payment gateway adapters per process.
        $this->app->singleton(\App\Modules\Finance\Gateways\GatewayRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        $policies = [
            Campus::class => CampusPolicy::class,
            \App\Modules\Academics\Models\AcademicSession::class => \App\Modules\Academics\Policies\AcademicsPolicy::class,
            \App\Modules\Academics\Models\SchoolClass::class => \App\Modules\Academics\Policies\AcademicsPolicy::class,
            \App\Modules\Academics\Models\Section::class => \App\Modules\Academics\Policies\AcademicsPolicy::class,
            \App\Modules\Academics\Models\Subject::class => \App\Modules\Academics\Policies\AcademicsPolicy::class,
            \App\Modules\People\Models\Student::class => \App\Modules\People\Policies\StudentPolicy::class,
            \App\Modules\People\Models\Guardian::class => \App\Modules\People\Policies\GuardianPolicy::class,
            \App\Modules\People\Models\StaffMember::class => \App\Modules\People\Policies\StaffPolicy::class,
            \App\Modules\Admissions\Models\AdmissionApplication::class => \App\Modules\Admissions\Policies\AdmissionPolicy::class,
            \App\Modules\Imports\Models\DataImportJob::class => \App\Modules\Imports\Policies\ImportPolicy::class,
        ];

        foreach ($policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Surface N+1 queries and silently discarded attributes outside production.
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
