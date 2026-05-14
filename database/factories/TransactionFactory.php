<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\TransactionType;
use App\Enums\TransactionModule;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(['income', 'expense', 'transfer']);

        return [
            'type' => $type,
            'amount' => $this->faker->randomFloat(2, 100, 10000),
            'from_account_id' => $type === 'expense' || $type === 'transfer' ? Account::factory() : null,
            'to_account_id' => $type === 'income' || $type === 'transfer' ? Account::factory() : null,
            'module' => $this->faker->randomElement(['Flight', 'Bus', 'Service', 'Online', 'Fawry', 'General']),
            'related_type' => null,
            'related_id' => null,
            'notes' => $this->faker->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function income(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'income',
        ]);
    }

    public function expense(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'expense',
        ]);
    }

    public function transfer(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'transfer',
        ]);
    }

    public function withAmount(float $amount): self
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
        ]);
    }

    public function forModule(string $module): self
    {
        return $this->state(fn (array $attributes) => [
            'module' => $module,
        ]);
    }

    public function relatedTo(string $relatedType, int $relatedId): self
    {
        return $this->state(fn (array $attributes) => [
            'related_type' => $relatedType,
            'related_id' => $relatedId,
        ]);
    }
}
