<?php

namespace App\Support\Finance;

use App\Enums\AccountType;

/**
 * Single source of truth for the Office / Tourism division split across
 * all {@see \App\Models\Account} records.
 *
 * This contract is the canonical reference for:
 *  - Which `module_type` values identify the Office division vs. the Tourism division.
 *  - Which modules belong to each division.
 *  - Which {@see AccountType} values are operational (liquidity),
 *    subject (AR/AP mirroring), or internal (clearing / GL line items).
 *
 * Rules of engagement (enforced by code paths that read this contract —
 * see {@see \App\Models\Account}, {@see \App\Rules\BusLiquidityAccount},
 * {@see \App\Rules\HajjUmraLiquidityAccount}, the
 * `useTreasuryAccountGroups` Vue composable, and the Phase-5 / Phase-6
 * coupling-point fixes):
 *
 *  1. An Office-division account NEVER appears in any Tourism-division
 *     dropdown or filter, and vice versa. This is the primary safety
 *     invariant of the unification work.
 *
 *  2. `module_type` is the strict classification column. `module` is
 *     an OPTIONAL preferred-label / alias column. A division-unified
 *     account has `module_type='office'` (or `'tourism'`) and
 *     `module=null` when it is truly shared across all modules of
 *     that division. Setting `module='bus'` on an office-division
 *     account narrows the visible label to "bus" but the account is
 *     still valid for fawry/online/wallet_transfer within office.
 *
 *  3. `module_type='general'` is intentionally NOT in either division's
 *     `*_DIVISION_MODULES` list. It must be migrated or explicitly
 *     reclassified before being relied on; legacy code that still
 *     uses it remains in `AccountModuleDivision::OFFICE` only for
 *     backward compatibility, NOT as a contract.
 *
 *  4. This class is `final` — extend by adding constants/methods here,
 *     not by subclassing.
 */
final class AccountModuleContract
{
    /** Office division primary marker. Members: bus, fawry, online, wallet_transfer. */
    public const OFFICE_MODULE_TYPE = 'office';

    /** Tourism division primary marker. Members: flights, hajj_umra, visas. */
    public const TOURISM_MODULE_TYPE = 'tourism';

    /**
     * Module names that live under the Office division.
     * Used as the closure set for `accountBelongsToModule` etc.
     */
    public const OFFICE_DIVISION_MODULES = [
        'office', 'bus', 'fawry', 'online', 'wallet_transfer',
    ];

    /**
     * Module names that live under the Tourism division.
     */
    public const TOURISM_DIVISION_MODULES = [
        'tourism', 'flights', 'hajj_umra', 'visas',
    ];

    /**
     * Operational (liquidity) AccountType values.
     * Mirrored from {@see \App\Support\Finance\AccountModuleDivision::LIQUIDITY_TYPES}
     * as the canonical reference for new code.
     *
     * 'treasury' and 'post' removed after Phase 3.5 cleanup (2026-07-14):
     * the DB enum ({@see migrations 2026_07_09+}) no longer accepts those
     * values, and the PHP enum {@see AccountType} cases were removed for
     * consistency. Any account previously called "treasury" or "post" is now
     * represented by {@see 'bank'} or {@see 'cashbox'} using a free-text name.
     */
    public const LIQUIDITY_TYPES = [
        'cashbox', 'wallet', 'bank',
    ];

    /**
     * Subject (AR / AP mirroring) AccountType values.
     */
    public const SUBJECT_TYPES = [
        'customer', 'supplier',
    ];

    /**
     * Internal GL line accounts (used for clearing and equalisation).
     */
    public const INTERNAL_TYPES = [
        'expense', 'revenue', 'liability', 'owner',
    ];

    /**
     * Determine the division a module belongs to.
     *
     * Returns the string `'office'` or `'tourism'`, or `null` if the
     * module is not part of either division (e.g. legacy `'general'`).
     *
     * @param  string|null  $module  Module name (case-insensitive).
     */
    public static function divisionFor(?string $module): ?string
    {
        $m = strtolower((string) $module);

        if (in_array($m, self::OFFICE_DIVISION_MODULES, true)) {
            return 'office';
        }

        if (in_array($m, self::TOURISM_DIVISION_MODULES, true)) {
            return 'tourism';
        }

        return null;
    }

    /**
     * True iff the module belongs to the Office division.
     */
    public static function isOfficeModule(?string $module): bool
    {
        return self::divisionFor($module) === 'office';
    }

    /**
     * True iff the module belongs to the Tourism division.
     */
    public static function isTourismModule(?string $module): bool
    {
        return self::divisionFor($module) === 'tourism';
    }

    /**
     * Convenience: classify an AccountType as liquidity / subject / internal.
     *
     * @return 'liquidity'|'subject'|'internal'
     */
    public static function categoryForAccountType(string $typeValue): string
    {
        if (in_array($typeValue, self::LIQUIDITY_TYPES, true)) {
            return 'liquidity';
        }

        if (in_array($typeValue, self::SUBJECT_TYPES, true)) {
            return 'subject';
        }

        return 'internal';
    }
}
