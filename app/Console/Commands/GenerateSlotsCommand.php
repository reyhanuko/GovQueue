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

        /*
         * Reported whenever it is non-zero.
         *
         * A scheduled run's output goes to a log nobody reads on a good day, so
         * the count is here for the bad day: if an administrator's overrides are
         * being preserved, that is worth seeing, and if the number is unexpectedly
         * large it means a lot of slots stopped following their template.
         */
        if ($result['preserved'] > 0) {
            $this->line(
                "  Preserved {$result['preserved']} administrator-set capacity override(s); "
                .'those slots will not follow their template.'
            );
        }

        return self::SUCCESS;
    }
}
