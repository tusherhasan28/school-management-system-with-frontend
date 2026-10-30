<?php

namespace Tests;

use App\Enums\SchoolStatus;
use App\Models\School;
use App\Models\User;
use App\Services\Tenancy\SchoolProvisioner;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    protected function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * Provision a school with default roles, main campus and an owner
     * (owner@{slug}.test / password).
     */
    protected function provisionSchool(string $slug, SchoolStatus $status = SchoolStatus::Active): School
    {
        return app(SchoolProvisioner::class)->provision(
            ['slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)).' School', 'locale' => 'en'],
            ['name' => 'Owner of '.$slug, 'email' => "owner@{$slug}.test", 'password' => 'password'],
            $status,
        )->fresh();
    }

    protected function ownerOf(School $school): User
    {
        return $this->tenant()->run($school, fn () => User::query()->where('email', "owner@{$school->slug}.test")->firstOrFail());
    }

    /**
     * Make route() / url() generate URLs on this school's host and set the
     * tenant context for factories used directly in the test.
     */
    protected function onSchool(School $school): static
    {
        $this->tenant()->clear();
        $this->tenant()->set($school);
        URL::forceRootUrl($this->schoolUrl($school));

        return $this;
    }

    protected function schoolUrl(School $school, string $path = ''): string
    {
        return 'http://'.$school->slug.'.'.config('tenancy.domain').$path;
    }

    protected function platformUrl(string $path = ''): string
    {
        return 'http://'.config('tenancy.admin_subdomain').'.'.config('tenancy.domain').$path;
    }

    /**
     * @template T
     *
     * @param  Closure(School): T  $callback
     * @return T
     */
    protected function inSchool(School $school, Closure $callback): mixed
    {
        return $this->tenant()->run($school, $callback);
    }
}
