<?php

namespace Database\Factories\Bus;

use App\Enums\BusInventoryPaymentType;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusInventory>
 */
class BusInventoryFactory extends Factory
{
    protected $model = BusInventory::class;

    public function definition(): array
    {
        $tickets = $this->faker->numberBetween(20, 50);
        $cost = $this->faker->numberBetween(70, 150);
        $selling = $cost + $this->faker->numberBetween(20, 60);
        $totalCost = $tickets * $cost;

        return [
            'company_id' => BusCompany::factory(),
            'route' => $this->faker->randomElement(['القاهرة - الإسكندرية', 'القاهرة - أسوان', 'الإسكندرية - القاهرة', 'الجيزة - أسوان', 'القاهرة - شرم الشيخ']),
            'travel_date' => now()->addDays($this->faker->numberBetween(1, 30))->toDateString(),
            'departure_time' => $this->faker->randomElement(['06:00', '08:00', '10:00', '14:00', '20:00', '22:00']),
            'total_tickets' => $tickets,
            'available_tickets' => $tickets,
            'cost_per_ticket' => $cost,
            'selling_price' => $selling,
            'payment_type' => BusInventoryPaymentType::Deferred,
            'total_cost' => $totalCost,
            'amount_paid' => 0,
            'remaining_debt' => $totalCost,
            'account_id' => null,
            'transaction_id' => null,
            'is_auto_created' => false,
            'notes' => null,
            'currency' => 'EGP',
            'created_by' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (BusInventory $inventory): void {
            if ((float) $inventory->total_cost <= 0) {
                $inventory->total_cost = round(
                    (float) $inventory->total_tickets * (float) $inventory->cost_per_ticket,
                    2
                );
            }

            if ($inventory->payment_type === BusInventoryPaymentType::Deferred
                && (float) $inventory->remaining_debt <= 0
                && (float) $inventory->amount_paid <= 0) {
                $inventory->remaining_debt = (float) $inventory->total_cost;
            }
        });
    }

    public function unlimited(): self
    {
        return $this->state(fn () => [
            'total_tickets' => 999999,
            'available_tickets' => 999999,
        ]);
    }

    public function withSeats(int $total, int $available): self
    {
        return $this->state(fn () => [
            'total_tickets' => $total,
            'available_tickets' => $available,
        ]);
    }

    public function soldOut(): self
    {
        return $this->state(fn () => [
            'total_tickets' => 30,
            'available_tickets' => 0,
        ]);
    }

    public function cash(): self
    {
        return $this->state(fn () => [
            'payment_type' => BusInventoryPaymentType::Cash,
        ]);
    }

    public function inCurrency(string $currency, float $costRateToEgp = 50.0, ?float $exchangeRate = null): self
    {
        $rate = $exchangeRate ?? match (strtoupper($currency)) {
            'USD' => 50.0,
            'SAR' => 13.33,
            'KWD' => 162.5,
            'EUR' => 54.5,
            default => 1.0,
        };

        return $this->state(function (array $attrs) use ($currency, $rate) {
            $cost = $attrs['cost_per_ticket'];
            $selling = $attrs['selling_price'];

            return [
                'currency' => strtoupper($currency),
                'cost_per_ticket' => round($cost / $rate, 2),
                'selling_price' => round($selling / $rate, 2),
                'exchange_rate_to_egp' => $rate,
            ];
        });
    }

    public function autoCreated(): self
    {
        return $this->state(fn () => ['is_auto_created' => true]);
    }
}
