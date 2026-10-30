<?php

namespace App\Http\Controllers\Platform\Auth;

use App\Http\Controllers\Controller;
use App\Models\PlatformUser;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlatformLoginController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('platform/auth/login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        $guard = Auth::guard('platform');

        $attempt = [
            'email' => Str::lower($credentials['email']),
            'password' => $credentials['password'],
            'status' => 'active',
        ];

        if (! $guard->attempt($attempt, (bool) ($credentials['remember'] ?? false))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        /** @var PlatformUser $user */
        $user = $guard->user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $audit->platform('platform.login', $user);

        return redirect()->intended(route('platform.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
