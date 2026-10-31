<?php

namespace App\Providers;

use App\Models\PlatformUser;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Long-wait alerts (see 'waits' in config/horizon.php) go to the ops inbox.
        $alertTo = array_values(array_filter(array_map('trim', explode(',', (string) config('ops.alert_email')))));
        if ($alertTo !== []) {
            Horizon::routeMailNotificationsTo($alertTo[0]);
        }
    }

    /**
     * Horizon is for platform owners/administrators only, in every environment
     * (Horizon's default lets anyone in when APP_ENV=local). The route group
     * already requires the admin host and the platform guard.
     */
    protected function authorization(): void
    {
        Horizon::auth(function (Request $request): bool {
            $user = $request->user('platform');

            return $user instanceof PlatformUser && $user->hasPlatformAbility('horizon.view');
        });
    }

    protected function gate(): void
    {
        // Not used: see authorization().
    }
}
