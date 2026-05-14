<?php

namespace App\Http\Resources\Bus;

use App\Enums\BusCompanyPaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusCompanyPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof BusCompanyPaymentStatus
            ? $this->status
            : BusCompanyPaymentStatus::from($this->status);

        return [
            'id' => $this->id,
            'company' => [
                'id' => $this->whenLoaded('company', fn () => $this->company?->id),
                'name' => $this->whenLoaded('company', fn () => $this->company?->name),
            ],
            'inventory' => [
                'id' => $this->whenLoaded('inventory', fn () => $this->inventory?->id),
                'route' => $this->whenLoaded('inventory', fn () => $this->inventory?->route),
                'travel_date' => $this->whenLoaded('inventory', fn () => $this->inventory?->travel_date?->format('Y-m-d')),
            ],
            'amount' => (float) $this->amount,
            'account' => [
                'id' => $this->whenLoaded('account', fn () => $this->account?->id),
                'name' => $this->whenLoaded('account', fn () => $this->account?->name),
            ],
            'transaction_id' => $this->transaction_id,
            'status' => $this->status,
            'status_label' => $status->label(),
            'notes' => $this->notes,
            'created_by_id' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->id),
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
