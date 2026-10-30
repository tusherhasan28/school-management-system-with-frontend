<?php

namespace App\Http\Middleware;

use App\Models\SchoolDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform routes are path-only (so generated frontend URLs stay relative)
 * and are only served on admin.APP_DOMAIN. Any other host gets a 404.
 */
class EnsurePlatformHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = strtolower(config('tenancy.admin_subdomain').'.'.config('tenancy.domain'));

        if (SchoolDomain::normalize($request->getHost()) !== $expected) {
            abort(404);
        }

        app()->setLocale(config('tenancy.platform_locale', 'en'));

        return $next($request);
    }
}
