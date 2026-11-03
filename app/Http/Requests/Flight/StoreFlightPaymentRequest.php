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
            // QA FIX (2026-11-03): explicit opt-in for an overpayment.
            // When the payment exceeds the remaining selling price the service
            // REJECTS it (exactly like `main`) unless this flag is explicitly
            // true — the Vue client sends it only after the user confirms that
            // the excess moves to the customer's unified credit balance.
            'allow_overpayment' => ['nullable', 'boolean'],
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
            'allow_overpayment.boolean' => 'The allow_overpayment flag must be a boolean.',
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
            'allow_overpayment',  // QA FIX (2026-11-03)
        ];

        // Accept the string forms ("true"/"false"/"1"/"0") the Vue client may
        // send and normalise them to real booleans before validation.
        if (array_key_exists('allow_overpayment', $this->all())) {
            $raw = $this->input('allow_overpayment');
            if (is_string($raw)) {
                $lower = strtolower(trim($raw));
                $this->merge([
                    'allow_overpayment' => in_array($lower, ['1', 'true', 'yes', 'on'], true)
                        ? true
                        : (in_array($lower, ['0', 'false', 'no', 'off'], true) ? false : $raw),
                ]);
            }
        }

        $unknown = array_diff(array_keys($this->all()), $allowed);

        if (!empty($unknown)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_fill_keys($unknown, 'This field is not allowed.')
            );
        }
    }
}
