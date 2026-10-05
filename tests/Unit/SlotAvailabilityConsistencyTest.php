<?php

namespace Tests\Unit;

use App\Models\Holiday;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Services\SlotAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Bookable" must mean one thing.
 *
 * Three answers to the same question were built by hand - the booking grid, the
 * catalogue's open-slot count, and the service page's "next available" hint - and
 * they drifted apart:
 *
 *  - the hint ignored the booking horizon, so with a 30-day horizon it advertised
 *    a slot 40 days out: a date the booking screen then refused to open, while a
 *    free slot three days away went unmentioned.
 *  - the count and the hint ignored declared holidays, so the catalogue could
 *    claim availability on a day the office was shut.
 *
 * These tests pin the agreement rather than each method in isolation, because the
 * bug was precisely that they were tested separately.
 */
class SlotAvailabilityConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private SlotAvailabilityService $availability;

    private Service $service;

    private int $horizon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->availability = new SlotAvailabilityService;
        $this->service = Service::factory()->create();
        $this->horizon = (int) config('govqueue.booking_horizon_days');
    }

    private function slot(int $daysFromToday, string $start = '09:00:00', int $capacity = 4, int $booked = 0): TimeSlot
    {
        $date = today()->addDays($daysFromToday)->toDateString();

        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->service->service_window_id,
            'slot_date' => $date,
            'start_time' => $start,
            'end_time' => date('H:i:s', strtotime($start) + 1800),
            'start_at' => $date.' '.$start,
            'end_at' => date('Y-m-d H:i:s', strtotime($date.' '.$start) + 1800),
            'capacity' => $capacity,
            'booked_count' => $booked,
            'is_open' => true,
        ]);
    }

    /** Every slot the service page would advertise must be one the grid offers. */
    private function assertHintAgreesWithGrid(): void
    {
        $grid = $this->availability->availabilityFor($this->service);
        $hint = $this->availability->nextAvailableSlot($this->service);

        if ($hint === null) {
            $this->assertTrue(
                $grid->isEmpty(),
                'The service page says nothing is available, but the grid offers '
                    .$grid->flatten()->count().' slot(s).'
            );

            return;
        }

        $date = $hint->slot_date->toDateString();

        $this->assertTrue(
            $grid->has($date),
            "The service page advertises {$date}, but the booking grid does not offer that day."
        );
    }

    public function test_the_hint_never_points_beyond_the_booking_horizon(): void
    {
        // The reported symptom: a slot the client is not allowed to book.
        $this->slot($this->horizon + 10);

        $this->assertNull(
            $this->availability->nextAvailableSlot($this->service),
            'A slot past the booking horizon must not be offered as the next available.'
        );

        $this->assertSame(0, $this->availability->openSlotCount($this->service));
        $this->assertTrue($this->availability->availabilityFor($this->service)->isEmpty());
    }

    public function test_the_hint_prefers_an_in_horizon_slot_over_a_far_away_one(): void
    {
        $soon = $this->slot(3, '09:00:00');
        $this->slot($this->horizon + 10, '09:00:00');

        $this->assertSame(
            $soon->id,
            $this->availability->nextAvailableSlot($this->service)?->id,
            'An in-horizon slot three days out should be offered before one 40 days out.'
        );

        $this->assertHintAgreesWithGrid();
    }

    public function test_a_declared_holiday_is_not_counted_as_open(): void
    {
        Holiday::create(['date' => today()->addDays(2)->toDateString(), 'name' => 'Probe Holiday']);
        $this->slot(2);

        $this->assertSame(0, $this->availability->openSlotCount($this->service));
        $this->assertNull($this->availability->nextAvailableSlot($this->service));
        $this->assertHintAgreesWithGrid();
    }

    public function test_a_holiday_does_not_hide_the_other_open_days(): void
    {
        $open = $this->slot(2, '09:00:00');
        Holiday::create(['date' => today()->addDays(4)->toDateString(), 'name' => 'Probe Holiday']);
        $this->slot(4, '10:00:00');

        $this->assertSame(1, $this->availability->openSlotCount($this->service));
        $this->assertSame(
            $open->id,
            $this->availability->nextAvailableSlot($this->service)?->id
        );
        $this->assertHintAgreesWithGrid();
    }

    public function test_the_hint_skips_a_closed_slot(): void
    {
        $open = $this->slot(1, '08:00:00');
        $this->slot(2, '10:00:00', capacity: 4, booked: 4);

        $this->assertSame($open->id, $this->availability->nextAvailableSlot($this->service)?->id);
        $this->assertHintAgreesWithGrid();
    }

    public function test_the_hint_and_count_agree_with_the_grid_for_a_mixed_service(): void
    {
        // One slot too early, one full, one closed for a holiday, one bookable.
        $this->slot(-1, '08:00:00');
        $this->slot(1, '09:00:00', capacity: 2, booked: 2);
        Holiday::create(['date' => today()->addDays(2)->toDateString(), 'name' => 'Probe Holiday']);
        $this->slot(2, '10:00:00');
        $good = $this->slot(3, '11:00:00');

        $this->assertSame(1, $this->availability->openSlotCount($this->service));
        $this->assertSame($good->id, $this->availability->nextAvailableSlot($this->service)?->id);
        $this->assertHintAgreesWithGrid();
    }

    public function test_an_explicit_horizon_override_is_respected_by_all_three(): void
    {
        $this->slot(5);
        $this->slot(20);

        $this->assertSame(2, $this->availability->openSlotCount($this->service, 30));
        $this->assertSame(1, $this->availability->openSlotCount($this->service, 7));
        // One slot (day 5) is inside a 7-day horizon; the day-20 one is not.
        $this->assertSame(1, $this->availability->availabilityFor($this->service, 7)->flatten()->count());
        $this->assertSame(2, $this->availability->availabilityFor($this->service, 30)->flatten()->count());
        $this->assertNotNull($this->availability->nextAvailableSlot($this->service, 7));
    }
}
