<?php

namespace App\Http\Middleware;

use App\Models\SchoolDomain;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the school from the request hostname and sets the tenant context.
 *
 * School identity is derived from the host only; any school_id in the query,
 * body or headers is ignored. Unknown hosts get a generic 404; schools that
 * are not accessible get a generic "unavailable" page.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = SchoolDomain::normalize($request->getHost());

        if ($this->isCentralHost($host)) {
            abort(404);
        }

        $domain = SchoolDomain::query()
            ->with('school')
            ->where('domain', $host)
            ->first();

        $school = $domain?->school;

        if ($school === null) {
            abort(404);
        }

        if (! $school->isAccessible()) {
            return response()->view('errors.school-unavailable', [], 503);
        }

        // run() restores the previous state afterwards, so a context can never
        // leak into the next request on a long-lived worker (Octane, tests).
        return $this->tenant->run($school, function () use ($request, $next, $school) {
            app()->setLocale(in_array($school->locale, ['bn', 'en'], true) ? $school->locale : 'bn');

            return $next($request);
        });
    }

    private function isCentralHost(string $host): bool
    {
        $root = strtolower((string) config('tenancy.domain'));

        return $host === $root
            || $host === 'www.'.$root
            || $host === config('tenancy.admin_subdomain').'.'.$root;
    }
}
