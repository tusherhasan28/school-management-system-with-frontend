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

        // Resolve the school before the session starts and before auth runs.
        $middleware->prependToPriorityList(before: StartSession::class, prepend: ResolveTenant::class);
        // Bind the session to the school before auth, Inertia shared props or bindings run.
        $middleware->appendToPriorityList(after: StartSession::class, append: EnsureSessionBelongsToTenant::class);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'platform.host' => EnsurePlatformHost::class,
            'tenant.session' => EnsureSessionBelongsToTenant::class,
            'active' => EnsureUserIsActive::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('platform', 'platform/*')
            ? route('platform.login')
            : route('login'));

        $middleware->redirectUsersTo(fn (Request $request) => $request->is('platform', 'platform/*')
            ? route('platform.dashboard')
            : route('dashboard'));

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
