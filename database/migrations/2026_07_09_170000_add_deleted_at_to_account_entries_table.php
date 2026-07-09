<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the soft-delete column to account_entries.
 *
 * Reason: certain code paths (e.g. Filament pages, services, or third-party packages)
 * issue queries like:
 *     select sum(credit - debit) from account_entries where account_id = ? and deleted_at is null
 *
 * But the original `2026_04_27_170118_create_account_entries_table.php` migration
 * did NOT include `softDeletes()` — the column is missing on production.
 *
 * Resolution: add `deleted_at` as a nullable timestamp. This satisfies the WHERE clause
 * safely (NULL is treated as "not deleted"). Existing rows will have `deleted_at = NULL`
 * which evaluates to "not deleted" — no impact on balances or existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Skip if already exists (idempotent — safe to re-run)
        if (! Schema::hasColumn('account_entries', 'deleted_at')) {
            Schema::table('account_entries', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('account_entries', 'deleted_at')) {
            Schema::table('account_entries', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
