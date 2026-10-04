<?php

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\SlotAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlotAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private SlotAvailabilityService $service;

    private Service $catalogueService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SlotAvailabilityService;
        $this->catalogueService = Service::factory()->create();
    }

    private function slot(string $date, string $start, int $capacity = 4, int $booked = 0): TimeSlot
    {
        return TimeSlot::create([
            'service_id' => $this->catalogueService->id,
            'service_window_id' => $this->catalogueService->service_window_id,
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

    public function test_it_groups_slots_by_date(): void
    {
        $this->slot(today()->addDay()->toDateString(), '09:00:00');
        $this->slot(today()->addDay()->toDateString(), '09:30:00');
        $this->slot(today()->addDays(2)->toDateString(), '09:00:00');

        $availability = $this->service->availabilityFor($this->catalogueService);

        $this->assertCount(2, $availability);
        $this->assertCount(2, $availability->first());
    }

    public function test_it_hides_fully_booked_days(): void
    {
        // A day where every slot is full should not be offered at all.
        $this->slot(today()->addDay()->toDateString(), '09:00:00', capacity: 2, booked: 2);
        $this->slot(today()->addDays(2)->toDateString(), '09:00:00');

        $availability = $this->service->availabilityFor($this->catalogueService);

        $this->assertCount(1, $availability);
        $this->assertSame(today()->addDays(2)->toDateString(), $availability->keys()->first());
    }

    public function test_it_keeps_partially_booked_days_with_remaining_places(): void
    {
        $this->slot(today()->addDay()->toDateString(), '09:00:00', capacity: 4, booked: 3);

        $availability = $this->service->availabilityFor($this->catalogueService);

        $this->assertCount(1, $availability);
        $this->assertSame(1, $availability->first()->first()->remainingCapacity());
    }

    public function test_it_excludes_closed_holidays(): void
    {
        $date = today()->addDays(3);
        $this->slot($date->toDateString(), '09:00:00');
        Holiday::create(['date' => $date->toDateString(), 'name' => 'Office Maintenance']);

        $availability = $this->service->availabilityFor($this->catalogueService);

        $this->assertCount(0, $availability);
    }

    public function test_it_excludes_slots_in_the_past(): void
    {
        // Yesterday's slots exist but must never be offered.
        $this->slot(today()->subDay()->toDateString(), '09:00:00');

        $availability = $this->service->availabilityFor($this->catalogueService);

        $this->assertCount(0, $availability);
    }

    public function test_it_excludes_closed_slots(): void
    {
        // A slot an administrator closed is never offered, even with space free.
        $slot = $this->slot(today()->addDay()->toDateString(), '09:00:00');
        $slot->update(['is_open' => false]);

        $this->assertCount(0, $this->service->availabilityFor($this->catalogueService));
    }

    public function test_a_day_with_one_bookable_slot_is_still_offered(): void
    {
        $this->slot(today()->addDay()->toDateString(), '09:00:00', capacity: 1, booked: 1);
        $this->slot(today()->addDay()->toDateString(), '09:30:00');

        $availability = $this->service->availabilityFor($this->catalogueService);

        $this->assertCount(1, $availability);
        $this->assertCount(1, $availability->first(),
            'Only the slot with space left should appear under that day.');
    }

    public function test_it_respects_the_booking_horizon(): void
    {
        $this->slot(today()->addDays(config('govqueue.booking_horizon_days') + 5)->toDateString(), '09:00:00');
        $this->slot(today()->addDays(2)->toDateString(), '09:00:00');

        $availability = $this->service->availabilityFor($this->catalogueService);

        $this->assertCount(1, $availability);
    }

    public function test_next_available_slot_skips_full_and_past_slots(): void
    {
        $this->slot(today()->addDay()->toDateString(), '09:00:00', capacity: 1, booked: 1);
        $this->slot(today()->addDays(2)->toDateString(), '09:00:00');

        $next = $this->service->nextAvailableSlot($this->catalogueService);

        $this->assertNotNull($next);
        $this->assertSame(today()->addDays(2)->toDateString(), $next->slot_date->toDateString());
    }

    public function test_next_available_slot_is_null_when_fully_booked(): void
    {
        $this->slot(today()->addDay()->toDateString(), '09:00:00', capacity: 1, booked: 1);

        $this->assertNull($this->service->nextAvailableSlot($this->catalogueService));
    }

    public function test_open_slot_count_reflects_bookable_rows(): void
    {
        $this->slot(today()->addDay()->toDateString(), '09:00:00');
        $this->slot(today()->addDays(2)->toDateString(), '09:00:00', capacity: 1, booked: 1);

        $this->assertSame(1, $this->service->openSlotCount($this->catalogueService));
    }

    public function test_it_detects_an_active_booking_for_a_service(): void
    {
        $user = User::factory()->client()->create();
        $slot = $this->slot(today()->addDay()->toDateString(), '09:00:00');

        $this->assertFalse($this->service->hasActiveBooking($user->id, $this->catalogueService));

        Appointment::factory()->create([
            'user_id' => $user->id,
            'service_id' => $this->catalogueService->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->catalogueService->service_window_id,
            'scheduled_at' => $slot->start_at,
            'status' => 'confirmed',
        ]);

        $this->assertTrue($this->service->hasActiveBooking($user->id, $this->catalogueService));
    }

    public function test_a_cancelled_appointment_does_not_count_as_an_active_booking(): void
    {
        $user = User::factory()->client()->create();
        $slot = $this->slot(today()->addDay()->toDateString(), '09:00:00');

        Appointment::factory()->cancelled()->create([
            'user_id' => $user->id,
            'service_id' => $this->catalogueService->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->catalogueService->service_window_id,
            'scheduled_at' => $slot->start_at,
        ]);

        $this->assertFalse($this->service->hasActiveBooking($user->id, $this->catalogueService));
    }

    public function test_it_lists_the_slots_a_client_already_booked_on_a_date(): void
    {
        $user = User::factory()->client()->create();
        $other = User::factory()->client()->create();

        $mine = $this->slot(today()->addDay()->toDateString(), '09:00:00');
        $theirs = $this->slot(today()->addDay()->toDateString(), '09:30:00');

        foreach ([[$mine, $user], [$theirs, $other]] as [$slot, $owner]) {
            Appointment::factory()->create([
                'user_id' => $owner->id,
                'service_id' => $this->catalogueService->id,
                'time_slot_id' => $slot->id,
                'service_window_id' => $this->catalogueService->service_window_id,
                'scheduled_at' => $slot->start_at,
                'status' => 'confirmed',
            ]);
        }

        $ids = $this->service->bookedSlotIdsFor($user->id, today()->addDay()->toDateString());

        $this->assertSame([$mine->id], $ids);
    }

    public function test_day_labels_are_human_readable(): void
    {
        $this->assertSame('Today', $this->service->dayLabel(today()->toDateString()));
        $this->assertSame('Tomorrow', $this->service->dayLabel(today()->addDay()->toDateString()));
        $this->assertMatchesRegularExpression(
            '/^[A-Z][a-z]{2}, [A-Z][a-z]{2} \d{2}$/',
            $this->service->dayLabel(today()->addDays(5)->toDateString()),
        );
    }
}
