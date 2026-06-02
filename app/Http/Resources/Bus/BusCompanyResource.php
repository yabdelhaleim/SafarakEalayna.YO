<?php

namespace App\Http\Resources\Bus;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusCompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'balance' => (float) ($this->account?->balance ?? 0),
            'account_id' => $this->account_id,
            'account' => $this->account ? [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'balance' => (float) $this->account->balance,
            ] : null,
            'total_inventories' => $this->whenLoaded('inventories', fn () => $this->inventories->count()),
            'created_by_id' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->id),
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
