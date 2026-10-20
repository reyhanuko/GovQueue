<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\NoShowStrike;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The no-show policy (decision D6).
 *
 * A resident who does not turn up, does not cancel, and is closed out by the
 * hourly command has recorded an unexcused no-show. Three of those pause their
 * new bookings for a fortnight.
 *
 * Two things make this a policy rather than a punishment, and both are tested
 * here: a client who *did* come to the office and was never called has been let
 * down by the queue and counts for nothing, and the block ends - a resident
 * who serves their fortnight starts again rather than being locked out for
 * good.
 */
class NoShowBlockingTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Service $service;

    private ServiceWindow $window;

    private int $slotCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->client()->create();
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
    }

    protected function tearDown(): void
    {
        // The block expiry is tested by moving the clock, and a pinned clock
        // that outlives the test would decide the behaviour of every test
        // that ran after it.
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A slot that ended days ago, so the close-out picks it up.
     */
    private function pastSlot(): TimeSlot
    {
        $this->slotCounter++;

        $start = now()->subDays(3)->setTime(9, $this->slotCounter * 10);

        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 1,
            'is_open' => true,
        ]);
    }

    /**
     * A slot in the future, so the client has something to book.
     */
    private function bookableSlot(): TimeSlot
    {
        $this->slotCounter++;

        $start = now()->addDay()->setTime(9, $this->slotCounter * 10);

        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    /**
     * A ticket that was never resolved, so the close-out will close it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function missedAppointment(array $attributes = []): Appointment
    {
        $slot = $this->pastSlot();

        return Appointment::factory()->create(array_merge([
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'scheduled_at' => $slot->start_at,
            'queue_date' => $slot->slot_date,
            'status' => AppointmentStatus::Confirmed->value,
        ], $attributes));
    }

    /* ------------------------------------------------------------------ */
    /* Counting */
    /* ------------------------------------------------------------------ */

    public function test_a_client_who_never_came_is_counted(): void
    {
        $this->missedAppointment();

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        $this->assertSame(1, NoShowStrike::where('user_id', $this->client->id)->count());
    }

    public function test_a_client_who_checked_in_is_not_counted(): void
    {
        /*
         * They came to the office. The office's own call is what lost them, so
         * their no-show is excused and counts for nothing against them.
         */
        $this->missedAppointment([
            'status' => AppointmentStatus::CheckedIn->value,
            'checked_in_at' => now()->subDays(3),
        ]);

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        $this->assertSame(0, NoShowStrike::where('user_id', $this->client->id)->count());
    }

    public function test_the_strike_names_the_appointment_it_came_from(): void
    {
        $appointment = $this->missedAppointment();

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        $this->assertDatabaseHas('no_show_strikes', [
            'user_id' => $this->client->id,
            'appointment_id' => $appointment->id,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Blocking */
    /* ------------------------------------------------------------------ */

    public function test_three_unexcused_no_shows_block_new_bookings_for_a_fortnight(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->missedAppointment();

            $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();
        }

        $this->assertSame(3, NoShowStrike::where('user_id', $this->client->id)->count());

        $client = $this->client->fresh();

        $this->assertTrue($client->isBookingBlocked());
        $this->assertEqualsWithDelta(
            now()->addDays(14)->getTimestamp(),
            $client->booking_blocked_until->getTimestamp(),
            5,
            'The block does not run for the fourteen days the policy says.',
        );
    }

    public function test_two_no_shows_are_not_enough(): void
    {
        foreach (range(1, 2) as $ignored) {
            $this->missedAppointment();

            $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();
        }

        $this->assertFalse($this->client->fresh()->isBookingBlocked());
    }

    public function test_a_blocked_client_cannot_book(): void
    {
        $this->blockTheClient();

        $slot = $this->bookableSlot();

        $before = Appointment::where('user_id', $this->client->id)->count();

        $this->actingAs($this->client)
            ->post(route('client.booking.store', $this->service), ['time_slot_id' => $slot->id])
            ->assertRedirect();

        $this->assertSame(
            $before,
            Appointment::where('user_id', $this->client->id)->count(),
            'A blocked client was given a booking.',
        );
    }

    public function test_a_blocked_client_is_told_why_on_the_booking_screen(): void
    {
        $this->blockTheClient();

        // A slot in the future, so the page renders the form rather than the
        // "nothing available" branch - the button is part of that form.
        $this->bookableSlot();

        $this->actingAs($this->client)
            ->get(route('client.booking.create', $this->service))
            ->assertOk()
            ->assertSee('You cannot make a new booking yet')
            ->assertSee('Bookings paused');
    }

    public function test_a_blocked_client_is_told_on_their_appointments_page(): void
    {
        $this->blockTheClient();

        $this->actingAs($this->client)
            ->get(route('client.appointments.index'))
            ->assertOk()
            ->assertSee('You cannot make a new booking yet');
    }

    public function test_the_block_ends(): void
    {
        $this->blockTheClient();

        $this->assertTrue($this->client->fresh()->isBookingBlocked());

        Carbon::setTestNow(now()->addDays(15));

        $this->assertFalse(
            $this->client->fresh()->isBookingBlocked(),
            'The block did not end after its fourteen days.',
        );

        $slot = $this->bookableSlot();

        $before = Appointment::where('user_id', $this->client->id)->count();

        $this->actingAs($this->client)
            ->post(route('client.booking.store', $this->service), ['time_slot_id' => $slot->id]);

        $this->assertSame(
            $before + 1,
            Appointment::where('user_id', $this->client->id)->count(),
            'The client could not book once the block had ended.',
        );
    }

    public function test_the_count_starts_again_after_a_block(): void
    {
        $this->blockTheClient();

        Carbon::setTestNow(now()->addDays(15));

        // One more absence after the block has been served.
        $this->missedAppointment();

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        $this->assertFalse(
            $this->client->fresh()->isBookingBlocked(),
            'The three absences that produced the first block were counted again.',
        );
    }

    public function test_a_blocked_client_can_still_be_served_as_a_walk_in(): void
    {
        /*
         * The block is on booking, not on being served. A resident who turns
         * up at the counter is not turned away - which is what makes this a
         * block rather than a ban.
         */
        $this->blockTheClient();

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => now()->toDateString(),
            'start_time' => now()->format('H:i:s'),
            'end_time' => now()->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => now()->toDateTimeString(),
            'end_at' => now()->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        $appointment = app(BookingService::class)->createWalkIn([
            'name' => 'Blocked Resident',
            'phone' => '09171234567',
            'service_id' => $this->service->id,
        ], null);

        $this->assertSame($slot->id, $appointment->time_slot_id);
    }

    /* ------------------------------------------------------------------ */
    /* Harness */
    /* ------------------------------------------------------------------ */

    /**
     * Give the client three unexcused no-shows.
     */
    private function blockTheClient(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->missedAppointment();

            $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();
        }

        $this->client->refresh();
    }
}
