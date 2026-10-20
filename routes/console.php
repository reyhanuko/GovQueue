<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
| Run `php artisan schedule:work` in development, or add the single cron entry
| documented in the README for production:
|
|   * * * * * cd /path/to/govqueue && php artisan schedule:run >> /dev/null 2>&1
*/

// Hourly: appointment reminders at the configured offsets (24h and 1h by
// default). The command is idempotent per offset, so a missed or duplicated run
// does not double-message a client.
Schedule::command('govqueue:send-reminders')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Nightly: keep generated bookable slots rolling forward so the catalogue never
// runs dry. A failing generation is emailed rather than swallowed.
Schedule::command('govqueue:generate-slots --days=45')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onFailure(function (Throwable $e) {
        report($e);
    });

// Weekly: trim notification and audit rows whose subject no longer exists.
//
// Only orphaned rows, and only ones already a year old - never a live
// appointment's trail, and never a refused admin action or a failed sign-in. Those
// are the records an audit exists to keep. --force because the command is
// conservative by construction and a scheduled run has nobody to answer a prompt;
// run it by hand first to see what it would remove.
Schedule::command('govqueue:prune-history --days=365 --force')
    ->weeklyOn(1, '03:15')
    ->withoutOverlapping()
    ->onFailure(function (Throwable $e) {
        report($e);
    });

/*
 * Nightly: close tickets whose slot ended without anyone resolving them.
 *
 * Without this a client who checked in and was never called leaves a ticket
 * open forever - still "Checked in", still "You are next" in their account,
 * still counted in that day's figures.
 *
 * The close also tells the client (decision D6's notification) and counts an
 * unexcused no-show against them, which is what eventually pauses their
 * bookings.
 *
 * Hourly rather than nightly so a day's last appointments are not left open
 * until the following morning, and idempotent, so a missed or repeated run is
 * harmless.
 */
Schedule::command('govqueue:close-stale-appointments')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function (Throwable $e) {
        report($e);
    });
