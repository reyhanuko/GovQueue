<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Services\SlotAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The catalogue's batched availability must equal the per-service answers.
 *
 * Sprint 10 batched the public catalogue from two queries per service down to
 * two queries for the whole page (34 -> 6 on a catalogue of 8). The risk that
 * introduces is drift: if the batched query is built separately from the
 * single-service one, the catalogue can start advertising availability that the
 * booking screen refuses - which is precisely the bug the shared bookableQuery
 * was introduced to prevent in Sprint 8.
 *
 * These tests hold the two paths against each other deliberately.
 */
class CatalogueAvailabilityBatchingTest extends TestCase
{
    use RefreshDatabase;

    private SlotAvailabilityService $availability;

    private ServiceWindow $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->availability = new SlotAvailabilityService;
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
    }

    private function service(string $code): Service
    {
        return Service::factory()->create([
            'service_window_id' => $this->window->id,
            'code' => $code,
            'is_active' => true,
        ]);
    }

    private function slot(Service $service, int $daysAhead, string $start, array $overrides = []): TimeSlot
    {
        $date = today()->addDays($daysAhead)->toDateString();

        return TimeSlot::create(array_merge([
            'service_id' => $service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $date,
            'start_time' => $start,
            'end_time' => date('H:i:s', strtotime($start) + 1800),
            'start_at' => $date.' '.$start,
            'end_at' => date('Y-m-d H:i:s', strtotime($date.' '.$start) + 1800),
            'capacity' => 4,
            'booked_count' => 0,
            'is_open' => true,
        ], $overrides));
    }

    /* ------------------------------------------------------------------ */

    public function test_the_batched_counts_match_the_per_service_counts(): void
    {
        $a = $this->service('BATCH-A');
        $b = $this->service('BATCH-B');
        $c = $this->service('BATCH-C');

        $this->slot($a, 1, '09:00:00');
        $this->slot($a, 1, '09:30:00');
        $this->slot($b, 1, '09:00:00');
        // c has nothing at all.

        $services = collect([$a, $b, $c]);
        $batched = $this->availability->catalogueAvailabilityFor($services);

        foreach ($services as $service) {
            $this->assertSame(
                $this->availability->openSlotCount($service),
                (int) ($batched['counts']->get($service->id) ?? 0),
                "Batched count disagrees for {$service->code}."
            );
        }
    }

    public function test_the_batched_next_slot_matches_the_per_service_one(): void
    {
        $a = $this->service('NEXT-A');
        $b = $this->service('NEXT-B');

        $late = $this->slot($a, 5, '14:00:00');
        $early = $this->slot($a, 1, '08:00:00');
        $onlyB = $this->slot($b, 2, '11:00:00');

        $batched = $this->availability->catalogueAvailabilityFor(collect([$a, $b]));

        $this->assertSame(
            $early->id,
            $batched['next']->get($a->id)?->id,
            'The batched hint must be the earliest slot, not merely one of them.'
        );
        $this->assertSame(
            $onlyB->id,
            $batched['next']->get($b->id)?->id
        );

        $this->assertSame($this->availability->nextAvailableSlot($a)?->id, $batched['next']->get($a->id)?->id);
        $this->assertSame($this->availability->nextAvailableSlot($b)?->id, $batched['next']->get($b->id)?->id);
    }

    public function test_the_batched_path_excludes_holidays_exactly_as_the_single_service_path(): void
    {
        $service = $this->service('HOL-BATCH');

        $open = $this->slot($service, 1, '09:00:00');
        $this->slot($service, 2, '09:00:00');

        Holiday::create(['date' => today()->addDay()->toDateString(), 'name' => 'Probe Closure']);

        $batched = $this->availability->catalogueAvailabilityFor(collect([$service]));

        $this->assertSame(
            $this->availability->openSlotCount($service),
            (int) ($batched['counts']->get($service->id) ?? 0)
        );
        $this->assertSame(
            $this->availability->nextAvailableSlot($service)?->id,
            $batched['next']->get($service->id)?->id,
            'A holiday must be excluded from the batched hint too.'
        );
    }

    public function test_the_batched_path_respects_capacity_and_closure(): void
    {
        $service = $this->service('CAP-BATCH');

        $this->slot($service, 1, '09:00:00', ['capacity' => 2, 'booked_count' => 2]); // full
        $this->slot($service, 1, '09:30:00', ['is_open' => false]);                 // closed
        $this->slot($service, 2, '09:00:00');                                        // open

        $batched = $this->availability->catalogueAvailabilityFor(collect([$service]));

        $this->assertSame(1, (int) ($batched['counts']->get($service->id) ?? 0));
        $this->assertSame(
            $this->availability->nextAvailableSlot($service)?->id,
            $batched['next']->get($service->id)?->id
        );
    }

    public function test_the_batched_path_respects_the_booking_horizon(): void
    {
        $service = $this->service('HORIZON-BATCH');
        $days = (int) config('govqueue.booking_horizon_days');

        $this->slot($service, 1, '09:00:00');
        $this->slot($service, $days + 5, '09:00:00'); // beyond the horizon

        $batched = $this->availability->catalogueAvailabilityFor(collect([$service]));

        $this->assertSame(1, (int) ($batched['counts']->get($service->id) ?? 0));
        $this->assertSame(
            $this->availability->nextAvailableSlot($service)?->id,
            $batched['next']->get($service->id)?->id
        );
    }

    public function test_an_empty_catalogue_costs_nothing(): void
    {
        $batched = $this->availability->catalogueAvailabilityFor(collect());

        $this->assertTrue($batched['counts']->isEmpty());
        $this->assertTrue($batched['next']->isEmpty());
    }

    public function test_the_catalogue_page_still_renders_real_numbers(): void
    {
        $service = $this->service('RENDER-BATCH');
        $this->slot($service, 1, '09:00:00');
        $this->slot($service, 2, '09:00:00');

        $this->get('/services')
            ->assertOk()
            ->assertSee('RENDER-BATCH')
            ->assertSee('2 open');
    }

    /* ------------------------------------------------------------------ */

    public function test_holidays_are_fetched_once_per_request_not_once_per_service(): void
    {
        // Without request memoisation this issued one holidays query per service.
        $services = collect(range(1, 5))->map(fn ($i) => $this->service('HOL-CACHE-'.$i));

        DB::enableQueryLog();
        $this->availability->catalogueAvailabilityFor($services);
        $holidayQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'holidays'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(
            1,
            $holidayQueries,
            'The closure list should be fetched once for the whole page, not once per service.'
        );
    }
}
