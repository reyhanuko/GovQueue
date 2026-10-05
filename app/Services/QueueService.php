<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PriorityType;
use App\Exceptions\InvalidQueueTransitionException;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ServiceWindow;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The running queue at the counter: check-in, calling, serving, completing,
 * no-shows, skips, and priority verification.
 *
 * Every state change goes through transition() so the legal move table
 * (AppointmentStatus::canTransitionTo), the audit trail, and the history log
 * cannot be bypassed. Callers never set ->status directly.
 */
class QueueService
{
    public function __construct(
        private BookingService $booking,
        private ?QueueBroadcaster $broadcaster = null,
    ) {}

    private function broadcaster(): QueueBroadcaster
    {
        // Resolved lazily: QueueBroadcaster depends on this service, so injecting
        // it eagerly would create a circular construction.
        return $this->broadcaster ??= app(QueueBroadcaster::class);
    }

    /* ------------------------------------------------------------------ */
    /* Lifecycle */
    /* ------------------------------------------------------------------ */

    /**
     * Priority verification changes the queue order, so listeners are told.
     */
    private function announcePriorityChange(Appointment $appointment): void
    {
        DB::afterCommit(fn () => $this->broadcaster()->appointmentChanged(
            $appointment->fresh(['service', 'window', 'user', 'timeSlot']) ?? $appointment,
        ));
    }

    /**
     * Move a ticket to a new status, validating the transition first.
     *
     * @param  array{actor?:?User, note?:?string, reason?:?string}  $context
     */
    public function transition(
        Appointment $appointment,
        AppointmentStatus $target,
        array $context = [],
    ): Appointment {
        $actor = $context['actor'] ?? null;

        if (! $appointment->status->canTransitionTo($target)) {
            throw InvalidQueueTransitionException::notAllowed($appointment, $target->value);
        }

        $from = $appointment->status;

        return DB::transaction(function () use ($appointment, $target, $from, $context, $actor) {
            $appointment->status = $target;

            $this->stampTimestamps($appointment, $from, $target);

            $appointment->save();

            $this->booking->recordStatus(
                $appointment,
                $from,
                $target,
                $context['note'] ?? $context['reason'] ?? null,
                $actor,
            );

            AuditLog::record('appointment.'.$target->value, $appointment, [
                'from' => $from->value,
                'by' => $actor?->id,
            ], $actor?->id);

            $updated = $appointment->fresh(['service', 'window', 'user', 'timeSlot']);

            // Announced after the transaction commits so listeners never refetch
            // a row that is not visible yet.
            DB::afterCommit(fn () => $this->broadcaster()->appointmentChanged($updated));

            return $updated;
        }, 3);
    }

    /**
     * Client arrives at the counter and joins today's physical line.
     *
     * This is what creates the walk-in path described in the project's scope:
     * residents without internet access are still accommodated.
     */
    public function checkIn(Appointment $appointment, ?User $actor = null): Appointment
    {
        $checkedIn = $this->transition($appointment, AppointmentStatus::CheckedIn, [
            'actor' => $actor,
            'note' => 'Client checked in at the counter',
        ]);

        /*
         * Record the estimate the client was just given.
         *
         * estimated_wait_minutes existed as a column but nothing ever wrote to
         * it: the estimate was computed on the fly for the live display and
         * thrown away. That left no record of what a client was actually told,
         * so "how good is our wait estimate?" could never be answered - the one
         * question a queue system is judged on.
         *
         * Check-in is the honest moment to store it. actualWaitMinutes() measures
         * checked_in_at -> serving_started_at, so the figure recorded here is
         * measured over exactly the same window the client was promised, making
         * the two directly comparable.
         */
        $this->rememberEstimate($checkedIn);

        return $checkedIn;
    }

    /**
     * Store this ticket's current wait estimate against the row.
     */
    private function rememberEstimate(Appointment $appointment): void
    {
        $window = $appointment->window;

        if (! $window) {
            return;
        }

        $queue = $this->waitingFor($window, $appointment->queue_date?->toDateString());

        if (! $queue->contains($appointment)) {
            return;
        }

        $estimates = $this->estimateWaits($window, $queue);

        if (! array_key_exists($appointment->id, $estimates)) {
            return;
        }

        $appointment->forceFill(['estimated_wait_minutes' => $estimates[$appointment->id]])->save();
    }

    /**
     * Issue the next ticket in service order to the window.
     *
     * The candidate is chosen by QueueOrderingService, so statutory priority
     * (RA 9994 / RA 10754) is applied automatically rather than by the
     * operator's judgement.
     */
    public function callNext(ServiceWindow $window, ?User $actor = null, ?string $queueDate = null): ?Appointment
    {
        $queueDate = $queueDate ?? today()->toDateString();

        // Nothing is callable while someone is already at the window.
        $busy = Appointment::where('service_window_id', $window->id)
            ->whereDate('queue_date', $queueDate)
            ->where('status', AppointmentStatus::Serving->value)
            ->first();

        if ($busy) {
            return null;
        }

        $candidate = $this->waitingFor($window, $queueDate)->first();

        if (! $candidate) {
            return null;
        }

        $called = $this->transition($candidate, AppointmentStatus::Serving, [
            'actor' => $actor,
            'note' => 'Called to '.strtolower($window->name),
        ]);

        return $called;
    }

    /**
     * Finish serving a client and record what they transacted.
     *
     * @param  array{details?:?array, fee_paid?:?float}  $transaction
     */
    public function complete(
        Appointment $appointment,
        User $staff,
        array $transaction = [],
    ): Appointment {
        // Wrapped so the status change, the slot release, and the transaction
        // record all commit together. If the slot release were outside the
        // transaction a failure here would strand the client holding capacity.
        return DB::transaction(function () use ($appointment, $staff, $transaction) {
            $completed = $this->transition($appointment, AppointmentStatus::Completed, [
                'actor' => $staff,
                'note' => 'Transaction completed',
            ]);

            // A completed booking no longer holds its slot.
            $this->releaseSlot($appointment);

            Transaction::updateOrCreate(
                ['appointment_id' => $appointment->id],
                [
                    'reference_number' => $this->transactionReference($completed),
                    'user_id' => $appointment->user_id,
                    'service_id' => $appointment->service_id,
                    'handled_by' => $staff->id,
                    'details' => $transaction['details'] ?? null,
                    'fee_paid' => $transaction['fee_paid'] ?? $appointment->service->fee,
                    'status' => 'completed',
                    'completed_at' => now(),
                ],
            );

            return $completed;
        }, 3);
    }

    /**
     * Client did not answer when called.
     */
    public function markNoShow(Appointment $appointment, ?User $actor = null, ?string $note = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $actor, $note) {
            $noShow = $this->transition($appointment, AppointmentStatus::NoShow, [
                'actor' => $actor,
                'note' => $note ?: 'Client did not appear',
            ]);

            $this->releaseSlot($appointment);

            return $noShow;
        }, 3);
    }

    /**
     * Client is not ready; return them to the end of their group.
     *
     * The skip count is tracked so a client who repeatedly does not answer can
     * be closed out as a no-show instead of being called forever.
     */
    public function skip(Appointment $appointment, ?User $actor = null, ?string $note = null): Appointment
    {
        $appointment->increment('skip_count');

        return $this->transition($appointment, AppointmentStatus::Skipped, [
            'actor' => $actor,
            'note' => $note ?: 'Client asked to wait a little longer',
        ]);
    }

    public function hasBeenSkippedTooOften(Appointment $appointment): bool
    {
        return (int) $appointment->skip_count >= (int) config('govqueue.max_skips');
    }

    /* ------------------------------------------------------------------ */
    /* Priority verification */
    /* ------------------------------------------------------------------ */

    /**
     * Staff confirm or refuse a client's claimed priority at the counter.
     *
     * This is the human decision the whole priority feature hinges on. The
     * system records it and reorders the queue accordingly; it never reaches an
     * external database to confirm eligibility itself.
     */
    public function verifyPriority(Appointment $appointment, User $staff, ?string $reference = null): Appointment
    {
        $appointment->update([
            'priority_verified_at' => now(),
            'priority_verified_by' => $staff->id,
            'priority_reference' => $reference ?? $appointment->priority_reference,
        ]);

        $types = collect($appointment->claimedPriorities())
            ->map(fn (PriorityType $t) => $t->label())
            ->implode(', ');

        AuditLog::record('appointment.priority_verified', $appointment, [
            'types' => $types,
            'reference' => $appointment->priority_reference,
            'by' => $staff->id,
        ], $staff->id);

        $this->booking->recordStatus(
            $appointment,
            $appointment->status,
            $appointment->status,
            'Priority verified: '.$types,
            $staff,
        );

        $fresh = $appointment->fresh();
        $this->announcePriorityChange($fresh);

        return $fresh;
    }

    /**
     * Staff refuse a claimed priority, with a reason.
     */
    public function refusePriority(
        Appointment $appointment,
        User $staff,
        string $reason,
    ): Appointment {
        $claimed = collect($appointment->claimedPriorities())
            ->map(fn (PriorityType $t) => $t->label())
            ->implode(', ');

        $appointment->update([
            'claims_senior_citizen' => false,
            'claims_pwd' => false,
            'claims_pregnant' => false,
            'priority_verified_at' => null,
            'priority_verified_by' => null,
        ]);

        AuditLog::record('appointment.priority_refused', $appointment, [
            'refused' => $claimed,
            'reason' => $reason,
            'by' => $staff->id,
        ], $staff->id);

        $this->booking->recordStatus(
            $appointment,
            $appointment->status,
            $appointment->status,
            'Priority claim refused: '.$reason,
            $staff,
        );

        $fresh = $appointment->fresh();
        $this->announcePriorityChange($fresh);

        return $fresh;
    }

    /* ------------------------------------------------------------------ */
    /* Queue views */
    /* ------------------------------------------------------------------ */

    /**
     * Everyone physically at the counter, in service order.
     *
     * @return Collection<int, Appointment>
     */
    public function waitingFor(ServiceWindow $window, ?string $queueDate = null): Collection
    {
        $queueDate = $queueDate ?? today()->toDateString();

        return Appointment::with(['user', 'service'])
            ->forWindow($window->id)
            ->whereDate('queue_date', $queueDate)
            ->whereIn('status', [
                AppointmentStatus::CheckedIn->value,
                AppointmentStatus::Skipped->value,
            ])
            ->inQueueOrder($queueDate)
            ->get();
    }

    /**
     * The ticket currently at the window, if any.
     */
    public function currentlyServing(ServiceWindow $window, ?string $queueDate = null): ?Appointment
    {
        return Appointment::with(['user', 'service'])
            ->forWindow($window->id)
            ->whereDate('queue_date', $queueDate ?? today())
            ->where('status', AppointmentStatus::Serving->value)
            ->first();
    }

    /**
     * Clients who have a booking today but have not checked in yet.
     *
     * @return Collection<int, Appointment>
     */
    public function expectedButNotArrived(ServiceWindow $window, ?string $queueDate = null): Collection
    {
        return Appointment::with(['user', 'service'])
            ->forWindow($window->id)
            ->whereDate('queue_date', $queueDate ?? today())
            ->where('status', AppointmentStatus::Confirmed->value)
            ->orderBy('scheduled_at')
            ->get();
    }

    /* ------------------------------------------------------------------ */
    /* Wait time estimation */
    /* ------------------------------------------------------------------ */

    /**
     * Minutes each waiting client should expect to wait.
     *
     * Uses the measured average service time for that window when enough data
     * exists, otherwise the configured default. It is an estimate for display
     * only: the document does not promise a committed wait time.
     *
     * @param  Collection<int, Appointment>  $queue
     * @return array<int, int|null> keyed by appointment id
     */
    public function estimateWaits(ServiceWindow $window, Collection $queue): array
    {
        if ($queue->isEmpty()) {
            return [];
        }

        $perClient = $this->averageServiceMinutes($window);
        $estimates = [];
        $position = 0;

        foreach ($queue as $appointment) {
            $estimates[$appointment->id] = $position === 0
                ? 0
                : (int) round($position * $perClient);
            $position++;
        }

        return $estimates;
    }

    /**
     * Measured average minutes per client for a window, over recent history.
     */
    public function averageServiceMinutes(ServiceWindow $window): float
    {
        $recent = Appointment::forWindow($window->id)
            ->where('status', AppointmentStatus::Completed->value)
            ->whereNotNull('serving_started_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subDays(30))
            ->get(['serving_started_at', 'completed_at']);

        // Below this many observations the sample is too thin to trust.
        if ($recent->count() < 5) {
            return (float) $window->avg_service_minutes;
        }

        $average = $recent->avg(
            fn (Appointment $a) => $a->serving_started_at->diffInMinutes($a->completed_at)
        );

        return $average > 0 ? (float) $average : (float) $window->avg_service_minutes;
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    /**
     * Set the timestamp column that corresponds to the new status.
     */
    private function stampTimestamps(Appointment $appointment, AppointmentStatus $from, AppointmentStatus $target): void
    {
        match ($target) {
            AppointmentStatus::CheckedIn => $appointment->checked_in_at = now(),
            AppointmentStatus::Serving => $appointment->serving_started_at = now(),
            AppointmentStatus::Completed => $appointment->completed_at = now(),
            AppointmentStatus::NoShow => $appointment->checked_in_at = $appointment->checked_in_at ?? now(),
            AppointmentStatus::Skipped => $appointment->serving_started_at = null,
            // A client who walks away mid-transaction is recorded with the
            // arrival time they actually had.
            AppointmentStatus::Cancelled => $appointment->cancelled_at = now(),
            default => null,
        };

        if ($target === AppointmentStatus::Serving) {
            $appointment->called_at = now();
        }
    }

    /**
     * Give back the slot place held by a finished ticket.
     *
     * Scoped through the query builder: an unconstrained decrement would hit
     * every slot in the table (a bug this class previously inherited).
     */
    private function releaseSlot(Appointment $appointment): void
    {
        DB::table('time_slots')
            ->where('id', $appointment->time_slot_id)
            ->where('booked_count', '>', 0)
            ->decrement('booked_count');
    }

    /**
     * Unique transaction reference.
     *
     * Derived from the appointment id plus a short random suffix: the id alone
     * can repeat across a reseeded database, and a duplicate would break the
     * unique index on transactions.reference_number.
     */
    private function transactionReference(Appointment $appointment): string
    {
        do {
            $reference = 'TX-'.Carbon::parse($appointment->scheduled_at ?? now())->format('ymd')
                .'-'.str_pad((string) ($appointment->id % 100000), 5, '0', STR_PAD_LEFT)
                .'-'.Str::upper(Str::random(4));
        } while (Transaction::where('reference_number', $reference)->exists());

        return $reference;
    }
}
