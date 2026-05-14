<?php

namespace App\Http\Resources\Bus;

use App\Enums\BusBookingStatus;
use App\Enums\BusPaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof BusBookingStatus
            ? $this->status
            : ($this->status ? BusBookingStatus::from($this->status) : null);

        $paymentStatus = $this->payment_status instanceof BusPaymentStatus
            ? $this->payment_status
            : ($this->payment_status ? BusPaymentStatus::from($this->payment_status) : null);

        return [
            'id' => $this->id,
            'inventory' => [
                'id' => $this->whenLoaded('inventory', fn () => $this->inventory?->id),
                'route' => $this->whenLoaded('inventory', fn () => $this->inventory?->route),
                'travel_date' => $this->whenLoaded('inventory', fn () => $this->inventory?->travel_date),
                'departure_time' => $this->whenLoaded('inventory', fn () => $this->inventory?->departure_time),
            ],
            'company' => [
                'id' => $this->when($this->relationLoaded('inventory') && $this->inventory?->relationLoaded('company'), fn () => $this->inventory?->company?->id),
                'name' => $this->when($this->relationLoaded('inventory') && $this->inventory?->relationLoaded('company'), fn () => $this->inventory?->company?->name),
            ],
            'customer' => [
                'id' => $this->whenLoaded('customer', fn () => $this->customer?->id),
                'name' => $this->whenLoaded('customer', fn () => $this->customer?->full_name),
                'phone' => $this->whenLoaded('customer', fn () => $this->customer?->phone),
            ],
            'employee' => [
                'id' => $this->whenLoaded('employee', fn () => $this->employee?->id),
                'name' => $this->when($this->relationLoaded('employee') && $this->employee?->relationLoaded('user'), fn () => $this->employee?->user?->name),
            ],
            'quantity' => (int) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'total_price' => (float) $this->total_price,
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'profit' => (float) $this->profit,
            'status' => $this->status,
            'status_label' => $status?->label(),
            'payment_status' => $this->payment_status,
            'payment_status_label' => $paymentStatus?->label(),
            'account' => [
                'id' => $this->whenLoaded('account', fn () => $this->account?->id),
                'name' => $this->whenLoaded('account', fn () => $this->account?->name),
            ],
            'transaction_id' => $this->transaction_id,
            'notes' => $this->notes,
            'created_by_id' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->id),
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'payment_method' => $payment->payment_method,
                'account_id' => $payment->account_id,
                'transaction_id' => $payment->transaction_id,
                'notes' => $payment->notes,
                'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
            ])->toArray()),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
