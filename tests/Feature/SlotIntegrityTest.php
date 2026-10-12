<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\ScheduleAdminService;
use App\Services\SlotGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two rules that silently undid each other, and one that silently undid work.
 *
 * The nightly `govqueue:generate-slots --days=45` run rewrites every existing
 * slot's capacity from its template. It has always done that, and until this
 * round nothing recorded that an administrator had decided otherwise - so an
 * override survived exactly until 01:30, when it was silently reset. The
 * administrator had been told "Capacity updated" and the office opened to find
 * the number gone.
 *
 * The second pair is the reverse: a walk-in never holds a place, so it must
 * never give one back. Both are invisible from the screen that made the change.
 */
class SlotIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    private SlotTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create([
            'code' => 'INTEGRITY_1',
            'name' => 'Integrity Window',
        ]);

        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'code' => 'INTEGRITY-SVC',
            'name' => 'Integrity Service',
        ]);

        // Monday, 08:00-12:00 in 30-minute slots of 4 places.
        $this->template = SlotTemplate::create([
            'service_id' => $this->service->id,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'slot_minutes' => 30,
            'capacity' => 4,
            'effective_from' => today()->subWeek()->toDateString(),
            'is_active' => true,
        ]);
    }

    /** The next Monday, so the template above definitely applies. */
    private function nextMonday(): string
    {
        return today()->copy()->next('Monday')->toDateString();
    }

    /**
     * @return array{created:int, skipped:int, preserved:int}
     */
    private function generate(): array
    {
        return app(SlotGeneratorService::class)->generate($this->nextMonday(), $this->nextMonday());
    }

    /* ------------------------------------------------------------------ */
    /* Administrator capacity overrides survive the nightly run */
    /* ------------------------------------------------------------------ */

    public function test_an_administrator_capacity_override_survives_the_nightly_generator(): void
    {
        $this->generate();

        $slot = TimeSlot::where('service_id', $this->service->id)->firstOrFail();

        $this->assertSame(4, $slot->capacity, 'The template capacity should have been generated.');

        app(ScheduleAdminService::class)->updateSlot($slot, 9, (bool) $slot->is_open);

        $this->assertSame(9, $slot->fresh()->capacity);

        // This is the whole test. The scheduled job runs this every night at
        // 01:30 with a 45-day horizon, so "the change is gone tomorrow" was the
        // actual behaviour.
        $this->generate();

        $this->assertSame(
            9,
            $slot->fresh()->capacity,
            'The nightly generator reverted an administrator\'s capacity override.'
        );
    }

    public function test_an_untouched_slot_still_follows_its_template(): void
    {
        // The other half of the contract: marking an override must not freeze
        // every slot against its template, or a template edit would never again
        // reach slots that have not been individually touched.
        $this->generate();

        $slot = TimeSlot::where('service_id', $this->service->id)->firstOrFail();

        $this->template->update(['capacity' => 7]);
        $this->template->save();

        $this->generate();

        $this->assertSame(
            7,
            $slot->fresh()->capacity,
            'A slot nobody had touched should have taken the new template capacity.'
        );
    }

    public function test_closing_a_slot_does_not_freeze_its_capacity(): void
    {
        // Only a *capacity* decision is an override. Toggling a slot open and
        // closed is routine, and marking those slots as hand-set would silently
        // detach the whole day from its template the first time anybody closed
        // anything.
        $this->generate();

        $slot = TimeSlot::where('service_id', $this->service->id)->firstOrFail();

        app(ScheduleAdminService::class)->updateSlot($slot, (int) $slot->capacity, false);

        $this->assertFalse(
            (bool) $slot->fresh()->capacity_is_overridden,
            'Closing a slot marked its capacity as hand-set.'
        );

        $this->template->update(['capacity' => 6]);
        $this->template->save();

        $this->generate();

        $this->assertSame(6, $slot->fresh()->capacity);
    }

    public function test_the_generator_reports_how_many_overrides_it_preserved(): void
    {
        // Reported so a scheduled run leaves a trace. The count going unexpectedly
        // high is the signal that a lot of slots stopped following their template.
        $this->generate();

        $slot = TimeSlot::where('service_id', $this->service->id)->firstOrFail();

        app(ScheduleAdminService::class)->updateSlot($slot, 9, (bool) $slot->is_open);

        $result = $this->generate();

        $this->assertArrayHasKey('preserved', $result);
        $this->assertSame(1, $result['preserved']);
    }

    public function test_an_overridden_slot_is_still_recorded_in_the_audit_trail(): void
    {
        $this->generate();

        $slot = TimeSlot::where('service_id', $this->service->id)->firstOrFail();

        app(ScheduleAdminService::class)->updateSlot($slot, 9, (bool) $slot->is_open);

        $entry = \App\Models\AuditLog::where('action', 'time_slot.updated')
            ->where('subject_id', $slot->id)
            ->firstOrFail();

        // It changes how the generator will treat this slot from now on, and
        // nothing else about the row says so.
        $this->assertTrue((bool) data_get($entry->payload, 'after.capacity_is_overridden'));
    }

    /* ------------------------------------------------------------------ */
    /* Walk-ins never hold, and never release, a place */
    /* ------------------------------------------------------------------ */

    public function test_serving_a_walk_in_does_not_release_a_place_it_never_held(): void
    {
        /*
         * Walk-ins are filed under a real time_slots row because the column is
         * NOT NULL, but they never increment booked_count.
         *
         * releaseSlot() did not check that, so every walk-in that was served or
         * marked a no-show decremented a counter it had never incremented - the
         * place belonged to whoever had actually booked it. A slot with capacity
         * 5 and one online booking drifted toward 0 across a day until the office
         * was advertising places it had already given away.
         */
        $date = today()->toDateString();

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $date,
            'start_time' => '08:00:00',
            'end_time' => '23:59:00',
            'start_at' => $date.' 08:00:00',
            'end_at' => $date.' 23:59:00',
            'capacity' => 5,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        $walkIn = $this->issueWalkIn($slot, 'Rina Santos');

        $this->assertSame(1, $slot->fresh()->booked_count, 'A walk-in should not have changed the count when issued.');

        // callNext() moves the ticket to serving on its own instance; the one
        // held here is still 'checked_in', and completing from that would be a
        // rejected transition rather than the test being about anything.
        $queue = app(\App\Services\QueueService::class);

        $called = $queue->callNext($this->window, null, $date);

        $queue->complete($called, $this->staff());

        $this->assertSame(
            1,
            $slot->fresh()->booked_count,
            'Serving a walk-in released a place that belonged to an online booking.'
        );
    }

    public function test_cancelling_a_walk_in_does_not_release_a_place(): void
    {
        $date = today()->toDateString();

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $date,
            'start_time' => '08:00:00',
            'end_time' => '23:59:00',
            'start_at' => $date.' 08:00:00',
            'end_at' => $date.' 23:59:00',
            'capacity' => 5,
            'booked_count' => 2,
            'is_open' => true,
        ]);

        $walkIn = $this->issueWalkIn($slot, 'Nina Dizon');

        app(\App\Services\BookingService::class)->cancel($walkIn, 'Changed their mind');

        $this->assertSame(
            2,
            $slot->fresh()->booked_count,
            'Cancelling a walk-in released a place that belonged to an online booking.'
        );
    }

    public function test_serving_a_real_booking_still_releases_its_place(): void
    {
        // The counterpart. The fix must not have become "capacity never decreases".
        $date = today()->toDateString();

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $date,
            'start_time' => '08:00:00',
            'end_time' => '23:59:00',
            'start_at' => $date.' 08:00:00',
            'end_at' => $date.' 23:59:00',
            'capacity' => 5,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        $booking = Appointment::create([
            'reference_number' => 'GQ-REAL-1',
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->window->id,
            'status' => 'checked_in',
            'source' => 'online',
            'queue_number' => 'A-001',
            'queue_date' => $date,
            'checked_in_at' => now(),
        ]);

        app(\App\Services\QueueService::class)->callNext($this->window, null, $date);

        app(\App\Services\QueueService::class)
            ->complete($booking->fresh(), $this->staff());

        $this->assertSame(
            0,
            $slot->fresh()->booked_count,
            'A completed online booking should have given its place back.'
        );
    }

    private function staff(): User
    {
        return User::factory()->staff()->create(['service_window_id' => $this->window->id]);
    }

    private function issueWalkIn(TimeSlot $slot, string $name): Appointment
    {
        return app(\App\Services\BookingService::class)->createWalkIn(
            [
                'name' => $name,
                'phone' => '0917'.random_int(1000000, 9999999),
                'service_id' => $this->service->id,
            ],
            null,
            true,
        );
    }
}
