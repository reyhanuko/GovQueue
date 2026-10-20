<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\ClientNotificationService;
use App\Services\NoShowPolicy;
use App\Services\QueueService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Closes tickets nobody closed.
 *
 * Reported as an appointment for 12 October still showing as "Checked in" and
 * "You are next" on the 19th. Nothing was wrong with the state machine: the
 * ticket genuinely was still CheckedIn, because no code path ever moved it. A
 * client who arrives, is never called, and goes home leaves an open ticket
 * behind that keeps occupying a place in the day's counts and keeps telling
 * the client they are next.
 *
 * The close is a no-show, which is the same terminal state the staff button
 * uses, reached the same way, through QueueService::markNoShow() - so the slot
 * is released and the status history is written exactly as it would be by hand.
 *
 * A closed ticket is told to its client. The client finds out otherwise by
 * looking at a screen that says their appointment was closed and their place
 * was given away, which is how a resident discovers they are recorded as a
 * no-show and how the office discovers the record is wrong.
 *
 * The close also counts (decision D6). A client who never came and never
 * cancelled has recorded an unexcused no-show; three of those pause their new
 * bookings for a fortnight. A client who *did* check in came to the office and
 * was let down by the queue, so their no-show is excused and counts for
 * nothing.
 */
class CloseStaleAppointmentsCommand extends Command
{
    protected $signature = 'govqueue:close-stale-appointments
                            {--dry-run : Report what would be closed without closing it}
                            {--grace= : Minutes after a slot ends before it is considered stale}';

    protected $description = 'Close tickets whose slot has ended and that nobody completed, cancelled, or no-showed';

    public function handle(
        QueueService $queue,
        NoShowPolicy $policy,
        ClientNotificationService $notifications,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        $grace = $this->option('grace') !== null
            ? (int) $this->option('grace')
            : (int) config('govqueue.close_out.grace_minutes', 60);

        // The slot has to have ended, plus a margin. Without the margin, a
        // service running five minutes over gets closed from under the clerk
        // who is still finishing with the client.
        $cutoff = Carbon::now()->subMinutes($grace);

        $stale = $this->staleTickets($cutoff);

        if ($stale->isEmpty()) {
            $this->info('Nothing to close.');

            return self::SUCCESS;
        }

        $closed = 0;
        $failed = 0;
        $strikes = 0;
        $blocked = 0;

        foreach ($stale as $appointment) {
            $label = ($appointment->queue_number ?? $appointment->reference_number)
                .' ('.$appointment->status->value.')';

            if ($dryRun) {
                $this->line(sprintf('  [dry] would close %s at %s', $label, $appointment->reference_number));

                if ($this->isUnexcused($appointment)) {
                    $this->line(sprintf('  [dry] would count an unexcused no-show against the client'));
                }

                $closed++;

                continue;
            }

            try {
                /*
                 * Whether this no-show is the client's fault is decided from
                 * the ticket as it was *before* the close: the transition to
                 * NoShow stamps an arrival time on the row, so afterwards the
                 * ticket looks as though the client had been there.
                 */
                $unexcused = $this->isUnexcused($appointment);

                $queue->markNoShow($appointment, null, 'Closed automatically: the slot ended and the ticket was never resolved.');

                $closed++;

                /*
                 * Count the no-show, and block the client if it was the one
                 * that reached the threshold.
                 *
                 * Only for a client who never came: one who checked in was at
                 * the office, and the office's own calling is what lost them.
                 */
                if ($unexcused) {
                    $strikes++;

                    if ($policy->recordStrike(
                        $appointment,
                        'Closed out by govqueue:close-stale-appointments.'
                    )) {
                        $blocked++;
                        $this->warn(sprintf(
                            '  %s has reached %d unexcused no-shows and cannot make new bookings until %s.',
                            $appointment->user?->name ?? 'A client',
                            $policy->threshold(),
                            $policy->blockedUntil($appointment->user)->format('M d, Y'),
                        ));
                    }
                }

                // The client is told their appointment was closed out. Sent
                // after the close, so the message describes what happened.
                if (config('govqueue.notifications.enabled', true)) {
                    $notifications->noShow($appointment->fresh(['user', 'service', 'window', 'timeSlot']));
                }
            } catch (Throwable $e) {
                // One bad ticket must not stop the rest of the day's queue from
                // closing, or a single malformed row leaves the office with the
                // same problem tomorrow.
                $failed++;
                $this->warn(sprintf('  Could not close %s: %s', $label, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            '%s %d ticket(s)%s.',
            $dryRun ? 'Would close' : 'Closed',
            $closed,
            $failed > 0 ? sprintf(', %d could not be closed', $failed) : '',
        ));

        if ($strikes > 0) {
            $this->info(sprintf(
                'Counted %d unexcused no-show(s).%s',
                $strikes,
                $blocked > 0
                    ? sprintf(' %d client(s) are now blocked from booking for %d days.', $blocked, $policy->blockDays())
                    : '',
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Was this a no-show the client is answerable for?
     *
     * A client who checked in came to the office. They were let down by the
     * queue, not by their own absence, so their no-show is excused and counts
     * for nothing against them.
     *
     * Judged on the status the ticket held before it was closed, and not on
     * checked_in_at: the transition to NoShow stamps an arrival time on the
     * row, so a ticket that nobody ever arrived for carries one afterwards.
     */
    private function isUnexcused(Appointment $appointment): bool
    {
        if ($appointment->user === null) {
            return false;
        }

        return in_array($appointment->status, [
            AppointmentStatus::Pending,
            AppointmentStatus::Confirmed,
        ], true);
    }

    /**
     * Tickets that are still open and whose slot has finished.
     *
     * @return Collection<int, Appointment>
     */
    private function staleTickets(Carbon $cutoff)
    {
        return Appointment::query()
            ->with(['timeSlot', 'service', 'user'])
            // Mirrors isClosed() exactly. Anything already terminal is left
            // alone, so re-running this is safe. See below on why this is not
            // what stops a terminal ticket being touched.
            /*
             * Excludes terminal statuses.
             *
             * Not the safety mechanism - transition() already refuses to move a
             * completed, cancelled or no-show ticket, and the test proving those
             * survive passes with this clause removed. It is here so the command
             * does not select them and then report each as "could not be
             * closed", which would bury real failures in a nightly run nobody
             * is watching.
             */
            ->whereNotIn('status', [
                AppointmentStatus::Completed->value,
                AppointmentStatus::Cancelled->value,
                AppointmentStatus::NoShow->value,
            ])
            ->where(function ($query) use ($cutoff) {
                // Staleness is judged on the slot, and appointments.time_slot_id
                // is NOT NULL, so this covers walk-ins too - a walk-in holds the
                // slot it was issued against and goes stale on the same clock.
                $query->whereHas(
                    'timeSlot',
                    fn ($slot) => $slot->where('end_at', '<', $cutoff),
                );
            })
            ->orderBy('id')
            ->get();
    }
}
