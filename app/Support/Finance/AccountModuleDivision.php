<?php

namespace App\Support\Finance;

use Illuminate\Database\Eloquent\Builder;

/**
 * Maps Vue/Filament module_type values to business divisions (tourism vs office).
 * Filament stores granular keys (flights, bus, fawry…) while legacy Vue tabs use tourism/office.
 */
final class AccountModuleDivision
{
    public const TOURISM = ['tourism', 'flights', 'hajj_umra', 'visas'];

    public const OFFICE = ['office', 'bus', 'fawry', 'online', 'wallet_transfer', 'general'];

    /**
     * Canonical liquidity account types — ALIASED to {@see AccountModuleContract::LIQUIDITY_TYPES}.
     *
     * Pre-fix this was an independent 5-value array (cashbox/wallet/bank/treasury/post)
     * that drifted from the canonical 3-value set after Phase 3.5b removed the
     * 'treasury'/'post' cases from the DB enum. Now a structural reference so the
     * two can never drift again — any future change to the contract automatically
     * propagates here. All existing call-sites (controllers, services, reports,
     * `applyLiquidityTreasuryScope()` below, `UnifiedLiquidityGrouper`, etc.) keep
     * working unchanged.
     */
    public const LIQUIDITY_TYPES = AccountModuleContract::LIQUIDITY_TYPES;

    /** @var array<string, string> Vue/API singular or legacy keys → Filament canonical module_type */
    public const LEGACY_MODULE_TO_TYPE = [
        'flight' => 'flights',
        'visa' => 'visas',
        'hajj' => 'hajj_umra',
        'umrah' => 'hajj_umra',
        'wallet' => 'wallet_transfer',
    ];

    public static function applyLiquidityTreasuryScope(Builder $query): void
    {
        $query->whereIn('owner_type', ['office', 'owner'])
            ->whereIn('type', self::LIQUIDITY_TYPES)
            ->where('name', 'not like', '%عميل%')
            ->where('name', 'not like', '%شركة%')
            ->where('name', 'not like', '%مورد%')
            ->where('name', 'not like', '%إقفال%')
            ->where('name', 'not like', '%(نظام)%')
            ->where('name', 'not like', '%ذممة%')
            ->where('name', 'not like', '%sad%')
            ->where('name', 'not like', '%رصيد مسبق%');

        $prepaidNames = array_values(array_filter(
            config('accounting.clearing.prepaid', []),
            fn ($name) => is_string($name) && $name !== ''
        ));

        if ($prepaidNames !== []) {
            $query->whereNotIn('name', $prepaidNames);
        }
    }

    public static function resolveModuleTypeKey(?string $moduleType, ?string $module = null): string
    {
        if ($moduleType !== null && $moduleType !== '') {
            return $moduleType;
        }

        if ($module === null || $module === '') {
            return 'general';
        }

        return self::LEGACY_MODULE_TO_TYPE[$module] ?? $module;
    }

    public static function moduleLabel(string $moduleType): string
    {
        return match ($moduleType) {            'general' => 'الإدارة العامة',            'office' => 'المكتب / الإداري',            'flights' => 'الطيران',            'tourism' => 'السياحة',            'hajj_umra' => 'الحج والعمرة',            'visas' => 'التأشيرات',            'bus' => 'الباصات',            'fawry' => 'فوري',            'online' => 'الخدمات الإلكترونية',            'wallet_transfer' => 'المحافظ والتحويلات',            default => ucfirst(str_replace('_', ' ', $moduleType)),        };    }    /** @return array<string, string> */    public static function moduleTypeOptions(): array    {        return [            'general' => self::moduleLabel('general'),            'office' => self::moduleLabel('office'),            'flights' => self::moduleLabel('flights'),            'bus' => self::moduleLabel('bus'),            'hajj_umra' => self::moduleLabel('hajj_umra'),            'visas' => self::moduleLabel('visas'),            'fawry' => self::moduleLabel('fawry'),            'online' => self::moduleLabel('online'),            'wallet_transfer' => self::moduleLabel('wallet_transfer'),            'tourism' => self::moduleLabel('tourism'),        ];    }    /** Legacy `accounts.module` column value for API/Vue compatibility. */    public static function legacyModuleColumn(string $moduleType): string    {        return match ($moduleType) {            'flights' => 'flight',            'visas' => 'visa',            default => $moduleType,
        };
    }

    /**
     * Filtered dropdown options for Filament — drops 'general' (and any
     * non-division value) when the account type is operational liquidity
     * (cashbox/wallet/bank).
     *
     * Background:
     *   The Account model boot guard (Account.php line ~341) hard-rejects
     *   liquidity accounts that carry `module_type='general'` because
     *   `general` is not part of any division contract — see
     *   {@see \App\Support\Finance\AccountModuleContract}. The Filament
     *   create form previously offered `general` as a default for every
     *   type, which caused 500s when the cashier saved a cashbox/wallet/
     *   bank without changing the dropdown.
     *
     * Effect:
     *   - type ∈ LIQUIDITY_TYPES → options restricted to division members
     *     (office/tourism + their subordinate modules: flights/bus/
     *     hajj_umra/visas/fawry/online/wallet_transfer). 'general' is
     *     excluded.
     *   - type ∈ INTERNAL_TYPES (expense/revenue/liability/owner) or
     *     SUBJECT_TYPES (customer/supplier) → all options including
     *     'general' remain allowed, because those rows aren't constrained
     *     by the liquidity division contract.
     *   - type === null (form not yet dirty) → all options; the default
     *     value below still takes care of the common case.
     *
     * @param  string|null  $typeValue  AccountType::value or null
     * @return array<string, string>
     */
    public static function moduleTypeOptionsForAccountType(?string $typeValue): array
    {
        if ($typeValue !== null
            && in_array($typeValue, AccountModuleContract::LIQUIDITY_TYPES, true)
        ) {
            // Liquidity accounts may only carry a DIVISION or a subordinate
            // module belonging to that division. Strip 'general' so the
            // cashier can't pick a value the Account boot guard will reject.
            return [
                'office' => self::moduleLabel('office'),
                'tourism' => self::moduleLabel('tourism'),
                'flights' => self::moduleLabel('flights'),
                'bus' => self::moduleLabel('bus'),
                'hajj_umra' => self::moduleLabel('hajj_umra'),
                'visas' => self::moduleLabel('visas'),
                'fawry' => self::moduleLabel('fawry'),
                'online' => self::moduleLabel('online'),
                'wallet_transfer' => self::moduleLabel('wallet_transfer'),
            ];
        }

        return self::moduleTypeOptions();
    }

    /**
     * Default `module_type` value for the create form, respecting the
     * Account boot guard constraint:
     *
     *   - liquidity → 'office' (the most common division for
     *     wallet_transfer/bus/fawry/online — all Office-division modules)
     *   - other     → 'general' (legacy default preserved for
     *     internal/subject rows; not constrained by the division contract)
     *
     * Single source of truth so the AccountFormSchema default and the
     * Account boot guard can never drift again.
     */
    public static function defaultModuleTypeForAccountType(?string $typeValue): string
    {
        if ($typeValue !== null
            && in_array($typeValue, AccountModuleContract::LIQUIDITY_TYPES, true)
        ) {
            return AccountModuleContract::OFFICE_MODULE_TYPE;
        }

        return 'general';
    }

    public static function applyModuleFilter(Builder $query, string $module): void
    {
        if ($module === 'general') {
            $query->where(function (Builder $q): void {
                $q->whereNull('module_type')
                    ->orWhere('module_type', 'general')
                    ->orWhere('module_type', '');
            });

            return;
        }

        $query->where(function (Builder $q) use ($module): void {
            $q->where('module_type', $module)
                ->orWhere('module', $module);

            // Vue/API singular keys → Filament canonical plural (flight → flights, visa → visas)
            $canonical = self::LEGACY_MODULE_TO_TYPE[$module] ?? $module;
            if ($canonical !== $module) {
                $q->orWhere('module_type', $canonical)
                    ->orWhere('module', $canonical);
            }

            // Filament canonical plural → legacy singular module column
            $legacyKey = array_search($module, self::LEGACY_MODULE_TO_TYPE, true);
            if ($legacyKey !== false) {
                $q->orWhere('module', $legacyKey)
                    ->orWhere('module_type', $legacyKey);
            }

            // Phase 6 / Phase 7: Division-unified vaults
            // If the module belongs to a division, also include the division's unified accounts (where module_type = division).
            if (in_array($canonical, self::OFFICE, true)) {
                $q->orWhere('module_type', 'office');
            } elseif (in_array($canonical, self::TOURISM, true)) {
                $q->orWhere('module_type', 'tourism');
            }
        });
    }

    public static function applyModuleTypeFilter(Builder $query, string $moduleType): void
    {
        if ($moduleType === 'tourism') {
            $query->whereIn('module_type', self::TOURISM);

            return;
        }

        if ($moduleType === 'office') {
            $query->whereIn('module_type', self::OFFICE);

            return;
        }

        $query->where('module_type', $moduleType);
    }

    public static function divisionForModuleType(?string $moduleType): string
    {
        if ($moduleType === null || $moduleType === '') {
            return 'office';
        }

        if (in_array($moduleType, self::TOURISM, true)) {
            return 'tourism';
        }

        return 'office';
    }
}
