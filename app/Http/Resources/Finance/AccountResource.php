<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'balance' => (float) $this->balance,
            'currency' => $this->currency,
            'is_active' => (bool) $this->is_active,
            'wallet_provider' => $this->wallet_provider instanceof \BackedEnum
                ? $this->wallet_provider->value
                : $this->wallet_provider,
            'wallet_number' => $this->wallet_number,
            'notes' => $this->notes,
            'module_type' => $this->module_type,
            'module' => $this->module,
            'payment_status' => $this->payment_status,
            'owner_type' => $this->owner_type,
            'created_by_id' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->id),
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
