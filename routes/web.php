<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::view('/', 'welcome')->name('home');

/*
|--------------------------------------------------------------------------
| Placeholders
|--------------------------------------------------------------------------
| These exist so the Sprint 0 navigation shell renders. Each one is replaced
| by a real controller as its sprint lands:
|   services.index              -> S2
|   display.index               -> S5
|   client.appointments.index   -> S3
|   staff.dashboard             -> S7
|   admin.dashboard             -> S8 / S9
|   login / register / logout   -> S1
*/

Route::view('/services', 'placeholder', ['title' => 'Services', 'sprint' => 'Sprint 2'])
    ->name('services.index');

Route::view('/queue-display', 'placeholder', ['title' => 'Live Queue Display', 'sprint' => 'Sprint 5'])
    ->name('display.index');

Route::view('/login', 'placeholder', ['title' => 'Sign In', 'sprint' => 'Sprint 1'])
    ->name('login');

Route::view('/register', 'placeholder', ['title' => 'Create Account', 'sprint' => 'Sprint 1'])
    ->name('register');

Route::middleware('auth')->group(function () {
    Route::view('/my-appointments', 'placeholder', ['title' => 'My Appointments', 'sprint' => 'Sprint 3'])
        ->name('client.appointments.index');

    Route::view('/staff', 'placeholder', ['title' => 'Staff Console', 'sprint' => 'Sprint 7'])
        ->name('staff.dashboard');

    Route::view('/admin', 'placeholder', ['title' => 'Admin Dashboard', 'sprint' => 'Sprint 8'])
        ->name('admin.dashboard');

    Route::post('/logout', fn () => redirect()->route('home'))->name('logout');
});