<?php

namespace Database\Factories\Bus;

use App\Models\Bus\BusBooking;
use App\Models\Bus\BusPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusPayment>
 */
class BusPaymentFactory extends Factory
{
    protected $model = BusPayment::class;

    public function definition(): array
    {
        $booking = BusBooking::factory()->create();

        return [
            'booking_id' => $booking->id,
            'amount' => $booking->total_price,
            'payment_method' => 'cash',
            'account_id' => null,
            'transaction_id' => null,
            'currency' => $booking->currency ?? 'EGP',
            'exchange_rate_to_egp' => $booking->exchange_rate_to_egp ?? 1.0,
            'notes' => null,
            'created_by' => User::query()->inRandomOrder()->value('id'),
        ];
    }

    public function forBooking(BusBooking $booking, float $amount = null): self
    {
        return $this->state(fn () => [
            'booking_id' => $booking->id,
            'amount' => $amount ?? (float) $booking->total_price,
            'currency' => $booking->currency ?? 'EGP',
            'exchange_rate_to_egp' => $booking->exchange_rate_to_egp ?? 1.0,
        ]);
    }
}
