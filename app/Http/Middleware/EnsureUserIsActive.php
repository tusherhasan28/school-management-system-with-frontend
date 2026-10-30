<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out disabled or locked accounts on their next request.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        $user = Auth::guard($guard)->user();

        if ($user !== null && method_exists($user, 'isActive') && ! $user->isActive()) {
            Auth::guard($guard)->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to($guard === 'platform' ? route('platform.login') : route('login'))
                ->with('status', __('auth.account_disabled'));
        }

        return $next($request);
    }
}
