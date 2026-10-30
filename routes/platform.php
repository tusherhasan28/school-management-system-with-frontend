<?php

use App\Http\Controllers\Platform\Auth\PlatformLoginController;
use App\Http\Controllers\Platform\PlatformDashboardController;
use App\Http\Controllers\Platform\RegistrationController;
use App\Http\Controllers\Platform\SchoolController;
use App\Http\Controllers\Platform\SchoolStatusController;
use Illuminate\Support\Facades\Route;

/*
| admin.APP_DOMAIN: platform (Super Admin) area. Guard: platform.
| Host is enforced by the platform.host middleware (bootstrap/app.php).
*/

Route::prefix('platform')->name('platform.')->group(function () {
    Route::middleware('guest:platform')->group(function () {
        Route::get('login', [PlatformLoginController::class, 'create'])->name('login');
        Route::post('login', [PlatformLoginController::class, 'store'])
            ->middleware('throttle:platform-login')
            ->name('login.store');
    });

    Route::middleware(['auth:platform', 'active:platform'])->group(function () {
        Route::post('logout', [PlatformLoginController::class, 'destroy'])->name('logout');

        Route::get('dashboard', PlatformDashboardController::class)
            ->middleware('can:platform.view')
            ->name('dashboard');

        Route::get('schools', [SchoolController::class, 'index'])->middleware('can:schools.view')->name('schools.index');
        Route::get('schools/{school}', [SchoolController::class, 'show'])->middleware('can:schools.view')->name('schools.show');
        Route::put('schools/{school}/status', SchoolStatusController::class)->middleware('can:schools.manage')->name('schools.status');

        Route::get('registrations', [RegistrationController::class, 'index'])->middleware('can:schools.view')->name('registrations.index');
        Route::post('registrations/{registration}/approve', [RegistrationController::class, 'approve'])->middleware('can:schools.manage')->name('registrations.approve');
        Route::post('registrations/{registration}/reject', [RegistrationController::class, 'reject'])->middleware('can:schools.manage')->name('registrations.reject');
    });
});
