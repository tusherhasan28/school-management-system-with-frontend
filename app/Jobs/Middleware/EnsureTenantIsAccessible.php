<?php

namespace App\Jobs\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * Job middleware for tenant jobs: the job must run inside a tenant context,
 * and is deleted (not run) if the school has since been suspended. Data is
 * kept; only the work is skipped.
 */
class EnsureTenantIsAccessible
{
    public function handle(object $job, Closure $next): void
    {
        $school = app(TenantContext::class)->require();

        if (! $school->isAccessible()) {
            if (method_exists($job, 'delete')) {
                $job->delete();
            }

            return;
        }

        $next($job);
    }
}
