<?php

namespace App\Http\Middleware;

use App\Models\PlatformUser;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Only whitelisted fields are shared with the browser: never whole models.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $tenant = app(TenantContext::class);
        $school = $tenant->school();
        $locale = app()->getLocale();

        $user = $school ? Auth::guard('web')->user() : null;
        /** @var PlatformUser|null $platformUser */
        $platformUser = $school ? null : Auth::guard('platform')->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => $locale,
            'translations' => fn () => $this->translations($locale),
            'school' => $school ? [
                'name' => $school->name,
                'name_bn' => $school->name_bn,
                'slug' => $school->slug,
                'locale' => $school->locale,
                'timezone' => $school->timezone,
                'currency' => $school->currency,
            ] : null,
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                    'avatar' => null,
                ] : null,
                'permissions' => $user ? fn () => $user->getAllPermissions()->pluck('name')->values() : [],
                // Linked to an active staff record (teachers get "My timetable").
                'isStaff' => $user ? fn () => \App\Modules\People\Models\StaffMember::query()->where('user_id', $user->id)->where('status', 'active')->exists() : false,
                // Which area this login belongs to (staff, teacher, parent, student): picks the layout.
                'area' => $user ? fn () => app(\App\Modules\Portal\Services\PortalAccess::class)->area($user) : null,
                // Parent portal child switcher: the guardian's own linked children only.
                'children' => $user ? fn () => $user->hasRole('Parent')
                    ? app(\App\Modules\Portal\Services\PortalAccess::class)->children($user)->map(fn ($s) => ['id' => $s->id, 'name' => $s->full_name, 'name_bn' => $s->name_bn])->values()
                    : [] : [],
                'platformUser' => $platformUser ? [
                    'id' => $platformUser->id,
                    'name' => $platformUser->name,
                    'email' => $platformUser->email,
                    'role' => $platformUser->role->value,
                ] : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function translations(string $locale): array
    {
        $path = lang_path($locale.'.json');

        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }
}
