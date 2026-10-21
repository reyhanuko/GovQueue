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

    private function book(ServiceWindow $window, string $date): Appointment
    {
        $service = Service::factory()->create(['service_window_id' => $window->id]);
        $client = User::factory()->client()->create();

        $start = Carbon::parse($date)->setTime(9, 0);

        $slot = TimeSlot::create([
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
}
