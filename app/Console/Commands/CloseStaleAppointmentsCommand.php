<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
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
 * Deliberately sends no notification, because the equivalent staff action
 * sends none either; adding an email that says "you were recorded as a no-show"
 * is a policy question and is not answered here.
 *
 * This closes tickets. It does not count strikes and does not block anyone from
 * booking again - that is decision D6, and is not decided.
 */
class CloseStaleAppointmentsCommand extends Command
{
    protected $signature = 'govqueue:close-stale-appointments
                            {--dry-run : Report what would be closed without closing it}
                            {--grace= : Minutes after a slot ends before it is considered stale}';

    protected $description = 'Close tickets whose slot has ended and that nobody completed, cancelled, or no-showed';

    public function handle(QueueService $queue): int
    {
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

        foreach ($stale as $appointment) {
            $label = ($appointment->queue_number ?? $appointment->reference_number)
                .' ('.$appointment->status->value.')';

            if ($dryRun) {
                $this->line(sprintf('  [dry] would close %s at %s', $label, $appointment->reference_number));

                $closed++;

                continue;
            }

            try {
                $queue->markNoShow($appointment, null, 'Closed automatically: the slot ended and the ticket was never resolved.');

                $closed++;
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

        return self::SUCCESS;
    }

    /**
     * Tickets that are still open and whose slot has finished.
     *
     * @return Collection<int, Appointment>
     */
    private function staleTickets(Carbon $cutoff)
    {
        return Appointment::query()
            ->with(['timeSlot', 'service'])
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
