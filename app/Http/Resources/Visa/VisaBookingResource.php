<?php

namespace App\Http\Resources\Visa;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VisaBookingResource extends JsonResource
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
            'visa_detail' => $this->whenLoaded('visaDetail', fn () => $this->visaDetail ? [
                'id' => $this->visaDetail->id,
                'visa_type' => $this->visaDetail->visa_type?->value,
                'visa_type_label' => $this->visaDetail->visa_type?->label(),
                'country' => $this->visaDetail->country,
                'duration' => $this->visaDetail->duration,
                'duration_row' => $this->visaDetail->relationLoaded('durationRow') && $this->visaDetail->durationRow ? [
                    'id' => $this->visaDetail->durationRow->id,
                    'code' => $this->visaDetail->durationRow->code,
                    'label_ar' => $this->visaDetail->durationRow->label_ar,
                    'months' => $this->visaDetail->durationRow->months,
                    'entry_type' => $this->visaDetail->durationRow->entry_type,
                ] : null,
                'entry_type' => $this->visaDetail->entry_type?->value,
                'entry_type_label' => $this->visaDetail->entry_type?->label(),
                'validity_from' => $this->visaDetail->validity_from,
                'validity_to' => $this->visaDetail->validity_to,
                'executing_company' => $this->visaDetail->executing_company,
                'executing_agent' => $this->visaDetail->executing_agent,
                'executing_agent_contact' => $this->visaDetail->executing_agent_contact,
                'agent' => $this->visaDetail->relationLoaded('agent') && $this->visaDetail->agent ? [
                    'id' => $this->visaDetail->agent->id,
                    'company_name' => $this->visaDetail->agent->company_name,
                    'contact_person' => $this->visaDetail->agent->contact_person,
                    'phone' => $this->visaDetail->agent->phone,
                    'country' => $this->visaDetail->agent->country,
                ] : null,
                'submission_date' => $this->visaDetail->submission_date,
                'expected_result_date' => $this->visaDetail->expected_result_date,
                'visa_number' => $this->visaDetail->visa_number,
                'status' => $this->visaDetail->status?->value,
            ] : null),
            'pricing' => [
                'purchase_price' => (float) $this->purchase_price,
                'selling_price' => (float) $this->selling_price,
                'service_fee' => (float) ($this->service_fee ?? 0),
                'profit' => (float) $this->profit,
                'currency' => $this->currency,
            ],
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
