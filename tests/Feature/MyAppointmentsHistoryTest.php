<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The client's appointment list, and how its history is presented.
 *
 * Reported as: history is one long list running off the bottom of the screen,
 * and a booking cancelled today for a date next month sits at the very bottom
 * of it - furthest from the thing that just happened.
 *
 * That second part was a direct consequence of the sort. One query ordered
 * "scheduled_at IS NULL, scheduled_at ASC" served both halves of the page, so
 * history came out oldest-first, and the most recent event in it was the last
 * row on the page.
 *
 * Also covered: history was an unbounded get() of every appointment the client
 * has ever had, rendered as one table.
 */
class MyAppointmentsHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Service $service;

    private ServiceWindow $window;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->client()->create();
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
    }

    private function appointment(string $date, string $status, ?User $user = null): Appointment
    {
        $this->counter++;

        $start = Carbon::parse($date)->setTime(9, $this->counter * 10);
        $user ??= $this->client;

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 50,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        return Appointment::factory()->create([
            'user_id' => $user->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'scheduled_at' => $start->toDateTimeString(),
            'queue_date' => $start->toDateString(),
            'status' => $status,
        ]);
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->client)->get(route('client.appointments.index', $query));
    }

    /* ------------------------------------------------------------------ */
    /* Order */
    /* ------------------------------------------------------------------ */

    /**
     * The reported bug, stated as a property.
     */
    public function test_history_runs_newest_first(): void
    {
        $old = $this->appointment(now()->subMonths(8)->toDateString(), 'completed');
        $middle = $this->appointment(now()->subMonths(2)->toDateString(), 'cancelled');
        $new = $this->appointment(now()->subWeek()->toDateString(), 'completed');

        $html = $this->page()->assertOk()->getContent();

        $positions = array_map(
            fn (Appointment $a) => strpos($html, $a->reference_number),
            [$new, $middle, $old],
        );

        $this->assertNotContains(false, $positions, 'A reference was missing from the page entirely.');

        // Sorted ascending by position means the array was in newest-first order.
        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions, 'History is not in newest-first order.');
    }

    public function test_a_cancellation_for_a_future_date_is_at_the_top_not_the_bottom(): void
    {
        $old = $this->appointment(now()->subYear()->toDateString(), 'completed');
        $futureCancellation = $this->appointment(now()->addMonth()->toDateString(), 'cancelled');

        $html = $this->page()->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, $old->reference_number),
            strpos($html, $futureCancellation->reference_number),
            'A cancellation made today for a future date is buried under a year-old '
                .'appointment, which is the opposite of where it belongs.',
        );
    }

    public function test_upcoming_still_runs_soonest_first(): void
    {
        $far = $this->appointment(now()->addMonth()->toDateString(), 'confirmed');
        $near = $this->appointment(now()->addDay()->toDateString(), 'confirmed');

        $html = $this->page()->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, $far->reference_number),
            strpos($html, $near->reference_number),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Pagination */
    /* ------------------------------------------------------------------ */

    public function test_history_is_paginated_rather_than_one_unbounded_list(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->appointment(now()->subDays(30 - $i)->toDateString(), 'completed');
        }

        $first = $this->page()->assertOk();

        // Twelve records, eight to a page, so the first page cannot contain them
        // all and a next page has to exist.
        $this->assertSame(8, $first->viewData('history')->count());
        $this->assertTrue($first->viewData('history')->hasMorePages());
        $this->assertSame(12, $first->viewData('history')->total());
    }

    public function test_the_second_page_holds_the_remainder_and_not_the_first_page_again(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->appointment(now()->subDays(30 - $i)->toDateString(), 'completed');
        }

        $pageOne = $this->page()->viewData('history')->pluck('id')->all();
        $pageTwo = $this->page(['page' => 2])->assertOk()->viewData('history')->pluck('id')->all();

        $this->assertEmpty(array_intersect($pageOne, $pageTwo));
    }

    public function test_the_page_says_how_much_there_is(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->appointment(now()->subDays(30 - $i)->toDateString(), 'completed');
        }

        $this->page()
            ->assertOk()
            ->assertSee('of 12');
    }

    /* ------------------------------------------------------------------ */
    /* Filtering */
    /* ------------------------------------------------------------------ */

    public function test_history_can_be_filtered_by_status(): void
    {
        $cancelled = $this->appointment(now()->subDay()->toDateString(), 'cancelled');
        $completed = $this->appointment(now()->subDays(2)->toDateString(), 'completed');

        $html = $this->page(['status' => 'cancelled'])->assertOk()->getContent();

        $this->assertStringContainsString($cancelled->reference_number, $html);
        $this->assertStringNotContainsString($completed->reference_number, $html);
    }

    public function test_a_filter_that_matches_nothing_says_so_instead_of_claiming_no_history(): void
    {
        $this->appointment(now()->subDay()->toDateString(), 'completed');

        $this->page(['status' => 'cancelled'])
            ->assertOk()
            ->assertSee('No cancelled appointments')
            ->assertSee('Show all history');
    }

    public function test_an_unknown_filter_is_ignored_rather_than_returning_nothing(): void
    {
        $appointment = $this->appointment(now()->subDay()->toDateString(), 'completed');

        // A hand-edited query string must not be able to blank the page. Asserted
        // on the record being present, not on the "of N" summary, which only
        // renders when history runs to more than one page.
        $this->page(['status' => 'nonsense'])
            ->assertOk()
            ->assertSee($appointment->reference_number);
    }

    /* ------------------------------------------------------------------ */

    public function test_the_empty_history_wording_no_longer_says_transactions(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Nothing in your history yet')
            ->assertDontSee('transactions');
    }

    public function test_another_clients_appointments_never_appear(): void
    {
        $mine = $this->appointment(now()->subDay()->toDateString(), 'completed');

        $theirs = $this->appointment(
            now()->subDay()->toDateString(),
            'completed',
            User::factory()->client()->create(),
        );

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString($mine->reference_number, $html);
        $this->assertStringNotContainsString($theirs->reference_number, $html);
    }
}
