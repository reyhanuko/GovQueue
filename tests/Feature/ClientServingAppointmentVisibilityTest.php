<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A client who is at the counter must be able to see that they are.
 *
 * AppointmentStatus::isActive() means "still occupying a place in the queue",
 * and it deliberately leaves out `serving` - a ticket being served is at the
 * counter, not in the line. That is the right meaning for the two places that
 * ask "where is this client in the line?", and it is the wrong meaning for
 * the three that ask "is this ticket still live?".
 *
 * The three were: the client's own appointment list, the "still worth showing
 * as upcoming" rule, and the live board on the appointment page. So a ticket
 * that was `serving` appeared in none of them - not in the list, not in the
 * history, and not as a live board.
 *
 * The live board is where the client is told their number has been called, and
 * it is the page the "your number has been called" notification links to. So
 * the client whose number had just been called was sent to a page that did not
 * say so.
 */
class ClientServingAppointmentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Service $service;

    private ServiceWindow $window;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->client()->create();
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
    }

    private function appointment(string $date, string $status): Appointment
    {
        $this->counter++;

        $start = Carbon::parse($date)->setTime(9, $this->counter * 10);

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 50,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        return Appointment::factory()->create([
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'scheduled_at' => $start->toDateTimeString(),
            'queue_date' => $start->toDateString(),
            'status' => $status,
            'serving_started_at' => $status === AppointmentStatus::Serving->value ? now() : null,
        ]);
    }

    public function test_a_client_being_served_still_sees_their_appointment(): void
    {
        $appointment = $this->appointment(today()->toDateString(), AppointmentStatus::Serving->value);

        $this->actingAs($this->client)
            ->get(route('client.appointments.index'))
            ->assertSee($appointment->reference_number);
    }

    public function test_a_client_being_served_is_told_to_go_to_the_window(): void
    {
        $appointment = $this->appointment(today()->toDateString(), AppointmentStatus::Serving->value);

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertSee('Your number has been called');
    }

    /**
     * The control: a client waiting in the line was always shown, so a test
     * that only ever checked the serving case would pass for the wrong reason
     * if the whole list stopped working.
     */
    public function test_a_client_waiting_in_the_line_is_still_shown(): void
    {
        $appointment = $this->appointment(today()->toDateString(), AppointmentStatus::CheckedIn->value);

        $this->actingAs($this->client)
            ->get(route('client.appointments.index'))
            ->assertSee($appointment->reference_number);
    }
}
