<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'type' => TransactionType::Transfer,
            'module' => TransactionModule::General,
            'amount' => 100.00,
            'from_account_id' => Account::factory(),
            'to_account_id' => Account::factory(),
            'created_by' => User::factory(),
        ];
    }
}
