<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WLT-1 RECEIVE-DESTINATION FLEXIBILITY (2026-09-02)
 * ====================================================
 *
 * Background:
 *   Pre-fix, the Wallet & Transfers module's RECEIVE flow hard-coded the
 *   destination of the cash leg:
 *     - registered customer → customer's account (the customer's debt/credit
 *       balance at the agency)
 *     - anonymous customer  → cash_account_id (default cashbox)
 *
 *   The user requested the ability to choose ANY active account as the
 *   destination of a RECEIVE — e.g. a bank account, a wallet provider
 *   balance, a card clearing account, or the customer's account.
 *
 * Solution:
 *   Add an OPTIONAL `receive_destination_account_id` column. When supplied
 *   on a RECEIVE transaction, the Expense leg is routed to that account
 *   instead of the legacy default. Backward-compatible: when NULL, the
 *   pre-fix default behaviour applies unchanged (existing rows + existing
 *   API clients are not broken).
 *
 * Storage:
 *   wallet_transactions.receive_destination_account_id (nullable bigint,
 *   references accounts.id). Indexed for reporting/filter joins.
 *
 * Scope:
 *   - column is nullable → existing rows preserved unchanged.
 *   - column is only meaningful for type='receive' → no constraint needed
 *     since the model's `receiveDestinationAccount` relation is the only
 *     reader and ignores nulls.
 *   - no row-level data modifications.
 *   - reversible via down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wallet_transactions', 'receive_destination_account_id')) {
            Schema::table('wallet_transactions', function (Blueprint $t) {
                // Place next to cash_account_id so all "settlement-style"
                // columns cluster together in the schema dump.
                $t->foreignId('receive_destination_account_id')
                    ->nullable()
                    ->after('cash_account_id')
                    ->constrained('accounts')
                    ->nullOnDelete();
            });
        }

        // Index for reporting/filter joins (e.g. "all receives that landed
        // in bank-account X today"). Lightweight single-column index —
        // we do NOT need a composite because destination is rarely queried
        // together with other filters in the same predicate.
        $indexName = 'wt_recv_dest_idx';
        if (! $this->indexExists('wallet_transactions', $indexName)) {
            Schema::table('wallet_transactions', function (Blueprint $t) use ($indexName) {
                $t->index('receive_destination_account_id', $indexName);
            });
        }
    }

    public function down(): void
    {
        $indexName = 'wt_recv_dest_idx';
        if ($this->indexExists('wallet_transactions', $indexName)) {
            Schema::table('wallet_transactions', function (Blueprint $t) use ($indexName) {
                $t->dropIndex($indexName);
            });
        }
        if (Schema::hasColumn('wallet_transactions', 'receive_destination_account_id')) {
            Schema::table('wallet_transactions', function (Blueprint $t) {
                $t->dropConstrainedForeignId('receive_destination_account_id');
            });
        }
    }

    /**
     * Cross-driver check: does the named index exist on this table?
     * Mirrors the helper used in 2026_08_20_120000_add_idempotency_key_to_wallet_transactions.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        switch ($driver) {
            case 'mysql':
                $dbName = \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
                $count = \Illuminate\Support\Facades\DB::selectOne(
                    'SELECT COUNT(*) AS n FROM information_schema.statistics
                     WHERE table_schema = ? AND table_name = ? AND index_name = ?',
                    [$dbName, $table, $indexName]
                );
                return ((int) ($count->n ?? 0)) > 0;

            case 'sqlite':
                $rows = \Illuminate\Support\Facades\DB::select(
                    'SELECT name FROM sqlite_master WHERE type = ? AND tbl_name = ? AND name = ?',
                    ['index', $table, $indexName]
                );
                return count($rows) > 0;

            case 'pgsql':
                $count = \Illuminate\Support\Facades\DB::selectOne(
                    'SELECT COUNT(*) AS n FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                    [$table, $indexName]
                );
                return ((int) ($count->n ?? 0)) > 0;

            default:
                return false;
        }
    }
};
