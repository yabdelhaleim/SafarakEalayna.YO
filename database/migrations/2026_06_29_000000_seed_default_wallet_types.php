<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 2026-07-29: Disabled by user request — `migrate:fresh` should
        // produce an EMPTY database. The previous baseline rows were a
        // data-seed disguised as a migration. Historical DATA is preserved
        // in git history (pre-change blob); historical SCHEMA (the
        // `wallet_types` table itself) was created by an earlier migration
        // and is unaffected by this no-op.
        //
        // To re-seed manually, see the original array in
        // `git log -p database/migrations/2026_06_29_000000_seed_default_wallet_types.php`.
    }

    public function down(): void
    {
        // No-op
    }
};
