<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\ClientNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sends appointment reminders.
 *
 * Scheduled hourly by routes/console.php. Which offsets have gone out are
 * recorded on the appointment itself (appointments.reminders_sent), so an
 * appointment can never be reminded twice for the same offset even if two runs
 * overlap or the schedule fires twice.
 */
class SendAppointmentRemindersCommand extends Command
{
    protected $signature = 'govqueue:send-reminders
                            {--dry-run : Report what would be sent without sending}
                            {--hours= : Only consider this reminder offset}';

    protected $description = 'Send appointment reminders for upcoming appointments';

    /** Half-width of the due window, so an hourly schedule always catches it. */
    private const TOLERANCE_MINUTES = 30;

    public function handle(ClientNotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $offsets = $this->option('hours') !== null
            ? [(int) $this->option('hours')]
            : array_map('intval', (array) config('govqueue.notifications.reminder_hours_before', [24, 1]));

        $offsets = array_values(array_filter($offsets, fn ($h) => $h > 0));

        if ($offsets === []) {
            $this->warn('No valid reminder offsets are configured; nothing to do.');

            return self::SUCCESS;
        }

        if (! config('govqueue.notifications.enabled', true)) {
            $this->warn('Notifications are disabled (govqueue.notifications.enabled). Nothing to do.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;

        foreach ($offsets as $hours) {
            foreach ($this->dueAppointments(now()->addHours($hours)) as $appointment) {
                if ($appointment->hasSentReminder($hours)) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '  [dry] %dh reminder -> %s (%s) at %s',
                        $hours,
                        $appointment->user?->email ?? 'no email on file',
                        $appointment->reference_number,
                        $appointment->scheduled_at?->format('D d M g:i A'),
                    ));
                    $sent++;

                    continue;
                }

                // Marked before sending so a crash mid-send cannot cause a
                // duplicate on the next run.
                $appointment->markReminderSent($hours);
                $notifications->reminder($appointment, $hours);
                $sent++;
            }
        }

        $this->info(sprintf(
            '%s %d reminder(s); %d skipped as already sent.',
            $dryRun ? 'Would send' : 'Sent',
            $sent,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * Appointments due within the tolerance window around the target time.
     *
     * @return Collection<int, Appointment>
     */
    private function dueAppointments(Carbon $target): Collection
    {
        return Appointment::query()
            ->with(['user', 'service', 'window'])
            ->whereIn('status', [
                AppointmentStatus::Pending->value,
                AppointmentStatus::Confirmed->value,
            ])
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [
                $target->copy()->subMinutes(self::TOLERANCE_MINUTES),
                $target->copy()->addMinutes(self::TOLERANCE_MINUTES),
            ])
            ->get();
    }
}
