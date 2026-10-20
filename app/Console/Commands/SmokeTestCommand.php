<?php

namespace App\Console\Commands;

use App\Contracts\QueueAnnouncer;
use App\Contracts\QueueReader;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BookingService;
use App\Services\QueueService;
use App\Services\SlotAvailabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks that a freshly deployed instance can actually serve a client.
 *
 * The existing health endpoint answers "does the database answer". This answers the
 * question an operator has just after a deploy: "can a resident book a slot, and
 * can a staff member work that queue right now" - which is a different question,
 * and the one that matters when the deployment is meant to open its doors.
 *
 * It runs the whole booking-to-completed path against a transaction that is always
 * rolled back, so it is safe to point at production and cannot leave a test
 * appointment, a queue number, or a consumed slot behind. That matters: a smoke
 * test that wrote to the real queue would need cleanup, and cleanup that fails is
 * how a real client's slot gets released.
 *
 * What is deliberately not covered: Reverb reachability, which is a socket rather
 * than a request and is already answered end to end by govqueue:verify-realtime.
 */
class SmokeTestCommand extends Command
{
    protected $signature = 'govqueue:smoke-test
                            {--skip-booking : Check connectivity and schema only, without the booking path}';

    protected $description = 'Verify a deployment can serve a client end to end, without leaving any data behind';

    public function handle(
        BookingService $booking,
        QueueService $queue,
        QueueAnnouncer $announcer,
        QueueReader $reader,
        SlotAvailabilityService $availability,
    ): int {
        $checks = [];

        // Returns a string, not DB::select()'s array of rows: the probe's return
        // value is what gets rendered, and casting that array to a string is a
        // warning rather than an error - which the framework turns into an
        // exception and reports as a failed connectivity check.
        $checks[] = $this->check('Database is reachable', fn () => DB::select('select 1') ? 'query answered' : 'query returned nothing');
        $checks[] = $this->check('Schema is migrated', fn () => $this->assertSchema());
        $checks[] = $this->check('Realtime announcer is reachable', fn () => $this->assertAnnouncer($announcer));
        $checks[] = $this->check('Booking path round-trips', fn () => $this->assertBookingPath(
            $booking,
            $queue,
            $reader,
            $availability,
            $announcer
        ), skipped: (bool) $this->option('skip-booking'));

        $failed = array_filter($checks, fn ($c) => $c['status'] === 'FAIL');

        $this->newLine();

        foreach ($checks as $check) {
            $this->line(sprintf(
                '  %s  %s%s',
                $check['status'],
                $check['name'],
                $check['detail'] ? "  ({$check['detail']})" : ''
            ));
        }

        $this->newLine();

        if ($failed === []) {
            $this->info('Smoke test passed. This deployment can serve a client.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Smoke test failed: %d of %d checks. Do not open the office until these pass.',
            \count($failed),
            \count($checks)
        ));

        return self::FAILURE;
    }

    /**
     * Run one check, turning any throwable into a reported failure.
     *
     * A smoke test that dies on the first error is useless for triage: the whole
     * point is to find out everything that is wrong at once, so each check is
     * isolated and the command reports at the end.
     *
     * Pass $skipped = true from the probe to opt a check out. It is an explicit
     * argument rather than a sentinel return value because every successful check
     * returns a detail string too, and inferring "skipped" from the shape of that
     * string reported the schema and announcer checks as skipped while they had
     * actually passed.
     *
     * @return array{name:string, status:string, detail:string}
     */
    private function check(string $name, callable $probe, bool $skipped = false): array
    {
        if ($skipped) {
            return ['name' => $name, 'status' => 'SKIP', 'detail' => 'skipped by request'];
        }

        try {
            return ['name' => $name, 'status' => 'PASS', 'detail' => (string) $probe()];
        } catch (Throwable $e) {

            // Exception class only, never the message: this output is read aloud
            // over a call or pasted into a ticket, and a connection string or a
            // hostname in a message is the thing this whole command is built to
            // avoid leaking elsewhere.
            return ['name' => $name, 'status' => 'FAIL', 'detail' => class_basename($e)];
        }
    }

    /**
     * The tables the application cannot start without.
     */
    private function assertSchema(): string
    {
        $required = [
            'users', 'services', 'service_windows', 'slot_templates', 'time_slots',
            'appointments', 'queue_counters', 'queue_status_histories',
            'transactions', 'audit_logs', 'notification_logs', 'holidays',
        ];

        $missing = array_values(array_filter(
            $required,
            fn (string $table) => ! Schema::hasTable($table)
        ));

        if ($missing !== []) {
            throw new \RuntimeException('Missing tables: '.implode(', ', $missing));
        }

        return count($required).' tables present';
    }

    /**
     * Can the application announce a queue change at all?
     *
     * Not whether the socket is reachable - that is a connection this command
     * cannot make cheaply and govqueue:verify-realtime covers it. This only checks
     * that the contract is bound and the circuit breaker is not already tripped,
     * which is what tells staff why the display is not updating.
     */
    private function assertAnnouncer(QueueAnnouncer $announcer): string
    {
        $healthy = $announcer->isHealthy();

        // Not a failure: the office can still book and serve with a stale display.
        // But it has to be said out loud, because otherwise a deploy looks healthy
        // while the lobby board is frozen.
        return $healthy
            ? 'announcer ready'
            : 'announcer is cooling down after a recent failure - run govqueue:verify-realtime';
    }

    /**
     * Book, check in, serve and complete - then undo all of it.
     *
     * Wrapped in a transaction that is always rolled back. The queue number
     * counter and the slot capacity both move inside it, so a rollback returns
     * every one of those to its previous value and nothing has to be cleaned up by
     * hand. The one thing deliberately not exercised is a real broadcast: this runs
     * with the announcer replaced by a no-op, because announcing a queue change for
     * a ticket that does not exist would be worse than not testing it.
     */
    private function assertBookingPath(
        BookingService $booking,
        QueueService $queue,
        QueueReader $reader,
        SlotAvailabilityService $availability,
        QueueAnnouncer $announcer,
    ): string {
        // What the check did, reported to the operator. The booking always
        // happens; the queue is only worked when a slot is inside its
        // check-in window.
        $detail = 'booked, served and completed, then rolled back';

        // A stand-in announcer, so the smoke test cannot push a phantom ticket to
        // the real lobby display or to a client's private channel.
        //
        // Bound through the container rather than a property on the command:
        // Illuminate\Console\Command has no $app, so $this->app would be an
        // undefined-property warning that the framework escalates to an exception
        // and reports as a failed booking check.
        app()->instance(QueueAnnouncer::class, new class implements QueueAnnouncer
        {
            public function queueChanged(ServiceWindow $window, ?string $queueDate = null): void {}

            public function appointmentChanged(Appointment $appointment): void {}

            public function appointmentsChanged(iterable $appointments): void {}

            public function isHealthy(): bool
            {
                return true;
            }
        });

        try {
            DB::transaction(function () use ($booking, $queue, $reader, $availability, &$detail) {
                $service = Service::where('is_active', true)->first();

                if (! $service) {
                    throw new \RuntimeException('No active service is configured');
                }

                $slot = $this->firstBookableSlot($service, $availability);

                if (! $slot) {
                    throw new \RuntimeException(
                        'No bookable slot in the horizon - run govqueue:generate-slots'
                    );
                }

                /*
                 * callNext() legitimately refuses while a client is already at the
                 * window, so this check can only be meaningful at an idle one.
                 *
                 * A database with queue history - a demo seed, a real office mid-
                 * shift - has windows mid-service, and booking into one of those
                 * reports a broken deployment when the deployment is fine. Advance
                 * to the next bookable slot until a window is free.
                 */
                while ($slot && ! $this->windowIsIdle($slot)) {
                    $slot = $this->nextBookableSlotAfter($service, $slot);
                }

                if (! $slot) {
                    throw new \RuntimeException(
                        'No idle service window to test against - every bookable window is mid-service'
                    );
                }

                $client = User::factory()->client()->create([
                    'email' => 'smoke-test-'.uniqid().'@govqueue.invalid',
                    'claims_senior_citizen' => false,
                ]);

                $appointment = $booking->book($client, $service, $slot->id);

                // Every one of these is a distinct way the deployment can be
                // subtly broken while still serving HTTP 200 on every page.
                if ((int) $slot->fresh()->booked_count !== 1) {
                    throw new \RuntimeException('Slot capacity did not move');
                }

                if (! $appointment->queue_number) {
                    throw new \RuntimeException('No queue number was issued');
                }

                /*
                 * The check-in window (decision D7).
                 *
                 * A client may check in from half an hour before their slot
                 * until a quarter of an hour after it ends, so a client
                 * booked into a slot that is not running right now cannot be
                 * checked in - correctly, because they have not arrived for
                 * their slot yet.
                 *
                 * That means the queue half of this check only makes sense
                 * while the office is open. Outside those hours the booking
                 * still round-trips, and that is what is reported; the queue
                 * is not worked, because there is nobody at the window to work
                 * it.
                 */
                if (! $this->checkInWindowIsOpen($slot)) {
                    $detail = 'booked; the queue was not exercised - no slot is inside its check-in window right now';

                    throw new RollbackSmokeTest;
                }

                $appointment = $queue->checkIn($appointment);

                $served = $queue->callNext(
                    $appointment->window,
                    null,
                    $appointment->queue_date?->toDateString()
                );

                if (! $served) {
                    throw new \RuntimeException('Nothing was called to the window');
                }

                $queue->complete($served, User::factory()->staff()->create());

                if (! Transaction::where('appointment_id', $served->id)->exists()) {
                    throw new \RuntimeException('No transaction was recorded on completion');
                }

                if (! AuditLog::where('action', 'appointment.booked')->exists()) {
                    throw new \RuntimeException('The booking was not audited');
                }

                $stillQueued = $reader
                    ->waitingFor($appointment->window, $appointment->queue_date?->toDateString())
                    ->where('status', AppointmentStatus::CheckedIn->value)
                    ->count();

                if ($stillQueued > 0) {
                    throw new \RuntimeException('The completed ticket is still on the queue');
                }

                /*
                 * Every check passed, so unwind. The queue number counter and the
                 * slot capacity both moved inside this transaction, so the rollback
                 * returns both - which is why nothing here needs cleaning up and
                 * the command is safe to point at production.
                 */
                throw new RollbackSmokeTest;
            });
        } catch (RollbackSmokeTest) {
            return $detail;
        }

        // Unreachable: the closure always throws. Present so the return type is
        // honoured if that ever changes, and so a reader does not go looking for a
        // path that silently skips the booking.
        throw new \LogicException('The booking check completed without rolling back.');
    }

    /**
     * Earliest slot the booking screen would actually offer.
     *
     * Asked through SlotAvailabilityService rather than by querying time_slots
     * directly, so the smoke test exercises the same rules a client sees: open,
     * not full, not in the past, inside the horizon, not a holiday.
     */
    private function firstBookableSlot(Service $service, SlotAvailabilityService $availability): ?TimeSlot
    {
        $first = $availability->nextAvailableSlot($service)
            ?? TimeSlot::query()
                ->where('service_id', $service->id)
                ->where('is_open', true)
                ->where('start_at', '>', now())
                ->orderBy('start_at')
                ->first();

        /*
         * A slot the counter could check a client into right now.
         *
         * A client may only check in from half an hour before their slot until
         * a quarter of an hour after it ends (decision D7), so the queue half
         * of this check only works against a slot whose window is open. The
         * first bookable slot is usually a later one - tomorrow's, if today is
         * done - and the client could not be checked into it.
         */
        $open = TimeSlot::query()
            ->where('service_id', $service->id)
            ->where('is_open', true)
            ->where('start_at', '>', now())
            ->orderBy('start_at')
            ->get()
            ->first(fn (TimeSlot $slot) => $this->checkInWindowIsOpen($slot));

        return $open ?? $first;
    }

    /**
     * Is this slot inside its check-in window right now? (decision D7)
     */
    private function checkInWindowIsOpen(TimeSlot $slot): bool
    {
        $opensAt = $slot->start_at->copy()
            ->subMinutes((int) config('govqueue.check_in_window.early_minutes', 30));

        $closesAt = $slot->end_at->copy()
            ->addMinutes((int) config('govqueue.check_in_window.late_minutes', 15));

        $now = now();

        return $now->gte($opensAt) && $now->lte($closesAt);
    }

    /**
     * The next candidate slot after $slot, under the same rules the booking
     * screen applies.
     *
     * AvailabilityService answers "the first one"; this answers "the one after
     * this one", which is what a window-by-window search needs.
     */
    private function nextBookableSlotAfter(Service $service, TimeSlot $slot): ?TimeSlot
    {
        // Same rules as firstBookableSlot()'s fallback, minus the holiday and
        // horizon filtering nextAvailableSlot() applies; the first bookable slot
        // already covered those, so every later one in the horizon is fine too.
        return TimeSlot::query()
            ->where('service_id', $service->id)
            ->where('is_open', true)
            ->where('start_at', '>', now())
            ->where('start_at', '>', $slot->start_at)
            ->orderBy('start_at')
            ->first();
    }

    /**
     * Is nobody currently being served at this slot's window, on this queue date?
     *
     * Mirrors the guard in QueueService::callNext() that refuses to call a
     * second client while the window is busy.
     */
    private function windowIsIdle(TimeSlot $slot): bool
    {
        return ! Appointment::where('service_window_id', $slot->service_window_id)
            ->whereDate('queue_date', $slot->slot_date)
            ->where('status', AppointmentStatus::Serving->value)
            ->exists();
    }
}

/**
 * Marker for "everything passed, now undo it".
 *
 * A dedicated exception rather than returning a flag, because the transaction has
 * to roll back and the return value has to travel out through the throw. Naming it
 * makes the rollback at the end of the closure read as intentional rather than as
 * an error being swallowed.
 */
class RollbackSmokeTest extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Smoke test completed; rolling back.');
    }
}
