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
            // Names are deliberately NOT faked as unique. The pool only has five
            // entries, so unique() exhausts it after five services in one process
            // and every later create() dies with an OverflowException. Nothing
            // requires distinct names - only the code is unique, because services
            // are addressed by it and the database enforces that.
            'name' => fake()->randomElement([
                'Certificate of Indigency',
                'Business Permit',
                'Barangay Clearance',
                'Police Clearance',
                'Certificate of Residency',
            ]),
            // The code is the public route key, so it has to be unique, and it is
            // generated rather than drawn from a small pool to avoid the same trap.
            'code' => 'SRV-'.strtoupper(fake()->unique()->bothify('###??')),
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
