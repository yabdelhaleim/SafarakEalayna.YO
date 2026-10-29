<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE PURCHASE ACCOUNT SEPARATION (2026-10-29)
 * ====================================================
 *
 * Adds an optional `purchase_account_id` to `online_transactions`.
 * Allows the user to explicitly select the treasury, bank, or wallet
 * from which the purchase cost was deducted (e.g. Vodafone Cash, Fawry balance,
 * Bank card, Cashbox), independently of the customer collection account (`account_id`).
 *
 * Backward-compatible: when NULL, legacy fallback logic applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('online_transactions', 'purchase_account_id')) {
            Schema::table('online_transactions', function (Blueprint $table) {
                $table->foreignId('purchase_account_id')
                    ->nullable()
                    ->after('account_id')
                    ->constrained('accounts')
                    ->nullOnDelete();
            });
        }

        $indexName = 'ot_purchase_account_idx';
        if (! $this->indexExists('online_transactions', $indexName)) {
            Schema::table('online_transactions', function (Blueprint $table) use ($indexName) {
                $table->index('purchase_account_id', $indexName);
            });
        }
    }

    public function down(): void
    {
        $indexName = 'ot_purchase_account_idx';
        if ($this->indexExists('online_transactions', $indexName)) {
            Schema::table('online_transactions', function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        }

        if (Schema::hasColumn('online_transactions', 'purchase_account_id')) {
            Schema::table('online_transactions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('purchase_account_id');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();
        switch ($driver) {
            case 'mysql':
                $dbName = DB::connection()->getDatabaseName();
                $count = DB::selectOne(
                    'SELECT COUNT(*) AS n FROM information_schema.statistics
                     WHERE table_schema = ? AND table_name = ? AND index_name = ?',
                    [$dbName, $table, $indexName]
                );
                return ((int) ($count->n ?? 0)) > 0;

            case 'sqlite':
                $rows = DB::select(
                    'SELECT name FROM sqlite_master WHERE type = ? AND tbl_name = ? AND name = ?',
                    ['index', $table, $indexName]
                );
                return count($rows) > 0;

            case 'pgsql':
                $count = DB::selectOne(
                    'SELECT COUNT(*) AS n FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                    [$table, $indexName]
                );
                return ((int) ($count->n ?? 0)) > 0;

            default:
                return false;
        }
    }
};
