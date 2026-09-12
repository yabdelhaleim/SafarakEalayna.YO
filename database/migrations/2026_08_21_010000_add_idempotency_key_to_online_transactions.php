<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-4 REMEDIATION (2026-08-21) — Online transactions idempotency.
 * ============================================================
 *
 * Online Services (`POST /api/v1/online/transactions`) had no idempotency
 * mechanism: a network retry or double-click could create a duplicate
 * financial transaction (income + cash settlement + expense + AR mutation).
 * This is SEC-4 (MEDIUM) in the Online audit report.
 *
 * This migration mirrors the established Hajj/Umra, Flight, Visa, Wallet,
 * and Bus idempotency pattern (see 2026_08_15_143500, 2026_08_15_150000,
 * 2026_08_15_200000, 2026_08_20_053507, 2026_08_20_120000).
 *
 * Identity:    (created_by, idempotency_key)
 * Stored on:   online_transactions.idempotency_key  (nullable, 100 chars)
 * Enforced:    UNIQUE index `ot_idem_uniq` on (created_by, idempotency_key)
 *
 * Why scope by (created_by, idempotency_key) and not idempotency_key alone:
 *   Online transactions are not booking-bound (Hajj/Umra, Flight, Visa, Bus
 *   pattern), but they DO have an actor (the cashier who created the sale).
 *   Wallet already uses the same (created_by, key) pattern because wallet
 *   transactions are likewise actor-bound. Reusing the Wallet convention
 *   keeps idempotency keys caller-scoped without inventing a new scope.
 *
 * Why `deleted_at` is NOT in the unique index:
 *   Same convention as Wallet/Hajj/Umra/Visa: soft-deleted rows are historical
 *   records and must NOT block a fresh attempt with the same key. The
 *   application layer (OnlineTransactionService) filters them in the
 *   pre-check before treating a unique match as a replay.
 *
 * Pre-flight check:
 *   Before adding the UNIQUE, check for existing duplicate
 *   (created_by, idempotency_key) rows. If found, the migration STOPS and
 *   reports them — per the project rule "do not silently clean financial
 *   history".
 *
 * Backward compatibility:
 *   - The column is nullable. Legacy callers (without Idempotency-Key)
 *     persist NULL, and NULLs are distinct in unique indexes.
 *   - Existing rows are not modified.
 *   - The migration is REVERSIBLE via down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('online_transactions', 'idempotency_key')) {
            $duplicates = DB::table('online_transactions')
                ->select('created_by', 'idempotency_key', DB::raw('COUNT(*) as n'))
                ->whereNotNull('idempotency_key')
                ->groupBy('created_by', 'idempotency_key')
                ->having('n', '>', 1)
                ->get();

            if ($duplicates->isNotEmpty()) {
                throw new \RuntimeException(
                    'SEC-4 IDEMPOTENCY MIGRATION BLOCKED: '.
                    $duplicates->count().' duplicate (created_by, idempotency_key) row(s) already exist. '.
                    'Investigate and remediate manually before adding the unique constraint. '.
                    'First few duplicates: '.$duplicates->take(5)->toJson()
                );
            }
        }

        if (! Schema::hasColumn('online_transactions', 'idempotency_key')) {
            Schema::table('online_transactions', function (Blueprint $t) {
                // 100 chars matches the canonical project pattern.
                $t->string('idempotency_key', 100)
                    ->nullable()
                    ->after('reference_number');
            });
        }

        $indexName = 'ot_idem_uniq';
        if (! $this->indexExists('online_transactions', $indexName)) {
            Schema::table('online_transactions', function (Blueprint $t) use ($indexName) {
                $t->unique(
                    ['created_by', 'idempotency_key'],
                    $indexName
                );
            });
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        $indexName = 'ot_idem_uniq';
        $createdByFk = 'online_transactions_created_by_foreign';

        if ($driver === 'mysql') {
            // MySQL won't drop the UNIQUE index `ot_idem_uniq` while a
            // foreign key (online_transactions_created_by_foreign) uses its
            // leading column `created_by`. Temporarily drop the FK so the
            // index can be removed; re-add it after the column is dropped.
            DB::statement("ALTER TABLE online_transactions DROP FOREIGN KEY {$createdByFk}");
        }

        if ($this->indexExists('online_transactions', $indexName)) {
            Schema::table('online_transactions', function (Blueprint $t) use ($indexName) {
                $t->dropUnique($indexName);
            });
        }
        if (Schema::hasColumn('online_transactions', 'idempotency_key')) {
            Schema::table('online_transactions', function (Blueprint $t) {
                $t->dropColumn('idempotency_key');
            });
        }

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE online_transactions "
                ."ADD CONSTRAINT {$createdByFk} FOREIGN KEY (created_by) REFERENCES users(id)"
            );
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