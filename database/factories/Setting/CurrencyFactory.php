<?php

namespace Database\Factories\Setting;

use App\Models\Setting\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    public function definition(): array
    {
        $code = 'CUR-'.$this->faker->unique()->randomNumber(5);

        return [
            'code' => $code,
            'name_ar' => $this->faker->words(2, true),
            'name_en' => $this->faker->words(2, true),
            'symbol' => $this->faker->currencyCode(),
            'exchange_rate' => $this->faker->randomFloat(4, 10, 100),
            'is_active' => $this->faker->boolean(90),
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

    public function usd(): self
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'USD',
            'name_ar' => 'دولار أمريكي',
            'name_en' => 'US Dollar',
            'symbol' => '$',
        ]);
    }

    public function eur(): self
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'EUR',
            'name_ar' => 'يورو',
            'name_en' => 'Euro',
            'symbol' => '€',
        ]);
    }

    public function withExchangeRate(float $rate): self
    {
        return $this->state(fn (array $attributes) => [
            'exchange_rate' => $rate,
        ]);
    }
}
