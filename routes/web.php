<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminServiceController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\NotificationLogController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ServiceWindowController;
use App\Http\Controllers\Admin\SlotTemplateController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TimeSlotController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Client\AppointmentController as ClientAppointmentController;
use App\Http\Controllers\Client\BookingController;
use App\Http\Controllers\Client\ProfileController as ClientProfileController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NotificationInboxController;
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

/*
 * Liveness and readiness for a deployment.
 *
 * Unauthenticated on purpose so a load balancer or a plain curl can use it, and
 * shallow on purpose: it reports whether each dependency answers, never what is in
 * it. A health endpoint that leaked table names or version numbers would be a worse
 * problem than the outage it exists to detect.
 */
Route::get('/up', HealthController::class)->name('health');

/*
 * CSP violation reports. Public and unauthenticated by necessity - the browser
 * posts them without credentials - and safe because the handler only logs. Throttled
 * so it cannot be used to flood the log file.
 */
Route::post('/csp-report', CspReportController::class)
    ->middleware('throttle:30,1')
    ->name('csp.report');

/*
 * Prometheus scrape endpoint. Gated on METRICS_TOKEN inside the controller, and
 * returning 404 when that is unset, so a deployment which forgets to configure it
 * is closed rather than open. See MetricsController for why it is not behind the
 * admin login.
 */
Route::get('/metrics', MetricsController::class)->name('metrics');

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

    /*
     * Password reset.
     *
     * Without this a client who forgets their password is locked out of booking,
     * viewing and cancelling appointments permanently, because no counter process
     * can reset it for them.
     *
     * The reset form route is signed, so the token cannot be guessed from a
     * crafted URL, and throttle stops the request form being used to spray reset
     * mail at third parties.
     */
    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])
        ->middleware('signed')
        ->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.update');
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

    /*
     * The in-app inbox, for every signed-in role.
     *
     * Deliberately outside the role groups below: a message belongs to the
     * account that received it, and the controller only ever queries the
     * signed-in user's own rows. What differs by role is what a row links to,
     * not who may read their own mail.
     */
    Route::get('/messages', [NotificationInboxController::class, 'index'])
        ->name('notifications.index');
    Route::post('/messages/read-all', [NotificationInboxController::class, 'readAll'])
        ->name('notifications.read-all');
    Route::get('/messages/{id}', [NotificationInboxController::class, 'read'])
        ->name('notifications.read');
    Route::delete('/messages/{id}', [NotificationInboxController::class, 'destroy'])
        ->name('notifications.destroy');

    // Forced password change for a new staff or administrator account. Kept
    // outside the role groups because it applies to back-office staff only -
    // a client's own password is changed through the profile form.
    Route::middleware('role:staff,admin')->group(function () {
        Route::get('/change-password', [ChangePasswordController::class, 'edit'])
            ->name('auth.password.change');
        Route::post('/change-password', [ChangePasswordController::class, 'update'])
            ->middleware('throttle:6,1')
            ->name('auth.password.change.store');
    });

    Route::middleware('role:client')->group(function () {
        Route::get('/my-appointments', [ClientAppointmentController::class, 'index'])
            ->name('client.appointments.index');
        Route::get('/my-appointments/{appointment}', [ClientAppointmentController::class, 'show'])
            ->name('client.appointments.show');
        Route::get('/my-appointments/{appointment}/move', [ClientAppointmentController::class, 'rescheduleForm'])
            ->name('client.appointments.reschedule');
        // Each reschedule releases one place and claims another, so a script that
        // hammered these two endpoints could cycle capacity through slots in
        // seconds. Ten actions a minute is far above a real client's rate and low
        // enough that an automated sweep gets nowhere.
        Route::put('/my-appointments/{appointment}/move', [BookingController::class, 'reschedule'])
            ->middleware('throttle:booking')
            ->name('client.appointments.reschedule.store');
        Route::delete('/my-appointments/{appointment}', [BookingController::class, 'cancel'])
            ->name('client.appointments.cancel');

        Route::get('/services/{service}/book', [BookingController::class, 'create'])
            ->name('client.booking.create');
        // Booking and cancellation both change slot occupancy, so both are capped.
        // Throttling the GET as well is deliberately avoided: it is a plain page
        // view and blocking it would break clients who press back or reload.
        Route::post('/services/{service}/book', [BookingController::class, 'store'])
            ->middleware('throttle:booking')
            ->name('client.booking.store');

        Route::get('/profile', [ClientProfileController::class, 'edit'])->name('client.profile.edit');
        Route::put('/profile', [ClientProfileController::class, 'update'])->name('client.profile.update');
        Route::put('/profile/password', [ClientProfileController::class, 'updatePassword'])
            ->name('client.profile.password');

        // Separate from the profile form so unticking both boxes cannot be lost in
        // a profile submission.
        Route::put('/profile/notifications', [ClientProfileController::class, 'updateNotifications'])
            ->name('client.profile.notifications');
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
        // Seeds the fixed-date national holidays for a year. Never overwrites an
        // existing closure, so it is safe to run again after adding one by hand.
        Route::post('/admin/holidays/seed-regular', [HolidayController::class, 'seedRegularHolidays'])
            ->name('admin.holidays.seed');
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

        // Analytics and reporting
        Route::get('/admin/reports', [ReportController::class, 'index'])->name('admin.reports.index');
        Route::get('/admin/reports/export', [ReportController::class, 'export'])->name('admin.reports.export');
        Route::get('/admin/reports/export/pdf', [ReportController::class, 'exportPdf'])
            ->name('admin.reports.export.pdf');

        // Audit trail
        Route::get('/admin/audit', [AuditLogController::class, 'index'])->name('admin.audit.index');

        Route::get('/admin/notifications', [NotificationLogController::class, 'index'])
            ->name('admin.notifications.index');
    });
});
