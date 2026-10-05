<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The model casts `password` to 'hashed', so the factory supplies a plain
     * string and lets the cast do the hashing. Supplying an already-hashed value
     * here would double-hash it and break sign-in.
     */
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= 'password',
            'role' => UserRole::Client,
            'phone' => '09'.fake()->numerify('#########'),
            'address' => fake()->streetAddress(),
            'barangay' => fake()->randomElement([
                'Poblacion', 'Batang', 'Crossing', 'Datu Angelino',
                'Lime', 'Maligunong', 'New Corella', 'Pangian',
                'Rajah Buayan', 'San Agustin', 'San Isidro',
                'San Jose', 'San Miguel', 'San Vicente', 'Tampacan',
                'Tiwi', 'Tupig', 'Tubod',
            ]),
            'birthdate' => fake()->dateTimeBetween('-70 years', '-18 years'),
            'is_active' => true,
            // Stated explicitly rather than left to the column default: a model
            // that was never refreshed from the database has no attribute for a
            // column the INSERT omitted, so `notify_email` would read as null in
            // memory and appear to be opted out.
            'notify_email' => true,
            'notify_sms' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function client(): static
    {
        return $this->state(fn () => ['role' => UserRole::Client]);
    }

    public function staff(): static
    {
        return $this->state(fn () => ['role' => UserRole::Staff]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    /** A birthdate that puts the user over the RA 9994 threshold of 60. */
    public function seniorCitizen(): static
    {
        return $this->state(fn () => [
            'birthdate' => fake()->dateTimeBetween('-85 years', '-60 years'),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
