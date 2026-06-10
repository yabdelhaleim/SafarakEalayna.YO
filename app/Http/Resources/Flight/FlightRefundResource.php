<?php

namespace App\Http\Resources\Flight;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlightRefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flight_booking_id' => $this->flight_booking_id,
            'booking_id' => $this->flight_booking_id,
            'airline_penalty' => (float) $this->airline_penalty,
            'office_penalty' => (float) $this->office_penalty,
            'total_paid' => (float) $this->total_paid,
            'refund_amount' => (float) $this->refund_amount,
            'status' => $this->status,
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
