<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Order matters: staff accounts reference service windows, and the demo
        // activity needs both windows and services to exist.
        $seeders = [
            ServiceCatalogSeeder::class,
            UserSeeder::class,
            ScheduleSeeder::class,
            DemoActivitySeeder::class,
        ];

        /*
         * The browser suite's named fixtures, and only there.
         *
         * These are additive - they sit on top of the generated data rather than
         * replacing it, so the browser environment looks like a populated office
         * rather than a clean install - which is exactly why they cannot run
         * everywhere. They mint queue numbers A-001 and A-002 for WINDOW_1 on
         * today, and DemoActivitySeeder has already minted its own. The result is
         * two appointments sharing a queue number, which is not a fixture problem
         * but a real data-integrity violation, and SeededDataIntegrityTest says so
         * on the very first run that was not scoped to the browser environment.
         *
         * The previous version of this comment claimed they were "harmless in
         * every other environment". They were not, and the suite proved it.
         */
        if (app()->environment('browser')) {
            $seeders[] = BrowserFixturesSeeder::class;
        }

        $this->call($seeders);
    }
}
