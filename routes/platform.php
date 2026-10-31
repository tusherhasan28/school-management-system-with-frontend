<?php

use App\Http\Controllers\Platform\Auth\PlatformLoginController;
use App\Http\Controllers\Platform\PlatformDashboardController;
use App\Http\Controllers\Platform\RegistrationController;
use App\Http\Controllers\Platform\SchoolController;
use App\Http\Controllers\Platform\SchoolStatusController;
use App\Modules\Billing\Http\Controllers\PlanController;
use App\Modules\Billing\Http\Controllers\PlatformInvoiceController;
use App\Modules\Billing\Http\Controllers\SchoolBillingController;
use App\Modules\Billing\Http\Controllers\SubscriptionIndexController;
use App\Modules\Support\Http\Controllers\PlatformSupportController;
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

        // Milestone 7: plans, subscriptions, invoices.
        Route::get('plans', [PlanController::class, 'index'])->middleware('can:billing.view')->name('plans.index');
        Route::post('plans', [PlanController::class, 'store'])->middleware('can:billing.manage')->name('plans.store');
        Route::put('plans/{plan}', [PlanController::class, 'update'])->middleware('can:billing.manage')->name('plans.update');
        Route::get('subscriptions', SubscriptionIndexController::class)->middleware('can:billing.view')->name('subscriptions.index');
        Route::middleware('can:billing.manage')->prefix('schools/{school}/billing')->name('schools.billing.')->controller(SchoolBillingController::class)->group(function () {
            Route::post('assign', 'assign')->name('assign');
            Route::post('suspend', 'suspend')->name('suspend');
            Route::post('reactivate', 'reactivate')->name('reactivate');
            Route::post('cancel', 'cancel')->name('cancel');
            Route::post('extend-trial', 'extendTrial')->name('extend-trial');
            Route::post('invoices', 'invoice')->name('invoice');
        });
        Route::get('invoices', [PlatformInvoiceController::class, 'index'])->middleware('can:billing.view')->name('invoices.index');
        Route::get('invoices/{invoice}', [PlatformInvoiceController::class, 'show'])->middleware('can:billing.view')->name('invoices.show');
        Route::post('invoices/{invoice}/payments', [PlatformInvoiceController::class, 'pay'])->middleware(['can:billing.manage', 'throttle:30,1'])->name('invoices.pay');
        Route::post('invoices/{invoice}/void', [PlatformInvoiceController::class, 'void'])->middleware('can:billing.manage')->name('invoices.void');

        Route::get('support', [PlatformSupportController::class, 'index'])->middleware('can:support.view')->name('support.index');
        Route::get('support/{ticket}', [PlatformSupportController::class, 'show'])->middleware('can:support.view')->name('support.show');
        Route::post('support/{ticket}/reply', [PlatformSupportController::class, 'reply'])->middleware('can:support.manage')->name('support.reply');
        Route::post('support/{ticket}/status', [PlatformSupportController::class, 'status'])->middleware('can:support.manage')->name('support.status');
        Route::post('schools/{school}/support-access', [PlatformSupportController::class, 'requestAccess'])->middleware(['can:support.manage', 'throttle:10,1'])->name('support.access.request');
        Route::get('support-access/{grant}', [PlatformSupportController::class, 'snapshot'])->middleware('can:support.manage')->name('support.access.snapshot');

        Route::get('registrations', [RegistrationController::class, 'index'])->middleware('can:schools.view')->name('registrations.index');
        Route::post('registrations/{registration}/approve', [RegistrationController::class, 'approve'])->middleware('can:schools.manage')->name('registrations.approve');
        Route::post('registrations/{registration}/reject', [RegistrationController::class, 'reject'])->middleware('can:schools.manage')->name('registrations.reject');
    });
});
