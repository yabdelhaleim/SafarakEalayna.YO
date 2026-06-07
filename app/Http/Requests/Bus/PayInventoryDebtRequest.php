<?php

namespace App\Http\Requests\Bus;

use App\Rules\BusLiquidityAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class PayInventoryDebtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'account_id' => ['required', 'integer', 'exists:accounts,id', new BusLiquidityAccount],
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'The amount is required.',
            'amount.numeric' => 'The amount must be a number.',
            'amount.min' => 'The amount must be at least 0.01.',
            'account_id.required' => 'The account ID is required.',
            'account_id.exists' => 'The selected account is invalid.',
            'notes.string' => 'The notes must be a valid string.',
            'notes.max' => 'The notes may not be greater than 1000 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $allowed = ['amount', 'account_id', 'notes'];
        $unknown = array_diff(array_keys($this->all()), $allowed);

        if (! empty($unknown)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_fill_keys($unknown, 'This field is not allowed.')
            );
        }
    }
}
