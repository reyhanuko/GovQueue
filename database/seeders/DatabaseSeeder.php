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
        $this->call([
            ServiceCatalogSeeder::class,
            UserSeeder::class,
            ScheduleSeeder::class,
            DemoActivitySeeder::class,
        ]);
    }
}
