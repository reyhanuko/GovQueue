<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\StaffingCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-window shared budget (decision D1).
 *
 * The reported symptom, verbatim from the audit: "Window 1 has one staff member
 * and three services, so each 30-minute time can hold up to 12 clients against
 * roughly 3. The oversubscription warning is computed per service, so lowering one
 * service to 3 makes its warning disappear while the window is still
 * oversubscribed."
 *
 * So the question is not whether one service can be served. It is whether the
 * window, across all its services, is offering more clients at a time than its
 * clerks can see.
 */
class WindowSharedBudgetTest extends TestCase
{
    use RefreshDatabase;

    private StaffingCapacityService $capacity;

    private ServiceWindow $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capacity = app(StaffingCapacityService::class);

        // One clerk, on purpose. The whole failure mode is a window whose
        // services each look fine and which is collectively far too deep.
        $this->window = ServiceWindow::factory()->unstaffed()->create([
            'code' => 'WINDOW_1',
            'name' => 'Window 1',
        ]);

        User::factory()->staff()->create([
            'service_window_id' => $this->window->id,
            'is_active' => true,
        ]);
    }

    private function service(string $name, int $duration = 20, array $extra = []): Service
    {
        return Service::factory()->create([
            'service_window_id' => $this->window->id,
            'name' => $name,
            'duration_minutes' => $duration,
            'is_active' => true,
            ...$extra,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The budget itself */
    /* ------------------------------------------------------------------ */

    /**
     * floor(1 clerk x 30 minutes / 20 minutes) = 1.
     *
     * floor, not ceil, and the difference matters: ceil would let the window
     * commit to a second client it has no minutes for.
     */
    public function test_the_budget_floors(): void
    {
        $this->service('Barangay Clearance', 20);

        $this->assertSame(1, $this->capacity->sharedBudget($this->window, 30));
    }

    public function test_the_budget_scales_with_staff(): void
    {
        $this->service('Barangay Clearance', 20);

        User::factory()->staff()->create([
            'service_window_id' => $this->window->id,
            'is_active' => true,
        ]);

        // floor(2 x 30 / 20) = 3.
        $this->assertSame(3, $this->capacity->sharedBudget($this->window, 30));
    }

    public function test_the_budget_averages_across_the_services(): void
    {
        $this->service('Short', 10);
        $this->service('Long', 30);

        // Mean of 10 and 30 is 20: floor(1 x 30 / 20) = 1. Averaging the two
        // budgets separately would have given 3 + 1 = 4, which is the number of
        // clients two different clients could be served at once.
        $this->assertSame(20.0, $this->capacity->averageServiceMinutes($this->window));
        $this->assertSame(1, $this->capacity->sharedBudget($this->window, 30));
    }

    public function test_an_unstaffed_window_has_a_zero_budget(): void
    {
        $this->service('Barangay Clearance', 20);

        User::where('service_window_id', $this->window->id)->delete();

        $this->assertSame(0, $this->capacity->sharedBudget($this->window, 30));
    }

    /**
     * A window nobody has configured cannot be measured.
     *
     * null, not zero. A caller reading null as zero would warn that a brand new
     * window is overcommitted, before anyone has entered a service.
     */
    public function test_a_window_with_no_durations_has_no_budget(): void
    {
        $this->assertNull($this->capacity->sharedBudget($this->window, 30));
    }

    /** Inactive services do not compete for the clerk's minutes today. */
    public function test_an_inactive_service_is_excluded_from_the_average(): void
    {
        $this->service('Active Short', 10);
        $this->service('Retired Long', 60, ['is_active' => false]);

        $this->assertSame(10.0, $this->capacity->averageServiceMinutes($this->window));
    }

    /* ------------------------------------------------------------------ */
    /* The reported symptom */
    /* ------------------------------------------------------------------ */

    /**
     * The exact case from the audit, and the reason the aggregate exists.
     *
     * Three services at four places each, one clerk. Every service is inside the
     * per-service check - four places against one clerk who can serve about six in
     * a 30-minute slot - so no individual warning fires. The window is offering
     * twelve against one.
     */
    public function test_three_reasonable_services_still_overcommit_the_window(): void
    {
        foreach (['Tax Payment', 'Document Pickup', 'Barangay Clearance'] as $name) {
            $this->service($name, 20);
        }

        // Each service on its own: fine. One clerk over 30 minutes can absorb
        // ceil(30/20) = 2 of these services' clients, so 2 places each is inside
        // every individual limit...
        $perService = $this->capacity->warning($this->window, 30, 20, 2, 'Tax Payment');
        $this->assertNull($perService, 'A single service at two places should not warn on its own.');

        // ...and the window's real budget is floor(30/20) = 1 for all three of
        // them together. Six offered against one.
        $warning = $this->capacity->aggregateWarning($this->window, 30, 6);

        $this->assertNotNull($warning, 'Six places against one clerk produced no window-level warning.');
        $this->assertStringContainsString('Window 1', $warning);
        $this->assertStringContainsString('6 places', $warning);
    }

    /**
     * The audit's complaint about the fix that existed: "lowering one service to
     * 3 makes its warning disappear while the window is still oversubscribed."
     *
     * Lowering one service does NOT clear the aggregate, which is the whole point.
     */
    public function test_lowering_one_service_does_not_clear_the_window_warning(): void
    {
        foreach (['Tax Payment', 'Document Pickup', 'Barangay Clearance'] as $name) {
            $this->service($name, 20);
        }

        // Administrator lowers one service from 2 to 1.
        $offered = 1 + 2 + 2;

        $this->assertNotNull(
            $this->capacity->aggregateWarning($this->window, 30, $offered),
            'The window warning cleared after lowering one of three services.',
        );
    }

    public function test_a_window_inside_its_budget_produces_no_warning(): void
    {
        $this->service('Tax Payment', 20);

        // Budget is 1, so offering exactly 1 is fine.
        $this->assertNull($this->capacity->aggregateWarning($this->window, 30, 1));
    }

    /** An unconfigured window is not overcommitted; it is unmeasured. */
    public function test_no_warning_when_the_window_has_no_durations(): void
    {
        $this->assertNull(
            $this->capacity->aggregateWarning($this->window, 30, 99),
            'A window with no services was reported as overcommitted.',
        );
    }

    /**
     * The unstaffed case reports differently from the overcommitted one, because
     * the fix is different: assign somebody, versus lower a capacity.
     */
    public function test_an_unstaffed_window_says_nobody_is_assigned(): void
    {
        $this->service('Tax Payment', 20);
        User::where('service_window_id', $this->window->id)->delete();

        $warning = $this->capacity->aggregateWarning($this->window, 30, 12);

        $this->assertNotNull($warning);
        $this->assertStringContainsString('no active staff', $warning);
    }

    /* ------------------------------------------------------------------ */
    /* On the screens */
    /* ------------------------------------------------------------------ */

    public function test_the_daily_slots_screen_shows_the_window_level_warning(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['Tax Payment', 'Document Pickup', 'Barangay Clearance'] as $name) {
            $service = $this->service($name, 20);

            $start = today()->setTime(9, 0);

            TimeSlot::create([
                'service_id' => $service->id,
                'service_window_id' => $this->window->id,
                'slot_date' => $start->toDateString(),
                'start_time' => '09:00:00',
                'end_time' => '09:30:00',
                'start_at' => $start->toDateTimeString(),
                'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
                'capacity' => 4,
                'booked_count' => 0,
                'is_open' => true,
            ]);
        }

        $html = $this->actingAs($admin)->get('/admin/slots')->assertOk()->getContent();

        $this->assertStringContainsString(
            'overcommitted as a whole',
            $html,
            'The daily slots screen did not report the window-level overcommitment.',
        );
    }

    public function test_the_template_screen_shows_the_window_level_warning(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['Tax Payment', 'Document Pickup', 'Barangay Clearance'] as $name) {
            $service = $this->service($name, 20);

            SlotTemplate::create([
                'service_id' => $service->id,
                'day_of_week' => 1,
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
                'slot_minutes' => 30,
                'capacity' => 4,
                'effective_from' => today()->toDateString(),
                'effective_to' => null,
                'is_active' => true,
            ]);
        }

        $last = Service::where('service_window_id', $this->window->id)->first();

        $html = $this->actingAs($admin)
            ->get(route('admin.templates.index', $last))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'overcommitted as a whole',
            $html,
            'The template screen did not report the window-level overcommitment.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* A spent template is not offered */
    /* ------------------------------------------------------------------ */

    /**
     * A template whose range has closed generates nothing.
     *
     * Counting it would keep warning about a problem the administrator has already
     * resolved, which is how a warning screen stops being read.
     */
    public function test_a_spent_template_is_not_counted_as_offered(): void
    {
        $admin = User::factory()->admin()->create();

        $service = $this->service('Tax Payment', 20);

        SlotTemplate::create([
            'service_id' => $service->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'slot_minutes' => 30,
            'capacity' => 50,
            'effective_from' => today()->subYear()->toDateString(),
            'effective_to' => today()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.templates.index', $service))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'overcommitted as a whole',
            $html,
            'A template that ended yesterday was still counted as offered capacity.',
        );
    }
}
