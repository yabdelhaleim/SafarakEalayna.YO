<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent migration: seeds baseline cashbox + bank + wallet accounts
 * for the Online Services module (module_type = 'office', per the
 * AccountModuleContract — online is a sub-module of the Office division).
 *
 * ONLY inserts if an account with the same name does not already exist
 * in the online/office division, so running this on production with
 * existing data is 100% safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 2026-07-29: Disabled by user request — `migrate:fresh` should
        // produce an EMPTY database. The previous baseline rows were a
        // data-seed disguised as a migration and they kept re-appearing
        // on every fresh migration (the "خزينة الخدمات الإلكترونية"
        // cashbox with 0.00 balance seen in the Filament TransferAccounts
        // UI was this exact row).
        //
        // Historical DATA is preserved in git history (pre-change blob);
        // historical SCHEMA (the `accounts` table itself) was created by
        // an earlier migration and is unaffected by this no-op.
        //
        // To re-seed manually, see the original array in
        // `git log -p database/migrations/2026_07_27_122858_seed_online_module_accounts.php`.
    }

    public function down(): void
    {
        // Intentionally left empty — do not delete financial accounts on rollback.
    }
};
