<?php

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Queue-number integrity.
 *
 * The queue number is what staff call out and what the public display shows, so
 * two clients sharing one is a serious defect: staff could not tell the tickets
 * apart. These tests cover the paths by which numbers can drift out of step
 * with the tickets actually in existence.
 */
class QueueNumberIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $booking;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->booking = new BookingService;
        $this->service = Service::factory()->create();
    }

    private int $slotCounter = 0;

    private function slot(array $overrides = []): TimeSlot
    {
        // Each call gets its own 30 minute step: time_slots has a unique index
        // on (service, date, start_time), so reusing a time would collide.
        $this->slotCounter++;
        $start = $overrides['start_at']
            ?? now()->addDay()->setTime(9, 0)->addMinutes(($this->slotCounter - 1) * 30)->toDateTimeString();

        return TimeSlot::create(array_merge([
            'service_id' => $this->service->id,
            'service_window_id' => $this->service->service_window_id,
            'slot_date' => substr($start, 0, 10),
            'start_time' => substr($start, 11, 8),
            'end_time' => date('H:i:s', strtotime($start) + 1800),
            'start_at' => $start,
            'end_at' => date('Y-m-d H:i:s', strtotime($start) + 1800),
            'capacity' => 10,
            'booked_count' => 0,
            'is_open' => true,
        ], $overrides));
    }

    public function test_a_booking_never_reuses_a_number_already_on_the_display(): void
    {
        // Regression: tickets inserted outside BookingService (demo seed data, a
        // manual import) left no queue_counters row, so the next real booking
        // restarted the sequence at A-001 and collided with a live ticket.
        $day = now()->addDay()->toDateString();
        $legacySlot = $this->slot();

        Appointment::create([
            'reference_number' => 'GQ-LEGACY-0001',
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $legacySlot->id,
            'service_window_id' => $this->service->service_window_id,
            'status' => 'serving',
            'source' => 'online',
            'scheduled_at' => $legacySlot->start_at,
            'queue_number' => 'A-001',
            'queue_date' => $day,
        ]);

        $this->assertSame(0, DB::table('queue_counters')->count(),
            'Precondition: the legacy ticket has no backing counter row.');

        $new = $this->booking->book(User::factory()->client()->create(), $this->service, $this->slot()->id);

        $this->assertNotSame('A-001', $new->queue_number,
            'The new booking must not reuse a number that is already on the display.');

        $collides = Appointment::where('service_window_id', $new->service_window_id)
            ->whereDate('queue_date', $day)
            ->where('queue_number', $new->queue_number)
            ->where('id', '!=', $new->id)
            ->exists();

        $this->assertFalse($collides);
    }

    public function test_a_counter_that_has_fallen_behind_self_heals(): void
    {
        $day = now()->addDay()->toDateString();

        // Book through the service, then rewind the counter as if data had been
        // edited or restored from a partial backup.
        $first = $this->booking->book(User::factory()->client()->create(), $this->service, $this->slot()->id);
        $this->assertSame('A-001', $first->queue_number);

        DB::table('queue_counters')->update(['last_number' => 0]);

        $next = $this->booking->book(User::factory()->client()->create(), $this->service, $this->slot()->id);

        $this->assertSame('A-002', $next->queue_number,
            'The counter must recover from its own tickets rather than reissue A-001.');
    }

    public function test_walk_in_numbers_do_not_consume_the_a_sequence(): void
    {
        // Walk-ins share the physical line but use a W- prefix. They must not
        // push an online booking's number along.
        $day = now()->addDay()->toDateString();
        $slot = $this->slot();

        foreach (range(1, 3) as $i) {
            Appointment::create([
                'reference_number' => 'GQ-WALK-000'.$i,
                'user_id' => User::factory()->client()->create()->id,
                'service_id' => $this->service->id,
                'time_slot_id' => $slot->id,
                'service_window_id' => $this->service->service_window_id,
                'status' => 'checked_in',
                'source' => 'walk_in',
                'scheduled_at' => null,
                'queue_number' => 'W-00'.$i,
                'queue_date' => $day,
            ]);
        }

        $booking = $this->booking->book(User::factory()->client()->create(), $this->service, $this->slot()->id);

        $this->assertSame('A-001', $booking->queue_number);
    }

    public function test_numbers_are_unique_across_a_whole_day_of_bookings(): void
    {
        $numbers = collect();

        foreach (range(1, 12) as $i) {
            $numbers->push($this->booking->book(
                User::factory()->client()->create(),
                $this->service,
                $this->slot()->id,
            )->queue_number);
        }

        $this->assertCount(12, $numbers->unique(), 'Duplicate queue numbers: '.$numbers->implode(', '));
    }

    public function test_the_counter_row_is_created_on_first_booking(): void
    {
        $this->assertSame(0, DB::table('queue_counters')->count());

        $this->booking->book(User::factory()->client()->create(), $this->service, $this->slot()->id);

        $this->assertSame(1, DB::table('queue_counters')->count());
        $this->assertSame(1, (int) DB::table('queue_counters')->value('last_number'));
    }
}
