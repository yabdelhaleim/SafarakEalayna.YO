<?php

namespace Database\Factories\Bus;

use App\Enums\BusBookingStatus;
use App\Enums\BusPaymentStatus;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusBooking>
 */
class BusBookingFactory extends Factory
{
    protected $model = BusBooking::class;

    public function definition(): array
    {
        $inventory = BusInventory::factory()->create();

        $qty = $this->faker->numberBetween(1, 4);
        $unit = (float) $inventory->selling_price;
        $total = round($qty * $unit, 2);

        return [
            'inventory_id' => $inventory->id,
            'customer_id' => Customer::factory(),
            'employee_id' => null,
            'quantity' => $qty,
            'unit_price' => $unit,
            'total_price' => $total,
            'paid_amount' => 0,
            'payment_status' => BusPaymentStatus::Pending,
            'profit' => round(($unit - (float) $inventory->cost_per_ticket) * $qty, 2),
            'status' => BusBookingStatus::Pending,
            'account_id' => null,
            'transaction_id' => null,
            'currency' => $inventory->currency ?? 'EGP',
            'exchange_rate_to_egp' => $inventory->exchange_rate_to_egp ?? 1.0,
            'notes' => null,
            'created_by' => User::query()->inRandomOrder()->value('id'),
        ];
    }

    public function paid(): self
    {
        return $this->state(function (array $attrs) {
            return [
                'paid_amount' => $attrs['total_price'],
                'payment_status' => BusPaymentStatus::Paid,
                'status' => BusBookingStatus::Paid,
            ];
        });
    }

    public function partial(float $amount): self
    {
        return $this->state(fn (array $attrs) => [
            'paid_amount' => $amount,
            'payment_status' => BusPaymentStatus::Partial,
            'status' => BusBookingStatus::Pending,
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn () => [
            'status' => BusBookingStatus::Cancelled,
        ]);
    }

    public function refunded(): self
    {
        return $this->state(fn () => [
            'status' => BusBookingStatus::Refunded,
        ]);
    }

    public function forInventory(BusInventory $inventory): self
    {
        return $this->state(function () use ($inventory) {
            $qty = $this->faker->numberBetween(1, max(1, min(4, (int) $inventory->available_tickets)));
            $unit = (float) $inventory->selling_price;
            $total = round($qty * $unit, 2);

            return [
                'inventory_id' => $inventory->id,
                'quantity' => $qty,
                'unit_price' => $unit,
                'total_price' => $total,
                'profit' => round(($unit - (float) $inventory->cost_per_ticket) * $qty, 2),
                'currency' => $inventory->currency ?? 'EGP',
                'exchange_rate_to_egp' => $inventory->exchange_rate_to_egp ?? 1.0,
            ];
        });
    }

    public function forCustomer($customerId): self
    {
        return $this->state(fn () => ['customer_id' => $customerId]);
    }
}
