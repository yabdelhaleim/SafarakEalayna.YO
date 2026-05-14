<?php

namespace Database\Factories\Fawry;

use App\Models\Fawry\FawryCurrency;
use App\Models\Setting\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

class FawryCurrencyFactory extends Factory
{
    protected $model = FawryCurrency::class;

    public function definition(): array
    {
        return [
            'currency_id' => Currency::factory(),
            'exchange_rate' => $this->faker->randomFloat(4, 10, 100),
            'min_amount' => $this->faker->optional()->randomFloat(2, 10, 100),
            'max_amount' => $this->faker->optional()->randomFloat(2, 5000, 50000),
            'fee_percent' => $this->faker->randomFloat(2, 0, 5),
            'fixed_fee' => $this->faker->randomFloat(2, 0, 20),
            'is_active' => $this->faker->boolean(90),
            'order' => $this->faker->numberBetween(1, 100),
        ];
    }

    public function active(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withExchangeRate(float $rate): self
    {
        return $this->state(fn (array $attributes) => [
            'exchange_rate' => $rate,
        ]);
    }

    public function withLimits(float $min, float $max): self
    {
        return $this->state(fn (array $attributes) => [
            'min_amount' => $min,
            'max_amount' => $max,
        ]);
    }

    public function withFees(float $percent, float $fixed): self
    {
        return $this->state(fn (array $attributes) => [
            'fee_percent' => $percent,
            'fixed_fee' => $fixed,
        ]);
    }

    public function ordered(int $order): self
    {
        return $this->state(fn (array $attributes) => [
            'order' => $order,
        ]);
    }
}
