<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the catalogue says about a service that cannot be booked.
 *
 * Reported as a service with no slot templates at all showing "Fully booked for
 * now. New slots are added daily. Walk-ins are still accepted at the office."
 *
 * Both halves of that are wrong for an unscheduled service. It is not full - it
 * has no timetable, so "new slots are added daily" promises something nobody
 * has committed to. And the walk-in line is a claim the system has no way to
 * make true or false: there is no per-service walk-in setting at all, so it is
 * asserted about every service unconditionally.
 */
class ServiceScheduleStateTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);

        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'code' => 'UNSCHEDULED',
            'name' => 'Barangay Clearance',
            'is_active' => true,
        ]);
    }

    private function template(array $overrides = []): SlotTemplate
    {
        // Built directly rather than through a factory: there is no
        // SlotTemplateFactory, and adding one for a single test is more than
        // this needs.
        return SlotTemplate::create(array_merge([
            'service_id' => $this->service->id,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'slot_minutes' => 30,
            'capacity' => 5,
            'effective_from' => today()->toDateString(),
            'is_active' => true,
        ], $overrides));
    }

    public function test_an_unscheduled_service_is_not_reported_as_fully_booked(): void
    {
        $response = $this->get(route('services.index'));

        $response->assertOk();
        $response->assertDontSee('Fully booked for now');
        $response->assertSee('No schedule yet');
    }

    public function test_an_unscheduled_service_does_not_promise_daily_new_slots(): void
    {
        // "New slots are added daily" is a commitment. On a service with no
        // timetable, nobody has made it.
        $this->get(route('services.index'))
            ->assertOk()
            ->assertDontSee('New slots are added daily');
    }

    public function test_the_unscheduled_service_is_still_not_bookable(): void
    {
        $this->get(route('services.index'))
            ->assertOk()
            ->assertDontSee('Book a slot');
    }

    public function test_the_detail_page_does_not_claim_a_walk_in_policy(): void
    {
        $this->get(route('services.show', $this->service))
            ->assertOk()
            ->assertDontSee('Walk-ins are still accommodated');
    }

    public function test_a_scheduled_service_with_no_open_slots_is_still_reported_as_full(): void
    {
        // The other half of the distinction, and the one an earlier attempt at
        // this fix got wrong: a template alone does not make a service
        // "scheduled" for booking purposes. What matters is slots existing. A
        // service whose slots are all taken IS full, and calling it
        // unscheduled would be its own lie.
        TimeSlot::factory()->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => today()->addDay()->toDateString(),
            'start_at' => now()->addDay()->setTime(9, 0),
            'end_at' => now()->addDay()->setTime(9, 30),
            'capacity' => 2,
            'booked_count' => 2,
            'is_open' => true,
        ]);

        $response = $this->get(route('services.index'));

        $response->assertOk();
        $response->assertSee('Fully booked for now');
        $response->assertDontSee('No schedule yet');
    }

    /**
     * The walk-in claim is gone from both pages.
     *
     * Not because walk-ins are wrong - the office does accept them - but
     * because there is no per-service setting behind the sentence, so it was
     * an unconditional claim the system could not honour or retract. Making it
     * conditional needs a column, which is decision territory and is raised
     * separately rather than assumed here.
     */
    public function test_no_page_asserts_walk_ins_are_accepted_without_a_source_of_truth(): void
    {
        $this->template();

        foreach ([route('services.index'), route('services.show', $this->service)] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee('Walk-ins are still accepted')
                ->assertDontSee('Walk-ins are still accommodated');
        }
    }

    public function test_a_client_still_sees_a_booking_link_when_slots_exist(): void
    {
        $client = User::factory()->client()->create();

        $slot = TimeSlot::factory()->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => today()->toDateString(),
            'start_at' => now()->addHour()->startOfHour(),
            'end_at' => now()->addHour()->startOfHour()->copy()->addMinutes(30),
            'capacity' => 4,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        $this->actingAs($client)
            ->get(route('services.index'))
            ->assertOk()
            ->assertSee('Book a slot');
    }
}
