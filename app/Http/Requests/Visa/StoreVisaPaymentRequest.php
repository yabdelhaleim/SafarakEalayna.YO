<?php

namespace App\Http\Requests\Visa;

use Illuminate\Foundation\Http\FormRequest;

class StoreVisaPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'string', 'max:50'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'payment_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_by' => ['nullable', 'string', 'max:150'],
            'currency' => ['nullable', 'string', 'max:3'],
        ];
    }
}
