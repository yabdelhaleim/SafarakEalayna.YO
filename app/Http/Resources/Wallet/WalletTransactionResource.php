<?php

namespace App\Http\Resources\Wallet;

use App\Enums\WalletTransactionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof WalletTransactionType
            ? $this->type
            : WalletTransactionType::from($this->type);

        return [
            'id' => $this->id,
            'wallet_type' => [
                'id' => $this->whenLoaded('walletType', fn () => $this->walletType?->id),
                'name' => $this->whenLoaded('walletType', fn () => $this->walletType?->name),
                'code' => $this->whenLoaded('walletType', fn () => $this->walletType?->code),
            ],
            'customer' => [
                'id' => $this->whenLoaded('customer', fn () => $this->customer?->id),
                'name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            ],
            'customer_name' => $this->customer_name,
            'wallet_number' => $this->wallet_number,
            'type' => $type->value,
            'type_label' => $type->label(),
            'type_color' => $type->color(),
            'amount' => (float) $this->amount,
            'service_fee' => (float) $this->service_fee,
            'total_amount' => (float) $this->total_amount,
            'amount_paid' => (float) $this->amount_paid,
            'profit' => (float) $this->service_fee,
            'wallet_account' => [
                'id' => $this->whenLoaded('walletAccount', fn () => $this->walletAccount?->id),
                'name' => $this->whenLoaded('walletAccount', fn () => $this->walletAccount?->name),
            ],
            'cash_account' => [
                'id' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount?->id),
                'name' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount?->name),
            ],
            'employee' => [
                'id' => $this->whenLoaded('employee', fn () => $this->employee?->id),
                'name' => $this->whenLoaded('employee.user', fn () => $this->employee?->user?->name),
            ],
            'notes' => $this->notes,
            'created_by_id' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->id),
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
