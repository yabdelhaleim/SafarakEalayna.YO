<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdateFlightPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // D4 FIX (2026-08-15): reject negative prices at the HTTP boundary.
            // Zero is allowed (consistent with existing business rules for
            // voucher / complimentary bookings). min:0.01 was too strict —
            // blocked legitimate zero-priced entries.
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'purchase_price.required' => 'The purchase price is required.',
            'purchase_price.numeric' => 'The purchase price must be a number.',
            'purchase_price.min' => 'The purchase price must be zero or greater (negative prices are not allowed).',
            'selling_price.required' => 'The selling price is required.',
            'selling_price.numeric' => 'The selling price must be a number.',
            'selling_price.min' => 'The selling price must be zero or greater (negative prices are not allowed).',
        ];
    }

    protected function prepareForValidation(): void
    {
        $allowed = [
            'purchase_price',
            'selling_price',
        ];

        $unknown = array_diff(array_keys($this->all()), $allowed);

        if (!empty($unknown)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_fill_keys($unknown, 'This field is not allowed.')
            );
        }
    }
}
