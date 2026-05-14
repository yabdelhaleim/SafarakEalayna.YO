<?php

namespace App\Http\Resources\Bus;

use App\Enums\BusInventoryPaymentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusInventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paymentType = $this->payment_type instanceof BusInventoryPaymentType
            ? $this->payment_type
            : BusInventoryPaymentType::from($this->payment_type);

        return [
            'id' => $this->id,
            'company' => [
                'id' => $this->whenLoaded('company', fn () => $this->company?->id),
                'name' => $this->whenLoaded('company', fn () => $this->company?->name),
            ],
            'route' => $this->route,
            'travel_date' => $this->travel_date,
            'departure_time' => $this->departure_time,
            'total_tickets' => (int) $this->total_tickets,
            'available_tickets' => (int) $this->available_tickets,
            'sold_tickets' => (int) ($this->total_tickets - $this->available_tickets),
            'cost_per_ticket' => (float) $this->cost_per_ticket,
            'selling_price' => (float) $this->selling_price,
            'profit_per_ticket' => (float) ($this->selling_price - $this->cost_per_ticket),
            'payment_type' => $this->payment_type,
            'payment_type_label' => $paymentType->label(),
            'total_cost' => (float) $this->total_cost,
            'amount_paid' => (float) $this->amount_paid,
            'remaining_debt' => (float) $this->remaining_debt,
            'is_fully_paid' => (float) $this->remaining_debt <= 0,
            'account' => [
                'id' => $this->whenLoaded('account', fn () => $this->account?->id),
                'name' => $this->whenLoaded('account', fn () => $this->account?->name),
            ],
            'notes' => $this->notes,
            'created_by_id' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->id),
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
