<?php

namespace App\Rules;

use App\Models\Account;
use App\Support\Finance\AccountModuleContract;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that the selected account is a usable Fawry-module liquidity account.
 *
 * Phase 5 (Account Unification) — designed broadened from the start:
 *
 *  1. Strict per-module: `module_type='fawry'` OR `module='fawry'`
 *  2. Office-division unified vault: `module_type='office'`
 *     A single office-wide vault now serves bus/fawry/online/wallet_transfer
 *     simultaneously. The `module` column on such accounts is just a label
 *     hint and is NOT used as a filter.
 *
 * REJECTS:
 *  - Tourism-division accounts (`module_type='tourism'`) — preserves the
 *    office/tourism separation contract.
 *  - Other office modules (`module_type='bus'`, 'online', 'wallet_transfer')
 *    — those are per-module vaults owned by their respective rules.
 *  - Subject accounts (customer/supplier) — wrong type.
 *  - Inactive accounts.
 *
 * @see \App\Support\Finance\AccountModuleContract
 */
class FawryLiquidityAccount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $account = Account::query()->find($value);
        if (! $account) {
            return;
        }

        if (! self::belongsToFawryModule($account)) {
            $fail('يجب أن يكون الحساب تابعاً لموديول فوري أو خزينة قسم المكتب الموحّدة.');

            return;
        }

        $type = $account->type instanceof \BackedEnum ? $account->type->value : (string) $account->type;
        if (! in_array($type, AccountModuleContract::LIQUIDITY_TYPES, true)) {
            $fail('يجب اختيار حساب سيولة (خزينة / بنك / محفظة) تابع لفوري أو قسم المكتب.');

            return;
        }

        if (! $account->is_active) {
            $fail('الحساب المحدد غير مفعّل.');
        }
    }

    /**
     * True if the account can be used as a Fawry-module liquidity vault.
     *
     * Acceptance matrix:
     *  ┌─────────────────────────────┬───────┐
     *  │ module_type / module        │ result│
     *  ├─────────────────────────────┼───────┤
     *  │ module_type=fawry           │ ✅    │
     *  │ module=fawry (alias only)   │ ✅    │
     *  │ module_type=office          │ ✅    │ (Phase 5)
     *  │ module_type=bus             │ ❌    │
     *  │ module_type=online          │ ❌    │
     *  │ module_type=wallet_transfer │ ❌    │
     *  │ module_type=tourism         │ ❌    │
     *  └─────────────────────────────┴───────┘
     */
    public static function belongsToFawryModule(Account $account): bool
    {
        $moduleType = $account->module_type instanceof \BackedEnum
            ? $account->module_type->value
            : (string) ($account->module_type ?? '');
        $module = $account->module instanceof \BackedEnum
            ? $account->module->value
            : (string) ($account->module ?? '');

        if ($moduleType === 'fawry' || $module === 'fawry') {
            return true;
        }

        if ($moduleType === AccountModuleContract::OFFICE_MODULE_TYPE) {
            return true;
        }

        return false;
    }
}