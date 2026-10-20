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
 * A client who has already arrived cannot cancel their own appointment.
 *
 * That is the service's rule, and it is deliberate: once the client is
 * physically at the office the ticket is no longer theirs to cancel, because
 * releasing the slot would let them walk out of a queue position without
 * becoming a no-show.
 *
 * The page did not agree with the rule. "Cancel appointment" was offered to
 * anybody whose ticket was still active, and a checked-in client is active - so
 * a client standing in the queue was offered a button that the service then
 * refused. The refusal was an exception the controller did not catch, so
 * pressing it produced a 500 error page rather than a sentence explaining why.
 *
 * The rule belongs in one place, and both the page and the service should ask
 * it the same question: has this client arrived?
 */
class ClientCancellationAfterArrivalTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Service $service;

    private ServiceWindow $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->client()->create();
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
    }

    private function appointment(string $status): Appointment
    {
        $start = Carbon::parse(today()->toDateString())->setTime(9, 0);

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
            'checked_in_at' => $status !== AppointmentStatus::Confirmed->value ? now() : null,
        ]);
    }

    public function test_a_client_who_is_waiting_in_the_queue_is_not_offered_a_cancel_button(): void
    {
        $appointment = $this->appointment(AppointmentStatus::CheckedIn->value);

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertDontSee('Cancel appointment');
    }

    public function test_a_client_who_is_being_served_is_not_offered_a_cancel_button(): void
    {
        $appointment = $this->appointment(AppointmentStatus::Serving->value);

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertDontSee('Cancel appointment');
    }

    /**
     * The button is gone, so the request should not be reachable - but a
     * client with a stale tab, or a bookmarked form, can still post it. That
     * has to be an answer, not an error page.
     */
    public function test_cancelling_after_arrival_is_refused_with_an_explanation(): void
    {
        $appointment = $this->appointment(AppointmentStatus::CheckedIn->value);

        $response = $this->actingAs($this->client)
            ->delete(route('client.appointments.cancel', $appointment));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    /**
     * The control: a client who has not arrived yet can cancel.
     */
    public function test_a_client_who_has_not_arrived_yet_can_still_cancel(): void
    {
        $appointment = $this->appointment(AppointmentStatus::Confirmed->value);

        $this->actingAs($this->client)
            ->delete(route('client.appointments.cancel', $appointment))
            ->assertRedirect(route('client.appointments.index'));

        $this->assertSame(
            AppointmentStatus::Cancelled->value,
            $appointment->fresh()->status->value,
        );
    }
}
