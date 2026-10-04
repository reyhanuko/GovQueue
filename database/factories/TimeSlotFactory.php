<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<TimeSlot>
 */
class TimeSlotFactory extends Factory
{
    public function definition(): array
    {
        $start = Carbon::instance(fake()->dateTimeBetween('+1 day', '+20 days'))
            ->setTime(fake()->numberBetween(8, 15), fake()->randomElement([0, 30]));

        $service = Service::factory();

        return [
            'service_id' => $service,
            'service_window_id' => ServiceWindow::factory(),
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $start->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            'capacity' => 5,
            'booked_count' => 0,
            'is_open' => true,
        ];
    }

    public function full(): static
    {
        return $this->state(fn () => ['booked_count' => fn (array $a) => $a['capacity']]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['is_open' => false]);
    }

    public function forDate(Carbon|string $date): static
    {
        return $this->state(function () use ($date) {
            $day = Carbon::parse($date)->startOfDay();
            $start = $day->copy()->setTime(9, 0);

            return [
                'slot_date' => $day->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
                'start_at' => $start->format('Y-m-d H:i:s'),
                'end_at' => $start->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            ];
        });
    }
}
