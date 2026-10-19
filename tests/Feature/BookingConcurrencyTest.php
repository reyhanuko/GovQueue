<?php

namespace Tests\Feature;

use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\NotificationLog;
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

        /*
         * Someone has to be at the counter (decision D2).
         *
         * This window is built with ServiceWindow::create() rather than the
         * factory, so it does not pick up the factory's default staffer. Under
         * D2 an unstaffed window is unbookable, which would make every booking
         * in this test fail on the staffing rule instead of the race it exists to
         * check.
         *
         * Cleaned up with the window in tearDown, which cascades.
         */
        User::factory()->staff()->create([
            'service_window_id' => $window->id,
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
            // Notification logs and audit logs are keyed to the user, not the
            // service, so they survive the cascade. Without deleting them here
            // this class leaks rows into every later test in the run - the
            // booking now sends a confirmation, so each racer writes one.
            NotificationLog::whereIn('appointment_id', function ($query) use ($services) {
                $query->select('id')->from('appointments')->whereIn('service_id', $services);
            })->delete();

            NotificationLog::whereIn('user_id', $this->createdUserIds)->delete();

            AuditLog::whereIn('subject_id', function ($query) use ($services) {
                $query->select('id')->from('appointments')->whereIn('service_id', $services);
            })->delete();

            AuditLog::whereIn('user_id', $this->createdUserIds)->delete();

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

    public function test_concurrent_walk_ins_never_share_a_queue_number(): void
    {
        /*
         * The walk-in numbering race.
         *
         * nextWalkInNumber() derived the next W- number from an unlocked MAX()
         * read taken BEFORE the transaction opened, where nextQueueNumber()
         * locks a QueueCounter row inside it. Two counters pressing the button
         * at the same moment therefore both read the same maximum and both
         * wrote it - and a queue number shown twice on the lobby board is the one
         * failure this system cannot have.
         *
         * Capacity is not the subject here, so it is generous and every racer is
         * expected to win. What is asserted is that the numbers differ.
         */
        $slot = $this->makeSlot(capacity: self::RACERS + 5, forToday: true);

        $numbers = $this->raceWalkIns();

        $this->assertCount(
            self::RACERS,
            $numbers,
            'Every walk-in should have been issued. Results: '.json_encode($numbers)
        );

        $this->assertCount(
            self::RACERS,
            array_unique($numbers),
            'Two walk-ins were issued the same queue number: '.json_encode($numbers)
        );

        // And the walk-in series must be untouched by the online one.
        foreach ($numbers as $number) {
            $this->assertStringStartsWith('W-', $number);
        }
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

    private function makeSlot(int $capacity, bool $forToday = false): TimeSlot
    {
        $start = $forToday ? now()->setTime(0, 0) : now()->addDay()->setTime(9, 0);

        return TimeSlot::create([
            'service_id' => $this->serviceId,
            'service_window_id' => Service::findOrFail($this->serviceId)->service_window_id,
            'slot_date' => $start->toDateString(),
            'start_time' => $forToday ? '00:00:00' : '09:00:00',
            'end_time' => $forToday ? '23:59:00' : '09:30:00',
            'start_at' => $start->toDateTimeString(),
            'end_at' => $forToday
                ? $start->copy()->setTime(23, 59)->toDateTimeString()
                : $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => $capacity,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    /**
     * One process per walk-in, all released together by a gate file.
     *
     * Mirrors race(), but for the walk-in path: no user id (the service matches
     * or creates the client itself) and a distinct phone number per racer so the
     * duplicate guard does not refuse them as one person.
     *
     * @return array<int, string> the queue numbers issued, in worker order
     */
    private function raceWalkIns(): array
    {
        $gate = sys_get_temp_dir().DIRECTORY_SEPARATOR.'govqueue_walkin_gate_'.getmypid().'_'.uniqid();
        @unlink($gate);

        $handles = [];
        $pipes = [];

        for ($i = 0; $i < self::RACERS; $i++) {
            $handles[$i] = proc_open(
                [PHP_BINARY, base_path('tests/Support/walkin_racer.php'),
                    (string) $this->serviceId, (string) $i, $gate],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
                base_path(),
            );
        }

        touch($gate);

        $numbers = [];

        foreach ($handles as $i => $handle) {
            $output = stream_get_contents($pipes[$i][1]);
            $errors = stream_get_contents($pipes[$i][2]);

            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($handle);

            if ($errors !== '') {
                $this->fail("Walk-in racer {$i} errored: {$errors}");
            }

            if (str_starts_with(trim($output), 'won:')) {
                $numbers[] = trim(substr(trim($output), 4));
            }
        }

        @unlink($gate);

        return $numbers;
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
