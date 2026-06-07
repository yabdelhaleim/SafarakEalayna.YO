<?php

namespace App\Http\Requests\Online;

use App\Enums\OnlineTransactionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOnlineTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_type_id' => ['sometimes', 'integer', 'exists:online_service_types,id'],
            'provider_id' => ['nullable', 'integer', 'exists:online_service_providers,id'],

            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'customer_country' => ['nullable', 'string', 'max:120'],

            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],

            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],

            'payment_method' => ['sometimes', 'string', 'exists:payment_methods,code'],
            'account_id' => ['sometimes', 'integer', 'exists:accounts,id'],
            'reference_number' => ['nullable', 'string', 'max:255'],

            'status' => ['nullable', Rule::in(array_column(OnlineTransactionStatus::cases(), 'value'))],
            'failure_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
