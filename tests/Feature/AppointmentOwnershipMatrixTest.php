<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Can a client reach another client's appointment by changing the number?
 *
 * This is the gate for any change to how appointment URLs are identified, so
 * it is asserted per action rather than once. "Moving" was covered; opening
 * and cancelling were not, and the two differ: a read that leaks a name and a
 * booking time is a disclosure, while a cancel destroys a record.
 *
 * Every case here passes through the real route with a real session. The
 * point is the URL, so nothing calls the controller directly.
 *
 * The identifiers are sequential integers today. Whether that becomes
 * something unguessable is a separate question - it is only safe to answer
 * once this matrix is proven, and this is the proof.
 */
class AppointmentOwnershipMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Appointment $mine;

    private Appointment $theirs;

    private User $owner;

    private User $intruder;

    private int $slotCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $service = Service::factory()->create(['service_window_id' => $window->id]);

        $this->owner = User::factory()->client()->create();
        $this->intruder = User::factory()->client()->create();

        $this->mine = $this->appointmentFor($window, $service, $this->owner, 'Mine');
        $this->theirs = $this->appointmentFor($window, $service, $this->owner, 'Theirs');
    }

    private function appointmentFor(
        ServiceWindow $window,
        Service $service,
        User $user,
        string $name,
    ): Appointment {
        // A distinct time per appointment: time_slots has a unique index on
        // (service, date, start), so two appointments at 09:00 collide.
        $this->slotCounter++;

        $start = now()->addDay()->setTime(9, $this->slotCounter * 30);

        $slot = TimeSlot::create([
            'service_id' => $service->id,
            'service_window_id' => $window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        return Appointment::factory()->create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'service_window_id' => $window->id,
            'time_slot_id' => $slot->id,
            'scheduled_at' => $start->toDateTimeString(),
            'queue_date' => $start->toDateString(),
            'status' => 'confirmed',
            'reference_number' => 'GQ-'.strtoupper(substr(md5($name.microtime(true)), 0, 6)),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Opening */
    /* ------------------------------------------------------------------ */

    public function test_a_client_cannot_open_another_clients_appointment(): void
    {
        $this->actingAs($this->intruder)
            ->get(route('client.appointments.show', $this->theirs))
            ->assertForbidden();

        // The reference is the thing that would leak.
        $this->assertStringNotContainsString(
            $this->theirs->reference_number,
            $this->actingAs($this->intruder)
                ->get(route('client.appointments.show', $this->theirs))
                ->getContent() ?? '',
        );
    }

    public function test_a_client_can_open_their_own_appointment(): void
    {
        $this->actingAs($this->owner)
            ->get(route('client.appointments.show', $this->mine))
            ->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* Cancelling */
    /* ------------------------------------------------------------------ */

    public function test_a_client_cannot_cancel_another_clients_appointment(): void
    {
        $this->actingAs($this->intruder)
            ->delete(route('client.appointments.cancel', $this->theirs))
            ->assertForbidden();

        // A 403 that still cancelled would be the worst outcome, so the state is
        // checked rather than only the status code.
        $this->assertDatabaseHas('appointments', [
            'id' => $this->theirs->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_a_client_can_cancel_their_own_appointment(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('client.appointments.cancel', $this->mine))
            ->assertRedirect();

        $this->assertDatabaseHas('appointments', [
            'id' => $this->mine->id,
            'status' => 'cancelled',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Moving */
    /* ------------------------------------------------------------------ */

    public function test_a_client_cannot_reach_the_move_form_for_another_clients_appointment(): void
    {
        $this->actingAs($this->intruder)
            ->get(route('client.appointments.reschedule', $this->theirs))
            ->assertForbidden();
    }

    public function test_a_client_can_reach_their_own_move_form(): void
    {
        $this->actingAs($this->owner)
            ->get(route('client.appointments.reschedule', $this->mine))
            ->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* Roles that are not clients at all */
    /* ------------------------------------------------------------------ */

    public function test_staff_cannot_use_the_client_appointment_routes(): void
    {
        $staff = User::factory()->staff()->create();

        foreach ([
            fn () => $this->get(route('client.appointments.show', $this->theirs)),
            fn () => $this->delete(route('client.appointments.cancel', $this->theirs)),
            fn () => $this->get(route('client.appointments.reschedule', $this->theirs)),
            fn () => $this->get(route('client.appointments.index')),
        ] as $attempt) {
            $this->actingAs($staff);
            $attempt()->assertForbidden();
        }
    }

    public function test_an_administrator_cannot_use_the_client_appointment_routes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('client.appointments.show', $this->theirs))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_sign_in_rather_than_shown_an_appointment(): void
    {
        $this->get(route('client.appointments.show', $this->theirs))
            ->assertRedirect(route('login'));
    }

    /*
     * On the identifiers themselves.
     *
     * appointments.id is a sequential integer, so /my-appointments/235 is
     * guessable. That is F-25, and it is recorded here as a property of the
     * schema rather than as a test.
     *
     * An earlier version asserted the id was "small" and failed the moment the
     * suite grew past a hundred appointments - which is a statement about how
     * many tests have ever run, not about the system. Nothing meaningful can be
     * asserted about a value that changes as the suite grows; the thing worth
     * holding onto is the matrix above, which passes whatever the id is.
     */
}
