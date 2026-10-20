<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moving a booked appointment to a different slot.
 *
 * Reported as a 405 on submit: "POST method is not supported for route
 * my-appointments/235/move. Supported methods: GET, HEAD, PUT."
 *
 * The cause is entirely in the form. resources/views/client/booking/
 * reschedule.blade.php posted to route('client.appointments.reschedule'),
 * which is the GET page route, and sent no @method('PUT') - so the request
 * went to the form as the browser actually submits it and hit the PUT-only
 * store route as a POST.
 *
 * Every test here submits the way the page does. That is deliberate: a test
 * that PUTs directly to the store route passes against the broken form,
 * which is precisely how this shipped with no coverage at all. The first test
 * reads the action out of the rendered HTML for exactly that reason.
 */
class RescheduleAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    private User $client;

    private int $slotCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create([
            'name' => 'Window 1 - Certificates',
            'code' => 'WINDOW_1',
        ]);

        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'name' => 'Certificate of Indigency',
            'code' => 'CERT-IND',
            'is_active' => true,
        ]);

        $this->client = User::factory()->client()->create();
    }

    private function slot(string $date, array $overrides = []): TimeSlot
    {
        $this->slotCounter++;

        return TimeSlot::create(array_merge([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $date,
            'start_time' => sprintf('%02d:00:00', 9 + $this->slotCounter),
            'end_time' => sprintf('%02d:30:00', 9 + $this->slotCounter),
            'start_at' => $date.' '.sprintf('%02d:00:00', 9 + $this->slotCounter),
            'end_at' => $date.' '.sprintf('%02d:30:00', 9 + $this->slotCounter),
            'capacity' => 4,
            'booked_count' => 0,
            'is_open' => true,
        ], $overrides));
    }

    private function booked(TimeSlot $originalSlot): Appointment
    {
        $originalSlot->increment('booked_count');

        return Appointment::factory()->create([
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $originalSlot->id,
            'scheduled_at' => $originalSlot->start_at,
            'queue_date' => $originalSlot->slot_date,
            'status' => 'confirmed',
            'queue_number' => 'A-001',
        ]);
    }

    /**
     * The regression itself: what the page actually submits.
     *
     * Reads the form's action and method out of the rendered HTML rather than
     * hardcoding the route, because the defect WAS the form pointing at the
     * wrong route. A test that names the correct route would not have caught it
     * and would not catch it being reintroduced.
     */
    public function test_the_move_form_submits_to_the_store_route_with_a_put(): void
    {
        $appointment = $this->booked($this->slot(today()->addDay()->toDateString()));

        $html = $this->actingAs($this->client)
            ->get(route('client.appointments.reschedule', $appointment))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'action="'.route('client.appointments.reschedule.store', $appointment).'"',
            $html,
            'The move form posts to the GET page route, so submitting it is a 405.',
        );

        // Without the spoof the request is a POST, and the route only allows PUT.
        $this->assertMatchesRegularExpression(
            '/name="_method"\s+value="PUT"/',
            $html,
            'The move form has no @method("PUT"), so the browser sends POST to a PUT-only route.',
        );
    }

    public function test_a_client_can_move_to_an_open_slot(): void
    {
        $appointment = $this->booked($this->slot(today()->addDay()->toDateString()));
        $newSlot = $this->slot(today()->addDays(3)->toDateString());

        $this->actingAs($this->client)
            ->put(route('client.appointments.reschedule.store', $appointment), [
                'time_slot_id' => $newSlot->id,
            ])
            ->assertRedirect(route('client.appointments.show', $appointment));

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'time_slot_id' => $newSlot->id,
        ]);

        // The new slot is taken and the old one released.
        $this->assertSame(1, $newSlot->fresh()->booked_count);
        $this->assertSame(0, $appointment->timeSlot->fresh()->booked_count);

        /*
         * The ticket moves days, not just times.
         *
         * Asserted on queue_date rather than on the queue number changing:
         * numbering restarts each day, so moving from the 1st to the 3rd can
         * legitimately land on the same "A-001". What must not happen is the
         * ticket staying filed under the old day's queue, where the lobby board
         * would keep showing a number for a day the client is no longer coming
         * to.
         */
        $this->assertSame(
            today()->addDays(3)->toDateString(),
            $appointment->fresh()->queue_date->toDateString(),
        );
    }

    public function test_a_full_slot_is_refused_and_the_original_is_kept(): void
    {
        $appointment = $this->booked($this->slot(today()->addDay()->toDateString()));
        $full = $this->slot(today()->addDays(3)->toDateString(), [
            'capacity' => 2,
            'booked_count' => 2,
        ]);

        $this->actingAs($this->client)
            ->put(route('client.appointments.reschedule.store', $appointment), [
                'time_slot_id' => $full->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'time_slot_id' => $appointment->time_slot_id,
        ]);
    }

    public function test_a_closed_slot_is_refused(): void
    {
        $appointment = $this->booked($this->slot(today()->addDay()->toDateString()));
        $closed = $this->slot(today()->addDays(3)->toDateString(), ['is_open' => false]);

        $this->actingAs($this->client)
            ->put(route('client.appointments.reschedule.store', $appointment), [
                'time_slot_id' => $closed->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'time_slot_id' => $appointment->time_slot_id,
        ]);
    }

    public function test_a_slot_on_a_closure_day_is_refused(): void
    {
        $date = today()->addDays(3)->toDateString();
        $appointment = $this->booked($this->slot(today()->addDay()->toDateString()));
        $holidaySlot = $this->slot($date);

        Holiday::create([
            'name' => 'Foundation Day',
            'date' => $date,
        ]);

        $this->actingAs($this->client)
            ->put(route('client.appointments.reschedule.store', $appointment), [
                'time_slot_id' => $holidaySlot->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'time_slot_id' => $appointment->time_slot_id,
        ]);
    }

    public function test_a_past_slot_is_refused(): void
    {
        $appointment = $this->booked($this->slot(today()->addDay()->toDateString()));
        $past = $this->slot(today()->subDay()->toDateString());

        $this->actingAs($this->client)
            ->put(route('client.appointments.reschedule.store', $appointment), [
                'time_slot_id' => $past->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'time_slot_id' => $appointment->time_slot_id,
        ]);
    }

    public function test_a_client_cannot_move_someone_elses_appointment(): void
    {
        $appointment = $this->booked($this->slot(today()->addDay()->toDateString()));
        $newSlot = $this->slot(today()->addDays(3)->toDateString());

        $intruder = User::factory()->client()->create();

        $this->actingAs($intruder)
            ->put(route('client.appointments.reschedule.store', $appointment), [
                'time_slot_id' => $newSlot->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'time_slot_id' => $appointment->time_slot_id,
        ]);
    }

    public function test_a_checked_in_appointment_cannot_be_moved(): void
    {
        $appointment = $this->booked($this->slot(today()->addDay()->toDateString()));
        $appointment->update(['status' => 'checked_in', 'checked_in_at' => now()]);

        $newSlot = $this->slot(today()->addDays(3)->toDateString());

        $this->actingAs($this->client)
            ->put(route('client.appointments.reschedule.store', $appointment), [
                'time_slot_id' => $newSlot->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'time_slot_id' => $appointment->time_slot_id,
        ]);
    }
}
