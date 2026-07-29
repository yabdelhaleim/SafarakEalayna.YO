<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent migration: seeds the default payment methods used by
 * ALL modules (Online, Fawry, Bus, etc.).
 *
 * Safe to run multiple times — uses INSERT IGNORE / ON DUPLICATE KEY UPDATE
 * so existing rows are never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 2026-07-29: Disabled by user request — `migrate:fresh` should
        // produce an EMPTY database. The previous baseline rows were a
        // data-seed disguised as a migration. Historical DATA is preserved
        // in git history (pre-change blob); historical SCHEMA (the
        // `payment_methods` table itself) was created by an earlier
        // migration and is unaffected by this no-op.
        //
        // To re-seed manually, see the original array in
        // `git log -p database/migrations/2026_07_27_122817_seed_default_payment_methods.php`.
    }

    public function down(): void
    {
        // Do not delete — these are operational records.
        // Rolling back this migration is a no-op by design.
    }
};
