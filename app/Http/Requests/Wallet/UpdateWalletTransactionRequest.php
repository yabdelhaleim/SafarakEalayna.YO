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
            // WLT-1 (2026-09-02): optional destination override for RECEIVE.
            // Allowed to change ONLY when the existing transaction is a
            // receive — sending a destination on a send is a 422.
            'receive_destination_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:accounts,id',
                'different:wallet_account_id',
                'different:cash_account_id',
            ],
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

            // WLT-1 (2026-09-02): receive_destination_account_id is only
            // meaningful for RECEIVE transactions. The bound transaction
            // tells us the persisted type (cast to WalletTransactionType
            // enum by the model); we forbid a non-null destination on a SEND.
            $existingType = $this->route('transaction')?->type;
            $existingTypeValue = $existingType instanceof \BackedEnum
                ? $existingType->value
                : (string) $existingType;
            if ($this->has('receive_destination_account_id')
                && ! empty($this->input('receive_destination_account_id'))
                && $existingType !== null
                && $existingTypeValue !== 'receive'
                && $existingTypeValue !== \App\Enums\WalletTransactionType::Receive->value
            ) {
                $v->errors()->add(
                    'receive_destination_account_id',
                    'حساب الاستبدال مسموح فقط في عمليات الاستقبال (receive).'
                );
            }

            // If a destination override is supplied, it must be active
            // and must share the wallet's currency (same invariants as
            // store). Mirror the store-side rules for symmetry.
            $destId = $this->input('receive_destination_account_id');
            if (! empty($destId)) {
                $dest = Account::find($destId);
                if ($dest) {
                    if (! $dest->is_active) {
                        $v->errors()->add(
                            'receive_destination_account_id',
                            'الحساب المختار للاستقبال غير نشط — لا يمكن إجراء عمليات عليه.'
                        );
                    }
                    if ($dest->currency !== $wallet->currency) {
                        $v->errors()->add(
                            'receive_destination_account_id',
                            sprintf(
                                'عملة حساب الاستقبال (%s) لا تطابق عملة حساب المحفظة (%s).',
                                $dest->currency,
                                $wallet->currency
                            )
                        );
                    }
                }
            }
        });
    }
}