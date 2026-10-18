<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The daily slots screen, seen as an administrator using it.
 *
 * Reported as F-24: the page is very long (hundreds of rows), it saves per row,
 * the Open checkbox auto-submits with nothing to confirm and nothing to undo, the
 * same warning is repeated per service, and there is no way to collapse a window
 * or see a summary of it.
 *
 * Two of the five were already addressed by the F-21 work - filters for the long
 * page, and per-window counts. What is asserted here is the remaining behaviour,
 * including the two choices made about it: long windows collapse, short ones do
 * not, and the Open toggle is confirmed rather than applied on a bare click.
 */
class DailySlotsUxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceWindow $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
    }

    /**
     * N services, one slot each, on tomorrow.
     *
     * @return Collection<int, Service>
     */
    private function servicesWithSlots(int $count, ?string $date = null)
    {
        $date ??= today()->addDay()->toDateString();

        return collect(range(1, $count))->map(function (int $n) use ($date) {
            $service = Service::factory()->create([
                'service_window_id' => $this->window->id,
                'code' => 'SVC-'.$n,
                'name' => 'Service '.$n,
                'is_active' => true,
            ]);

            $start = Carbon::parse($date)->setTime(9, 0);

            TimeSlot::create([
                'service_id' => $service->id,
                'service_window_id' => $this->window->id,
                'slot_date' => $date,
                'start_time' => $start->format('H:i:s'),
                'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
                'start_at' => $start->toDateTimeString(),
                'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
                'capacity' => 5,
                'booked_count' => 0,
                'is_open' => true,
            ]);

            return $service;
        });
    }

    private function screen(?string $date = null, array $query = [])
    {
        $query['date'] = $date ?? today()->addDay()->toDateString();

        return $this->actingAs($this->admin)->get('/admin/slots?'.http_build_query($query));
    }

    /* ------------------------------------------------------------------ */
    /* Collapse */
    /* ------------------------------------------------------------------ */

    public function test_a_short_window_stays_open(): void
    {
        $this->servicesWithSlots(3);

        $html = $this->screen()->assertOk()->getContent();

        // A handful of rows must not need a click to read. Matched on the
        // disclosure's own tag rather than on the word "open" appearing nearby,
        // which would match almost anything on this page.
        $this->assertMatchesRegularExpression(
            '/<details class="mt-6 rounded-xl border border-slate-200 bg-white"\s+open\s*>/',
            $html,
            'A window with 3 rows should have been open, not collapsed.',
        );
    }

    public function test_a_long_window_collapses_to_its_summary(): void
    {
        $this->servicesWithSlots(12);

        $html = $this->screen()->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/<details class="mt-6 rounded-xl border border-slate-200 bg-white"\s+open\s*>/',
            $html,
            'A window with 12 rows should have collapsed rather than opened.',
        );
    }

    /** The summary is what a collapsed window shows, so it has to be there. */
    public function test_the_summary_states_places_booked_and_staff_on_duty(): void
    {
        $this->servicesWithSlots(12);

        $html = $this->screen()->assertOk()->getContent();

        $this->assertStringContainsString('booked of', $html);
        $this->assertStringContainsString('places', $html);
        $this->assertStringContainsString('staff on duty', $html);
    }

    public function test_the_collapse_threshold_is_not_scattered_through_the_template(): void
    {
        // One named number on the controller, so "long" can be argued with in one
        // place rather than hunted for in markup.
        $this->assertStringContainsString(
            "'collapseAfterRows' => 10",
            file_get_contents(app_path('Http/Controllers/Admin/TimeSlotController.php')),
        );
    }

    /* ------------------------------------------------------------------ */
    /* The repeated warning */
    /* ------------------------------------------------------------------ */

    public function test_the_staffing_warning_is_stated_once_per_window_not_per_row(): void
    {
        // Over-capacity warnings need a window whose staff cannot serve the
        // offered places. No staff at all is the simplest version.
        $this->servicesWithSlots(12);

        $html = $this->screen()->assertOk()->getContent();

        $this->assertSame(
            0,
            substr_count($html, 'More places than this window can staff'),
            'The same sentence is repeated on each row again.',
        );
    }

    public function test_affected_rows_are_still_marked_visually(): void
    {
        $this->servicesWithSlots(12);

        $html = $this->screen()->assertOk()->getContent();

        // A non-verbal marker, so the warning survives a greyscale print and a
        // screen reader.
        $this->assertStringContainsString('sr-only', $html);
    }

    /* ------------------------------------------------------------------ */
    /* The Open toggle */
    /* ------------------------------------------------------------------ */

    public function test_the_open_toggle_is_confirmed_before_it_submits(): void
    {
        $this->servicesWithSlots(2);

        // One slot of each state. The confirmation text is a ternary, so only one
        // of the two sentences ever renders for a given slot - asserting both
        // without both states would pass only by accident.
        TimeSlot::query()->orderByDesc('id')->skip(1)->take(1)->update(['is_open' => false]);

        $html = $this->screen()->assertOk()->getContent();

        $this->assertStringContainsString('onchange="this.form.submit()"', $html);

        // ...but the form carrying it is guarded. Without this, a single click
        // closed a slot with no confirmation and no way back.
        $this->assertStringContainsString('Close this slot', $html);
        $this->assertStringContainsString('Open this slot', $html);
    }

    /**
     * The thing somebody toggling this is actually worried about.
     *
     * Closing a slot must not read as cancelling the clients already in it, so
     * the confirmation has to say so.
     */
    public function test_the_confirmation_says_bookings_are_not_cancelled(): void
    {
        $this->servicesWithSlots(2);

        $this->screen()
            ->assertOk()
            ->assertSee('Clients already booked are not cancelled', false);
    }

    /** A past day is view-only, and must offer no control that looks live. */
    public function test_a_past_day_still_offers_nothing_to_toggle(): void
    {
        $this->servicesWithSlots(2, today()->subDay()->toDateString());

        $html = $this->screen(today()->subDay()->toDateString())->assertOk()->getContent();

        $this->assertStringNotContainsString('onchange="this.form.submit()"', $html);
        $this->assertStringContainsString('view only', $html);
    }

    /* ------------------------------------------------------------------ */
    /* The sticky header */
    /* ------------------------------------------------------------------ */

    public function test_the_date_stays_visible_while_scrolling_a_long_day(): void
    {
        $this->servicesWithSlots(12);

        $this->screen()
            ->assertOk()
            ->assertSee('sticky', false);
    }
}
