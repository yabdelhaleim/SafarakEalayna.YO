<?php

namespace Database\Factories\Fawry;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryTransaction;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FawryTransactionFactory extends Factory
{
    protected $model = FawryTransaction::class;

    public function definition(): array
    {
        $fawryPrice = $this->faker->randomFloat(2, 50, 500);
        $sellingPrice = $fawryPrice + $this->faker->randomFloat(2, 5, 50);

        return [
            'client_id' => Customer::factory(),
            'client_name' => $this->faker->name(),
            'operation_type' => $this->faker->randomElement(['bill_payment', 'mobile_recharge', 'internet_payment', 'landline']),
            'client_amount' => $this->faker->randomFloat(2, 50, 500),
            'fawry_price' => $fawryPrice,
            'selling_price' => $sellingPrice,
            'profit' => $sellingPrice - $fawryPrice,
            'employee_id' => User::factory(),
            'account_id' => Account::factory(),
            'currency_id' => Currency::factory(),
            'payment_method' => $this->faker->randomElement(['cash', 'bank_transfer', 'cash_wallet', 'office_safe', 'office_drawer']),
            'amount' => $sellingPrice,
            'reference_number' => $this->faker->regexify('[A-Z]{3}[0-9]{6}'),
            'notes' => $this->faker->optional()->sentence(),
            'payment_details' => $this->faker->optional()->randomElement([
                ['bank_name' => 'Bank Misr', 'account_number' => $this->faker->bankAccountNumber()],
                ['wallet_provider' => 'Vodafone Cash', 'phone_number' => $this->faker->phoneNumber()],
            ]),
            'expense_transaction_id' => Transaction::factory(),
            'income_transaction_id' => Transaction::factory(),
        ];
    }

    public function billPayment(): self
    {
        return $this->state(fn (array $attributes) => [
            'operation_type' => 'bill_payment',
        ]);
    }

    public function mobileRecharge(): self
    {
        return $this->state(fn (array $attributes) => [
            'operation_type' => 'mobile_recharge',
        ]);
    }

    public function internetPayment(): self
    {
        return $this->state(fn (array $attributes) => [
            'operation_type' => 'internet_payment',
        ]);
    }

    public function cash(): self
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => 'cash',
        ]);
    }

    public function bankTransfer(): self
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => 'bank_transfer',
        ]);
    }

    public function withProfit(float $profit): self
    {
        return $this->state(fn (array $attributes) => [
            'selling_price' => $attributes['fawry_price'] + $profit,
            'profit' => $profit,
        ]);
    }
}
