<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'service_window_id' => ServiceWindow::factory(),
            'name' => fake()->unique()->randomElement([
                'Certificate of Indigency',
                'Business Permit',
                'Barangay Clearance',
                'Police Clearance',
                'Certificate of Residency',
            ]),
            'code' => strtoupper(fake()->unique()->bothify('SRV-###')),
            'description' => fake()->sentence(),
            'requirements' => "Valid ID\nCommunity Tax Certificate",
            'fee' => fake()->randomElement([0, 0, 50, 100, 200]),
            'duration_minutes' => fake()->randomElement([10, 15, 20]),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
