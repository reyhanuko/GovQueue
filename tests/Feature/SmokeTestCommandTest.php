<?php

namespace Tests\Feature;

use App\Events\AppointmentStatusChanged;
use App\Events\QueueUpdated;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\QueueCounter;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The post-deployment smoke test.
 *
 * Two properties matter more than the checks themselves.
 *
 * The first is that it leaves nothing behind. Its whole reason for existing is to
 * be safe to point at a live deployment - a smoke test that wrote a real
 * appointment onto the real queue would need cleanup, and cleanup that fails part
 * way through is how a genuine client's slot gets released to someone else.
 *
 * The second is that it fails loudly. A smoke test which passes on a broken
 * deployment is worse than none, because it is the thing someone trusts instead of
 * opening the doors.
 */
class SmokeTestCommandTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Mid-morning, on a day the office opens.
         *
         * The smoke test works a queue, and a client may only check in from
         * half an hour before their slot until a quarter of an hour after it
         * ends (decision D7). A clock pinned to the middle of the office's
         * day is what makes the check exercise the queue rather than only the
         * booking, and it makes the test mean the same thing at any hour.
         */
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00'));

        $this->window = ServiceWindow::factory()->create(['code' => 'W1', 'is_active' => true]);
        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'is_active' => true,
        ]);

        // A recurring template, so govqueue:generate-slots has something to expand.
        SlotTemplate::create([
            'service_id' => $this->service->id,
            'day_of_week' => now()->dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'slot_minutes' => 30,
            'capacity' => 10,
            'effective_from' => today()->subDay()->toDateString(),
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_passes_on_a_working_deployment(): void
    {
        Artisan::call('govqueue:generate-slots', ['--days' => 3]);

        $exit = Artisan::call('govqueue:smoke-test');

        $this->assertSame(0, $exit, 'Smoke test failed on a working deployment.');
        $this->assertStringContainsString('Smoke test passed', Artisan::output());
    }

    public function test_it_passes_when_an_earlier_window_is_already_serving(): void
    {
        Artisan::call('govqueue:generate-slots', ['--days' => 3]);

        // A second service day, so the smoke test has a later, idle slot to move
        // on to once it finds the first one sitting behind a busy window.
        SlotTemplate::create([
            'service_id' => $this->service->id,
            'day_of_week' => now()->addDays(2)->dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'slot_minutes' => 30,
            'capacity' => 10,
            'effective_from' => today()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        Artisan::call('govqueue:generate-slots', ['--days' => 3]);

        // callNext() refuses while a client is at the window, so a deployment with
        // queue history - a demo seed, an office mid-shift - used to report a
        // broken booking path when the deployment was fine.
        $slot = TimeSlot::query()
            ->where('service_id', $this->service->id)
            ->orderBy('start_at')
            ->first();

        Appointment::create([
            'reference_number' => 'GQ-BUSY-001',
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'slot_date' => $slot->slot_date,
            'status' => 'serving',
            'source' => 'online',
            'scheduled_at' => $slot->start_at,
            'queue_number' => 'A-001',
            'queue_date' => $slot->slot_date,
            'serving_started_at' => now(),
        ]);

        $exit = Artisan::call('govqueue:smoke-test');

        $this->assertStringContainsString('PASS  Booking path round-trips', Artisan::output());
        $this->assertSame(0, $exit, 'A busy window was reported as a broken deployment.');

        // The pre-existing serving ticket must be left exactly as it was.
        $this->assertSame('serving', Appointment::where('reference_number', 'GQ-BUSY-001')->first()->status->value);
    }

    public function test_it_leaves_no_appointment_behind(): void
    {
        Artisan::call('govqueue:generate-slots', ['--days' => 3]);

        Artisan::call('govqueue:smoke-test');

        // The one assertion the whole design exists to make.
        $this->assertSame(0, Appointment::count(), 'The smoke test left an appointment on the live queue.');
        $this->assertSame(0, Transaction::count(), 'The smoke test left a transaction behind.');
        $this->assertSame(0, AuditLog::where('action', 'like', 'appointment.%')->count());
    }

    public function test_it_restores_slot_capacity_exactly(): void
    {
        Artisan::call('govqueue:generate-slots', ['--days' => 3]);

        $before = TimeSlot::orderBy('id')->pluck('booked_count', 'id')->all();
        $counters = QueueCounter::count();

        Artisan::call('govqueue:smoke-test');

        $after = TimeSlot::orderBy('id')->pluck('booked_count', 'id')->all();

        // The booking increments the counter and the completion releases it, both
        // inside the transaction. If either half of that did not roll back, the
        // office would be permanently a place or two short of capacity - the kind
        // of slow leak nobody notices until the catalogue runs dry.
        $this->assertSame($before, $after, 'Slot capacity did not return to its starting value.');
        $this->assertSame($counters, QueueCounter::count());
    }

    public function test_it_restores_the_queue_number_counter(): void
    {
        Artisan::call('govqueue:generate-slots', ['--days' => 3]);

        Artisan::call('govqueue:smoke-test');

        $this->assertSame(
            0,
            QueueCounter::where('last_number', '>', 0)->count(),
            'A queue number was consumed and not returned.'
        );
    }

    public function test_it_creates_no_clients(): void
    {
        Artisan::call('govqueue:generate-slots', ['--days' => 3]);

        $before = User::count();

        Artisan::call('govqueue:smoke-test');

        // A smoke-test client left behind would show up in the staff console and
        // could eventually receive a real reminder.
        $this->assertSame($before, User::count());
    }

    public function test_it_fails_when_there_are_no_slots_to_book(): void
    {
        // No generated slots: exactly the state a fresh deploy is in before the
        // nightly job has run for the first time.
        $exit = Artisan::call('govqueue:smoke-test');

        // Captured once and reused. Artisan::output() delegates to
        // BufferedOutput::fetch(), which *drains* the buffer - so a second call
        // returns an empty string and any assertion on it fails for a reason that
        // has nothing to do with the command.
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'The smoke test passed with nothing bookable.');
        $this->assertStringContainsString('Booking path round-trips', $output);
        $this->assertStringContainsString('FAIL', $output);
    }

    public function test_it_names_the_missing_configuration_rather_than_dumping_a_stack_trace(): void
    {
        // The output gets pasted into a ticket or read over the phone, so it has to
        // say which thing to fix.
        Artisan::call('govqueue:smoke-test');

        $output = Artisan::output();

        $this->assertStringNotContainsString('Stack trace', $output);
        $this->assertStringNotContainsString('vendor/laravel', $output);
        $this->assertStringNotContainsString('SQLSTATE', $output);
    }

    public function test_a_passing_check_is_not_reported_as_skipped(): void
    {
        // A regression guard on the reporting itself: an earlier version inferred
        // "skipped" from a probe returning a string, which every successful check
        // does, so a healthy deployment reported SKIP for its passing checks.
        Artisan::call('govqueue:generate-slots', ['--days' => 3]);
        Artisan::call('govqueue:smoke-test');

        $output = Artisan::output();

        $this->assertStringContainsString('PASS  Database is reachable', $output);
        $this->assertStringContainsString('PASS  Schema is migrated', $output);
        $this->assertStringNotContainsString('SKIP', $output);
    }

    public function test_skip_booking_omits_the_booking_path(): void
    {
        Artisan::call('govqueue:generate-slots', ['--days' => 3]);

        $exit = Artisan::call('govqueue:smoke-test', ['--skip-booking' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SKIP  Booking path round-trips', Artisan::output());
    }

    public function test_a_stale_realtime_announcer_warns_without_failing(): void
    {
        // The office can still book and serve with a frozen lobby display, so this
        // must not stop the office opening - but it has to be said, because
        // otherwise a deploy looks healthy while clients cannot see their number.
        Artisan::call('govqueue:generate-slots', ['--days' => 3]);

        Cache::put(
            'govqueue:broadcast-breaker:'.config('broadcasting.default'),
            ['failed_at' => now()->toIso8601String()],
            60
        );

        $exit = Artisan::call('govqueue:smoke-test');
        $output = Artisan::output();

        $this->assertSame(0, $exit, 'A stale display failed the whole smoke test.');
        $this->assertStringContainsString('cooling down', $output);
        $this->assertStringContainsString('verify-realtime', $output);
    }

    public function test_it_never_broadcasts_a_phantom_ticket(): void
    {
        Artisan::call('govqueue:generate-slots', ['--days' => 3]);

        Event::fake();

        Artisan::call('govqueue:smoke-test');

        // A ticket that was rolled away must not appear on the real lobby display
        // or on a client's private channel, even for as long as it takes the socket
        // to deliver it.
        Event::assertNotDispatched(AppointmentStatusChanged::class);
        Event::assertNotDispatched(QueueUpdated::class);
    }
}
