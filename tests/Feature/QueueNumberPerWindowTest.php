<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\QueueCounter;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Two windows must never issue the same queue number.
 *
 * The prefix was hardcoded to 'A-', so Window 1 and Window 2 both produced
 * A-001 on the same day. The lobby display shows numbers and nothing else, so
 * two windows showing A-001 is a board that cannot answer the one question it
 * exists for - which number belongs to which counter.
 *
 * This went unnoticed because SeededDataIntegrityTest checks for duplicates per
 * (window, date, number), and per window there was never a duplicate. The
 * collision only ever existed *between* windows, which nothing asserted.
 */
class QueueNumberPerWindowTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->booking = app(BookingService::class);
    }

    private function window(string $code, string $prefix): ServiceWindow
    {
        return ServiceWindow::factory()->create([
            'code' => $code,
            'queue_prefix' => $prefix,
        ]);
    }

    private function slot(Service $service, ServiceWindow $window, string $date, string $time = '09:00'): TimeSlot
    {
        $start = Carbon::parse($date)->setTime(...explode(':', $time));

        return TimeSlot::create([
            'service_id' => $service->id,
            'service_window_id' => $window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    private function book(ServiceWindow $window, string $date): Appointment
    {
        $service = Service::factory()->create(['service_window_id' => $window->id]);
        $client = User::factory()->client()->create();

        $slot = $this->slot($service, $window, $date);

        return $this->booking->book($client, $service, $slot->id);
    }

    public function test_two_windows_never_issue_the_same_number_on_the_same_day(): void
    {
        $one = $this->window('WINDOW_1', '1');
        $two = $this->window('WINDOW_2', '2');

        $date = today()->addDay()->toDateString();

        $first = $this->book($one, $date);
        $second = $this->book($two, $date);

        $this->assertSame('1-001', $first->queue_number);
        $this->assertSame('2-001', $second->queue_number);

        // The regression itself, stated as the property rather than the values.
        $this->assertNotSame(
            $first->queue_number,
            $second->queue_number,
            'Two windows issued the same number, so the lobby board cannot tell them apart.',
        );
    }

    public function test_the_number_keeps_counting_within_a_window(): void
    {
        $window = $this->window('WINDOW_1', '1');
        $date = today()->addDay()->toDateString();

        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $numbers[] = $this->book($window, $date)->queue_number;
        }

        $this->assertSame(['1-001', '1-002', '1-003'], $numbers);
    }

    /**
     * The self-healing counter has to parse the window's own prefix.
     *
     * highestQueueNumberInUse() used substr(…, 2), which is right for a
     * two-character prefix and wrong for a one-character one: on "12-001" it
     * would read "2-001" as 2001 and skip four thousand numbers.
     */
    public function test_a_multi_character_prefix_is_parsed_correctly(): void
    {
        $window = $this->window('WINDOW_12', '12');
        $date = today()->addDay()->toDateString();

        $this->book($window, $date);

        // Force the counter behind the tickets, which is what the self-heal path
        // exists for, and check it recovers the right number rather than a
        // number derived from a mis-parsed one.
        QueueCounter::query()->update(['last_number' => 0]);

        $this->assertSame('12-002', $this->book($window, $date)->queue_number);
    }

    public function test_a_window_with_a_blank_prefix_keeps_the_original_behaviour(): void
    {
        // The column is NOT NULL and defaults to 'A', so a window always has
        // one; the reachable bad value is an empty string, from a form that
        // posted a blank field. It must not issue a number with no prefix.
        $window = ServiceWindow::factory()->create([
            'code' => 'WINDOW_LEGACY',
            'queue_prefix' => '  ',
        ]);

        $this->assertSame(
            'A-001',
            $this->book($window, today()->addDay()->toDateString())->queue_number,
        );
    }

    /**
     * The counter has to be seeded from the window's own series.
     *
     * The counter row is created on the first booking of the day and seeded
     * from the highest number already in use - but it looked for the default
     * 'A-' series whatever prefix the window issues. On a window that issues
     * '2-', a day that already had '2-005' on it - an import, a seed, a ticket
     * inserted outside the service - produced a counter seeded at 1, and the
     * next booking was handed "2-001": a number already on the display, with
     * nothing anywhere to catch it.
     */
    public function test_a_window_continues_its_own_series_when_the_day_already_has_tickets(): void
    {
        $window = $this->window('WINDOW_2', '2');
        $date = today()->addDay()->toDateString();

        $service = Service::factory()->create(['service_window_id' => $window->id]);
        $slot = $this->slot($service, $window, $date);

        // A ticket already issued today under this window's prefix, with no
        // counter row for the day - which is what an import or a seed leaves.
        Appointment::create([
            'reference_number' => 'GQ-IMPORTED-1',
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $window->id,
            'status' => 'confirmed',
            'source' => 'online',
            'scheduled_at' => $slot->start_at,
            'queue_number' => '2-005',
            'queue_date' => $date,
            'confirmed_at' => now(),
        ]);

        $client = User::factory()->client()->create();

        $this->assertSame(
            '2-006',
            $this->booking->book($client, $service, $slot->id)->queue_number,
            'The window issued a number that was already on the display.',
        );
    }

    /**
     * A walk-in must not consume a number from the window's series.
     *
     * The walk-in path takes the counter's row lock - both numbering paths
     * contend on the same row - and creating that row seeded it as if a number
     * had already been issued. The first online booking of the day then
     * started at 002, and 001 was never on the board for anybody to wait
     * behind: a hole in the sequence, on the one screen that shows numbers and
     * nothing else.
     */
    public function test_a_walk_in_does_not_consume_the_first_number_of_the_day(): void
    {
        // Frozen at 08:00 so the day's first slot is still ahead of the clock
        // and the test does not depend on what time of day it runs.
        $this->travelTo(Carbon::parse(today()->toDateString().' 08:00'));

        $window = $this->window('WINDOW_1', 'A');
        $service = Service::factory()->create(['service_window_id' => $window->id]);

        // A walk-in is filed under the slot at the counter, so the service
        // needs one today that has already started.
        $this->slot($service, $window, today()->toDateString(), '07:00');

        $this->booking->createWalkIn(
            ['name' => 'Walk In Client', 'service_id' => $service->id],
            null,
        );

        $counter = QueueCounter::where('service_window_id', $window->id)
            ->whereDate('queue_date', today()->toDateString())
            ->first();

        $this->assertNotNull($counter, 'The walk-in did not take the counter lock at all.');
        $this->assertSame(
            0,
            $counter->last_number,
            'The walk-in consumed a number from the window\'s series.',
        );
    }
}
