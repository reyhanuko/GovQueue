<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminServiceController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\NotificationLogController;
use App\Http\Controllers\Admin\ServiceWindowController;
use App\Http\Controllers\Admin\SlotTemplateController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TimeSlotController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Client\AppointmentController as ClientAppointmentController;
use App\Http\Controllers\Client\BookingController;
use App\Http\Controllers\Client\ProfileController as ClientProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PublicDisplayController;
use App\Http\Controllers\ServiceCatalogController;
use App\Http\Controllers\Staff\QueueController as StaffQueueController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::view('/', 'welcome')->name('home');

// The lobby display is intentionally unauthenticated: it shows queue numbers
// and order only, never client names, services, or priority claims.
Route::get('/queue-display', [PublicDisplayController::class, 'index'])->name('display.index');

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

    // Staff console (Sprint 4)
    Route::middleware('role:staff,admin')->group(function () {
        Route::get('/staff', [StaffQueueController::class, 'index'])->name('staff.dashboard');
    });

    // Administrator only. Kept in its own group rather than nested inside the
    // staff group with a second middleware call: forgetting that call once
    // exposed the notification log - with client emails and phone numbers - to
    // every member of staff.
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
        Route::post('/admin/regenerate-slots', [AdminDashboardController::class, 'regenerate'])
            ->name('admin.regenerate');

        // Service windows
        Route::get('/admin/windows', [ServiceWindowController::class, 'index'])->name('admin.windows.index');
        Route::get('/admin/windows/create', [ServiceWindowController::class, 'create'])->name('admin.windows.create');
        Route::post('/admin/windows', [ServiceWindowController::class, 'store'])->name('admin.windows.store');
        Route::get('/admin/windows/{window}/edit', [ServiceWindowController::class, 'edit'])->name('admin.windows.edit');
        Route::put('/admin/windows/{window}', [ServiceWindowController::class, 'update'])->name('admin.windows.update');
        Route::delete('/admin/windows/{window}', [ServiceWindowController::class, 'destroy'])->name('admin.windows.destroy');

        // Services
        Route::get('/admin/services', [AdminServiceController::class, 'index'])->name('admin.services.index');
        Route::get('/admin/services/create', [AdminServiceController::class, 'create'])->name('admin.services.create');
        Route::post('/admin/services', [AdminServiceController::class, 'store'])->name('admin.services.store');
        Route::get('/admin/services/{service}/edit', [AdminServiceController::class, 'edit'])->name('admin.services.edit');
        Route::put('/admin/services/{service}', [AdminServiceController::class, 'update'])->name('admin.services.update');
        Route::delete('/admin/services/{service}', [AdminServiceController::class, 'destroy'])->name('admin.services.destroy');

        // Slot templates, nested under the service they belong to
        Route::get('/admin/services/{service}/templates', [SlotTemplateController::class, 'index'])
            ->name('admin.templates.index');
        Route::post('/admin/services/{service}/templates', [SlotTemplateController::class, 'store'])
            ->name('admin.templates.store');
        Route::put('/admin/templates/{template}', [SlotTemplateController::class, 'update'])
            ->name('admin.templates.update');
        Route::delete('/admin/templates/{template}', [SlotTemplateController::class, 'destroy'])
            ->name('admin.templates.destroy');

        // Day-by-day slot capacity
        Route::get('/admin/slots', [TimeSlotController::class, 'index'])->name('admin.slots.index');
        Route::put('/admin/slots/bulk', [TimeSlotController::class, 'bulkUpdate'])->name('admin.slots.bulk');
        Route::put('/admin/slots/{slot}', [TimeSlotController::class, 'update'])->name('admin.slots.update');
        Route::delete('/admin/slots/{slot}', [TimeSlotController::class, 'destroy'])->name('admin.slots.destroy');

        // Holidays
        Route::get('/admin/holidays', [HolidayController::class, 'index'])->name('admin.holidays.index');
        Route::post('/admin/holidays', [HolidayController::class, 'store'])->name('admin.holidays.store');
        Route::delete('/admin/holidays/{holiday}', [HolidayController::class, 'destroy'])->name('admin.holidays.destroy');

        // Staff accounts
        Route::get('/admin/staff', [StaffController::class, 'index'])->name('admin.staff.index');
        Route::get('/admin/staff/create', [StaffController::class, 'create'])->name('admin.staff.create');
        Route::post('/admin/staff', [StaffController::class, 'store'])->name('admin.staff.store');
        Route::get('/admin/staff/{user}/edit', [StaffController::class, 'edit'])->name('admin.staff.edit');
        Route::put('/admin/staff/{user}', [StaffController::class, 'update'])->name('admin.staff.update');
        Route::put('/admin/staff/{user}/active', [StaffController::class, 'toggle'])->name('admin.staff.toggle');
        Route::put('/admin/staff/{user}/password', [StaffController::class, 'resetPassword'])
            ->name('admin.staff.password');
        Route::delete('/admin/staff/{user}', [StaffController::class, 'destroy'])->name('admin.staff.destroy');

        // Audit trail
        Route::get('/admin/audit', [AuditLogController::class, 'index'])->name('admin.audit.index');

        Route::get('/admin/notifications', [NotificationLogController::class, 'index'])
            ->name('admin.notifications.index');
    });
});
