<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed baseline cashbox + bank + wallet accounts for the Online Services
 * module when the `accounts` table is empty.
 *
 * Why this migration exists
 * ──────────────────────────
 * The earlier migration `2026_07_27_122858_seed_online_module_accounts`
 * was deliberately disabled by the user on 2026-07-29 (commit `ec158ab`)
 * to make `migrate:fresh` produce an EMPTY database — so any seed that
 * ran unconditionally would keep re-creating rows on every fresh
 * migration. That decision has the side-effect that, on production,
 * the `accounts` table for the Office division ended up empty,
 * which broke the Online Create page's "اختر حساب التحصيل" dropdown
 * (and, by extension, every other Office-division module that consumes
 * the same dropdown).
 *
 * This migration is the targeted re-seeding fix:
 *
 *   • ONLY inserts accounts that do NOT already exist (idempotent — no
 *     duplicates if the user has already created them via Filament).
 *   • ONLY inserts into the `module_type='office'` partition (per the
 *     AccountModuleContract — Online is a sub-module of Office).
 *   • Restricted to LIQUIDITY_TYPES (cashbox, bank, wallet) which are
 *     the ONLY types surfaced by OnlineSettingsController::accounts().
 *   • Each row gets a balanced 0.00 starting balance so the AR/vault
 *     reconciles from day one.
 *   • Same convention as `OnlineTransactionService::ensureCustomerAccount`
 *     so the seed rows look indistinguishable from rows the user
 *     would have created manually.
 *
 * If an Office-division account with the EXACT same name already exists
 * (e.g. user added one through Filament), we SKIP the insert. Otherwise
 * the user would end up with two rows having the same human-readable
 * name, which is confusing in the dropdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now()->toDateTimeString();

        // Each entry: name → AccountType value (string).
        // We use the raw string values rather than the BackedEnum because
        // this is a data seed running outside the application's typical
        // boot path. Matches the enum in App\Enums\AccountType.
        $accounts = [
            [
                'name' => 'الخزينة الرئيسية',
                'type' => 'cashbox',
                'wallet_provider' => null,
                'wallet_number' => null,
                'notes' => 'خزينة نقدية افتراضية — الحساب النقدي الرئيسي لمكتب الخدمات الإلكترونية.',
                'is_module_vault' => true,
            ],
            [
                'name' => 'البنك الأهلي المصري',
                'type' => 'bank',
                'wallet_provider' => null,
                'wallet_number' => null,
                'notes' => 'حساب بنكي افتراضي — البنك الأهلي المصري.',
                'is_module_vault' => false,
            ],
            [
                'name' => 'محفظة الشركة الرئيسية',
                'type' => 'wallet',
                'wallet_provider' => 'instapay',
                'wallet_number' => '01000000000',
                'notes' => 'محفظة إلكترونية افتراضية — إنستاباي.',
                'is_module_vault' => false,
            ],
        ];

        $inserted = 0;
        foreach ($accounts as $account) {
            // Idempotency check — keyed on the (name, module_type) pair so
            // we never create a duplicate. If the user already has a row
            // with the same name in the office division, leave it as-is.
            $existing = DB::table('accounts')
                ->where('name', $account['name'])
                ->whereIn('module_type', ['online', 'office'])
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                continue;
            }

            DB::table('accounts')->insert([
                'name' => $account['name'],
                'type' => $account['type'],
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => 1,
                'module_type' => 'office',
                'is_module_vault' => $account['is_module_vault'] ? 1 : 0,
                'owner_type' => 'office',
                'wallet_provider' => $account['wallet_provider'],
                'wallet_number' => $account['wallet_number'],
                'notes' => $account['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $inserted++;
        }

        \Illuminate\Support\Facades\Log::info('seed_online_module_accounts: complete', [
            'inserted' => $inserted,
            'total_office_accounts' => DB::table('accounts')
                ->whereIn('module_type', ['online', 'office'])
                ->whereNull('deleted_at')
                ->count(),
        ]);
    }

    public function down(): void
    {
        // Reversal is intentionally a no-op — financial-account seeds are
        // reference data that should not be auto-rolled-back from a
        // migration. If a recovery is needed, delete the rows by name
        // manually via the Filament UI.
    }
};
