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
