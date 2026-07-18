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
            'created_by' => null,
        ];
    }

    public function withBusAccount(float $balance = 0, string $currency = 'EGP'): self
    {
        return $this->afterCreating(function (\App\Models\Customer $customer) use ($balance, $currency) {
            \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($customer, $balance, $currency) {
                $account = \App\Models\Account::create([
                    'name' => 'حساب العميل: '.$customer->full_name,
                    'type' => \App\Enums\AccountType::Customer,
                    'currency' => $currency,
                    'balance' => $balance,
                    'is_active' => true,
                    'owner_type' => \App\Models\Account::OWNER_TYPE_OWNER,
                    'module_type' => 'bus',
                    'is_module_vault' => false,
                    'notes' => 'حساب تلقائي للعميل #'.$customer->id,
                    'created_by' => $customer->created_by ?? 1,
                ]);
                $customer->update(['account_id' => $account->id]);
            });
        });
    }

    public function phone(string $phone): self
    {
        return $this->state(fn () => ['phone' => $phone]);
    }
}
