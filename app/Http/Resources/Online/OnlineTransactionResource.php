<?php

namespace App\Http\Resources\Online;

use App\Enums\OnlineTransactionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnlineTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof OnlineTransactionStatus
            ? $this->status
            : (is_string($this->status) ? OnlineTransactionStatus::tryFrom($this->status) : null);

        return [
            'id' => $this->id,
            'service_type' => $this->whenLoaded('serviceType', fn () => $this->serviceType ? [
                'id' => $this->serviceType->id,
                'code' => $this->serviceType->code,
                'name' => $this->serviceType->name_ar,
                'color' => $this->serviceType->color,
                'icon' => $this->serviceType->icon,
            ] : null),
            'service_type_id' => $this->service_type_id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'code' => $this->provider->code,
                'name' => $this->provider->name_ar,
                'color' => $this->provider->color,
                'icon' => $this->provider->icon,
            ] : null),
            'provider_id' => $this->provider_id,

            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->full_name,
                'phone' => $this->customer->phone,
            ] : null),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name ?? $this->whenLoaded('customer', fn () => $this->customer?->full_name),
            'customer_phone' => $this->customer_phone ?? $this->whenLoaded('customer', fn () => $this->customer?->phone),
            'customer_country' => $this->customer_country,

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'name' => $this->employee->full_name ?? $this->employee->user?->name,
            ] : null),
            'employee_id' => $this->employee_id,

            'purchase_price' => (float) $this->purchase_price,
            'selling_price' => (float) $this->selling_price,
            'amount_paid' => (float) ($this->amount_paid ?? $this->selling_price),
            'profit' => (float) $this->profit,

            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->whenLoaded('paymentMethodRow', fn () => $this->paymentMethodRow?->name_ar),
            'payment_method_color' => $this->whenLoaded('paymentMethodRow', fn () => $this->paymentMethodRow?->color),

            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'type' => $this->account->type,
            ] : null),
            'account_id' => $this->account_id,

            'reference_number' => $this->reference_number,

            'status' => $status?->value,
            'status_label' => $status?->label(),
            'status_color' => $status?->color(),

            'failure_reason' => $this->failure_reason,
            'notes' => $this->notes,

            'expense_transaction_id' => $this->expense_transaction_id,
            'income_transaction_id' => $this->income_transaction_id,

            // Eager-loaded ledger transactions — used by the audit / repair
            // screens to trace a transaction back to its GL entries without
            // making follow-up API calls. The legacy `*_transaction_id`
            // scalars above remain for backward compatibility.
            'expense_transaction' => $this->whenLoaded('expenseTransaction', fn () => $this->expenseTransaction ? [
                'id' => $this->expenseTransaction->id,
                'amount' => (float) $this->expenseTransaction->amount,
                'from_account_id' => $this->expenseTransaction->from_account_id,
                'to_account_id' => $this->expenseTransaction->to_account_id,
                'type' => $this->expenseTransaction->type,
                'module' => $this->expenseTransaction->module,
                'reference' => $this->expenseTransaction->reference,
                'created_at' => $this->expenseTransaction->created_at?->toIso8601String(),
            ] : null),
            'income_transaction' => $this->whenLoaded('incomeTransaction', fn () => $this->incomeTransaction ? [
                'id' => $this->incomeTransaction->id,
                'amount' => (float) $this->incomeTransaction->amount,
                'from_account_id' => $this->incomeTransaction->from_account_id,
                'to_account_id' => $this->incomeTransaction->to_account_id,
                'type' => $this->incomeTransaction->type,
                'module' => $this->incomeTransaction->module,
                'reference' => $this->incomeTransaction->reference,
                'created_at' => $this->incomeTransaction->created_at?->toIso8601String(),
            ] : null),

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
