<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Services\QueueOrderingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Prints the queue the way the staff console would show it. Useful for
 * verifying the ordering rules from the terminal without a browser.
 */
class ShowQueueCommand extends Command
{
    protected $signature = 'govqueue:queue
                            {--window= : Limit to a window code, e.g. WINDOW_1}
                            {--date= : Queue date (Y-m-d), defaults to today}';

    protected $description = 'Show the current queue in service order';

    public function handle(): int
    {
        $date = $this->option('date') ?: today()->toDateString();

        $query = Appointment::query()
            ->with(['service', 'user'])
            ->whereDate('queue_date', $date)
            ->whereIn('status', ['confirmed', 'checked_in', 'skipped', 'serving'])
            ->inQueueOrder($date);

        if ($this->option('window')) {
            $window = ServiceWindow::where('code', $this->option('window'))->first();

            if (! $window) {
                $this->error("Unknown window: {$this->option('window')}");

                return self::FAILURE;
            }

            $query->where('service_window_id', $window->id);
        }

        $appointments = $query->get();

        if ($appointments->isEmpty()) {
            $this->warn("No active queue on {$date}.");
            $this->line('Upcoming open dates: '.TimeSlot::query()
                ->whereDate('slot_date', '>', today())
                ->distinct()
                ->orderBy('slot_date')
                ->limit(3)
                ->pluck('slot_date')
                ->map(fn ($d) => Carbon::parse($d)->format('D, M d'))
                ->implode(' | '));

            return self::SUCCESS;
        }

        $this->info("Queue for {$date} ({$appointments->count()} active)");
        $this->newLine();

        $rows = $appointments->map(fn (Appointment $a) => [
            $a->queue_number ?? '-',
            $a->status->label(),
            $a->priorityStatusLabel(),
            $a->service->name,
            $a->user->name,
            $a->checked_in_at?->format('H:i') ?? '-',
        ]);

        $this->table(['Queue #', 'Status', 'Priority', 'Service', 'Client', 'In at'], $rows);

        $this->newLine();
        $this->comment('Ordering: '.implode(' ', QueueOrderingService::RULE_DESCRIPTION));

        return self::SUCCESS;
    }
}
