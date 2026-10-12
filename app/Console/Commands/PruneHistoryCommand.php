<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Trims the operational tables that grow without bound.
 *
 * Scope was wrong in the first version of this command and the schema is what
 * corrected it. It pruned "history older than N days whose appointment no longer
 * exists", on the assumption that orphaned rows accumulate. Checking the
 * migrations rather than assuming:
 *
 *  - queue_status_histories.appointment_id is non-nullable ON DELETE CASCADE, so an
 *    orphan cannot exist. The foreign key already cleans up after itself.
 *  - notification_logs.appointment_id is nullable ON DELETE SET NULL, so deleting
 *    an appointment nulls the column and leaves the row behind.
 *  - audit_logs has no foreign key on its polymorphic (subject_type, subject_id)
 *    pair at all, so those rows are never cleaned up by anything.
 *
 * So the only genuinely prunable rows are notification logs and audit entries that
 * have lost their subject. Age alone is not sufficient and, applied to a live
 * appointment, would delete the record of what actually happened to a client - so
 * this command never prunes on age alone.
 *
 * What it will never touch:
 *  - appointments, which are the operational record and the basis of the statutory
 *    priority report.
 *  - queue_status_histories, which the schema guarantees belongs to a live ticket.
 *  - audit entries for a subject that still exists, however old.
 *  - refused admin actions and failed sign-ins, regardless of age: those are the
 *    evidence an auditor looks for, and they are rare enough to keep forever.
 */
class PruneHistoryCommand extends Command
{
    protected $signature = 'govqueue:prune-history
                            {--days=365 : Minimum age before an orphaned row is removed}
                            {--dry-run : Report what would go without deleting anything}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Remove notification and audit rows whose subject no longer exists';

    /**
     * Actions kept forever, whatever their age or subject.
     *
     * A refused action is the signal an audit trail exists to surface: someone
     * repeatedly trying to delete a service clients have booked, or demote the last
     * administrator, is precisely what should never quietly disappear. Failed
     * sign-ins are the only trace of a password-guessing run, and that often only
     * becomes obvious months afterwards.
     */
    private const PERMANENT_ACTIONS = [
        'user.change_blocked',
        'window.delete_blocked',
        'service.delete_blocked',
        'slot_template.rejected',
        'time_slot.capacity_blocked',
        'time_slot.delete_blocked',
        'auth.login_failed',
    ];

    /** Subject types whose rows are kept while the subject still exists. */
    private const SUBJECT_TABLES = [
        Appointment::class,
        User::class,
        Service::class,
        ServiceWindow::class,
        SlotTemplate::class,
        TimeSlot::class,
        Transaction::class,
    ];

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subDays($days);

        $this->line("Looking for notification and audit rows older than {$days} days whose subject is gone.");

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Delete these rows? This cannot be undone.', false)) {
            $this->warn('Nothing was deleted.');

            return self::SUCCESS;
        }

        /*
         * Counted, not just built.
         *
         * array_sum() over a set of Builder objects yields 0 - they do not cast to
         * a number - so an earlier version of this assembled the queries into an
         * array without counting them and reported "nothing to prune" on a
         * database full of orphans. Silent, and it would have looked like the
         * command was working.
         */
        $counts = [
            'notification_logs' => $this->prunableNotifications($cutoff)->count(),
            'audit_logs' => $this->prunableAudit($cutoff)->count(),
        ];

        $total = array_sum($counts);

        if ($total === 0) {
            $this->info('Nothing to prune. Every notification and audit row still describes something that exists.');

            return self::SUCCESS;
        }

        foreach ($counts as $table => $count) {
            $this->line(sprintf(
                '  %-20s %s',
                $table,
                $dryRun ? "{$count} would be deleted" : "{$count} deleted"
            ));
        }

        if ($dryRun) {
            $this->comment('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }

        // The delete reuses the same predicate as the count, so the number reported
        // above is exactly the number removed. Reporting one figure and deleting
        // another would be worse than reporting nothing.
        $this->prunableNotifications($cutoff)->delete();
        $this->prunableAudit($cutoff)->delete();

        $this->info("Pruned {$total} rows.");
        $this->line('  Appointments and their status history were not touched.');

        return self::SUCCESS;
    }

    /**
     * Notification rows whose appointment has been deleted.
     *
     * ON DELETE SET NULL means the row survives its appointment, holding a
     * destination and a subject line for a client who no longer has a booking. It
     * is the shape that grows forever, because nothing in the application deletes
     * a notification log.
     */
    private function prunableNotifications(Carbon $cutoff): Builder
    {
        return NotificationLog::query()
            ->where('created_at', '<', $cutoff)
            ->whereNull('appointment_id');
    }

    /**
     * Audit entries whose subject is gone.
     *
     * Two shapes qualify. Rows with no subject at all are configuration events with
     * nothing to keep them attached to - a slot template that was deactivated and
     * later deleted, for instance. Rows naming a subject type that no longer has
     * that row are the orphaned case the absent foreign key leaves behind.
     *
     * Note what is excluded: an entry whose subject still exists is kept no matter
     * how old, because an audit trail that stops before the record it describes
     * stops being evidence.
     */
    private function prunableAudit(Carbon $cutoff): Builder
    {
        return AuditLog::query()
            ->where('created_at', '<', $cutoff)
            ->whereNotIn('action', self::PERMANENT_ACTIONS)
            ->where(function ($query) {
                $query->whereNull('subject_id');

                foreach (self::SUBJECT_TABLES as $type) {
                    $query->orWhere(function ($inner) use ($type) {
                        $inner->where('subject_type', $type)
                            ->whereNotIn('subject_id', $this->idsFor($type));
                    });
                }
            });
    }

    /**
     * Current ids for a subject type.
     *
     * Correlated per type rather than as one union so the generated SQL stays
     * readable in the log, which is where an operator looks when the prune does
     * something unexpected.
     */
    private function idsFor(string $model): Collection
    {
        return $model::query()->pluck('id');
    }
}
