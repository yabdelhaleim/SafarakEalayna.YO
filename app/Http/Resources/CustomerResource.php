<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $apiType = 'regular';
        if ($this->type instanceof \App\Enums\CustomerType) {
            $apiType = $this->type === \App\Enums\CustomerType::Company ? 'counter' : 'regular';
        } else {
            $apiType = $this->type === 'company' ? 'counter' : 'regular';
        }

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'name' => $this->full_name,
            'phone' => $this->phone,
            'national_id' => $this->national_id,
            'passport_number' => $this->passport_number,
            'passport_expiry' => $this->passport_expiry?->format('Y-m-d'),
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'city' => $this->city,
            'affiliation' => $this->affiliation,
            'whatsapp_number' => $this->whatsapp_number,
            'travel_country' => $this->travel_country,
            'type' => $apiType,
            'customer_tier' => $this->customer_tier?->value,
            'notes' => $this->notes,
            'balance' => (float) ($this->ledgerAccount?->balance ?? 0),
            'created_by_id' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->id),
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
