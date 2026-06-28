<?php

namespace App\Http\Requests\Fawry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFawryTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_name' => ['sometimes', 'required', 'string', 'max:255'],
            'operation_type' => ['sometimes', 'required', 'string', 'max:50'],
            'client_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'fawry_price' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'selling_price' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'employee_id' => ['sometimes', 'required', 'exists:users,id'],
            'account_id' => ['sometimes', 'required', 'exists:accounts,id'],
            'payment_method' => ['sometimes', 'required', 'string', 'max:50'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
