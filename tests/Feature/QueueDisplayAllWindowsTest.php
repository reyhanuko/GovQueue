<?php

namespace Tests\Feature;

use App\Livewire\Display\PublicQueueDisplay;
use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The lobby display must show every window at once.
 *
 * Reported: the display shows a single window ("Window 1 - Certificates") and no
 * way to see the others, and the closed-office message is shown for the whole
 * display.
 *
 * It used to default to the first active window, so a client waiting for Window 2
 * was looking at a board that had silently decided Window 2 did not matter.
 *
 * What must not change: this screen is unauthenticated and in a public lobby, so
 * it renders queue numbers and nothing else. That is asserted here rather than
 * assumed, because "add more windows to the display" is exactly the kind of
 * change that ends up printing a client name on a television.
 */
class QueueDisplayAllWindowsTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $first;

    private ServiceWindow $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->first = ServiceWindow::factory()->create([
            'code' => 'WINDOW_1',
            'name' => 'Window 1 - Certificates',
        ]);

        $this->second = ServiceWindow::factory()->create([
            'code' => 'WINDOW_2',
            'name' => 'Window 2 - Permits',
        ]);
    }

    private function ticket(ServiceWindow $window, string $number, string $status = 'confirmed'): Appointment
    {
        $service = Service::factory()->create([
            'service_window_id' => $window->id,
            'code' => 'SVC-'.$number,
        ]);

        $start = today()->setTime(9, 0);

        $slot = TimeSlot::create([
            'service_id' => $service->id,
            'service_window_id' => $window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 20,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        return Appointment::factory()->create([
            'user_id' => User::factory()->client()->create(['name' => 'Client Name Here'])->id,
            'service_id' => $service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $window->id,
            'queue_number' => $number,
            'scheduled_at' => $start->toDateTimeString(),
            'queue_date' => $start->toDateString(),
            'status' => $status,
            'serving_started_at' => $status === 'serving' ? now() : null,
        ]);
    }

    /* ------------------------------------------------------------------ */

    public function test_the_board_shows_every_active_window(): void
    {
        $html = $this->get('/queue-display')->assertOk()->getContent();

        $this->assertStringContainsString('Window 1 - Certificates', $html);
        $this->assertStringContainsString('Window 2 - Permits', $html);
    }

    public function test_the_board_shows_the_serving_number_for_each_window(): void
    {
        $this->ticket($this->first, 'A-001', 'serving');
        $this->ticket($this->second, 'B-001', 'serving');

        $html = $this->get('/queue-display')->assertOk()->getContent();

        $this->assertStringContainsString('A-001', $html);
        $this->assertStringContainsString('B-001', $html);
    }

    public function test_an_inactive_window_is_left_off_the_board(): void
    {
        $this->second->update(['is_active' => false]);

        $html = $this->get('/queue-display')->assertOk()->getContent();

        $this->assertStringContainsString('Window 1 - Certificates', $html);
        $this->assertStringNotContainsString('Window 2 - Permits', $html);
    }

    public function test_the_board_can_still_be_scoped_to_one_window(): void
    {
        $html = $this->get('/queue-display?w='.$this->second->code)->assertOk()->getContent();

        $this->assertStringContainsString('Window 2 - Permits', $html);
        $this->assertStringNotContainsString('Window 1 - Certificates', $html);
    }

    /**
     * An unknown code must say so.
     *
     * An earlier attempt resolved an unrecognised code to "no window", which
     * meant a mistyped or retired URL quietly showed the whole board as though
     * nothing were wrong.
     */
    public function test_an_unknown_window_is_reported_rather_than_ignored(): void
    {
        $this->get('/queue-display?w=NOT_A_WINDOW')
            ->assertOk()
            ->assertSee('This service window is not available');
    }

    /**
     * The websocket binding has to cover every window shown.
     *
     * If this attribute only ever carried one code, the board would fall back to
     * its 10-second poll while looking perfectly healthy - a failure with no
     * visible symptom at all.
     */
    public function test_the_board_subscribes_to_every_window_it_shows(): void
    {
        $html = $this->get('/queue-display')->assertOk()->getContent();

        $this->assertStringContainsString(
            'data-window-codes="'.$this->first->code.','.$this->second->code.'"',
            $html,
            'The all-window board is not bound to every window channel.',
        );
    }

    public function test_a_scoped_board_subscribes_only_to_its_own_window(): void
    {
        $html = $this->get('/queue-display?w='.$this->second->code)->assertOk()->getContent();

        $this->assertStringContainsString('data-window-codes="'.$this->second->code.'"', $html);
    }

    /* ------------------------------------------------------------------ */
    /* The invariants that must survive this change */
    /* ------------------------------------------------------------------ */

    /** Numbers and order only. Nothing on this screen may identify a client. */
    public function test_the_board_prints_no_client_name_or_service(): void
    {
        $this->ticket($this->first, 'A-001', 'serving');

        $html = $this->get('/queue-display')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'Client Name Here',
            $html,
            'The lobby display must never print a client name.',
        );

        $this->assertStringNotContainsString(
            'SVC-A-001',
            $html,
            'The lobby display must never print a service name.',
        );
    }

    public function test_the_statutory_basis_is_still_shown_on_the_all_window_board(): void
    {
        // It used to live only in the single-window view, so making that view
        // optional would otherwise have dropped it from the default board.
        $this->get('/queue-display')
            ->assertOk()
            ->assertSee('RA 9994')
            ->assertSee('RA 10754');
    }

    public function test_a_closure_closes_the_whole_board(): void
    {
        Holiday::create(['date' => today()->toDateString(), 'name' => 'Office Maintenance']);

        $this->get('/queue-display')
            ->assertOk()
            ->assertSee('The office is closed today');
    }

    public function test_no_windows_at_all_is_explained(): void
    {
        $this->first->update(['is_active' => false]);
        $this->second->update(['is_active' => false]);

        $this->get('/queue-display')
            ->assertOk()
            ->assertSee('No service windows are set up');
    }

    public function test_the_component_reports_every_window_row(): void
    {
        $this->ticket($this->first, 'A-001', 'serving');

        Livewire::test(PublicQueueDisplay::class)
            ->assertSet('windowCode', '')
            ->assertSee('Window 2 - Permits');

        $component = Livewire::test(PublicQueueDisplay::class);
        $rows = $component->instance()->windowRows();

        $this->assertCount(2, $rows);
        $this->assertSame($this->first->id, $rows->first()['window']->id);
        $this->assertSame('A-001', $rows->first()['serving']->queue_number);
    }
}
