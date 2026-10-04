<?php

namespace Tests\Unit;

use App\Models\Holiday;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Services\SlotGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlotGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    private SlotGeneratorService $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new SlotGeneratorService;
    }

    private function makeTemplate(array $overrides = []): SlotTemplate
    {
        $window = ServiceWindow::factory()->create();
        $service = Service::factory()->create(['service_window_id' => $window->id]);

        return SlotTemplate::create(array_merge([
            'service_id' => $service->id,
            'day_of_week' => 1, // Monday
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'slot_minutes' => 30,
            'capacity' => 4,
            'effective_from' => today()->startOfYear()->toDateString(),
            'is_active' => true,
        ], $overrides));
    }

    public function test_it_chops_a_time_range_into_fixed_length_slots(): void
    {
        $this->makeTemplate(); // 08:00-10:00 in 30 minute steps = 4 slots

        $result = $this->generator->generate('next Monday', 'next Monday');

        $this->assertSame(4, $result['created']);
        $this->assertSame(0, $result['skipped']);

        $slots = TimeSlot::forDate('next Monday')->orderBy('start_time')->get();

        $this->assertSame(['08:00:00', '08:30:00', '09:00:00', '09:30:00'],
            $slots->pluck('start_time')->all());
        // 08:00-10:00 chopped in 30 minute steps ends exactly at 10:00:00.
        $this->assertSame('10:00:00', $slots->last()->end_at->format('H:i:s'));
        $this->assertSame('09:30:00', $slots->last()->start_at->format('H:i:s'));
    }

    public function test_it_does_not_emit_a_slot_that_would_overrun_the_end_time(): void
    {
        // 08:00-09:00 in 30 minute steps must yield 2 slots, not 3.
        $this->makeTemplate(['end_time' => '09:00:00']);

        $result = $this->generator->generate('next Monday', 'next Monday');

        $this->assertSame(2, $result['created']);
    }

    public function test_it_skips_days_the_template_does_not_cover(): void
    {
        $this->makeTemplate(['day_of_week' => 1]); // Mondays only

        $result = $this->generator->generate('next Monday', 'next Sunday');

        $this->assertSame(4, $result['created']);
        $this->assertSame(0, TimeSlot::forDate('next Tuesday')->count());
    }

    public function test_it_skips_declared_holidays(): void
    {
        $this->makeTemplate();
        Holiday::create(['date' => 'next Monday', 'name' => 'Office Maintenance']);

        $result = $this->generator->generate('next Monday', 'next Monday');

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, TimeSlot::forDate('next Monday')->count());
    }

    public function test_it_never_duplicates_a_slot_on_a_second_run(): void
    {
        $this->makeTemplate();

        $first = $this->generator->generate('next Monday', 'next Monday');
        $second = $this->generator->generate('next Monday', 'next Monday');

        $this->assertSame(4, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(4, $second['skipped']);
        $this->assertSame(4, TimeSlot::forDate('next Monday')->count());
    }

    public function test_rerunning_never_lowers_capacity_below_what_is_already_booked(): void
    {
        $template = $this->makeTemplate(['capacity' => 4]);
        $this->generator->generate('next Monday', 'next Monday');

        $slot = TimeSlot::forDate('next Monday')->first();
        $slot->update(['booked_count' => 4]);

        // An administrator lowers the template capacity to 1.
        $template->update(['capacity' => 1]);
        $this->generator->generate('next Monday', 'next Monday');

        $this->assertSame(4, $slot->fresh()->capacity,
            'Capacity must not drop below the number of clients already booked.');
    }

    public function test_generated_slots_carry_the_service_window(): void
    {
        $template = $this->makeTemplate();

        $this->generator->generate('next Monday', 'next Monday');

        $slot = TimeSlot::forDate('next Monday')->first();

        $this->assertSame($template->service->service_window_id, $slot->service_window_id);
    }

    public function test_remaining_capacity_tracks_bookings(): void
    {
        $this->makeTemplate();
        $this->generator->generate('next Monday', 'next Monday');

        $slot = TimeSlot::forDate('next Monday')->first();
        $this->assertSame(4, $slot->remainingCapacity());

        $slot->increment('booked_count');
        $this->assertSame(3, $slot->fresh()->remainingCapacity());
    }

    public function test_a_full_slot_is_not_bookable(): void
    {
        $this->makeTemplate();
        $this->generator->generate('next Monday', 'next Monday');

        // Fill every generated slot, not just the first.
        TimeSlot::forDate('next Monday')->update(['booked_count' => 4]);

        $this->assertTrue(TimeSlot::forDate('next Monday')->first()->fresh()->isFull());
        $this->assertFalse(TimeSlot::forDate('next Monday')->first()->fresh()->isBookable());
        $this->assertSame(0, TimeSlot::bookable()->forDate('next Monday')->count());
    }

    public function test_a_closed_slot_is_not_bookable_even_with_space_free(): void
    {
        $this->makeTemplate();
        $this->generator->generate('next Monday', 'next Monday');

        TimeSlot::forDate('next Monday')->update(['is_open' => false]);

        // Each generated slot keeps its own end time, so verify per-row rather
        // than from a single representative row.

        $this->assertSame(0, TimeSlot::bookable()->forDate('next Monday')->count());
    }
}
