<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' Account',
            'type' => $this->faker->randomElement([
                AccountType::Bank,
                AccountType::Cashbox,
                AccountType::Wallet,
                AccountType::Bank,
            ]),
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'office',
            'currency' => $this->faker->randomElement(['EGP', 'USD', 'EUR', 'SAR', 'KWD']),
            'balance' => $this->faker->randomFloat(2, 1000, 100000),
            'is_active' => $this->faker->boolean(90),
            'notes' => $this->faker->optional()->sentence(),
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

    public function bank(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => AccountType::Bank,
        ]);
    }

    public function cash(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => AccountType::Cashbox,
        ]);
    }

    public function wallet(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => AccountType::Wallet,
        ]);
    }

    public function withBalance(float $balance): self
    {
        return $this->state(fn (array $attributes) => [
            'balance' => $balance,
        ]);
    }
}
