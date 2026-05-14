<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreFlightRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'airline_penalty' => 'required|numeric|min:0',
            'office_penalty' => 'required|numeric|min:0',
            'account_id' => 'required|integer|exists:accounts,id',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'airline_penalty.required' => 'The airline penalty is required.',
            'airline_penalty.numeric' => 'The airline penalty must be a number.',
            'airline_penalty.min' => 'The airline penalty cannot be negative.',
            'office_penalty.required' => 'The office penalty is required.',
            'office_penalty.numeric' => 'The office penalty must be a number.',
            'office_penalty.min' => 'The office penalty cannot be negative.',
            'account_id.required' => 'The account ID is required.',
            'account_id.exists' => 'The selected account is invalid.',
            'notes.string' => 'The notes must be a valid string.',
            'notes.max' => 'The notes may not exceed 1000 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $allowed = [
            'airline_penalty',
            'office_penalty',
            'account_id',
            'notes',
        ];

        $unknown = array_diff(array_keys($this->all()), $allowed);

        if (!empty($unknown)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_fill_keys($unknown, 'This field is not allowed.')
            );
        }
    }
}
