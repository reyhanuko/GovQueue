<?php

namespace Database\Seeders;

use App\Models\Holiday;
use App\Models\Service;
use App\Models\SlotTemplate;
use Illuminate\Database\Seeder;

class ScheduleSeeder extends Seeder
{
    public function run(): void
    {
        // Monday-Friday, 8:00-12:00 and 13:00-17:00.
        $weekdays = [1, 2, 3, 4, 5];
        $saturday = [6];

        foreach (Service::with('window')->get() as $service) {
            // Window 3 (clearance) keeps a Saturday morning slot.
            $days = $service->service_window_id === Service::where('code', 'BRGY-CLR')->value('service_window_id')
                ? [...$weekdays, ...$saturday]
                : $weekdays;

            foreach ($days as $day) {
                SlotTemplate::updateOrCreate(
                    [
                        'service_id' => $service->id,
                        'day_of_week' => $day,
                        'start_time' => '08:00:00',
                    ],
                    [
                        'end_time' => '12:00:00',
                        'slot_minutes' => 30,
                        'capacity' => 4,
                        'effective_from' => today()->startOfYear()->toDateString(),
                        'effective_to' => today()->addYear()->toDateString(),
                        'is_active' => true,
                    ],
                );

                SlotTemplate::updateOrCreate(
                    [
                        'service_id' => $service->id,
                        'day_of_week' => $day,
                        'start_time' => '13:00:00',
                    ],
                    [
                        'end_time' => '17:00:00',
                        'slot_minutes' => 30,
                        'capacity' => 4,
                        'effective_from' => today()->startOfYear()->toDateString(),
                        'effective_to' => today()->addYear()->toDateString(),
                        'is_active' => true,
                    ],
                );
            }
        }

        // Sample closures so the "office is closed" path is exercisable.
        foreach ([['+45 days', 'Local Government Week'], ['+90 days', 'Office Maintenance']] as [$offset, $label]) {
            Holiday::updateOrCreate(
                ['date' => today()->add($offset)->toDateString()],
                ['name' => $label],
            );
        }
    }
}
