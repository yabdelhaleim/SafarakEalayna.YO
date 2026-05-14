<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreEmployeeBonusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'required|integer|exists:employees,id',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:1000',
            'account_id' => 'required|integer|exists:accounts,id',
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'The employee ID is required.',
            'employee_id.exists' => 'The selected employee is invalid.',
            'amount.required' => 'The amount is required.',
            'amount.numeric' => 'The amount must be a number.',
            'amount.min' => 'The amount must be at least 0.01.',
            'reason.required' => 'The reason is required.',
            'reason.string' => 'The reason must be a valid string.',
            'reason.max' => 'The reason may not be greater than 1000 characters.',
            'account_id.required' => 'The account ID is required.',
            'account_id.exists' => 'The selected account is invalid.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $allowed = ['employee_id', 'amount', 'reason', 'account_id'];
        $unknown = array_diff(array_keys($this->all()), $allowed);

        if (! empty($unknown)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_fill_keys($unknown, 'This field is not allowed.')
            );
        }
    }
}
