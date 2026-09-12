<?php

use Illuminate\Database\Migrations\Migration;

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
        // 2026-09-03: Disabled per user decision (re-affirms ec158ab from
        // 2026-07-29) — `migrate:fresh` must produce an EMPTY `accounts`
        // table. The Online Create page now ships a conditional Filament
        // Placeholder that tells the user to add an account via the
        // Filament Accounts resource before submitting.
        //
        // Historical DATA (the 3 baseline rows) is preserved in git
        // history; the SCHEMA is unaffected.
        //
        // To re-seed manually, see the original array in
        // `git log -p database/migrations/2026_08_28_130000_seed_online_module_accounts.php`.
    }

    public function down(): void
    {
        // No-op — financial account seeds are reference data; not auto-rolled back.
    }
};
