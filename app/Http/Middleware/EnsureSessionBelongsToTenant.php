<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a session to the school it was created on. Session cookies are
 * host-only already; this is a second line of defence against a session id
 * being replayed on another school's host, and against an authenticated user
 * whose school does not match the resolved tenant.
 */
class EnsureSessionBelongsToTenant
{
    public const SESSION_KEY = 'tenant.school_id';

    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $schoolId = $this->tenant->require()->getKey();
        $session = $request->session();
        $bound = $session->get(self::SESSION_KEY);

        if ($bound !== null && (int) $bound !== $schoolId) {
            $this->reset($request);
        }

        $user = Auth::guard('web')->user();

        if ($user !== null && (int) $user->getAttribute('school_id') !== $schoolId) {
            $this->reset($request);
        }

        $request->session()->put(self::SESSION_KEY, $schoolId);

        return $next($request);
    }

    private function reset(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
