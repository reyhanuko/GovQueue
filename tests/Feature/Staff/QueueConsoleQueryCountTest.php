<?php

namespace Tests\Feature\Staff;

use App\Livewire\Staff\QueueConsole;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * How many queries one console render costs.
 *
 * This exists because the console was doing 39 queries per render and nothing
 * about that is visible from reading the code. Every computed here is individually
 * reasonable; the cost came from how they were called:
 *
 *  - `#[Computed]` memoises *property* access. Calling `$this->window()` as a
 *    method bypasses the memo entirely, and the component did that from every
 *    other computed - so the same window row was fetched 15 times and the same
 *    ordered queue query ran 4 times.
 *  - The memo is `$value ??= ...`, so a computed returning null is never cached.
 *    The empty-window case - the most common state at a counter between clients -
 *    re-ran the lookup on every single access.
 *  - The window's 30-day average was measured twice per render, once for the
 *    headline figure and again inside estimateWaits().
 *
 * The count is pinned rather than the symptom so the next regression is a failing
 * number rather than a slow counter nobody noticed.
 */
class QueueConsoleQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    private User $staff;

    private int $slotCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create([
            'code' => 'WINDOW_1',
            'avg_service_minutes' => 10,
        ]);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
        $this->staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);
    }

    /** A queue of $count clients waiting, to make an N+1 actually visible. */
    private function fillQueue(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->slotCounter++;
            $start = today()->setTime(9, 0)->addMinutes($this->slotCounter * 30);

            $slot = TimeSlot::create([
                'service_id' => $this->service->id,
                'service_window_id' => $this->window->id,
                'slot_date' => today()->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
                'start_at' => $start->toDateTimeString(),
                'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
                'capacity' => 50,
                'booked_count' => 0,
                'is_open' => true,
            ]);

            Appointment::create([
                'reference_number' => 'GQ-QC-'.uniqid(),
                'user_id' => User::factory()->client()->create()->id,
                'service_id' => $this->service->id,
                'time_slot_id' => $slot->id,
                'service_window_id' => $this->window->id,
                'status' => 'checked_in',
                'queue_number' => 'A-'.$this->slotCounter,
                'queue_date' => today()->toDateString(),
                'checked_in_at' => now(),
            ]);
        }
    }

    /**
     * Run one render and return how many queries it issued.
     *
     * The listener is attached after setUp so the fixture queries above are not
     * counted; only what the component itself does is.
     */
    private function queriesForRender(): int
    {
        // Warm-up render, not counted. The window's derived service time is
        // memoised for the life of the resolved instance, so a cold first render
        // pays for that lookup and a warm one does not. Measuring a cold render
        // makes this test compare "first request" against "second request"
        // rather than what it is about.
        Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, [
                'windowCode' => $this->window->code,
                'queueDate' => today()->toDateString(),
            ])
            ->assertOk();

        $count = 0;

        DB::listen(function () use (&$count) {
            $count++;
        });

        Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, [
                'windowCode' => $this->window->code,
                'queueDate' => today()->toDateString(),
            ])
            ->assertOk();

        return $count;
    }

    public function test_a_render_costs_a_fixed_number_of_queries(): void
    {
        $this->fillQueue(12);

        $queries = $this->queriesForRender();

        // Ten: the window list, the selected window, one grouped status count,
        // the waiting queue plus its two eager loads, the window's average service
        // time, whoever is at the window, the not-yet-arrived list, and the list
        // of people already served today.
        //
        // The last one is the "Served today" list. A clerk working a counter
        // needs to see who they have already dealt with, and that is a
        // different set of rows from the queue - so it is one more query, and
        // it is still a fixed number.
        $this->assertLessThanOrEqual(
            10,
            $queries,
            "The console render issued {$queries} queries; the budget is 10."
        );
    }

    public function test_the_query_count_does_not_grow_with_the_queue_length(): void
    {
        // The actual N+1 guard. A per-client query would show up here and nowhere
        // else: the render above would still be under the budget with 12 clients.
        $this->fillQueue(4);
        $small = $this->queriesForRender();

        $this->fillQueue(16);
        $large = $this->queriesForRender();

        $this->assertSame(
            $small,
            $large,
            "The console issued {$small} queries for 4 waiting clients and {$large} for 20. "
            .'Waiting clients are loaded in one query with their user and service.'
        );
    }

    public function test_an_empty_window_is_not_cheaper_than_a_busy_one(): void
    {
        // The null-memo case. Nothing being served is the common state at a
        // counter between clients, so this is the path most likely to regress.
        $empty = $this->queriesForRender();

        $this->fillQueue(6);
        $busy = $this->queriesForRender();

        $this->assertLessThanOrEqual(
            9,
            $empty,
            "An empty window issued {$empty} queries. A computed returning null is not "
            .'memoised, so the "now serving" lookup ran once per access in the blade.'
        );

        // A busy window legitimately costs more than an empty one: rendering six
        // clients needs their users and services, which is two extra eager-load
        // queries no matter how many clients there are. What must not happen is a
        // per-client cost, which the previous test rules out.
        $this->assertLessThanOrEqual(
            $empty + 2,
            $busy,
            "An empty window cost {$empty} queries and a six-client window {$busy}. "
            .'Only the two eager loads should differ.'
        );
    }

    public function test_the_average_service_time_is_measured_once_per_render(): void
    {
        // The headline "avg service" figure and every client's estimate are shown on
        // the same screen. Measuring the 30-day average twice could let a completion
        // land between the two reads and produce a headline that disagrees with the
        // numbers printed beneath it.
        $this->fillQueue(8);

        $measurements = 0;

        DB::listen(function ($query) use (&$measurements) {
            if (str_contains($query->sql, 'TIMESTAMPDIFF(MINUTE, serving_started_at')) {
                $measurements++;
            }
        });

        Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, [
                'windowCode' => $this->window->code,
                'queueDate' => today()->toDateString(),
            ])
            ->assertOk();

        $this->assertSame(
            1,
            $measurements,
            "The window's average service time was measured {$measurements} times in one render."
        );
    }
}
