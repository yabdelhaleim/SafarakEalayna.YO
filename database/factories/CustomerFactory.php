<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'phone' => '+96650' . fake()->randomNumber(7),
            'email' => fake()->unique()->safeEmail(),
            'national_id' => fake()->numerify('#########'),
            'passport_number' => 'A' . fake()->numerify('########'),
            'passport_expiry' => fake()->date(),
            'date_of_birth' => fake()->date(),
            'city' => fake()->city(),
            'affiliation' => fake()->company(),
            'customer_tier' => \App\Enums\CustomerTier::STANDARD->value,
            'notes' => fake()->sentence(),
            'created_by' => 1,
        ];
    }
}
