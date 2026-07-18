<?php

namespace Database\Factories\Bus;

use App\Models\Account;
use App\Models\Bus\BusCompany;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusCompany>
 */
class BusCompanyFactory extends Factory
{
    protected $model = BusCompany::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => 'شركة باصات '.$name,
            'phone' => '010'.(string) $this->faker->unique()->numberBetween(10000000, 99999999),
            'address' => $this->faker->streetAddress(),
            'is_active' => true,
            'notes' => null,
            'account_id' => null,
            'created_by' => null,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withAccount(?float $balance = 0): self
    {
        return $this->afterCreating(function (BusCompany $company) use ($balance) {
            LedgerBalanceMutationGuard::run(function () use ($company, $balance) {
                $account = Account::create([
                    'name' => 'حساب شركة: '.$company->name,
                    'type' => \App\Enums\AccountType::Supplier,
                    'currency' => 'EGP',
                    'balance' => $balance,
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'bus',
                    'is_module_vault' => false,
                    'notes' => 'حساب تلقائي لـ BusCompany #'.$company->id,
                    'created_by' => $company->created_by ?? User::query()->value('id') ?? 1,
                ]);
                $company->update(['account_id' => $account->id]);
            });
        });
    }
}
