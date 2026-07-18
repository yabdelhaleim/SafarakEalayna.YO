<?php

namespace Database\Factories\Bus;

use App\Models\Bus\BusBooking;
use App\Models\Bus\BusRefundRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusRefundRequest>
 */
class BusRefundRequestFactory extends Factory
{
    protected $model = BusRefundRequest::class;

    public function definition(): array
    {
        $booking = BusBooking::factory()->paid()->create();
        $paid = (float) $booking->paid_amount;

        return [
            'bus_booking_id' => $booking->id,
            'company_id' => $booking->inventory->company_id ?? null,
            'refund_type' => 'cancel',
            'original_currency' => $booking->currency ?? 'EGP',
            'original_amount' => (float) $booking->total_price,
            'cancellation_fee' => 0,
            'company_penalty' => 0,
            'office_penalty' => 0,
            'total_paid' => $paid,
            'refund_amount' => $paid,
            'refund_currency' => $booking->currency ?? 'EGP',
            'refund_exchange_rate' => $booking->exchange_rate_to_egp ?? 1.0,
            'base_currency_refund' => $paid * ($booking->exchange_rate_to_egp ?? 1.0),
            'destination' => 'ledger',
            'treasury_id' => null,
            'account_id' => null,
            'transaction_id' => null,
            'status' => 'pending',
            'notes' => null,
            'processed_at' => null,
            'created_by' => $booking->created_by,
        ];
    }

    public function processed(): self
    {
        return $this->state(fn () => [
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    public function forBooking(BusBooking $booking, float $cancellationFee = 0): self
    {
        $paid = (float) $booking->paid_amount;
        $refund = max(0, $paid - $cancellationFee);

        return $this->state(fn () => [
            'bus_booking_id' => $booking->id,
            'company_id' => $booking->inventory->company_id ?? null,
            'original_currency' => $booking->currency ?? 'EGP',
            'original_amount' => (float) $booking->total_price,
            'cancellation_fee' => $cancellationFee,
            'company_penalty' => $cancellationFee > 0 ? $cancellationFee / 2 : 0,
            'office_penalty' => $cancellationFee > 0 ? $cancellationFee / 2 : 0,
            'total_paid' => $paid,
            'refund_amount' => $refund,
            'refund_currency' => $booking->currency ?? 'EGP',
            'refund_exchange_rate' => $booking->exchange_rate_to_egp ?? 1.0,
            'base_currency_refund' => $refund * ($booking->exchange_rate_to_egp ?? 1.0),
        ]);
    }
}
