<?php

namespace App\Http\Requests\HajjUmra;

use App\Rules\HajjUmraLiquidityAccount;
use Illuminate\Foundation\Http\FormRequest;

class StoreHajjUmraPaymentRequest extends FormRequest
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
            'account_id' => ['required', 'integer', 'exists:accounts,id', new HajjUmraLiquidityAccount],
            'payment_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            // PRE-PHASE-B IDEMPOTENCY FIX (2026-08-15):
            // Opt-in replay-protection identity. When supplied, the same
            // (booking_id, idempotency_key) returns the original payment
            // instead of creating a duplicate. NULL is allowed and means
            // "no protection requested" (backward-compatible).
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'paid_by' => ['nullable', 'string', 'max:150'],
            'currency' => ['nullable', 'string', 'max:3'],
        ];
    }
}
