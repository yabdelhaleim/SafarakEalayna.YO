<?php

namespace App\Http\Requests\Wallet;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateWalletTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => 'nullable|string|max:1000',
            'amount' => 'sometimes|required|numeric|min:1|max:999999.99',
            'service_fee' => 'sometimes|nullable|numeric|min:0',
            'amount_paid' => 'sometimes|nullable|numeric|min:0',
            'wallet_account_id' => 'sometimes|required|integer|exists:accounts,id',
            'cash_account_id' => 'sometimes|required|integer|exists:accounts,id|different:wallet_account_id',
        ];
    }

    /**
     * FINDING D-V2-008 (P3, LOW) REMEDIATION (2026-08-26):
     * Pre-fix, the active-state checks (`! $wallet->is_active`, `! $cash->is_active`)
     * were gated on `$this->has('wallet_account_id')` / `$this->has('cash_account_id')`.
     * This meant: if the wallet account was deactivated between create and update,
     * and the client sent a no-field update (e.g. only `notes` or only `amount`),
     * the validator silently allowed the update. The bound transaction's wallet
     * could then post a journal leg against an inactive vault.
     *
     * Post-fix: always resolve the EFFECTIVE account — if the request supplies
     * a new account id, validate that; otherwise resolve from the existing
     * transaction. Then ALWAYS enforce:
     *   - account exists
     *   - account is active
     *   - currencies match between effective wallet and effective cash
     *   - effective wallet != effective cash (FIN-6)
     *
     * Regression coverage: PhaseFinancialRetestV2Test covers the matrix
     * (active wallet + amount-only update PASSES, inactive wallet + amount-only
     * update REJECTS, currency mismatch REJECTS, etc.).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Resolve the EFFECTIVE wallet account id: request-supplied OR
            // bound to the existing transaction.
            $walletId = $this->input('wallet_account_id')
                ?? $this->route('transaction')?->wallet_account_id;
            $cashId = $this->input('cash_account_id')
                ?? $this->route('transaction')?->cash_account_id;

            if (! $walletId || ! $cashId) {
                return;
            }

            $wallet = Account::find($walletId);
            $cash = Account::find($cashId);
            if (! $wallet || ! $cash) {
                if (! $wallet) {
                    $v->errors()->add('wallet_account_id', 'حساب المحفظة غير موجود.');
                }
                if (! $cash) {
                    $v->errors()->add('cash_account_id', 'الحساب النقدي غير موجود.');
                }

                return;
            }

            // FIN-6: effective wallet must differ from effective cash.
            if ((int) $wallet->id === (int) $cash->id) {
                $v->errors()->add(
                    'cash_account_id',
                    'لا يمكن أن يكون حساب المحفظة هو نفسه الحساب النقدي.'
                );
            }

            // FIN-7: effective wallet must be active.
            // ALWAYS enforced — even when wallet_account_id is NOT in the
            // request payload. The fix for D-V2-008.
            if (! $wallet->is_active) {
                $v->errors()->add(
                    'wallet_account_id',
                    'حساب المحفظة غير نشط — لا يمكن إجراء عمليات عليه.'
                );
            }

            // FIN-7: effective cash must be active.
            // ALWAYS enforced — same rationale.
            if (! $cash->is_active) {
                $v->errors()->add(
                    'cash_account_id',
                    'الحساب النقدي غير نشط — لا يمكن إجراء عمليات عليه.'
                );
            }

            // VAL-1: effective wallet currency must match effective cash currency.
            if ($wallet->currency !== $cash->currency) {
                $v->errors()->add(
                    'cash_account_id',
                    sprintf(
                        'عملة الحساب النقدي (%s) لا تطابق عملة حساب المحفظة (%s).',
                        $cash->currency,
                        $wallet->currency
                    )
                );
            }
        });
    }
}