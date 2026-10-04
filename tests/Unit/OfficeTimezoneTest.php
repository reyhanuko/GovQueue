<?php

namespace Tests\Unit;

use App\Models\TimeSlot;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The LGU of Tupi is in South Cotabato (UTC+8). Queue times, the service day,
 * and the booking horizon are all stated in office local time, so the app must
 * run in Asia/Manila rather than the Laravel default of UTC.
 *
 * Running in UTC showed clients a 08:00 slot as 16:00 and rolled the service
 * day over eight hours late, which is exactly the kind of defect that only
 * shows up on the real deployment.
 */
class OfficeTimezoneTest extends TestCase
{
    public function test_the_application_runs_in_office_local_time(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->assertSame(config('app.timezone'), config('govqueue.office.timezone'),
            'The app timezone and the configured office timezone must agree.');
    }

    public function test_now_matches_wall_clock_time_in_the_office(): void
    {
        // A fixed instant expressed in UTC. Read in Manila it is 09:30, and
        // Laravel must report it as 09:30 rather than 01:30.
        Carbon::setTestNow(Carbon::parse('2026-10-05T01:30:00Z'));

        $this->assertSame('2026-10-05 09:30:00', now()->toDateTimeString());
        $this->assertSame('Asia/Manila', now()->timezoneName);

        Carbon::setTestNow();
    }

    public function test_the_service_day_rolls_over_at_midnight_manila_not_utc(): void
    {
        // 17:00 UTC is already 01:00 the next day in Manila, so the service day
        // must already have rolled over. Under UTC it would still read the 5th.
        Carbon::setTestNow(Carbon::parse('2026-10-05T17:00:00Z'));

        $this->assertSame('2026-10-06', today()->toDateString(),
            'The office service day must follow Asia/Manila midnight.');

        Carbon::setTestNow();
    }

    public function test_an_eight_am_slot_is_labelled_eight_am(): void
    {
        $slot = new TimeSlot([
            'start_at' => '2026-10-06 08:00:00',
            'end_at' => '2026-10-06 08:30:00',
            'start_time' => '08:00:00',
            'end_time' => '08:30:00',
        ]);

        $this->assertSame('8:00 AM', $slot->formatTime($slot->start_time));
        $this->assertSame('8:00 AM - 8:30 AM', $slot->timeRangeLabel());
    }

    public function test_the_booking_horizon_is_measured_from_the_office_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05T17:00:00Z')); // already 6 Oct in Manila

        $horizon = (int) config('govqueue.booking_horizon_days');
        $lastBookable = today()->addDays($horizon)->toDateString();

        $this->assertSame('2026-11-05', $lastBookable);

        Carbon::setTestNow();
    }
}
