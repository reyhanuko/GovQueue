<?php

namespace Tests\Unit;

use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\User;
use App\Services\StaffingCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The staffing check behind item 7: "places per slot" versus what a window can
 * actually serve.
 */
class StaffingCapacityServiceTest extends TestCase
{
    use RefreshDatabase;

    private StaffingCapacityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StaffingCapacityService;
    }

    public function test_one_clerk_and_a_thirty_minute_slot_serves_two_twenty_minute_clients(): void
    {
        // ceil(1 staff x 30 min / 20 min) = 2
        $this->assertSame(2, $this->service->placesServable(1, 30, 20));
    }

    public function test_a_service_filling_the_whole_slot_serves_one_client(): void
    {
        $this->assertSame(1, $this->service->placesServable(1, 30, 30));
    }

    public function test_a_short_service_serves_more_clients(): void
    {
        // ceil(1 x 30 / 10) = 3
        $this->assertSame(3, $this->service->placesServable(1, 30, 10));
    }

    public function test_a_second_clerk_doubles_what_the_window_can_serve(): void
    {
        // The whole point of the check: adding a clerk moves the number, which is
        // why it is derived rather than hard-coded.
        $this->assertSame(6, $this->service->placesServable(2, 30, 10));
    }

    public function test_an_unstaffed_window_can_serve_nobody(): void
    {
        $this->assertSame(0, $this->service->placesServable(0, 30, 20));
    }

    public function test_a_fractional_result_rounds_up_because_the_slot_still_books_two(): void
    {
        // ceil(1 x 30 / 40) = 1, not 0: a slot that can absorb 0.75 of a client
        // can still book one.
        $this->assertSame(1, $this->service->placesServable(1, 30, 40));
    }

    public function test_a_window_with_no_staff_is_reported_as_a_problem(): void
    {
        $window = ServiceWindow::factory()->create();

        $warning = $this->service->warning($window, 30, 20, 4, 'Barangay Clearance');

        $this->assertNotNull($warning);
        $this->assertStringContainsString('no active staff', $warning);
    }

    public function test_a_reasonable_capacity_produces_no_warning(): void
    {
        $window = ServiceWindow::factory()->create();
        User::factory()->staff()->create(['service_window_id' => $window->id, 'is_active' => true]);

        $this->assertNull($this->service->warning($window, 30, 20, 2, 'Barangay Clearance'));
    }

    public function test_an_over_committed_capacity_warns_and_names_the_window(): void
    {
        $window = ServiceWindow::factory()->create(['name' => 'Window 2 - Permits']);
        User::factory()->staff()->create(['service_window_id' => $window->id, 'is_active' => true]);

        $warning = $this->service->warning($window, 30, 20, 4, 'Barangay Clearance');

        $this->assertNotNull($warning);
        $this->assertStringContainsString('Window 2 - Permits', $warning);
        $this->assertStringContainsString('about 2 clients', $warning);
        $this->assertStringContainsString('offers 4 places', $warning);
    }

    public function test_only_active_staff_count_toward_capacity(): void
    {
        $window = ServiceWindow::factory()->create();

        User::factory()->staff()->create(['service_window_id' => $window->id, 'is_active' => false]);

        // A deactivated clerk cannot serve anybody, so the window is unstaffed
        // for this purpose even though a staff row still points at it.
        $this->assertSame(0, $this->service->staffCount($window));
    }

    public function test_the_warning_never_blocks_and_only_reports(): void
    {
        $window = ServiceWindow::factory()->create();
        User::factory()->staff()->create(['service_window_id' => $window->id, 'is_active' => true]);
        Service::factory()->create([
            'service_window_id' => $window->id,
            'duration_minutes' => 30,
        ]);

        $this->assertTrue($this->service->isOverCapacity(1, 30, 30, 5));
        $this->assertFalse($this->service->isOverCapacity(1, 30, 30, 1));
    }
}
