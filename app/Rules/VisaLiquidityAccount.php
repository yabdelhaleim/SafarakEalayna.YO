<?php

namespace App\Rules;

use App\Models\Account;
use App\Support\Finance\AccountModuleContract;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that the selected account is a usable Visa-module liquidity account.
 *
 * Phase 5 (Account Unification) — designed broadened from the start:
 *
 *  1. Strict per-module: `module_type ∈ {'visas','visa'}` OR
 *     `module ∈ {'visas','visa'}` — canonical 'visas' + legacy alias 'visa'.
 *     Per the Phase 3.5 saving hook, no NEW liquidity account can have
 *     these module_type values, but legacy data may exist and is still
 *     accepted for backward compatibility.
 *
 *  2. Tourism-division unified vault: `module_type='tourism'`
 *     A single tourism-wide vault now serves flights/hajj_umra/visas
 *     simultaneously. The `module` column on such accounts is just a
 *     label hint and is NOT used as a filter — per
 *     {@see \App\Support\Finance\AccountModuleContract} rule 2.
 *
 * REJECTS:
 *  - Office-division accounts (`module_type='office'`), even with
 *    `module='visa'` alias — preserves the office/tourism separation.
 *  - Subject accounts (customer/supplier) — wrong type.
 *  - Inactive accounts.
 *
 * @see \App\Support\Finance\AccountModuleContract
 */
class VisaLiquidityAccount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $account = Account::query()->find($value);
        if (! $account) {
            return;
        }

        if (! self::belongsToVisaModule($account)) {
            $fail('يجب أن يكون الحساب تابعاً لموديول التأشيرات أو خزينة قسم السياحة الموحّدة.');

            return;
        }

        $type = $account->type instanceof \BackedEnum ? $account->type->value : (string) $account->type;
        if (! in_array($type, AccountModuleContract::LIQUIDITY_TYPES, true)) {
            $fail('يجب اختيار حساب سيولة (خزينة / بنك / محفظة) تابع للتأشيرات أو قسم السياحة.');

            return;
        }

        if (! $account->is_active) {
            $fail('الحساب المحدد غير مفعّل.');
        }
    }

    /**
     * True if the account can be used as a Visa-module liquidity vault.
     *
     * Acceptance matrix:
     *  ┌──────────────────────────────────┬───────┐
     *  │ module_type / module             │ result│
     *  ├──────────────────────────────────┼───────┤
     *  │ module_type ∈ {visas,visa}       │ ✅    │ (legacy data)
     *  │ module ∈ {visas,visa}            │ ✅    │ (alias)
     *  │ module_type=tourism (any module) │ ✅    │ (Phase 5 — unified vault)
     *  │ module_type=flights              │ ❌    │
     *  │ module_type=hajj_umra            │ ❌    │
     *  │ module_type=office               │ ❌    │
     *  └──────────────────────────────────┴───────┘
     */
    public static function belongsToVisaModule(Account $account): bool
    {
        $moduleType = $account->module_type instanceof \BackedEnum
            ? $account->module_type->value
            : (string) ($account->module_type ?? '');
        $module = $account->module instanceof \BackedEnum
            ? $account->module->value
            : (string) ($account->module ?? '');

        // Strict per-module (canonical 'visas' + legacy alias 'visa')
        if (in_array($moduleType, ['visas', 'visa'], true)) {
            return true;
        }

        if (in_array($module, ['visas', 'visa'], true)) {
            return true;
        }

        // Phase 5: Tourism-division unified vault — module_type=tourism,
        // module value ignored (it's just a label after auto-fill).
        if ($moduleType === AccountModuleContract::TOURISM_MODULE_TYPE) {
            return true;
        }

        return false;
    }
}