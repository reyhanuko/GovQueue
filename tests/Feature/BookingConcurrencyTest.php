<?php

namespace Tests\Feature;

use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the slot capacity lock holds under genuine concurrency.
 *
 * This test deliberately does NOT use RefreshDatabase: the workers run in
 * separate OS processes and cannot see rows inside the test runner's
 * uncommitted transaction. It migrates the shared test database once, works
 * with committed rows, and cleans up after itself.
 *
 * Run explicitly because it is slow:
 *     php artisan test --filter=BookingConcurrencyTest
 */
class BookingConcurrencyTest extends TestCase
{
    private const RACERS = 6;

    /** Rows this test created, removed in tearDown. */
    private array $createdUserIds = [];

    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->canForkProcesses()) {
            $this->markTestSkipped('proc_open is unavailable in this environment.');
        }

        Artisan::call('migrate', ['--force' => true]);

        $window = ServiceWindow::create([
            'name' => 'Race Window',
            'code' => 'RACE-'.getmypid(),
            'avg_service_minutes' => 10,
            'is_active' => true,
        ]);

        $service = Service::create([
            'service_window_id' => $window->id,
            'name' => 'Race Service',
            'code' => 'RACE-SVC-'.getmypid(),
            'fee' => 0,
            'duration_minutes' => 10,
            'is_active' => true,
        ]);

        $this->serviceId = $service->id;

        $this->createdUserIds = User::factory()
            ->count(self::RACERS)
            ->client()
            ->create()
            ->pluck('id')
            ->all();
    }

    protected function tearDown(): void
    {
        // Scoped hard by the sentinel codes created in setUp so nothing else in
        // the shared test database is touched.
        $services = Service::where('code', 'RACE-SVC-'.getmypid())->pluck('id');

        if ($services->isNotEmpty()) {
            Appointment::whereIn('service_id', $services)->delete();
            DB::table('time_slots')->whereIn('service_id', $services)->delete();
            DB::table('queue_counters')->whereIn('service_window_id',
                ServiceWindow::where('code', 'RACE-'.getmypid())->pluck('id'))->delete();
            $services->each(fn ($id) => Service::whereKey($id)->delete());
            ServiceWindow::where('code', 'RACE-'.getmypid())->delete();
        }

        User::whereIn('id', $this->createdUserIds)->delete();

        parent::tearDown();
    }

    public function test_only_one_client_wins_the_last_place(): void
    {
        $slot = $this->makeSlot(capacity: 1);

        $results = $this->race($slot);

        $winners = array_filter($results, fn ($r) => $r === 'won');

        $this->assertCount(1, $winners,
            'Exactly one client may take the last place. Results: '.json_encode($results));
        $this->assertSame(1, (int) $this->bookedCount($slot));
        $this->assertSame(1, $this->appointmentsFor($slot));
    }

    public function test_booked_count_never_exceeds_capacity(): void
    {
        $capacity = 2;
        $slot = $this->makeSlot(capacity: $capacity);

        $results = $this->race($slot);

        $winners = count(array_filter($results, fn ($r) => $r === 'won'));
        $booked = $this->bookedCount($slot);

        $this->assertSame($capacity, $winners,
            'Winners must exactly match capacity. Results: '.json_encode($results));
        $this->assertSame($capacity, $booked);
        $this->assertLessThanOrEqual($capacity, $booked,
            'The capacity ceiling was breached under concurrent booking.');
        $this->assertSame($capacity, $this->appointmentsFor($slot));
    }

    public function test_concurrent_bookings_never_share_a_queue_number(): void
    {
        // Capacity is generous here: this test is about queue-number uniqueness
        // under contention, not about refusing bookings.
        $slot = $this->makeSlot(capacity: self::RACERS + 5);

        $this->race($slot);

        $numbers = Appointment::where('time_slot_id', $slot->id)->pluck('queue_number')->all();

        $this->assertCount(self::RACERS, $numbers);
        $this->assertCount(self::RACERS, array_unique($numbers),
            'Two clients were issued the same queue number: '.json_encode($numbers));
    }

    public function test_a_single_process_race_is_also_serialised_correctly(): void
    {
        // Same invariant without forking, as a cheap smoke check that the
        // service refuses the over-capacity case at all.
        $slot = $this->makeSlot(capacity: 2);
        $booking = new BookingService;

        $first = $booking->book(User::findOrFail($this->createdUserIds[0]), Service::findOrFail($this->serviceId), $slot->id);
        $second = $booking->book(User::findOrFail($this->createdUserIds[1]), Service::findOrFail($this->serviceId), $slot->id);

        $this->assertSame('confirmed', $first->status->value);
        $this->assertSame('confirmed', $second->status->value);

        $this->expectException(SlotUnavailableException::class);
        $booking->book(User::findOrFail($this->createdUserIds[2]), Service::findOrFail($this->serviceId), $slot->id);
    }

    /* ------------------------------------------------------------------ */
    /* Harness */
    /* ------------------------------------------------------------------ */

    private function makeSlot(int $capacity): TimeSlot
    {
        $start = now()->addDay()->setTime(9, 0);

        return TimeSlot::create([
            'service_id' => $this->serviceId,
            'service_window_id' => Service::findOrFail($this->serviceId)->service_window_id,
            'slot_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => $capacity,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    /**
     * Launch one PHP process per client, all released together by a gate file so
     * they contend for the slot row at the same moment.
     *
     * @return array<int, string> 'won' or 'lost' per racer
     */
    private function race(TimeSlot $slot): array
    {
        $gate = sys_get_temp_dir().DIRECTORY_SEPARATOR.'govqueue_gate_'.getmypid().'_'.uniqid();
        @unlink($gate);

        $handles = [];
        $pipes = [];

        foreach ($this->createdUserIds as $i => $clientId) {
            $handles[$i] = proc_open(
                [PHP_BINARY, base_path('tests/Support/book_racer.php'),
                    (string) $clientId, (string) $this->serviceId, (string) $slot->id, $gate],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
                base_path(),
            );
        }

        // Release every worker at once.
        touch($gate);

        $results = [];

        foreach ($handles as $i => $handle) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($handle);

            $results[$i] = str_starts_with(trim($stdout), 'won') ? 'won' : 'lost';

            if (trim($stderr) !== '') {
                fwrite(STDERR, "racer {$i}: ".trim($stderr)."\n");
            }
        }

        @unlink($gate);

        return $results;
    }

    private function bookedCount(TimeSlot $slot): int
    {
        return (int) DB::table('time_slots')->where('id', $slot->id)->value('booked_count');
    }

    private function appointmentsFor(TimeSlot $slot): int
    {
        return (int) Appointment::where('time_slot_id', $slot->id)->count();
    }

    private function canForkProcesses(): bool
    {
        return function_exists('proc_open') && PHP_BINARY !== '' && is_file(PHP_BINARY);
    }
}
