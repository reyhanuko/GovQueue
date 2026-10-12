<?php

namespace App\Services;

use App\Contracts\QueueAnnouncer;
use App\Contracts\QueueReader;
use App\Enums\AppointmentSource;
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
        private QueueAnnouncer $announcer,
        private QueueReader $reader,
    ) {}

    /* ------------------------------------------------------------------ */
    /* Lifecycle */
    /* ------------------------------------------------------------------ */

    /**
     * Priority verification changes the queue order, so listeners are told.
     */
    private function announcePriorityChange(Appointment $appointment): void
    {
        DB::afterCommit(fn () => $this->announcer->appointmentChanged(
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
            DB::afterCommit(fn () => $this->announcer->appointmentChanged($updated));

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

        // Compared by primary key, not by object. Collection::contains() falls back
        // to a loose object comparison, which silently stops matching as soon as
        // the queue is rebuilt into a different collection type - and when it
        // stops matching, estimated_wait_minutes is never written and the
        // "what did we tell this client?" question cannot be answered for any of
        // them. The id is what the question is actually about.
        if (! $queue->contains('id', $appointment->id)) {
            return;
        }

        $estimates = $this->estimateWaits($window, $queue);

        if (! array_key_exists($appointment->id, $estimates)) {
            return;
        }

        $appointment->forceFill(['estimated_wait_minutes' => $estimates[$appointment->id]])->save();
    }

    /**
     * Call one specific skipped ticket back to the window.
     *
     * For the case where a client was moved back, went and got what they needed,
     * and is standing at the counter again. Waiting for the queue order to reach
     * them is not reasonable when they are already there.
     *
     * Returns null - rather than throwing - when it cannot be done, so the
     * console can explain why on screen. Only a skipped ticket can be called
     * this way; anything else has to go through the normal order.
     */
    public function callSpecific(Appointment $appointment, ?User $actor = null): ?Appointment
    {
        if ($appointment->status !== AppointmentStatus::Skipped) {
            return null;
        }

        $busy = Appointment::where('service_window_id', $appointment->service_window_id)
            ->whereDate('queue_date', $appointment->queue_date)
            ->where('status', AppointmentStatus::Serving->value)
            ->whereKeyNot($appointment->getKey())
            ->exists();

        if ($busy) {
            return null;
        }

        return $this->transition($appointment, AppointmentStatus::Serving, [
            'actor' => $actor,
            'note' => 'Called back to the window',
        ]);
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

        $fresh = $called->fresh(['user', 'service', 'window']);

        /*
         * Tell the client their number was called.
         *
         * This is the message that makes the lobby board optional rather than
         * mandatory: a client who is not watching the display still finds out.
         * Dispatched after commit and queued, so a slow mail server can never
         * delay or fail the call itself.
         */
        DB::afterCommit(function () use ($fresh) {
            if (! config('govqueue.notifications.enabled', true)) {
                return;
            }

            app(ClientNotificationService::class)->called($fresh);
        });

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

        $skipped = $this->transition($appointment, AppointmentStatus::Skipped, [
            'actor' => $actor,
            'note' => $note ?: 'Client asked to wait a little longer',
        ]);

        $fresh = $skipped->fresh(['user', 'service', 'window']);

        /*
         * Tell the client they have moved back.
         *
         * Without this a client who was away from the window keeps standing
         * there while the counter serves somebody else, and only finds out when
         * their number is skipped a second time. The position is computed after
         * the move so the message can say where they ended up.
         */
        DB::afterCommit(function () use ($fresh) {
            if (! config('govqueue.notifications.enabled', true)) {
                return;
            }

            $window = $fresh->window;
            $index = $window
                ? $this->waitingFor($window, $fresh->queue_date?->toDateString())->search($fresh)
                : false;

            app(ClientNotificationService::class)->skipped($fresh, $index === false ? null : $index + 1);
        });

        return $skipped;
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
        /*
         * Wrapped so the grant, the audit row and the status history commit
         * together.
         *
         * A statutory-priority decision that exists without an audit row is the
         * worst outcome available: it changes who is served next, and there is no
         * record of who decided or why. Previously the three writes were
         * independent, so a failure in recordStatus() left a granted priority
         * with nothing in the trail.
         */
        return DB::transaction(function () use ($appointment, $staff, $reference) {
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

            // Safe inside the transaction because announcePriorityChange defers
            // through DB::afterCommit - listeners never see an order that a
            // rollback would take back.
            $this->announcePriorityChange($fresh);

            return $fresh;
        }, 3);
    }

    /**
     * Staff refuse a claimed priority, with a reason.
     */
    public function refusePriority(
        Appointment $appointment,
        User $staff,
        string $reason,
    ): Appointment {
        // Same reasoning as verifyPriority(): clearing a claim changes who is
        // served next, so the decision and its record must land together.
        return DB::transaction(function () use ($appointment, $staff, $reason) {
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
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /* Queue views */
    /* ------------------------------------------------------------------ */

    /*
     * These are delegations, not the implementation.
     *
     * The queries themselves live in QueueReaderService so the announcer can read
     * the queue without depending on this class - see App\Contracts\QueueReader
     * for why that had to be split out. They are kept here because call sites that
     * already hold a QueueService (the staff console, the queue unit tests) should
     * not have to learn about a second service, and because a single delegation
     * cannot drift from the one implementation the way a copy would.
     */

    /**
     * Everyone physically at the counter, in service order.
     *
     * @return Collection<int, Appointment>
     */
    public function waitingFor(ServiceWindow $window, ?string $queueDate = null): Collection
    {
        return $this->reader->waitingFor($window, $queueDate);
    }

    /**
     * The ticket currently at the window, if any.
     */
    public function currentlyServing(ServiceWindow $window, ?string $queueDate = null): ?Appointment
    {
        return $this->reader->currentlyServing($window, $queueDate);
    }

    /**
     * Clients who have a booking today but have not checked in yet.
     *
     * @return Collection<int, Appointment>
     */
    public function expectedButNotArrived(ServiceWindow $window, ?string $queueDate = null): Collection
    {
        return $this->reader->expectedButNotArrived($window, $queueDate);
    }

    /**
     * Minutes each waiting client should expect to wait.
     *
     * @param  Collection<int, Appointment>  $queue
     * @return array<int, int|null> keyed by appointment id
     */
    public function estimateWaits(ServiceWindow $window, Collection $queue, ?float $perClientMinutes = null): array
    {
        return $this->reader->estimateWaits($window, $queue, $perClientMinutes);
    }

    /**
     * Measured average minutes per client for a window, over recent history.
     */
    public function averageServiceMinutes(ServiceWindow $window): float
    {
        return $this->reader->averageServiceMinutes($window);
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
     *
     * A walk-in never holds a place.
     *
     * Walk-ins are filed under a real time_slots row because the column is NOT
     * NULL, but they deliberately never increment booked_count - see
     * BookingService::currentSlotFor(). Decrementing for them therefore took a
     * place that belonged to somebody else: a slot with capacity 5 holding one
     * online booking lost a place every time a walk-in was served or marked a
     * no-show, drifting the counter toward zero across a day until the office
     * was advertising places it had already given away.
     *
     * Checked here rather than at each of the three call sites, because a
     * per-call-site condition is exactly the kind of thing the fourth caller
     * forgets.
     */
    private function releaseSlot(Appointment $appointment): void
    {
        if ($appointment->source === AppointmentSource::WalkIn) {
            return;
        }

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
