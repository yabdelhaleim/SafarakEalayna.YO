<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'contact_person' => $this->contact_person,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'account_id' => $this->account_id,
            'account' => $this->when($this->account_id, function () {
                return [
                    'id' => $this->account->id,
                    'name' => $this->account->name,
                    'type' => $this->account->type->value,
                    'currency' => $this->account->currency,
                    'balance' => (float) $this->account->balance,
                    'is_active' => $this->account->is_active,
                ];
            }),
            'credit_limit' => (float) $this->credit_limit,
            'current_debt' => (float) $this->current_debt,
            'remaining_credit' => (float) ($this->credit_limit - $this->current_debt),
            'is_over_credit_limit' => $this->isOverCreditLimit(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
