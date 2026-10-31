<?php

use App\Http\Middleware\EnsurePlatformHost;
use App\Http\Middleware\EnsureSessionBelongsToTenant;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            $domain = config('tenancy.domain');

            // Order matters: hostname-bound groups first, tenant catch-all last.
            Route::middleware('web')->domain($domain)->group(base_path('routes/marketing.php'));

            // Platform routes are path-only so Wayfinder emits relative URLs;
            // the host is enforced by EnsurePlatformHost instead.
            Route::middleware('web')
                ->domain(config('tenancy.admin_subdomain').'.'.$domain)
                ->get('/', fn () => redirect('/platform/dashboard'));

            Route::middleware(['platform.host', 'web'])->group(base_path('routes/platform.php'));

            // Tenant routes match any other host; ResolveTenant 404s unknown hosts.
            // 'tenant' must run before 'web' so the session and Inertia shared
            // props see the tenant-scoped user provider.
            Route::middleware(['tenant', 'web', 'tenant.session'])->group(base_path('routes/tenant.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Gateways post server-to-server notifications without a CSRF token; the
        // adapter verifies the provider's signature instead.
        $middleware->validateCsrfTokens(except: ['payments/callback/*']);

        // Resolve the school before the session starts and before auth runs.
        $middleware->prependToPriorityList(before: StartSession::class, prepend: ResolveTenant::class);
        // Bind the session to the school before auth, Inertia shared props or bindings run.
        $middleware->appendToPriorityList(after: StartSession::class, append: EnsureSessionBelongsToTenant::class);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'platform.host' => EnsurePlatformHost::class,
            'tenant.session' => EnsureSessionBelongsToTenant::class,
            'active' => EnsureUserIsActive::class,
            'staff' => \App\Http\Middleware\EnsureStaffArea::class,
            'portal' => \App\Http\Middleware\EnsurePortalArea::class,
        ]);

        // Decide by host, not path: everything on admin.APP_DOMAIN (including
        // /horizon) belongs to the platform guard.
        $isAdminHost = fn (Request $request) => strtolower($request->getHost())
            === strtolower(config('tenancy.admin_subdomain').'.'.config('tenancy.domain'));

        $middleware->redirectGuestsTo(fn (Request $request) => $isAdminHost($request) ? route('platform.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $isAdminHost($request) ? route('platform.dashboard') : route('portal.home'));

        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Never flash these back to forms after a validation error (and so never into session storage).
        $exceptions->dontFlash([
            'current_password', 'password', 'password_confirmation',
            'national_id', 'birth_registration_no', 'credentials', 'api_key', 'secret', 'token',
        ]);
    })->create();
