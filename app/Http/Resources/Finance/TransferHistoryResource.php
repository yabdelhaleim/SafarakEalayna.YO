<?php

namespace App\Http\Resources\Finance;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transaction */
class TransferHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->id,
            'date' => $this->created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'amount' => (float) $this->amount,
            'module' => $this->module instanceof \BackedEnum ? $this->module->value : $this->module,
            'from_account_id' => $this->from_account_id,
            'to_account_id' => $this->to_account_id,
            'from_account' => $this->whenLoaded('fromAccount', fn () => [
                'id' => $this->fromAccount->id,
                'name' => $this->fromAccount->name,
                'currency' => $this->fromAccount->currency,
                'type' => $this->fromAccount->type instanceof \BackedEnum
                    ? $this->fromAccount->type->value
                    : $this->fromAccount->type,
            ]),
            'to_account' => $this->whenLoaded('toAccount', fn () => [
                'id' => $this->toAccount->id,
                'name' => $this->toAccount->name,
                'currency' => $this->toAccount->currency,
                'type' => $this->toAccount->type instanceof \BackedEnum
                    ? $this->toAccount->type->value
                    : $this->toAccount->type,
            ]),
            'description' => $this->notes,
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ]),
        ];
    }
}
