<?php

namespace Tests\Feature;

use App\Exceptions\CheckInWindowException;
use App\Livewire\Staff\QueueConsole;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The check-in window (decision D7).
 *
 * A client may join the line from half an hour before their slot starts until
 * a quarter of an hour after it ends. Earlier than that they have not arrived
 * for *this* slot; later than that the counter has moved on, and a ticket
 * checked in then is a person standing in a queue for a slot that has already
 * been served.
 *
 * The clock is pinned for every test here. A window that depends on the time
 * of day is a window that can only be tested by being written at the right
 * hour of the day, and the test that says so is the test that quietly stops
 * testing anything.
 */
class CheckInWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Service $service;

    private ServiceWindow $window;

    /** The slot every test here is about: 09:00 to 09:30. */
    private Carbon $slotStart;

    protected function setUp(): void
    {
        parent::setUp();

        // Noon, so no part of the window is near midnight and the arithmetic
        // in the test cannot be confused by a day boundary.
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00'));

        $this->client = User::factory()->client()->create();
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);

        $this->slotStart = Carbon::parse('2026-10-21 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function slot(): TimeSlot
    {
        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $this->slotStart->toDateString(),
            'start_time' => $this->slotStart->format('H:i:s'),
            'end_time' => $this->slotStart->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $this->slotStart->toDateTimeString(),
            'end_at' => $this->slotStart->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 1,
            'is_open' => true,
        ]);
    }

    private function appointment(array $attributes = []): Appointment
    {
        $slot = $this->slot();

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

    /* ------------------------------------------------------------------ */
    /* The window */
    /* ------------------------------------------------------------------ */

    public function test_a_client_may_check_in_thirty_minutes_before_their_slot(): void
    {
        Carbon::setTestNow($this->slotStart->copy()->subMinutes(30));

        $appointment = $this->appointment();

        $this->assertSame(
            'checked_in',
            app(QueueService::class)->checkIn($appointment)->status->value,
        );
    }

    public function test_a_client_may_check_in_fifteen_minutes_after_their_slot_ends(): void
    {
        Carbon::setTestNow($this->slotStart->copy()->addMinutes(45));

        $appointment = $this->appointment();

        $this->assertSame(
            'checked_in',
            app(QueueService::class)->checkIn($appointment)->status->value,
        );
    }

    public function test_a_client_may_not_check_in_before_the_window_opens(): void
    {
        Carbon::setTestNow($this->slotStart->copy()->subMinutes(31));

        $appointment = $this->appointment();

        $this->expectException(CheckInWindowException::class);

        app(QueueService::class)->checkIn($appointment);
    }

    public function test_a_client_may_not_check_in_after_the_window_closes(): void
    {
        Carbon::setTestNow($this->slotStart->copy()->addMinutes(46));

        $appointment = $this->appointment();

        $this->expectException(CheckInWindowException::class);

        app(QueueService::class)->checkIn($appointment);
    }

    public function test_a_refused_check_in_leaves_the_ticket_alone(): void
    {
        Carbon::setTestNow($this->slotStart->copy()->addHour());

        $appointment = $this->appointment();

        try {
            app(QueueService::class)->checkIn($appointment);
        } catch (CheckInWindowException) {
            // Expected.
        }

        $this->assertSame('confirmed', $appointment->fresh()->status->value);
    }

    public function test_the_refusal_says_when_the_window_is_open(): void
    {
        Carbon::setTestNow($this->slotStart->copy()->addHour());

        $appointment = $this->appointment();

        try {
            app(QueueService::class)->checkIn($appointment);

            $this->fail('The check-in was allowed outside the window.');
        } catch (CheckInWindowException $e) {
            // A clerk has to be able to repeat this to the person in front of
            // them, so the message carries the times.
            $this->assertStringContainsString('8:30 AM', $e->getMessage());
            $this->assertStringContainsString('9:45 AM', $e->getMessage());
        }
    }

    public function test_a_walk_in_is_never_outside_a_window(): void
    {
        /*
         * A walk-in is not booked into a slot - it is filed against the slot
         * that is open at the counter - so there is no window for it to be
         * outside of. It is already checked in when it is issued.
         */
        Carbon::setTestNow($this->slotStart->copy()->addHours(6));

        $appointment = $this->appointment([
            'source' => 'walk_in',
            'scheduled_at' => null,
            'status' => 'checked_in',
            'checked_in_at' => now(),
        ]);

        $this->assertSame('checked_in', $appointment->fresh()->status->value);
    }

    /* ------------------------------------------------------------------ */
    /* The console */
    /* ------------------------------------------------------------------ */

    public function test_the_console_explains_the_refusal(): void
    {
        Carbon::setTestNow($this->slotStart->copy()->addHours(2));

        $appointment = $this->appointment();

        $staff = User::factory()->staff()->create([
            'service_window_id' => $this->window->id,
            'is_active' => true,
        ]);

        $this->actingAs($staff);

        Livewire::test(QueueConsole::class)
            ->call('checkIn', $appointment->id)
            ->assertSet('error', fn (string $error) => str_contains($error, 'Check-in is open from'));
    }
}
