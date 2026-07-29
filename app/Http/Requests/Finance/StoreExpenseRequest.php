<?php

namespace App\Http\Requests\Finance;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for POST /api/v1/finance/expenses.
 *
 * Scope (after Phase-7 audit refactor):
 *  - Records an expense OUT of a single liquidity account (cashbox/bank/wallet).
 *  - The contra (clearing) account is resolved automatically by
 *    {@see \App\Services\Finance\LedgerClearingAccounts::expenseContraIdForModule()}.
 *  - `to_account_id` / `to_account_name` are intentionally NOT accepted —
 *    callers do not pick the destination GL row. The previous behavior of
 *    POSTing `type=expense` to /finance/transfers (with auto-create of an
 *    expense account) was buggy: it produced unbalanced entries and orphan
 *    accounts on partial failures.
 */
class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_account_id' => 'required|integer|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'module' => ['nullable', 'string', Rule::enum(TransactionModule::class)],
            'notes' => 'nullable|string|max:1000',
            'attachment' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = Account::query()->find($this->input('from_account_id'));
            if (! $from) {
                return;
            }

            $fromType = $from->type?->value ?? $from->type;
            if (! in_array($fromType, AccountModuleDivision::LIQUIDITY_TYPES, true)) {
                $validator->errors()->add(
                    'from_account_id',
                    'يجب اختيار حساب سيولة (خزينة / بنك / محفظة) كمصدر للمصروف.'
                );
            }

            if (! $from->is_active) {
                $validator->errors()->add('from_account_id', 'الحساب غير نشط ولا يمكن استخدامه.');
            }
        });
    }
}