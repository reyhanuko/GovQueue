<?php

namespace App\Console\Commands;

use App\Services\SlotGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateSlotsCommand extends Command
{
    protected $signature = 'govqueue:generate-slots
                            {--days=30 : Number of days ahead to generate}
                            {--from= : Start date (Y-m-d), defaults to today}';

    protected $description = 'Expand recurring slot templates into concrete bookable time slots';

    public function handle(SlotGeneratorService $generator): int
    {
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : today();

        $to = $from->copy()->addDays((int) $this->option('days'));

        $result = $generator->generate($from, $to);

        $this->info("Generated {$result['created']} new slots ({$result['skipped']} already existed).");
        $this->line("  Range: {$from->toDateString()} to {$to->toDateString()}");

        return self::SUCCESS;
    }
}
