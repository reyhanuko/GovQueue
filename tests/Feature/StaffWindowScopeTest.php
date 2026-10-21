<?php

namespace Tests\Feature;

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

/**
 * Can a staffer reach another window's queue by asking for it?
 *
 * A staffer is pinned to their own counter, and not by presentation: the
 * console is where the guard has to hold, because every number in it - the
 * appointment id in a method call, the window code in a property - arrives from
 * the browser and can be set to anything.
 *
 * The dangerous shape here is that guardWindow() compares a ticket against
 * $this->window, which is derived from $this->windowCode. If a crafted request
 * could move windowCode to another window and act in the same breath, the guard
 * would be comparing the ticket against the attacker's chosen window and would
 * pass. So the property change and the action are tested together, not apart.
 */
class StaffWindowScopeTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $mine;

    private ServiceWindow $theirs;

    private User $staff;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = today()->toDateString();

        $this->mine = ServiceWindow::factory()->create([
            'name' => 'Window 1 - Certificates',
            'code' => 'WINDOW_1',
        ]);

        $this->theirs = ServiceWindow::factory()->create([
            'name' => 'Window 2 - Permits',
            'code' => 'WINDOW_2',
        ]);

        $this->staff = User::factory()->staff()->create([
            'service_window_id' => $this->mine->id,
        ]);
    }

    private function ticket(ServiceWindow $window, string $number = 'A-001'): Appointment
    {
        $service = Service::factory()->create(['service_window_id' => $window->id]);
        $start = now()->setTime(9, 0);

        $slot = TimeSlot::create([
            'service_id' => $service->id,
            'service_window_id' => $window->id,
            'slot_date' => $this->today,
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        return Appointment::factory()->create([
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $service->id,
            'service_window_id' => $window->id,
            'time_slot_id' => $slot->id,
            'scheduled_at' => $start->toDateTimeString(),
            'queue_date' => $this->today,
            'status' => AppointmentStatus::CheckedIn,
            'queue_number' => $number,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The console is pinned */
    /* ------------------------------------------------------------------ */

    public function test_their_console_opens_on_their_own_window(): void
    {
        $this->actingAs($this->staff);

        Livewire::test(QueueConsole::class)
            ->assertSet('windowCode', $this->mine->code);
    }

    /**
     * The property change and the action, in one request.
     *
     * Testing them separately would prove nothing: the guard reads the window
     * that windowCode resolves to, so the only question that matters is whether
     * a request can move it and act before the reset hook pulls it back.
     */
    public function test_setting_the_window_code_to_another_window_is_refused(): void
    {
        $this->actingAs($this->staff);

        $component = Livewire::test(QueueConsole::class)
            ->set('windowCode', $this->theirs->code);

        $component->assertSet('windowCode', $this->mine->code);
    }

    public function test_they_cannot_read_another_windows_queue(): void
    {
        $foreign = $this->ticket($this->theirs, '2-001');

        $this->actingAs($this->staff);

        Livewire::test(QueueConsole::class)
            ->assertDontSee($foreign->queue_number);
    }

    /* ------------------------------------------------------------------ */
    /* Acting on another window's tickets */
    /* ------------------------------------------------------------------ */

    public function test_they_cannot_call_a_client_from_another_window(): void
    {
        $foreign = $this->ticket($this->theirs, '2-001');

        $this->actingAs($this->staff);

        Livewire::test(QueueConsole::class)
            ->call('callBack', $foreign->id)
            ->assertSet('error', 'That ticket belongs to a different service window.');

        $this->assertDatabaseHas('appointments', [
            'id' => $foreign->id,
            'status' => AppointmentStatus::CheckedIn->value,
        ]);
    }

    public function test_they_cannot_skip_a_client_from_another_window(): void
    {
        $foreign = $this->ticket($this->theirs, '2-001');

        $this->actingAs($this->staff);

        Livewire::test(QueueConsole::class)
            ->call('skip', $foreign->id)
            ->assertSet('error', 'That ticket belongs to a different service window.');

        $this->assertDatabaseHas('appointments', [
            'id' => $foreign->id,
            'status' => AppointmentStatus::CheckedIn->value,
        ]);
    }

    public function test_they_cannot_check_in_a_client_from_another_window(): void
    {
        $foreign = $this->ticket($this->theirs, '2-001');

        $this->actingAs($this->staff);

        Livewire::test(QueueConsole::class)
            ->call('checkIn', $foreign->id)
            ->assertSet('error', 'That ticket belongs to a different service window.');
    }

    public function test_they_cannot_verify_priority_on_another_windows_ticket(): void
    {
        $foreign = $this->ticket($this->theirs, '2-001');
        $foreign->update(['claims_senior_citizen' => true]);

        $this->actingAs($this->staff);

        // The id is set the way openVerify() would, because that is the value a
        // crafted request supplies directly.
        Livewire::test(QueueConsole::class)
            ->set('verifyingId', $foreign->id)
            ->call('verifyPriority')
            ->assertSet('error', 'That ticket belongs to a different service window.');

        $this->assertNull($foreign->fresh()->priority_verified_at);
    }

    public function test_they_cannot_refuse_priority_on_another_windows_ticket(): void
    {
        $foreign = $this->ticket($this->theirs, '2-001');
        $foreign->update(['claims_senior_citizen' => true]);

        $this->actingAs($this->staff);

        Livewire::test(QueueConsole::class)
            ->set('verifyingId', $foreign->id)
            ->call('refusePriority')
            ->assertSet('error', 'That ticket belongs to a different service window.');
    }

    /* ------------------------------------------------------------------ */
    /* Their own window still works */
    /* ------------------------------------------------------------------ */

    public function test_they_can_still_act_on_their_own_ticket(): void
    {
        $own = $this->ticket($this->mine, '1-001');

        $this->actingAs($this->staff);

        // skip(), not callBack(): callBack is for recalling somebody already
        // being served, and a checked-in ticket is correctly refused by it -
        // which is a rule, not a window-scope failure.
        Livewire::test(QueueConsole::class)
            ->call('skip', $own->id)
            ->assertSet('error', null);

        $this->assertDatabaseHas('appointments', [
            'id' => $own->id,
            'status' => AppointmentStatus::Skipped->value,
        ]);
    }
}
