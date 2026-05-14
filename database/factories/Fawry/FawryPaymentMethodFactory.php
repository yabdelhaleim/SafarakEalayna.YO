<?php

namespace Database\Factories\Fawry;

use App\Models\Account;
use App\Models\Fawry\FawryPaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class FawryPaymentMethodFactory extends Factory
{
    protected $model = FawryPaymentMethod::class;

    public function definition(): array
    {
        $code = $this->faker->unique()->randomElement(['cash', 'bank_transfer', 'cash_wallet', 'office_safe', 'office_drawer']);

        return [
            'code' => $code,
            'name_ar' => $this->faker->words(2, true),
            'name_en' => $this->faker->words(2, true),
            'color' => $this->faker->hexColor(),
            'icon' => 'heroicon-o-'.str_replace('_', '-', $code),
            'description_ar' => $this->faker->optional()->sentence(),
            'description_en' => $this->faker->optional()->sentence(),
            'provider_name' => $this->faker->optional()->company(),
            'account_number' => $this->faker->optional()->bankAccountNumber(),
            'phone_number' => $this->faker->optional()->phoneNumber(),
            'bank_name' => $this->faker->optional()->company(),
            'branch_name' => $this->faker->optional()->streetName(),
            'default_account_id' => Account::factory(),
            'is_active' => $this->faker->boolean(90),
            'order' => $this->faker->numberBetween(1, 100),
            'metadata' => $this->faker->optional()->randomElement([
                ['processing_time' => 'instant', 'fees' => 0.0],
                ['processing_time' => '1-24 hours', 'fees' => 5.0],
            ]),
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

    public function cash(): self
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'cash',
            'name_ar' => 'نقدي',
            'name_en' => 'Cash',
        ]);
    }

    public function bankTransfer(): self
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'bank_transfer',
            'name_ar' => 'تحويل بنكي',
            'name_en' => 'Bank Transfer',
        ]);
    }

    public function withBankDetails(string $bank, string $accountNumber): self
    {
        return $this->state(fn (array $attributes) => [
            'bank_name' => $bank,
            'account_number' => $accountNumber,
        ]);
    }

    public function ordered(int $order): self
    {
        return $this->state(fn (array $attributes) => [
            'order' => $order,
        ]);
    }
}
