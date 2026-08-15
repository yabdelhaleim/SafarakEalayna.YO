<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreFlightPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,cash_wallet,postal_transfer,office_safe,office_drawer,mixed,vodafone_cash,instapay',
            'account_id' => 'required|integer|exists:accounts,id',
            'notes' => 'nullable|string|max:1000',
            // D3 FIX (2026-08-15): opt-in idempotency key. When supplied,
            // replay of the same logical payment returns the original row
            // (HTTP 200 OK + idempotent_replay=true) instead of creating a
            // duplicate. When null/empty, legacy behavior is preserved.
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'The amount is required.',
            'amount.numeric' => 'The amount must be a number.',
            'amount.min' => 'The amount must be at least 0.01.',
            'payment_method.required' => 'The payment method is required.',
            'payment_method.in' => 'Invalid payment method selected.',
            'account_id.required' => 'The account ID is required.',
            'account_id.exists' => 'The selected account is invalid.',
            'notes.string' => 'The notes must be a valid string.',
            'notes.max' => 'The notes may not exceed 1000 characters.',
            'idempotency_key.string' => 'The idempotency key must be a string.',
            'idempotency_key.max' => 'The idempotency key may not exceed 100 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $allowed = [
            'amount',
            'payment_method',
            'account_id',
            'notes',
            'idempotency_key',  // D3 FIX
        ];

        $unknown = array_diff(array_keys($this->all()), $allowed);

        if (!empty($unknown)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_fill_keys($unknown, 'This field is not allowed.')
            );
        }
    }
}
