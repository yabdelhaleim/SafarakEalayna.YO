<?php

namespace Database\Factories\Fawry;

use App\Models\Fawry\FawryOperationType;
use Illuminate\Database\Eloquent\Factories\Factory;

class FawryOperationTypeFactory extends Factory
{
    protected $model = FawryOperationType::class;

    public function definition(): array
    {
        $code = $this->faker->unique()->randomElement(['bill_payment', 'mobile_recharge', 'internet_payment', 'landline', 'donation']);

        return [
            'code' => $code,
            'name_ar' => $this->faker->words(2, true),
            'name_en' => $this->faker->words(2, true),
            'color' => $this->faker->hexColor(),
            'icon' => 'heroicon-o-'.str_replace('_', '-', $code),
            'description_ar' => $this->faker->optional()->sentence(),
            'description_en' => $this->faker->optional()->sentence(),
            'is_active' => $this->faker->boolean(90), // 90% chance of being active
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

    public function billPayment(): self
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'bill_payment',
            'name_ar' => 'دفع فواتير',
            'name_en' => 'Bill Payment',
        ]);
    }

    public function mobileRecharge(): self
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'mobile_recharge',
            'name_ar' => 'شحن موبايل',
            'name_en' => 'Mobile Recharge',
        ]);
    }

    public function ordered(int $order): self
    {
        return $this->state(fn (array $attributes) => [
            'order' => $order,
        ]);
    }
}
