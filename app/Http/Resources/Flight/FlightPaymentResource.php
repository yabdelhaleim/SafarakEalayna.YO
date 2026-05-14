<?php

namespace App\Http\Resources\Flight;

use App\Enums\FlightPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlightPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $method = $this->payment_method instanceof FlightPaymentMethod
            ? $this->payment_method
            : FlightPaymentMethod::tryFrom((string) $this->payment_method) ?? FlightPaymentMethod::Cash;

        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'amount' => (float) $this->amount,
            'payment_method' => $method->value,
            'method' => $method->value,
            'method_label' => $method->label(),
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ]),
            'transaction_id' => $this->transaction_id,
            'notes' => $this->notes,
            'created_by_id' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->id),
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
