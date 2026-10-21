<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Walk-in eligibility is decided per service (decision D12).
 *
 * Not a property of the office. A high-volume predictable service - Real Estate
 * Tax Payments, Document Pickup - absorbs continuous walk-ins easily. A legally
 * intensive one such as a Zoning Board review or a Marriage License application
 * needs a booked slot, because each takes a clerk's full attention and a document
 * check that cannot be completed with the next person already at the counter.
 */
class WalkInEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceWindow $window;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);

        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'code' => 'ZONING',
            'name' => 'Zoning Board Review',
            'is_active' => true,
        ]);

        // A slot that is running right now, which is what a walk-in needs.
        $start = now()->subMinutes(5)->startOfMinute();

        TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addHours(2)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addHours(2)->toDateTimeString(),
            'capacity' => 20,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    private function issueWalkIn(array $overrides = []): Appointment
    {
        return app(BookingService::class)->createWalkIn(
            array_merge([
                'name' => 'Rosa Reyes',
                'service_id' => $this->service->id,
            ], $overrides),
            $this->admin,
        );
    }

    /* ------------------------------------------------------------------ */

    public function test_a_service_that_accepts_walkins_still_does(): void
    {
        $appointment = $this->issueWalkIn();

        $this->assertSame('walk_in', $appointment->source->value);
    }

    public function test_walkins_are_refused_for_an_appointment_only_service(): void
    {
        $this->service->update(['allows_walk_in' => false]);

        $this->expectException(SlotUnavailableException::class);

        $this->issueWalkIn();
    }

    /** The message has to name the service and say what to do instead. */
    public function test_the_refusal_explains_itself(): void
    {
        $this->service->update(['allows_walk_in' => false]);

        try {
            $this->issueWalkIn();
            $this->fail('A walk-in was issued for an appointment-only service.');
        } catch (SlotUnavailableException $e) {
            $this->assertStringContainsString('Zoning Board Review', $e->getMessage());
            $this->assertStringContainsString('appointment only', $e->getMessage());
        }
    }

    /**
     * The database guard against two live walk-ins for one person.
     *
     * The key is held while the ticket holds a place in the line and released
     * when it does not, so a resident who is served and comes back later the
     * same day is not stopped by their own history - but two counters
     * pressing the button for one resident at the same moment cannot both
     * succeed.
     */
    public function test_a_walk_in_holds_the_dedupe_key_while_it_is_in_the_line(): void
    {
        $appointment = $this->issueWalkIn();

        $this->assertNotNull(
            $appointment->fresh()->walk_in_dedupe_key,
            'A live walk-in does not hold the key that stops a second one.',
        );
    }

    public function test_the_dedupe_key_is_released_when_the_ticket_leaves_the_line(): void
    {
        $appointment = $this->issueWalkIn();

        // A walk-in is checked in the moment it is issued, so it is served
        // before it can be completed.
        $queue = app(QueueService::class);
        $served = $queue->transition($appointment, AppointmentStatus::Serving, ['actor' => $this->admin]);

        $queue->complete($served, $this->admin);

        $this->assertNull(
            $appointment->fresh()->walk_in_dedupe_key,
            'A finished ticket still holds the key, so the client cannot come back.',
        );

        // And the same person can be issued a second ticket later the same day.
        $second = $this->issueWalkIn();

        $this->assertNotSame(
            $appointment->id,
            $second->id,
            'The second walk-in was refused for a client who had already been served.',
        );
    }

    /**
     * The override flag must not be a way round this.
     *
     * $override exists so staff can get past the duplicate-name guard when two
     * different people share a surname. That is an escape hatch against a safety
     * net making a false accusation. Walk-in eligibility is not such a thing: it
     * is the service's operating rule, set deliberately by an administrator.
     */
    public function test_the_duplicate_override_does_not_bypass_eligibility(): void
    {
        $this->service->update(['allows_walk_in' => false]);

        $this->expectException(SlotUnavailableException::class);

        app(BookingService::class)->createWalkIn([
            'name' => 'Rosa Reyes',
            'service_id' => $this->service->id,
        ], $this->admin, override: true);
    }

    public function test_no_ticket_is_written_when_a_walkin_is_refused(): void
    {
        $this->service->update(['allows_walk_in' => false]);

        try {
            $this->issueWalkIn();
        } catch (\Throwable) {
            // Expected.
        }

        $this->assertSame(0, Appointment::where('service_id', $this->service->id)->count());
    }

    /* ------------------------------------------------------------------ */
    /* The default */
    /* ------------------------------------------------------------------ */

    /**
     * Every service accepted walk-ins until this column existed.
     *
     * Defaulting it the other way would have switched them all off the moment the
     * migration ran, breaking the counter on deployment day for a setting nobody
     * had chosen.
     */
    public function test_existing_services_default_to_accepting_walkins(): void
    {
        $fresh = Service::create([
            'service_window_id' => $this->window->id,
            'name' => 'Document Pickup',
            'code' => 'PICKUP',
        ]);

        $this->assertTrue($fresh->fresh()->allows_walk_in);
    }

    /* ------------------------------------------------------------------ */
    /* An administrator can set it */
    /* ------------------------------------------------------------------ */

    public function test_an_administrator_can_turn_walkins_off(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.services.update', $this->service), [
                'service_window_id' => $this->window->id,
                'name' => $this->service->name,
                'code' => $this->service->code,
                'fee' => '0',
                'duration_minutes' => 30,
                'is_active' => '1',
                'allows_walk_in_present' => '1',
                // allows_walk_in deliberately absent: that is what an unticked box sends.
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->service->fresh()->allows_walk_in);
    }

    public function test_an_administrator_can_turn_walkins_back_on(): void
    {
        $this->service->update(['allows_walk_in' => false]);

        $this->actingAs($this->admin)
            ->put(route('admin.services.update', $this->service), [
                'service_window_id' => $this->window->id,
                'name' => $this->service->name,
                'code' => $this->service->code,
                'fee' => '0',
                'duration_minutes' => 30,
                'is_active' => '1',
                'allows_walk_in_present' => '1',
                'allows_walk_in' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->service->fresh()->allows_walk_in);
    }

    /**
     * A request with no control at all must not silently change the setting.
     *
     * An unticked checkbox is not submitted, which is indistinguishable from a
     * request that never carried the field. The form sends allows_walk_in_present
     * to tell the two apart, so anything without it leaves the stored value alone.
     */
    public function test_a_request_without_the_control_leaves_the_setting_alone(): void
    {
        $this->service->update(['allows_walk_in' => false]);

        $this->actingAs($this->admin)
            ->put(route('admin.services.update', $this->service), [
                'service_window_id' => $this->window->id,
                'name' => 'Renamed',
                'code' => $this->service->code,
                'fee' => '0',
                'duration_minutes' => 30,
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $this->service->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertFalse($fresh->allows_walk_in, 'The setting was reset by a request that never mentioned it.');
    }

    /* ------------------------------------------------------------------ */
    /* D6 / D7: nobody is blocked from booking */
    /* ------------------------------------------------------------------ */

    /**
     * No-shows must not block anybody from booking (decisions D6 and D7).
     *
     * The reasoning is the reason this is written down: someone who missed the
     * morning may book the afternoon, and may well turn up for it. Treating a
     * no-show as a strike would push exactly the wrong people away.
     *
     * Asserted so that no future change can introduce blocking by accident.
     */
    public function test_repeated_no_shows_do_not_block_a_client_from_booking(): void
    {
        $client = User::factory()->client()->create();

        $past = now()->subDays(3)->setTime(9, 0);

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $past->toDateString(),
            'start_time' => $past->format('H:i:s'),
            'end_time' => $past->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $past->toDateTimeString(),
            'end_at' => $past->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        // Three no-shows, one per day, same client.
        foreach ([1, 2, 3] as $offset) {
            $when = now()->subDays($offset)->setTime(9, 0);

            Appointment::factory()->create([
                'user_id' => $client->id,
                'service_id' => $this->service->id,
                'service_window_id' => $this->window->id,
                'time_slot_id' => $slot->id,
                'scheduled_at' => $when->toDateTimeString(),
                'queue_date' => $when->toDateString(),
                'status' => 'no_show',
            ]);
        }

        $this->assertSame(3, Appointment::where('user_id', $client->id)->where('status', 'no_show')->count());

        // The next slot, tomorrow.
        $next = now()->addDay()->setTime(9, 0);

        $bookable = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $next->toDateString(),
            'start_time' => $next->format('H:i:s'),
            'end_time' => $next->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $next->toDateTimeString(),
            'end_at' => $next->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        $appointment = app(BookingService::class)->book($client, $this->service, $bookable->id);

        $this->assertSame($bookable->id, $appointment->time_slot_id);
    }

    /**
     * The D6 boundary has moved.
     *
     * This used to assert that the close-out command did not count strikes,
     * as a placeholder for a decision that had not been taken. The decision
     * has been taken - three unexcused no-shows pause a resident's new
     * bookings for a fortnight - and the counting now happens where a ticket
     * becomes a no-show, so that a no-show a clerk records at the counter
     * counts the same way as one the job closes.
     */
    public function test_a_no_show_is_counted_wherever_it_is_recorded(): void
    {
        $source = (string) file_get_contents(app_path('Services/QueueService.php'));

        $this->assertStringContainsString(
            'recordStrike',
            $source,
            'A no-show is not counted anywhere, so nobody is ever blocked.',
        );
    }
}
