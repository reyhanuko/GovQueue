<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Client\AppointmentController as ClientAppointmentController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::view('/', 'welcome')->name('home');

// Placeholder until Sprint 5 wires this to the live queue feed.
Route::view('/queue-display', 'placeholder', ['title' => 'Live Queue Display', 'sprint' => 'Sprint 5'])
    ->name('display.index');

/*
|--------------------------------------------------------------------------
| Guest authentication
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->name('register.store');
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Clients
    Route::middleware('role:client')->group(function () {
        Route::get('/my-appointments', [ClientAppointmentController::class, 'index'])
            ->name('client.appointments.index');
    });

    // Placeholders for later sprints, already role-protected.
    Route::middleware('role:staff,admin')->group(function () {
        Route::view('/staff', 'placeholder', ['title' => 'Staff Console', 'sprint' => 'Sprint 7'])
            ->name('staff.dashboard');

        Route::view('/admin', 'placeholder', ['title' => 'Admin Dashboard', 'sprint' => 'Sprint 8'])
            ->middleware('role:admin')
            ->name('admin.dashboard');
    });

    // The service catalogue is public; booking requires an account (Sprint 2/3).
    Route::view('/services', 'placeholder', ['title' => 'Services', 'sprint' => 'Sprint 2'])
        ->name('services.index');
});
