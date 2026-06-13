<?php

namespace App\Http\Resources\HajjUmra;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HajjUmraBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module' => $this->module,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'full_name' => $this->customer->full_name,
                'phone' => $this->customer->phone,
                'passport_number' => $this->customer->passport_number,
                'passport_expiry' => $this->customer->passport_expiry,
                'date_of_birth' => $this->customer->date_of_birth,
                'city' => $this->customer->city,
                'affiliation' => $this->customer->affiliation,
                'notes' => $this->customer->notes,
            ]),
            'companion' => $this->whenLoaded('companion', fn () => $this->companion ? [
                'id' => $this->companion->id,
                'full_name' => $this->companion->full_name,
                'phone' => $this->companion->phone,
            ] : null),
            'program' => $this->whenLoaded('program', fn () => [
                'id' => $this->program->id,
                'program_name' => $this->program->program_name,
                'program_type' => $this->program->program_type,
                'season' => $this->program->season,
                'total_nights' => $this->program->total_nights,
                'mecca_hotel_name' => $this->program->mecca_hotel_name,
                'mecca_nights' => $this->program->mecca_nights,
                'medina_hotel_name' => $this->program->medina_hotel_name,
                'medina_nights' => $this->program->medina_nights,
                'departure_date' => $this->program->departure_date,
                'return_date' => $this->program->return_date,
                'airline' => $this->program->airline,
                'accommodation_type' => $this->program->accommodation_type,
                'trip_supervisor' => $this->program->tripSupervisor?->full_name ?: $this->program->trip_supervisor,
                'executing_company' => $this->program->executingCompany?->name ?: $this->program->executing_company,
                'departure_point' => $this->program->departure_point,
            ]),
            'pricing' => [
                'purchase_price' => (float) $this->purchase_price,
                'companion_purchase_price' => (float) ($this->companion_purchase_price ?? 0),
                'selling_price' => (float) $this->selling_price,
                'companion_selling_price' => (float) ($this->companion_selling_price ?? 0),
                'profit' => (float) $this->profit,
                'currency' => $this->currency,
                'per_person' => (bool) $this->per_person,
                'accommodation_choice' => $this->accommodation_choice,
                'accommodation_extra_charge' => (float) ($this->accommodation_extra_charge ?? 0),
            ],
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'phone' => $this->supplier->phone,
            ] : null),
            'passengers' => $this->whenLoaded('passengers', fn () => $this->passengers->map(fn ($p) => [
                'id' => $p->id,
                'category' => $p->category,
                'count' => $p->count,
                'unit_price' => (float) $p->unit_price,
                'subtotal' => (float) $p->subtotal,
            ])),
            'finance' => [
                'account' => $this->whenLoaded('account', fn () => $this->account ? [
                    'id' => $this->account->id,
                    'name' => $this->account->name,
                    'type' => $this->account->type,
                    'currency' => $this->account->currency,
                ] : null),
                'expense_transaction_id' => $this->expense_transaction_id,
                'income_transaction_id' => $this->income_transaction_id,
                'paid_amount' => $this->paid_amount,
                'remaining_amount' => $this->remaining_amount,
                'is_fully_paid' => $this->is_fully_paid,
            ],
            'agent_name' => $this->agent_name,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'name' => $this->employee->name ?? $this->employee->full_name ?? null,
            ] : null),
            'notes' => $this->notes,
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'payment_method' => $p->payment_method,
                'currency' => $p->currency,
                'account' => $p->relationLoaded('account') && $p->account ? [
                    'id' => $p->account->id,
                    'name' => $p->account->name,
                ] : null,
                'transaction_id' => $p->transaction_id,
                'transaction_reference' => $p->transaction_reference,
                'payment_date' => $p->payment_date,
                'paid_by' => $p->paid_by,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
