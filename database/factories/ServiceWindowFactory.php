<?php

namespace Database\Factories;

use App\Models\ServiceWindow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceWindow>
 *
 * A window is staffed by default.
 *
 * Decision D2 makes a window with no active staff unbookable, so an unstaffed
 * factory would make almost every booking test fail on the staffing rule rather
 * than on the thing it was written to check - concurrency, notifications, queue
 * numbers. Eighty-four tests were in that position.
 *
 * The staffed default matches what a window is for. An unstaffed window is a
 * deliberate, unusual state, so it is opted into explicitly with
 * unstaffed() - which is what the tests about D2 itself use.
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

    /**
     * A window nobody is assigned to. Decision D2 says this is unbookable.
     *
     * Implemented by removing the staff the default just added, rather than by
     * a flag on the factory. Passing any attribute to create() routes through
     * state(), which builds a new factory instance - so a flag set on this one
     * would be gone by the time the model is made, and the window would come
     * back staffed. Removing the row afterwards survives that, because it does
     * not depend on which instance ends up doing the work.
     */
    public function unstaffed(): static
    {
        return $this->afterCreating(function (ServiceWindow $window): void {
            User::where('service_window_id', $window->id)->delete();
        });
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ServiceWindow $window): void {
            User::factory()->staff()->create([
                'service_window_id' => $window->id,
                'is_active' => true,
            ]);
        });
    }
}
