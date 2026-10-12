<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\ScheduleAdminService;
use App\Services\SlotAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The cached closed-date list, and the invalidation that keeps it honest.
 *
 * closedDates() was memoised in a plain instance array. That looked like caching
 * and was not: the service is resolved fresh from the container at most call
 * sites, so the memo died with the instance and every call still issued a holidays
 * query. Backing it with the application cache makes the memo real - which then
 * obliges the system to invalidate it, because a declared holiday that takes
 * effect a request late means the catalogue advertises a day the office is shut.
 */
class HolidayCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = Service::factory()->create(['is_active' => true]);

        Cache::forget(SlotAvailabilityService::closedDatesCacheKey());
    }

    private function slotOn(string $date): TimeSlot
    {
        $start = Carbon::parse($date)->setTime(9, 0);

        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->service->service_window_id,
            'slot_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 5,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    public function test_the_closed_dates_are_fetched_once_across_separate_instances(): void
    {
        $this->slotOn(today()->addDay()->toDateString());

        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'holidays')) {
                $queries++;
            }
        });

        // Three *separate* resolutions, which is what the container does per call
        // site. An instance-only memo would issue three queries here.
        app(SlotAvailabilityService::class)->closedDates();
        app(SlotAvailabilityService::class)->closedDates();
        app(SlotAvailabilityService::class)->closedDates();

        $this->assertSame(
            1,
            $queries,
            "The holidays table was queried {$queries} times across three service "
            .'instances; the cached list should be fetched once.'
        );
    }

    public function test_declaring_a_holiday_takes_effect_immediately(): void
    {
        $date = today()->addDay();

        $this->slotOn($date->toDateString());

        // Warm the cache while the day is still bookable.
        $this->assertNotEmpty(
            app(SlotAvailabilityService::class)->availabilityFor($this->service),
            'Sanity: the harness should start with the day bookable.'
        );

        // An administrator declares the closure.
        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/holidays', [
                'date' => $date->toDateString(),
                'name' => 'Declared holiday',
            ]);

        // The very next read must reflect it. If this failed, the catalogue would
        // keep offering a day the office has just been told is shut.
        $this->assertEmpty(
            app(SlotAvailabilityService::class)->availabilityFor($this->service),
            'A day declared a holiday was still being offered for booking.'
        );

        $this->assertSame(
            0,
            app(SlotAvailabilityService::class)->openSlotCount($this->service),
            'The open-slot count still advertised a declared holiday.'
        );
    }

    public function test_removing_a_holiday_takes_effect_immediately(): void
    {
        $date = today()->addDay();

        $this->slotOn($date->toDateString());

        // Created through the service, not the model, so the cache is invalidated
        // exactly as it would be in production. A bare Holiday::create() bypasses
        // the invalidation by design - the service is the entry point that owns it.
        app(ScheduleAdminService::class)->createHoliday([
            'date' => $date->toDateString(),
            'name' => 'Pre-existing',
        ]);

        // Warm the cache with the day closed.
        $this->assertSame(
            0,
            app(SlotAvailabilityService::class)->openSlotCount($this->service),
            'Sanity: the day should start closed.'
        );

        $holiday = Holiday::whereDate('date', $date)->firstOrFail();

        $this->actingAs(User::factory()->admin()->create())
            ->delete('/admin/holidays/'.$holiday->id);

        // And the reverse case: a stale cache here keeps hiding a bookable day,
        // which reads to a client as "there is nothing available".
        $this->assertNotEmpty(
            app(SlotAvailabilityService::class)->availabilityFor($this->service),
            'Removing a holiday did not make the day bookable again.'
        );
    }

    public function test_the_same_service_instance_sees_its_own_invalidation(): void
    {
        // The in-memory half of the memo matters within a single request: an
        // administrator who declares a holiday and then re-reads the catalogue is
        // served the same instance.
        $service = app(SlotAvailabilityService::class);
        $date = today()->addDay();

        // Warm the cache with nothing closed.
        $service->closedDates();

        app(ScheduleAdminService::class)->createHoliday([
            'date' => $date->toDateString(),
            'name' => 'Same-instance',
        ]);

        $this->assertContains(
            $date->toDateString(),
            $service->closedDates(),
            'The same instance kept serving its pre-declaration list after a holiday '
            .'was declared. Clearing only the shared cache would not help here.'
        );

        // And the reverse, on the same instance.
        $holiday = Holiday::whereDate('date', $date)->firstOrFail();
        app(ScheduleAdminService::class)->deleteHoliday($holiday);

        $this->assertNotContains(
            $date->toDateString(),
            $service->closedDates(),
            'The same instance kept a removed holiday in its in-memory copy.'
        );
    }
}
