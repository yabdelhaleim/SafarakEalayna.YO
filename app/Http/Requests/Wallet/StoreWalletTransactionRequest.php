<?php

namespace App\Http\Requests\Wallet;

use App\Enums\WalletTransactionType;
use App\Models\Account;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreWalletTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Pre-validation normalization:
     *   - VAL-4 (2026-08-21): strip HTML/script tags from `notes` so that an
     *     XSS payload stored today is at minimum reduced to text, even
     *     before downstream rendering. The frontend (Vue) escapes on
     *     output; this is defense in depth.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('notes') && is_string($this->input('notes'))) {
            $notes = $this->input('notes');
            // Strip control chars + HTML tags.
            $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $notes);
            $cleaned = strip_tags((string) $cleaned);
            $this->merge(['notes' => $cleaned]);
        }
    }

    public function rules(): array
    {
        return [
            'wallet_type_id' => 'required|integer|exists:wallet_types,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'customer_name' => 'required|string|max:200',
            'wallet_number' => 'required|string|max:30',
            'type' => ['required', new Enum(WalletTransactionType::class)],
            // VAL-2 (2026-08-21): require amount >= 1.00 to prevent dust attacks.
            // VAL-3 (2026-08-21): require amount <= 999_999.99 to prevent
            //   accidental multi-million transfers from a typo.
            'amount' => 'required|numeric|min:1|max:999999.99',
            'service_fee' => 'nullable|numeric|min:0',
            'amount_paid' => 'nullable|numeric|min:0',
            'wallet_account_id' => 'required|integer|exists:accounts,id',
            'cash_account_id' => 'required|integer|exists:accounts,id|different:wallet_account_id',
            // WLT-1 (2026-09-02): optional destination override for RECEIVE.
            // Only meaningful when type='receive'. If supplied, the Expense
            // leg is routed here instead of the legacy default (customer
            // account for registered customers; cash_account_id for anonymous).
            // Ignored for type='send' — wallet SEND already debits the
            // wallet_account_id by `amount` only and uses the customer
            // account / cashbox as the contra leg, not a user-chosen
            // destination.
            'receive_destination_account_id' => [
                'nullable',
                'integer',
                'exists:accounts,id',
                'different:wallet_account_id',
                'different:cash_account_id',
            ],
            'employee_id' => 'nullable|integer|exists:employees,id',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Cross-field database checks (FIN-6, FIN-7, VAL-1) (2026-08-21):
     *
     *   - FIN-7: BOTH `wallet_account_id` AND `cash_account_id` MUST be
     *     `is_active=true`. A deactivated wallet or cashbox MUST NOT be
     *     allowed to move money.
     *
     *   - FIN-6: `wallet_account_id != cash_account_id`. The same account
     *     for both sides creates an asymmetric journal where the fee is
     *     silently lost (the wallet loses `amount`, not `total_amount`,
     *     and the fee sticks in the income clearing account as a phantom
     *     liability). The Laravel-native `different:wallet_account_id`
     *     rule covers the simple case; this `withValidator` adds DB
     *     context for clarity in error messages.
     *
     *   - VAL-1: `wallet_account.currency == cash_account.currency`.
     *     Cross-currency transactions without an explicit FX path are
     *     rejected silently pre-fix.
     *
     * All three rejections are HTTP 422 (ValidationException) — distinct
     * from business-logic errors which use 409 (UX-1 fix).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $walletId = $this->input('wallet_account_id');
            $cashId = $this->input('cash_account_id');
            if (! $walletId || ! $cashId) {
                return;
            }
            $wallet = Account::find($walletId);
            $cash = Account::find($cashId);
            if (! $wallet || ! $cash) {
                // Already covered by `exists:` rules above.
                return;
            }

            // FIN-7: both accounts MUST be active.
            if (! $wallet->is_active) {
                $v->errors()->add(
                    'wallet_account_id',
                    'حساب المحفظة غير نشط — لا يمكن إجراء عمليات عليه.'
                );
            }
            if (! $cash->is_active) {
                $v->errors()->add(
                    'cash_account_id',
                    'الحساب النقدي غير نشط — لا يمكن إجراء عمليات عليه.'
                );
            }

            // VAL-1: currencies MUST match.
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

            // WLT-1 (2026-09-02): destination-override cross-field rules.
            // The destination account is ONLY meaningful for RECEIVE.
            // On SEND we reject any non-null destination outright — the
            // wallet SEND already debits the wallet provider only (WLT-
            // pre-fix) and the destination field has no semantic.
            $rawType = $this->input('type');
            $typeStr = $rawType instanceof \BackedEnum ? $rawType->value : (string) $rawType;
            $destId = $this->input('receive_destination_account_id');
            if ($typeStr === 'send' && ! empty($destId)) {
                $v->errors()->add(
                    'receive_destination_account_id',
                    'حساب الاستقبال مسموح فقط في عمليات الاستقبال (receive) — لا يمكن استخدامه في الإرسال.'
                );
            } elseif ($typeStr === 'receive' && ! empty($destId)) {
                // The destination account MUST be active (FIN-7 invariant).
                $dest = Account::find($destId);
                if ($dest && ! $dest->is_active) {
                    $v->errors()->add(
                        'receive_destination_account_id',
                        'الحساب المختار للاستقبال غير نشط — لا يمكن إجراء عمليات عليه.'
                    );
                }
                // VAL-1: destination currency MUST match wallet currency.
                // The wallet_provider and the destination both move the
                // same currency (one leg into the wallet, the other out
                // of the destination); cross-currency without an FX path
                // is silently rejected pre-fix.
                if ($dest && $dest->currency !== $wallet->currency) {
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
        });
    }

    public function messages(): array
    {
        return [
            'wallet_type_id.required' => 'نوع المحفظة مطلوب.',
            'wallet_type_id.exists' => 'نوع المحفظة غير موجود.',
            'customer_name.required' => 'اسم العميل مطلوب.',
            'wallet_number.required' => 'رقم المحفظة (الهاتف) مطلوب.',
            'type.required' => 'نوع العملية مطلوب (إرسال أو استقبال).',
            'amount.required' => 'المبلغ مطلوب.',
            'amount.min' => 'المبلغ يجب ألا يقل عن 1.00 جنيه.',
            'amount.max' => 'المبلغ يتجاوز الحد الأقصى المسموح (999,999.99).',
            'service_fee.min' => 'قيمة الخدمة لا يمكن أن تكون سالبة.',
            'wallet_account_id.required' => 'حساب المحفظة مطلوب.',
            'wallet_account_id.exists' => 'حساب المحفظة غير موجود.',
            'cash_account_id.required' => 'الحساب النقدي مطلوب.',
            'cash_account_id.exists' => 'الحساب النقدي غير موجود.',
            'cash_account_id.different' => 'الحساب النقدي يجب أن يكون مختلفاً عن حساب المحفظة.',
        ];
    }
}