<?php

namespace Tests\Feature\Staff;

use App\Enums\AppointmentStatus;
use App\Livewire\Staff\QueueConsole;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A day that is over is a record, not a queue.
 *
 * The console's date field reaches any day the operator types, and the actions -
 * call the next client, complete a transaction, mark a no-show, call one back,
 * check one in - all acted on whichever day was selected. So a clerk who
 * opened yesterday could call a client who came yesterday, serve them, and
 * complete them, and the figures for that day would change under them.
 *
 * A past day is history: what was served, when, for how long, who never
 * arrived, and who cancelled. It cannot be served again, and it cannot be
 * changed by accident.
 *
 * A future day is a booking list: who is expected, and nothing else. Nobody is
 * at a counter for a day that has not come.
 */
class QueueConsoleDateGuardTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create([
            'code' => 'WINDOW_1',
            'name' => 'Window 1 - Certificates',
            'avg_service_minutes' => 10,
        ]);

        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
        $this->staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);
    }

    private function slotFor(string $date, int $offsetMinutes = 0): TimeSlot
    {
        $start = Carbon::parse($date)->setTime(9, 0)->addMinutes($offsetMinutes);

        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $date,
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 20,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    private function ticketFor(string $date, array $overrides = [], int $slotOffsetMinutes = 0): Appointment
    {
        $slot = $this->slotFor($date, $slotOffsetMinutes);

        $appointment = Appointment::create(array_merge([
            'reference_number' => 'GQ-S-'.uniqid(),
            'user_id' => User::factory()->client()->create(['name' => 'Test Client'])->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->window->id,
            'status' => AppointmentStatus::CheckedIn,
            'source' => 'online',
            'scheduled_at' => $slot->start_at,
            'queue_number' => 'A-'.str_pad((string) (Appointment::count() + 1), 3, '0', STR_PAD_LEFT),
            'queue_date' => $date,
            'confirmed_at' => now(),
            'checked_in_at' => now(),
        ], $overrides));

        $slot->increment('booked_count');

        return $appointment;
    }

    private function consoleFor(string $date)
    {
        return Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1'])
            ->set('queueDate', $date);
    }

    public function test_a_day_that_is_over_cannot_have_the_next_client_called(): void
    {
        $yesterday = today()->subDay()->toDateString();

        $appointment = $this->ticketFor($yesterday);

        $console = $this->consoleFor($yesterday);

        $console->call('callNext');

        $this->assertNotSame(
            AppointmentStatus::Serving,
            $appointment->fresh()->status,
            'A client from a day that is over was called to the counter.',
        );
    }

    public function test_a_day_that_is_over_cannot_have_a_transaction_completed(): void
    {
        $yesterday = today()->subDay()->toDateString();

        $appointment = $this->ticketFor($yesterday, [
            'status' => AppointmentStatus::Serving,
            'serving_started_at' => now()->subDay(),
        ]);

        $console = $this->consoleFor($yesterday);

        $console->call('openComplete', $appointment->id);
        $console->call('complete');

        $this->assertNotSame(
            AppointmentStatus::Completed,
            $appointment->fresh()->status,
            'A transaction was completed for a day that is over.',
        );
    }

    public function test_a_day_that_has_not_come_cannot_have_the_next_client_called(): void
    {
        $tomorrow = today()->addDay()->toDateString();

        $appointment = $this->ticketFor($tomorrow);

        $console = $this->consoleFor($tomorrow);

        $console->call('callNext');

        $this->assertNotSame(
            AppointmentStatus::Serving,
            $appointment->fresh()->status,
            'A client was called to the counter for a day that has not come.',
        );
    }

    public function test_todays_console_is_still_a_queue(): void
    {
        $console = $this->consoleFor(today()->toDateString());

        $console->assertSee('Now serving');
        $console->assertDontSee('is over');
    }

    public function test_a_day_that_is_over_shows_the_record_instead_of_a_queue(): void
    {
        $yesterday = today()->subDay()->toDateString();

        $this->ticketFor($yesterday, [
            'status' => AppointmentStatus::Completed,
            'serving_started_at' => now()->subDay()->setTime(9, 30),
            'completed_at' => now()->subDay()->setTime(9, 45),
        ]);

        $console = $this->consoleFor($yesterday);

        // It says what day this is, and that the day is over.
        $console->assertSee('is over');

        // The record: who was served, and what it took.
        $console->assertSee('Served');
        $console->assertSee('Test Client');
        $console->assertSee('took 15 min');

        // And no queue to work.
        $console->assertDontSee('Now serving');
    }

    public function test_a_day_that_has_not_come_says_so_and_offers_no_queue(): void
    {
        $tomorrow = today()->addDay()->toDateString();

        $console = $this->consoleFor($tomorrow);

        $console->assertSee('has not come yet');
        $console->assertDontSee('Now serving');

        // And no "Served - 0": a day that has not opened has nothing to
        // report, and a section that says so is noise.
        $console->assertDontSee('Nobody was served at this window on this day');
    }

    /**
     * A booking the day never finished belongs in the record, not on a
     * working list of people to check in.
     */
    public function test_a_day_that_is_over_keeps_its_unfinished_bookings_in_the_record(): void
    {
        $yesterday = today()->subDay()->toDateString();

        // Booked, confirmed, and nobody ever came.
        $this->ticketFor($yesterday, [
            'status' => AppointmentStatus::Confirmed,
            'checked_in_at' => null,
        ]);

        $console = $this->consoleFor($yesterday);

        $console->assertSee('Never resolved');
        $console->assertDontSee('Booked but not yet checked in');
    }

    /**
     * A day that has not come is a booking list, and that is the list a
     * clerk planning tomorrow needs.
     */
    public function test_a_day_that_has_not_come_still_lists_who_is_booked(): void
    {
        $tomorrow = today()->addDay()->toDateString();

        $this->ticketFor($tomorrow, [
            'status' => AppointmentStatus::Confirmed,
            'checked_in_at' => null,
        ]);

        $console = $this->consoleFor($tomorrow);

        $console->assertSee('Booked but not yet checked in');
    }

    /**
     * A day's record is the whole day.
     *
     * The list of cancellations and no-shows is capped at eight, because
     * while the day is being worked it is a working list and the counter
     * wants to know who just walked out. Once the day is over it is a
     * record, and a record of a day that lost nine people is not a record
     * if it names eight of them.
     */
    public function test_a_day_that_is_over_records_everybody_the_day_lost(): void
    {
        $yesterday = today()->subDay()->toDateString();

        $this->nineNoShows($yesterday);

        $console = $this->consoleFor($yesterday);

        // All nine, including the oldest, which the cap would have dropped.
        $console->assertSee('Lost Client 1');
        $console->assertSee('Lost Client 9');
    }

    /**
     * And while the day is being worked, the list stays a working list.
     */
    public function test_todays_list_of_lost_clients_stays_short(): void
    {
        $this->nineNoShows(today()->toDateString());

        $console = $this->consoleFor(today()->toDateString());

        $console->assertSee('Lost Client 9');
        $console->assertDontSee('Lost Client 1');
    }

    /**
     * Nine no-shows, oldest last, so the order the list is shown in is
     * fixed rather than whatever the database happens to return.
     */
    private function nineNoShows(string $date): void
    {
        for ($i = 1; $i <= 9; $i++) {
            $appointment = $this->ticketFor($date, [
                'status' => AppointmentStatus::NoShow,
                'user_id' => User::factory()->client()->create(['name' => 'Lost Client '.$i])->id,
            ], $i * 30);

            // Eloquent leaves an updated_at it has been given alone.
            $appointment->forceFill(['updated_at' => now()->subMinutes(60 - $i)])->saveQuietly();
        }
    }
}
