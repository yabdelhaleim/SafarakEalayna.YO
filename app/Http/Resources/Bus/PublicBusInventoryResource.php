<?php

namespace App\Http\Resources\Bus;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicBusInventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'selling_price' => (float) $this->selling_price,
        ];
    }
}
