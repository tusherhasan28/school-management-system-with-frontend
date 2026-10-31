<?php

namespace App\Http\Middleware;

use App\Modules\Portal\Services\PortalAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps parent-only and student-only logins out of the staff area (/app):
 * they are sent to their own portal instead.
 */
class EnsureStaffArea
{
    public function __construct(private readonly PortalAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $this->access->isFamilyOnly($user)) {
            if ($request->expectsJson()) {
                abort(403);
            }

            return redirect()->to($this->access->homeUrl($user));
        }

        return $next($request);
    }
}
