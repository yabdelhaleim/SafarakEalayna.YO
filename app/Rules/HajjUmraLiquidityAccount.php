<?php

namespace App\Rules;

use App\Models\Account;
use App\Support\Finance\AccountModuleDivision;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;

class HajjUmraLiquidityAccount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $account = Account::query()->find($value);
        if (! $account) {
            return;
        }

        if (! $this->belongsToHajjUmraModule($account)) {
            $fail('يجب أن يكون الحساب تابعاً لموديول الحج والعمرة.');

            return;
        }

        $type = $account->type instanceof \BackedEnum ? $account->type->value : (string) $account->type;
        if (! in_array($type, AccountModuleDivision::LIQUIDITY_TYPES, true)) {
            $fail('يجب اختيار حساب سيولة (خزينة / بنك / محفظة) تابع للحج والعمرة.');

            return;
        }

        if (! $account->is_active) {
            $fail('الحساب المحدد غير مفعّل.');
        }
    }

    public static function applyLiquidityScope(Builder $query): Builder
    {
        $query->where('is_active', true)
            ->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES);

        AccountModuleDivision::applyModuleFilter($query, 'hajj_umra');

        return $query;
    }

    public static function belongsToHajjUmraModule(Account $account): bool
    {
        $moduleType = $account->module_type instanceof \BackedEnum
            ? $account->module_type->value
            : (string) ($account->module_type ?? '');
        $module = $account->module instanceof \BackedEnum
            ? $account->module->value
            : (string) ($account->module ?? '');

        if (in_array($moduleType, ['hajj_umra', 'hajj', 'umrah'], true)) {
            return true;
        }

        if (in_array($module, ['hajj_umra', 'hajj', 'umrah'], true)) {
            return true;
        }

        return false;
    }
}
