<?php

use Illuminate\Support\Facades\Route;

/*
| Root domain (APP_DOMAIN): marketing site and school registration.
| Registered before tenant routes so these win on the root host.
*/

Route::inertia('/', 'marketing/home')->name('marketing.home');
Route::inertia('/features', 'marketing/features')->name('marketing.features');
Route::inertia('/pricing', 'marketing/pricing')->name('marketing.pricing');
// School registration (Milestone 2) and "find your school" login.
Route::inertia('/register-school', 'marketing/register-school')->name('marketing.register-school');
Route::inertia('/login', 'marketing/find-school')->name('marketing.login');
