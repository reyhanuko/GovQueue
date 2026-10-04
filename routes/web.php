<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Client\AppointmentController as ClientAppointmentController;
use App\Http\Controllers\Client\BookingController;
use App\Http\Controllers\Client\ProfileController as ClientProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServiceCatalogController;
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
| Service catalogue (public; only the booking action needs an account)
|--------------------------------------------------------------------------
*/

Route::get('/services', [ServiceCatalogController::class, 'index'])->name('services.index');
Route::get('/services/{service}', [ServiceCatalogController::class, 'show'])->name('services.show');

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('role:client')->group(function () {
        Route::get('/my-appointments', [ClientAppointmentController::class, 'index'])
            ->name('client.appointments.index');
        Route::get('/my-appointments/{appointment}', [ClientAppointmentController::class, 'show'])
            ->name('client.appointments.show');
        Route::get('/my-appointments/{appointment}/move', [ClientAppointmentController::class, 'rescheduleForm'])
            ->name('client.appointments.reschedule');
        Route::put('/my-appointments/{appointment}/move', [BookingController::class, 'reschedule'])
            ->name('client.appointments.reschedule.store');
        Route::delete('/my-appointments/{appointment}', [BookingController::class, 'cancel'])
            ->name('client.appointments.cancel');

        Route::get('/services/{service}/book', [BookingController::class, 'create'])
            ->name('client.booking.create');
        Route::post('/services/{service}/book', [BookingController::class, 'store'])
            ->name('client.booking.store');

        Route::get('/profile', [ClientProfileController::class, 'edit'])->name('client.profile.edit');
        Route::put('/profile', [ClientProfileController::class, 'update'])->name('client.profile.update');
        Route::put('/profile/password', [ClientProfileController::class, 'updatePassword'])
            ->name('client.profile.password');
    });

    // Placeholders for later sprints, already role-protected.
    Route::middleware('role:staff,admin')->group(function () {
        Route::view('/staff', 'placeholder', ['title' => 'Staff Console', 'sprint' => 'Sprint 7'])
            ->name('staff.dashboard');

        Route::view('/admin', 'placeholder', ['title' => 'Admin Dashboard', 'sprint' => 'Sprint 8'])
            ->middleware('role:admin')
            ->name('admin.dashboard');
    });
});
