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
