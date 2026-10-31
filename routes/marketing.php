<?php

use App\Http\Controllers\Marketing\RegisterSchoolController;
use Illuminate\Support\Facades\Route;

/*
| Root domain (APP_DOMAIN): marketing site and school registration.
| Registered before tenant routes so these win on the root host.
*/

Route::inertia('/', 'marketing/home')->name('marketing.home');
Route::inertia('/features', 'marketing/features')->name('marketing.features');
Route::inertia('/pricing', 'marketing/pricing')->name('marketing.pricing');
Route::get('/register-school', [RegisterSchoolController::class, 'create'])->name('marketing.register-school');
Route::post('/register-school', [RegisterSchoolController::class, 'store'])->middleware('throttle:public-forms')->name('marketing.register-school.store');
Route::get('/register-school/check-slug', [RegisterSchoolController::class, 'checkSlug'])->middleware('throttle:30,1')->name('marketing.register-school.check');
// "Find your school" login.
Route::inertia('/login', 'marketing/find-school')->name('marketing.login');

Route::get('robots.txt', fn () => response("User-agent: *\nDisallow:\n", 200, ['Content-Type' => 'text/plain']))->name('marketing.robots');
