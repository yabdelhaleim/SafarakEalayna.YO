<?php

namespace Database\Factories\Bus;

use App\Enums\BusCompanyPaymentStatus;
use App\Models\Bus\BusCompanyPayment;
use App\Models\Bus\BusInventory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusCompanyPayment>
 */
class BusCompanyPaymentFactory extends Factory
{
    protected $model = BusCompanyPayment::class;

    public function definition(): array
    {
        $inventory = BusInventory::factory()->deferred()->create();

        return [
            'company_id' => $inventory->company_id,
            'inventory_id' => $inventory->id,
            'amount' => (float) $inventory->remaining_debt > 0 ? (float) $inventory->remaining_debt : 100,
            'account_id' => null,
            'transaction_id' => null,
            'status' => BusCompanyPaymentStatus::Paid,
            'notes' => null,
            'created_by' => User::query()->inRandomOrder()->value('id'),
        ];
    }
}
