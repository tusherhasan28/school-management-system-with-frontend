<?php

use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\PublicHomeController;
use App\Modules\Setup\Http\Controllers\CampusController;
use Illuminate\Support\Facades\Route;

/*
| {school}.APP_DOMAIN — one school. Middleware: tenant → web → tenant.session.
|
| Namespaces (see docs/architecture.md §2):
|   /, /about, /admission, /notices, /contact   public website (Milestone 6)
|   /login, /forgot-password, ...               tenant auth (Fortify)
|   /app/*                                      school administration
|   /teacher/*, /student/*, /parent/*           portals (Milestone 6)
|   /settings/*                                 personal account settings
*/

Route::get('/', PublicHomeController::class)->name('home');

// Fortify (login, password reset, 2FA, email verification) for tenant users only.
require base_path('vendor/laravel/fortify/routes/routes.php');

Route::middleware(['auth:web', 'active', 'verified'])->prefix('app')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('campuses', [CampusController::class, 'index'])->name('campuses.index');
    Route::post('campuses', [CampusController::class, 'store'])->name('campuses.store');
    Route::get('campuses/{campus}', [CampusController::class, 'show'])->name('campuses.show');
    Route::put('campuses/{campus}', [CampusController::class, 'update'])->name('campuses.update');
    Route::delete('campuses/{campus}', [CampusController::class, 'destroy'])->name('campuses.destroy');
});

require __DIR__.'/settings.php';
