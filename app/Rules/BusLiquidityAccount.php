<?php

namespace App\Rules;

use App\Models\Account;
use App\Support\Finance\AccountModuleDivision;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BusLiquidityAccount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $account = Account::query()->find($value);
        if (! $account) {
            return;
        }

        if (! self::belongsToBusModule($account)) {
            $fail('يجب أن يكون الحساب تابعاً لموديول الباصات.');

            return;
        }

        $type = $account->type instanceof \BackedEnum ? $account->type->value : (string) $account->type;
        if (! in_array($type, AccountModuleDivision::LIQUIDITY_TYPES, true)) {
            $fail('يجب اختيار حساب سيولة (خزينة / بنك / محفظة) تابع للباصات.');

            return;
        }

        if (! $account->is_active) {
            $fail('الحساب المحدد غير مفعّل.');
        }
    }

    public static function belongsToBusModule(Account $account): bool
    {
        $moduleType = $account->module_type instanceof \BackedEnum
            ? $account->module_type->value
            : (string) ($account->module_type ?? '');
        $module = $account->module instanceof \BackedEnum
            ? $account->module->value
            : (string) ($account->module ?? '');

        return $moduleType === 'bus' || $module === 'bus';
    }
}
