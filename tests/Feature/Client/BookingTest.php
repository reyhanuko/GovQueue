<?php

namespace Tests\Feature\Client;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned to a birthdate well under the RA 9994 threshold of 60 so the
        // senior-priority assertions are deterministic (the factory randomises
        // age between 18 and 70).
        $this->client = User::factory()->client()->create([
            'phone' => '09171234567',
            'birthdate' => '1995-04-12',
            'claims_senior_citizen' => false,
        ]);
        $this->service = Service::factory()->create(['code' => 'CERT-IND', 'is_active' => true]);
    }

    private function slot(array $overrides = []): TimeSlot
    {
        $start = $overrides['start_at'] ?? now()->addDay()->setTime(9, 0)->toDateTimeString();

        return TimeSlot::create(array_merge([
            'service_id' => $this->service->id,
            'service_window_id' => $this->service->service_window_id,
            'slot_date' => substr($start, 0, 10),
            'start_time' => substr($start, 11, 8),
            'end_time' => date('H:i:s', strtotime($start) + 1800),
            'start_at' => $start,
            'end_at' => date('Y-m-d H:i:s', strtotime($start) + 1800),
            'capacity' => 3,
            'booked_count' => 0,
            'is_open' => true,
        ], $overrides));
    }

    /* ------------------------------------------------------------------ */
    /* Booking screen */
    /* ------------------------------------------------------------------ */

    public function test_the_booking_screen_lists_open_slots(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)
            ->get('/services/CERT-IND/book')
            ->assertOk()
            ->assertSee('Book '.Service::find($this->service->id)->name)
            ->assertSee('Choose a time slot')
            ->assertSee('3/3 left')
            ->assertSee('value="'.$slot->id.'"', false);
    }

    public function test_the_booking_screen_explains_when_there_is_nothing_to_book(): void
    {
        $this->actingAs($this->client)
            ->get('/services/CERT-IND/book')
            ->assertOk()
            ->assertSee('No open slots available');
    }

    public function test_the_booking_screen_marks_a_slot_the_client_already_holds(): void
    {
        $slot = $this->slot();

        Appointment::factory()->create([
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->service->service_window_id,
            'scheduled_at' => $slot->start_at,
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->actingAs($this->client)
            ->get('/services/CERT-IND/book')
            ->assertOk()
            ->assertSee('Already booked');
    }

    public function test_the_booking_screen_offers_rescheduling_when_one_is_held(): void
    {
        $slot = $this->slot();

        $existing = Appointment::factory()->create([
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->service->service_window_id,
            'scheduled_at' => $slot->start_at,
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->actingAs($this->client)
            ->get('/services/CERT-IND/book')
            ->assertOk()
            ->assertSee('You already hold a booking')
            ->assertSee('Move this appointment')
            ->assertSee(route('client.appointments.reschedule', $existing))
            ->assertSee('Booking already held', false);
    }

    public function test_the_booking_screen_disables_an_unavailable_senior_claim(): void
    {
        // A slot must exist or the page renders the "nothing available" branch,
        // which does not include the priority fieldset at all.
        $this->slot();

        // This client is under 60, so the RA 9994 box must be shown disabled.
        $this->actingAs($this->client)
            ->get('/services/CERT-IND/book')
            ->assertOk()
            // Blade wraps this sentence across lines, so assert on a fragment
            // that survives the whitespace in the template.
            ->assertSee('does not yet meet the RA 9994 threshold')
            ->assertSee('disabled', false);
    }

    public function test_the_booking_screen_enables_senior_claim_for_a_qualifying_client(): void
    {
        $senior = User::factory()->seniorCitizen()->create(['claims_senior_citizen' => true]);
        $this->slot();

        $this->actingAs($senior)
            ->get('/services/CERT-IND/book')
            ->assertOk()
            ->assertSee('Recognised from your date of birth');
    }

    public function test_guests_are_redirected_to_sign_in(): void
    {
        $this->get('/services/CERT-IND/book')->assertRedirect(route('login'));
    }

    public function test_staff_cannot_use_the_client_booking_screen(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get('/services/CERT-IND/book')
            ->assertForbidden();
    }

    public function test_an_inactive_service_cannot_be_booked(): void
    {
        $this->service->update(['is_active' => false]);

        $this->actingAs($this->client)->get('/services/CERT-IND/book')->assertNotFound();
        $this->actingAs($this->client)->post('/services/CERT-IND/book', [])->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /* Confirming a booking */
    /* ------------------------------------------------------------------ */

    public function test_a_client_can_confirm_a_booking(): void
    {
        $slot = $this->slot();

        $response = $this->actingAs($this->client)
            ->post('/services/CERT-IND/book', ['time_slot_id' => $slot->id]);

        $appointment = Appointment::firstOrFail();

        $response->assertRedirect(route('client.appointments.show', $appointment));
        $response->assertSessionHas('success');

        $this->assertSame((int) $slot->id, (int) $appointment->time_slot_id);
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame(1, $slot->fresh()->booked_count);
    }

    public function test_the_confirmation_names_the_queue_number(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)
            ->post('/services/CERT-IND/book', ['time_slot_id' => $slot->id])
            ->assertSessionHas('success', 'Appointment confirmed. Your queue number is A-001.');
    }

    public function test_booking_requires_a_slot(): void
    {
        $this->actingAs($this->client)
            ->post('/services/CERT-IND/book', [])
            ->assertSessionHasErrors('time_slot_id');

        $this->assertSame(0, Appointment::count());
    }

    public function test_a_full_slot_is_refused_with_a_clear_message(): void
    {
        $slot = $this->slot(['capacity' => 1, 'booked_count' => 1]);

        $this->actingAs($this->client)
            ->post('/services/CERT-IND/book', ['time_slot_id' => $slot->id])
            ->assertSessionHas('error');

        $this->assertSame(0, Appointment::count());
    }

    public function test_a_closed_slot_is_refused(): void
    {
        $slot = $this->slot(['is_open' => false]);

        $this->actingAs($this->client)
            ->post('/services/CERT-IND/book', ['time_slot_id' => $slot->id])
            ->assertSessionHas('error');

        $this->assertSame(0, Appointment::count());
    }

    public function test_a_pwd_claim_without_a_reference_is_rejected(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)
            ->post('/services/CERT-IND/book', [
                'time_slot_id' => $slot->id,
                'claims_pwd' => '1',
            ])
            ->assertSessionHasErrors('priority_reference');

        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, $slot->fresh()->booked_count,
            'A rejected claim must not consume capacity.');
    }

    public function test_a_pwd_claim_with_a_reference_is_stored_unverified(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)
            ->post('/services/CERT-IND/book', [
                'time_slot_id' => $slot->id,
                'claims_pwd' => '1',
                'priority_reference' => 'PWD-9876543',
            ])
            ->assertSessionHasNoErrors();

        $appointment = Appointment::firstOrFail();

        $this->assertTrue($appointment->claims_pwd);
        $this->assertSame('PWD-9876543', $appointment->priority_reference);
        $this->assertNull($appointment->priority_verified_at);
        $this->assertFalse($appointment->hasVerifiedPriority());
    }

    public function test_a_client_cannot_self_grant_senior_priority(): void
    {
        $slot = $this->slot();

        // This client is under 60 but ticks the box anyway.
        $this->actingAs($this->client)
            ->post('/services/CERT-IND/book', [
                'time_slot_id' => $slot->id,
                'claims_senior_citizen' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(Appointment::firstOrFail()->claims_senior_citizen,
            'Senior priority is derived from the profile, not the form.');
    }

    public function test_a_duplicate_booking_is_refused_with_a_helpful_message(): void
    {
        $first = $this->slot();
        $second = $this->slot([
            'start_at' => now()->addDay()->setTime(14, 0)->toDateTimeString(),
        ]);

        $this->actingAs($this->client)->post('/services/CERT-IND/book', ['time_slot_id' => $first->id]);

        $this->actingAs($this->client)
            ->post('/services/CERT-IND/book', ['time_slot_id' => $second->id])
            ->assertSessionHas('error');

        $this->assertSame(1, Appointment::count());
    }

    public function test_an_overlapping_booking_for_another_service_is_refused(): void
    {
        $otherService = Service::factory()->create(['code' => 'BUS-PERMIT']);
        $booked = $this->slot();
        $overlapping = $this->slot([
            'service_id' => $otherService->id,
            'service_window_id' => ServiceWindow::factory()->create()->id,
            'start_at' => now()->addDay()->setTime(9, 15)->toDateTimeString(),
        ]);

        $this->actingAs($this->client)->post('/services/CERT-IND/book', ['time_slot_id' => $booked->id]);

        $this->actingAs($this->client)
            ->post('/services/BUS-PERMIT/book', ['time_slot_id' => $overlapping->id])
            ->assertSessionHas('error');

        $this->assertSame(1, Appointment::count());
    }

    /* ------------------------------------------------------------------ */
    /* Appointment detail */
    /* ------------------------------------------------------------------ */

    public function test_the_detail_page_shows_the_booking(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)->post('/services/CERT-IND/book', ['time_slot_id' => $slot->id]);
        $appointment = Appointment::firstOrFail();

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertOk()
            ->assertSee($appointment->reference_number)
            ->assertSee('A-001')
            ->assertSee('Your queue number')
            ->assertSee('Move to another slot');
    }

    public function test_the_detail_page_states_that_priority_is_unverified(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)->post('/services/CERT-IND/book', [
            'time_slot_id' => $slot->id,
            'claims_pwd' => '1',
            'priority_reference' => 'PWD-1234567',
        ]);
        $appointment = Appointment::firstOrFail();

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Not yet verified')
            ->assertSee('PWD-1234567');
    }

    public function test_a_client_cannot_view_another_clients_appointment(): void
    {
        $slot = $this->slot();
        $other = User::factory()->client()->create();

        $appointment = Appointment::factory()->create([
            'user_id' => $other->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->service->service_window_id,
            'scheduled_at' => $slot->start_at,
        ]);

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Reschedule */
    /* ------------------------------------------------------------------ */

    public function test_the_reschedule_screen_offers_other_slots(): void
    {
        $current = $this->slot();
        $other = $this->slot([
            'start_at' => now()->addDays(2)->setTime(11, 0)->toDateTimeString(),
        ]);

        $this->actingAs($this->client)->post('/services/CERT-IND/book', ['time_slot_id' => $current->id]);
        $appointment = Appointment::firstOrFail();

        $this->actingAs($this->client)
            ->get(route('client.appointments.reschedule', $appointment))
            ->assertOk()
            ->assertSee('Move your')
            ->assertSee('Your current slot')
            ->assertSee('value="'.$other->id.'"', false);
    }

    public function test_a_client_can_move_a_booking(): void
    {
        $current = $this->slot();
        $target = $this->slot([
            'start_at' => now()->addDays(2)->setTime(11, 0)->toDateTimeString(),
        ]);

        $this->actingAs($this->client)->post('/services/CERT-IND/book', ['time_slot_id' => $current->id]);
        $appointment = Appointment::firstOrFail();

        $this->actingAs($this->client)
            ->put(route('client.appointments.reschedule.store', $appointment), ['time_slot_id' => $target->id])
            ->assertSessionHas('success');

        $appointment->refresh();

        $this->assertSame((int) $target->id, (int) $appointment->time_slot_id);
        $this->assertSame(0, $current->fresh()->booked_count);
        $this->assertSame(1, $target->fresh()->booked_count);
    }

    public function test_moving_to_a_full_slot_is_refused_and_keeps_the_booking(): void
    {
        $current = $this->slot();
        $full = $this->slot([
            'start_at' => now()->addDays(2)->setTime(11, 0)->toDateTimeString(),
            'capacity' => 1,
            'booked_count' => 1,
        ]);

        $this->actingAs($this->client)->post('/services/CERT-IND/book', ['time_slot_id' => $current->id]);
        $appointment = Appointment::firstOrFail();

        $this->actingAs($this->client)
            ->put(route('client.appointments.reschedule.store', $appointment), ['time_slot_id' => $full->id])
            ->assertSessionHas('error');

        $appointment->refresh();
        $this->assertSame((int) $current->id, (int) $appointment->time_slot_id);
        $this->assertSame(1, $current->fresh()->booked_count);
    }

    public function test_a_client_cannot_move_someone_elses_appointment(): void
    {
        $other = User::factory()->client()->create();
        $appointment = Appointment::factory()->create(['user_id' => $other->id]);

        $this->actingAs($this->client)
            ->put(route('client.appointments.reschedule.store', $appointment), ['time_slot_id' => $this->slot()->id])
            ->assertForbidden();
    }

    public function test_a_checked_in_appointment_cannot_be_moved(): void
    {
        $current = $this->slot();
        $this->actingAs($this->client)->post('/services/CERT-IND/book', ['time_slot_id' => $current->id]);
        $appointment = Appointment::firstOrFail();
        $appointment->update(['status' => AppointmentStatus::CheckedIn]);

        $this->actingAs($this->client)
            ->get(route('client.appointments.reschedule', $appointment))
            ->assertRedirect(route('client.appointments.show', $appointment))
            ->assertSessionHas('error');

        $this->actingAs($this->client)
            ->put(route('client.appointments.reschedule.store', $appointment), [
                'time_slot_id' => $this->slot(['start_at' => now()->addDays(2)->setTime(11, 0)->toDateTimeString()])->id,
            ])
            ->assertSessionHas('error');

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->status);
    }

    /* ------------------------------------------------------------------ */
    /* Cancel */
    /* ------------------------------------------------------------------ */

    public function test_a_client_can_cancel_a_booking(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)->post('/services/CERT-IND/book', ['time_slot_id' => $slot->id]);
        $appointment = Appointment::firstOrFail();

        $this->actingAs($this->client)
            ->delete(route('client.appointments.cancel', $appointment), ['reason' => 'Cannot make it'])
            ->assertRedirect(route('client.appointments.index'))
            ->assertSessionHas('success');

        $appointment->refresh();

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertSame('Cannot make it', $appointment->cancel_reason);
        $this->assertSame(0, $slot->fresh()->booked_count);
    }

    public function test_a_client_cannot_cancel_someone_elses_appointment(): void
    {
        $other = User::factory()->client()->create();
        $appointment = Appointment::factory()->create(['user_id' => $other->id]);

        $this->actingAs($this->client)
            ->delete(route('client.appointments.cancel', $appointment))
            ->assertForbidden();

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_a_cancelled_booking_is_not_offered_again(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)->post('/services/CERT-IND/book', ['time_slot_id' => $slot->id]);
        $appointment = Appointment::firstOrFail();
        $this->actingAs($this->client)->delete(route('client.appointments.cancel', $appointment));

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('This appointment is closed')
            ->assertSee('Book again');
    }
}
