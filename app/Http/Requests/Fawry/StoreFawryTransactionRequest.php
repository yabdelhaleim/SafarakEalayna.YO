<?php

namespace App\Http\Requests\Fawry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFawryTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'exists:customers,id'],
            'client_name' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'operation_type' => ['required', 'string', 'max:50'],
            'currency_id' => ['nullable', 'exists:currencies,id'],
            'client_amount' => ['required', 'numeric', 'min:0'],
            'fawry_price' => ['required', 'numeric', 'gt:0'],
            'selling_price' => ['required', 'numeric', 'gt:0'],
            'employee_id' => ['required', 'exists:users,id'],
            'account_id' => ['required', 'exists:accounts,id'],
            'fawry_machine_id' => ['nullable', 'exists:fawry_machines,id'],
            'payment_method' => ['required', 'string', 'max:50', Rule::exists('fawry_payment_methods', 'code')],
            'amount' => ['required', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payment_details' => ['nullable', 'array'],
        ];
    }
}
