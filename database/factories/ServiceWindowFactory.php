<?php

namespace Database\Factories;

use App\Models\ServiceWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceWindow>
 */
class ServiceWindowFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Window '.fake()->unique()->numberBetween(1, 9999).' - Service';

        return [
            'name' => $name,
            // code is capped at 20 chars in the schema, so derive a short
            // sequential code instead of slugging the (longer) display name.
            'code' => 'WIN-'.fake()->unique()->numerify('####'),
            'description' => fake()->sentence(),
            'avg_service_minutes' => fake()->randomElement([10, 15, 20]),
            'is_active' => true,
        ];
    }
}
