<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\QueueStatusHistory;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closing the day's tickets.
 *
 * Reported as an appointment from 12 October still reading "Checked in" and
 * "You are next" on the 19th. The state machine was not at fault - the ticket
 * really was still CheckedIn - because nothing anywhere ever closed it.
 *
 * These tests cover the command and the client-facing list it feeds, because
 * the second is where the symptom was actually seen: an open ticket is mostly
 * harmless until it is listed to a client as still upcoming.
 */
class CloseStaleAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    private User $client;

    private int $slotCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
        $this->client = User::factory()->client()->create();
    }

    private function slot(array $overrides = []): TimeSlot
    {
        $this->slotCounter++;

        $date = $overrides['slot_date'] ?? now()->subDays(3)->toDateString();
        $start = now()->subDays(3)->setTime(9, $this->slotCounter * 10);

        return TimeSlot::create(array_merge([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $date,
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 4,
            'booked_count' => 1,
            'is_open' => true,
        ], $overrides));
    }

    private function appointment(array $attributes = [], ?TimeSlot $slot = null): Appointment
    {
        $slot ??= $this->slot();

        return Appointment::factory()->create(array_merge([
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'scheduled_at' => $slot->start_at,
            'queue_date' => $slot->slot_date,
            'status' => 'confirmed',
        ], $attributes));
    }

    /** --------------------------------------------------------------- */
    public function test_a_ticket_whose_slot_ended_is_closed_as_a_no_show(): void
    {
        $appointment = $this->appointment(['status' => 'checked_in', 'checked_in_at' => now()->subDays(3)]);

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'no_show',
        ]);
    }

    public function test_it_releases_the_slot_place_the_ticket_was_holding(): void
    {
        $slot = $this->slot();
        $appointment = $this->appointment(['status' => 'checked_in', 'checked_in_at' => now()->subDays(3)], $slot);

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        // Otherwise the day's capacity stays consumed by a ticket nobody can be
        // served, and the figures do not add up.
        $this->assertSame(0, $slot->fresh()->booked_count);
    }

    public function test_a_ticket_whose_slot_has_not_ended_is_left_alone(): void
    {
        $future = now()->addHour()->setTime(14, 0);
        $slot = $this->slot([
            'slot_date' => now()->toDateString(),
            'start_at' => $future->toDateTimeString(),
            'end_at' => $future->copy()->addMinutes(30)->toDateTimeString(),
        ]);

        $appointment = $this->appointment([
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'scheduled_at' => $future->toDateTimeString(),
            'queue_date' => now()->toDateString(),
        ], $slot);

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'checked_in',
        ]);
    }

    public function test_tickets_that_are_already_terminal_are_not_touched(): void
    {
        foreach (['completed', 'cancelled', 'no_show'] as $terminal) {
            $appointment = $this->appointment(['status' => $terminal]);

            $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

            $this->assertDatabaseHas('appointments', [
                'id' => $appointment->id,
                'status' => $terminal,
            ]);
        }
    }

    public function test_a_walk_in_that_was_never_resolved_is_closed(): void
    {
        // A walk-in holds the slot it was issued against - appointments.
        // time_slot_id is NOT NULL - so it goes stale on the same clock as a
        // booking. An earlier version of this command special-cased a null
        // slot; that branch was unreachable, and a walk-in is the case that
        // matters most here, so it is pinned explicitly.
        $appointment = Appointment::factory()->create([
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $this->slot()->id,
            'scheduled_at' => now()->subDays(2),
            'status' => 'checked_in',
            'checked_in_at' => now()->subDays(2),
            'source' => 'walk_in',
            'queue_number' => 'W-001',
        ]);

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'no_show',
        ]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $appointment = $this->appointment(['status' => 'checked_in', 'checked_in_at' => now()->subDays(3)]);

        $this->artisan('govqueue:close-stale-appointments --dry-run')->assertSuccessful();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'checked_in',
        ]);
    }

    public function test_running_it_twice_is_harmless(): void
    {
        $this->appointment(['status' => 'checked_in', 'checked_in_at' => now()->subDays(3)]);

        // Scheduled hourly. A repeated run must not double-release a slot or
        // rewrite a status history that is already correct.
        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();
        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        $this->assertSame(
            1,
            QueueStatusHistory::where('note', 'like', '%Closed automatically%')->count(),
        );
    }

    /* --------------------------------------------------------------- */
    /* What the client sees */
    /* --------------------------------------------------------------- */

    /**
     * The "Upcoming & active" section of /my-appointments.
     *
     * Split out rather than asserting on a phrase, because "You are next" was
     * the wording in the reported screenshot and the current page says nothing
     * of the sort. Asserting on section membership survives the copy changing,
     * which is what we care about: which list a ticket appears in.
     */
    private function upcomingSection(string $html): string
    {
        [$upcoming] = array_pad(explode('History', $html), 2, '');

        return $upcoming;
    }

    public function test_a_stale_ticket_is_not_listed_as_upcoming_after_it_is_closed(): void
    {
        $appointment = $this->appointment(['status' => 'checked_in', 'checked_in_at' => now()->subDays(3)]);

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        $html = $this->actingAs($this->client)->get('/my-appointments')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            $appointment->reference_number,
            $this->upcomingSection($html),
            'A ticket from three days ago is still in the upcoming list.',
        );

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'no_show']);
    }

    public function test_an_unresolved_past_ticket_is_hidden_from_upcoming_even_before_close_out_runs(): void
    {
        // The symptom persisted for seven days because the list filtered on
        // status alone. Closing the ticket fixes the record; filtering by date
        // stops the same lie being told while the ticket is still open.
        $appointment = $this->appointment(['status' => 'checked_in', 'checked_in_at' => now()->subDays(3)]);

        $html = $this->actingAs($this->client)->get('/my-appointments')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            $appointment->reference_number,
            $this->upcomingSection($html),
        );

        // Still visible to its owner - the filter is about the list it sits in,
        // not about hiding the record from the person it belongs to.
        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertOk();
    }

    public function test_a_todays_ticket_is_still_listed_as_upcoming(): void
    {
        $start = now()->setTime(14, 0);
        $slot = $this->slot([
            'slot_date' => now()->toDateString(),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
        ]);

        $appointment = $this->appointment([
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'scheduled_at' => $start->toDateTimeString(),
            'queue_date' => now()->toDateString(),
        ], $slot);

        $html = $this->actingAs($this->client)->get('/my-appointments')->assertOk()->getContent();

        $this->assertStringContainsString(
            $appointment->reference_number,
            $this->upcomingSection($html),
            'A ticket for today must not be filtered out of the upcoming list.',
        );
    }
}
