<?php

namespace Tests\Feature;

use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use App\Services\StaffingCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A window with nobody assigned to it must not take bookings (decision D2).
 *
 * Deleting the only staff member of a window used to leave that window active
 * and bookable. A client booked a slot, travelled to the office, and stood at a
 * counter with no clerk. The failure is discovered by the person who made the
 * effort to come in, which is the worst place for it to be found.
 *
 * Unbookable rather than warned about, because the alternative is not a slower
 * queue - it is a queue that cannot be served at all. The admin screens warn
 * separately, so the condition is visible where it can be fixed before a client
 * reaches it.
 */
class UnstaffedWindowTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // unstaffed(): decision D2 makes this unbookable, so the base fixture for
        // a window is staffed. These tests are about the unstaffed case, so they
        // opt out of the default.
        $this->window = ServiceWindow::factory()->unstaffed()->create([
            'code' => 'WINDOW_2',
            'name' => 'Window 2',
        ]);

        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'code' => 'REG',
            'name' => 'Business Registration',
            'duration_minutes' => 20,
        ]);
    }

    private function slotAt(int $daysAhead = 1): TimeSlot
    {
        $start = now()->addDays($daysAhead)->setTime(9, 0);

        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addHours(4)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addHours(4)->toDateTimeString(),
            'capacity' => 3,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    private function staffTheWindow(): User
    {
        return User::factory()->staff()->create([
            'service_window_id' => $this->window->id,
            'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The rule */
    /* ------------------------------------------------------------------ */

    public function test_a_window_with_no_staff_refuses_a_booking(): void
    {
        $slot = $this->slotAt();
        $client = User::factory()->client()->create();

        $this->expectException(SlotUnavailableException::class);

        app(BookingService::class)->book($client, $this->service, $slot->id);
    }

    /**
     * The message has to name the window and say what to do.
     *
     * "No slots available" would send the client to a different window, which is
     * worse than saying plainly that this one is unstaffed.
     */
    public function test_the_refusal_names_the_window_and_offers_a_way_on(): void
    {
        $slot = $this->slotAt();
        $client = User::factory()->client()->create();

        try {
            app(BookingService::class)->book($client, $this->service, $slot->id);
            $this->fail('An unstaffed window accepted a booking.');
        } catch (SlotUnavailableException $e) {
            $this->assertStringContainsString('Window 2', $e->getMessage());
            $this->assertStringContainsString('no active staff', strtolower($e->getMessage()));
            $this->assertStringContainsString('another window', strtolower($e->getMessage()));
        }
    }

    public function test_no_appointment_is_written_when_refused(): void
    {
        $slot = $this->slotAt();
        $client = User::factory()->client()->create();

        try {
            app(BookingService::class)->book($client, $this->service, $slot->id);
        } catch (\Throwable) {
            // Expected.
        }

        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, $slot->fresh()->booked_count, 'The place was consumed by a refused booking.');
    }

    /* ------------------------------------------------------------------ */
    /* The states that count as staffed */
    /* ------------------------------------------------------------------ */

    public function test_a_staffed_window_still_accepts_bookings(): void
    {
        $this->staffTheWindow();
        $slot = $this->slotAt();

        $appointment = app(BookingService::class)->book(
            User::factory()->client()->create(),
            $this->service,
            $slot->id,
        );

        $this->assertSame($slot->id, $appointment->time_slot_id);
    }

    /**
     * An administrator at the counter is serving it.
     *
     * An administrator who is not doing paperwork is the person a walk-in client
     * finds at the window, so counting only 'staff' would report a window as
     * unstaffed while somebody is standing in it.
     */
    public function test_an_assigned_administrator_counts_as_staff(): void
    {
        User::factory()->admin()->create([
            'service_window_id' => $this->window->id,
            'is_active' => true,
        ]);

        $slot = $this->slotAt();

        $appointment = app(BookingService::class)->book(
            User::factory()->client()->create(),
            $this->service,
            $slot->id,
        );

        $this->assertSame($slot->id, $appointment->time_slot_id);
    }

    /**
     * Someone assigned to another window is not staffing this one.
     *
     * The obvious false negative: counting users globally would let Window 1's
     * staffing paper over Window 2 being empty, which is the exact case the
     * decision is about.
     */
    public function test_staff_assigned_elsewhere_do_not_count(): void
    {
        $other = ServiceWindow::factory()->create(['code' => 'WINDOW_1', 'name' => 'Window 1']);

        User::factory()->staff()->create([
            'service_window_id' => $other->id,
            'is_active' => true,
        ]);

        $slot = $this->slotAt();

        $this->expectException(SlotUnavailableException::class);

        app(BookingService::class)->book(User::factory()->client()->create(), $this->service, $slot->id);
    }

    /** Deactivated staff are not at the counter. */
    public function test_deactivated_staff_do_not_count(): void
    {
        User::factory()->staff()->create([
            'service_window_id' => $this->window->id,
            'is_active' => false,
        ]);

        $slot = $this->slotAt();

        $this->expectException(SlotUnavailableException::class);

        app(BookingService::class)->book(User::factory()->client()->create(), $this->service, $slot->id);
    }

    /** A client who happens to be assigned to the window is not staffing it. */
    public function test_a_client_cannot_staff_a_window(): void
    {
        User::factory()->client()->create([
            'service_window_id' => $this->window->id,
            'is_active' => true,
        ]);

        $slot = $this->slotAt();

        $this->expectException(SlotUnavailableException::class);

        app(BookingService::class)->book(User::factory()->client()->create(), $this->service, $slot->id);
    }

    /* ------------------------------------------------------------------ */
    /* The reported symptom: deleting the last staff member */
    /* ------------------------------------------------------------------ */

    /**
     * The original symptom, not a hypothetical.
     *
     * Deleting the only staff member of Window 2 (Jomar) left Window 2 active
     * and bookable with zero active staff. This follows that exact path.
     */
    public function test_deleting_the_only_staff_member_closes_the_window_to_bookings(): void
    {
        $staff = $this->staffTheWindow();
        $slot = $this->slotAt();

        // Sanity: staffed, so it books.
        app(BookingService::class)->book(User::factory()->client()->create(), $this->service, $slot->id);

        $staff->delete();

        $this->expectException(SlotUnavailableException::class);

        app(BookingService::class)->book(User::factory()->client()->create(), $this->service, $slot->id);
    }

    /* ------------------------------------------------------------------ */
    /* Rescheduling is the same rule */
    /* ------------------------------------------------------------------ */

    public function test_moving_to_an_unstaffed_window_is_refused(): void
    {
        $this->staffTheWindow();

        $client = User::factory()->client()->create();
        $mine = $this->slotAt(1);

        $appointment = app(BookingService::class)->book($client, $this->service, $mine->id);

        // Now the staffing goes away.
        User::where('service_window_id', $this->window->id)->delete();

        $other = $this->slotAt(2);

        $this->expectException(SlotUnavailableException::class);

        app(BookingService::class)->reschedule($appointment, $other->id);
    }

    /* ------------------------------------------------------------------ */
    /* The admin warning (the "plus" in "unbookable plus admin warning") */
    /* ------------------------------------------------------------------ */

    public function test_the_capacity_service_reports_zero_for_an_unstaffed_window(): void
    {
        $this->assertSame(0, app(StaffingCapacityService::class)->staffCount($this->window));
    }

    public function test_the_admin_warning_says_there_is_nobody(): void
    {
        $warning = app(StaffingCapacityService::class)->warning(
            $this->window,
            240,
            20,
            3,
            $this->service->name,
        );

        $this->assertNotNull($warning, 'An unstaffed window produced no warning.');
        $this->assertStringContainsString('no active staff', strtolower($warning));
    }

    public function test_a_staffed_window_produces_no_such_warning(): void
    {
        $this->staffTheWindow();

        // One clerk, 240 minutes, 20-minute service = 12 places servable, so a
        // capacity of 3 is comfortably fine.
        $warning = app(StaffingCapacityService::class)->warning(
            $this->window,
            240,
            20,
            3,
            $this->service->name,
        );

        $this->assertNull($warning);
    }
}
