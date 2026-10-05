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
use Livewire\Livewire;
use Tests\TestCase;

class QueueConsoleTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    private User $staff;

    private int $slotCounter = 0;

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

    private function slot(): TimeSlot
    {
        $this->slotCounter++;
        $start = today()->setTime(9, 0)->addMinutes(($this->slotCounter - 1) * 30);

        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => today()->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 20,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    private function waiting(array $overrides = [], ?string $checkedInAt = null): Appointment
    {
        $slot = $this->slot();

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
            'queue_date' => today()->toDateString(),
            'confirmed_at' => now(),
            'checked_in_at' => $checkedInAt ?? now(),
        ], $overrides));

        $slot->increment('booked_count');

        return $appointment;
    }

    /**
     * Mounts the console for the acting staff member.
     *
     * Returns Livewire's Testable wrapper, which carries the ->call()
     * and ->assertSet() assertion helpers.
     */
    private function console()
    {
        return Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1'])
            ->set('queueDate', today()->toDateString());
    }

    /* ------------------------------------------------------------------ */

    public function test_staff_can_open_the_console(): void
    {
        Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1'])
            ->assertOk()
            ->assertSee('Call next client');
    }

    public function test_a_client_cannot_open_the_staff_console(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->get('/staff')
            ->assertForbidden();
    }

    public function test_guests_are_redirected_from_the_console(): void
    {
        $this->get('/staff')->assertRedirect(route('login'));
    }

    public function test_calling_next_serves_the_first_waiting_client(): void
    {
        $appointment = $this->waiting();

        $this->console()
            ->call('callNext')
            ->assertSet('feedback', 'Now serving '.$appointment->queue_number.' - Test Client');

        $this->assertSame(AppointmentStatus::Serving, $appointment->fresh()->status);
    }

    public function test_calling_next_with_nobody_waiting_explains_itself(): void
    {
        $this->console()
            ->call('callNext')
            ->assertSet('error', 'Nobody is waiting at this window.');
    }

    public function test_the_console_refuses_a_second_client_while_one_is_being_served(): void
    {
        $this->waiting();
        $this->waiting();

        $this->console()
            ->call('callNext')
            ->call('callNext')
            ->assertSet('error', 'Finish serving the current client before calling the next one.');
    }

    public function test_a_confirmed_client_can_be_checked_in(): void
    {
        $appointment = $this->waiting([
            'status' => AppointmentStatus::Confirmed,
            'checked_in_at' => null,
        ]);

        $this->console()
            ->call('checkIn', $appointment->id)
            ->assertSet('feedback', $appointment->queue_number.' checked in.');

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
    }

    public function test_staff_can_record_a_transaction(): void
    {
        $this->waiting();
        $this->console()->call('callNext');

        $serving = Appointment::where('status', AppointmentStatus::Serving->value)->firstOrFail();

        $this->console()
            ->call('openComplete', $serving->id)
            ->call('complete')
            ->assertSet('feedback', 'Transaction recorded for '.$serving->queue_number.'.');

        $this->assertSame(AppointmentStatus::Completed, $serving->fresh()->status);
        $this->assertDatabaseHas('transactions', [
            'appointment_id' => $serving->id,
            'handled_by' => $this->staff->id,
        ]);
    }

    public function test_staff_can_mark_a_no_show(): void
    {
        $appointment = $this->waiting();

        $this->console()->call('callNext')->call('markNoShow')
            ->assertSet('feedback', $appointment->queue_number.' marked as a no-show.');

        $this->assertSame(AppointmentStatus::NoShow, $appointment->fresh()->status);
    }

    public function test_staff_can_skip_a_client_and_are_warned_after_repeat_offences(): void
    {
        $appointment = $this->waiting();

        $this->console()->call('callNext');

        // First skip: moved back, with no warning yet.
        $this->console()
            ->call('skip', $appointment->id)
            ->assertSet('feedback', $appointment->queue_number.' moved back in the line.');

        // The second skip crosses config('govqueue.max_skips') and prompts staff
        // to close the ticket as a no-show instead of calling forever.
        $this->console()
            ->call('skip', $appointment->id)
            ->assertSee('consider marking this client a no-show');

        $this->assertSame(2, (int) $appointment->fresh()->skip_count);
    }

    public function test_staff_can_verify_a_priority_claim_from_the_console(): void
    {
        $appointment = $this->waiting([
            'claims_senior_citizen' => true,
            'priority_reference' => 'SC-7788',
        ]);

        $this->console()
            ->call('openVerify', $appointment->id)
            ->call('verifyPriority')
            ->assertSet('feedback', 'Priority verified for '.$appointment->queue_number.'. Reordering the queue.');

        $this->assertNotNull($appointment->fresh()->priority_verified_at);
    }

    public function test_staff_can_refuse_a_priority_claim(): void
    {
        $appointment = $this->waiting(['claims_pwd' => true, 'priority_reference' => 'PWD-1']);

        $this->console()
            ->call('openVerify', $appointment->id)
            ->set('priorityNote', 'No ID presented')
            ->call('refusePriority');

        $appointment->refresh();

        $this->assertFalse($appointment->claims_pwd);
        $this->assertNull($appointment->priority_verified_at);
    }

    public function test_verifying_a_ticket_with_no_claim_is_refused(): void
    {
        $appointment = $this->waiting();

        $this->console()
            ->call('openVerify', $appointment->id)
            ->call('verifyPriority')
            ->assertSet('error', 'This client has not claimed any priority category.');
    }

    public function test_the_console_shows_queue_statistics(): void
    {
        $this->waiting();

        $this->console()
            ->assertSee('Waiting')
            ->assertSee('Avg service');
    }

    public function test_the_console_lists_the_next_up_clients(): void
    {
        $confirmed = $this->waiting([
            'status' => AppointmentStatus::Confirmed,
            'checked_in_at' => null,
        ]);

        $this->console()->assertSee('Booked but not yet checked in');

        $this->console()->call('checkIn', $confirmed->id);
    }

    public function test_staff_cannot_act_on_a_ticket_from_another_window(): void
    {
        $otherWindow = ServiceWindow::factory()->create(['code' => 'WINDOW_2']);
        $otherService = Service::factory()->create(['service_window_id' => $otherWindow->id]);

        $slot = TimeSlot::create([
            'service_id' => $otherService->id,
            'service_window_id' => $otherWindow->id,
            'slot_date' => today()->toDateString(),
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
            'start_at' => today()->setTime(11, 0)->toDateTimeString(),
            'end_at' => today()->setTime(11, 30)->toDateTimeString(),
            'capacity' => 5,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        $foreign = Appointment::create([
            'reference_number' => 'GQ-F-'.uniqid(),
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $otherService->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $otherWindow->id,
            'status' => AppointmentStatus::CheckedIn,
            'source' => 'online',
            'scheduled_at' => $slot->start_at,
            'queue_number' => 'A-090',
            'queue_date' => today()->toDateString(),
        ]);

        $this->console()->call('checkIn', $foreign->id);

        // The foreign ticket must be untouched.
        $this->assertSame(AppointmentStatus::CheckedIn, $foreign->fresh()->status);
    }

    public function test_the_console_is_scoped_to_the_assigned_window_by_default(): void
    {
        $response = $this->actingAs($this->staff)->get('/staff');

        $response->assertOk()->assertSee('Window 1 - Certificates');
    }

    public function test_an_admin_may_open_the_console_for_any_window(): void
    {
        ServiceWindow::factory()->create(['code' => 'WINDOW_2', 'name' => 'Window 2 - Permits']);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/staff')
            ->assertOk();
    }
}
