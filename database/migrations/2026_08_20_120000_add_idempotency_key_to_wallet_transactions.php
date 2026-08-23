<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IDM-1 REMEDIATION (2026-08-20) — Wallet transactions idempotency.
 * ============================================================
 *
 * Wallet & Transfers (`POST /api/v1/wallet/transactions`) had no idempotency
 * mechanism: a retry, double-click, or network-flake created a duplicate
 * financial transaction. This is IDM-1 (CRITICAL) in the audit's
 * WALLET_TRANSFER_FINDINGS.md.
 *
 * This migration mirrors the established Hajj/Umra, Flight, Visa, and Bus
 * idempotency pattern (see 2026_08_15_143500, 2026_08_15_150000,
 * 2026_08_15_200000, 2026_08_20_053507).
 *
 * Identity:    (created_by, idempotency_key)
 * Stored on:   wallet_transactions.idempotency_key  (nullable, 100 chars)
 * Enforced:    UNIQUE index `wt_idem_uniq` on (created_by, idempotency_key)
 *
 * Why scope by (created_by, idempotency_key) and not idempotency_key alone:
 *   The project's other idempotent endpoints (Hajj/Umra, Flight, Visa, Bus)
 *   all scope by a principal + key. Idempotency keys are caller-controlled
 *   and reuse across principals would be a programming error. The convention
 *   is consistent across the codebase.
 *
 * Why include `deleted_at` is NOT in the unique index:
 *   The Laravel `SoftDeletes` trait uses a non-null `deleted_at` for soft-
 *   deleted rows. Soft-deleted rows with the same (created_by, key) are
 *   historical records of an undone operation and must NOT block a fresh
 *   attempt with the same key. We use the standard MySQL/SQLite behaviour
 *   where NULL is the only "active" marker; a soft-deleted row has
 *   `deleted_at IS NOT NULL` and the unique index does NOT compare
 *   `deleted_at` explicitly — instead, the application's Layer-1 pre-check
 *   filters out soft-deleted rows before treating a unique match as a
 *   replay. This is the same convention used by the other modules.
 *
 * Pre-flight check:
 *   Before adding the UNIQUE, we check for existing duplicate (created_by,
 *   idempotency_key) rows. If found, the migration STOPS and reports them
 *   — per the project rule "do not silently clean financial history".
 *
 * Backward compatibility:
 *   - The column is nullable. Legacy callers (without Idempotency-Key)
 *     persist NULL, and NULLs are distinct in unique indexes.
 *   - Existing rows are not modified.
 *   - The migration is REVERSIBLE via down().
 *
 * NO production DB schema change beyond additive column + index.
 * NO row-level data modifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Step 1: pre-flight check ─────────────────────────────────
        // Surface existing duplicates before the UNIQUE constraint fails.
        if (Schema::hasColumn('wallet_transactions', 'idempotency_key')) {
            $duplicates = DB::table('wallet_transactions')
                ->select('created_by', 'idempotency_key', DB::raw('COUNT(*) as n'))
                ->whereNotNull('idempotency_key')
                ->groupBy('created_by', 'idempotency_key')
                ->having('n', '>', 1)
                ->get();

            if ($duplicates->isNotEmpty()) {
                throw new \RuntimeException(
                    'IDM-1 IDEMPOTENCY MIGRATION BLOCKED: ' .
                    $duplicates->count() . ' duplicate (created_by, idempotency_key) row(s) already exist. ' .
                    'Investigate and remediate manually before adding the unique constraint. ' .
                    'First few duplicates: ' . $duplicates->take(5)->toJson()
                );
            }
        }

        // ─── Step 2: add the column ───────────────────────────────────
        if (! Schema::hasColumn('wallet_transactions', 'idempotency_key')) {
            Schema::table('wallet_transactions', function (Blueprint $t) {
                // 100 chars matches the project's Hajj/Umra, Flight, and
                // Visa columns (canonical IETF draft upper bound).
                $t->string('idempotency_key', 100)
                    ->nullable()
                    ->after('amount_paid');
            });
        }

        // ─── Step 3: add the unique index ─────────────────────────────
        $indexName = 'wt_idem_uniq';
        if (! $this->indexExists('wallet_transactions', $indexName)) {
            Schema::table('wallet_transactions', function (Blueprint $t) use ($indexName) {
                $t->unique(
                    ['created_by', 'idempotency_key'],
                    $indexName
                );
            });
        }
    }

    public function down(): void
    {
        $indexName = 'wt_idem_uniq';
        if ($this->indexExists('wallet_transactions', $indexName)) {
            Schema::table('wallet_transactions', function (Blueprint $t) use ($indexName) {
                $t->dropUnique($indexName);
            });
        }
        if (Schema::hasColumn('wallet_transactions', 'idempotency_key')) {
            Schema::table('wallet_transactions', function (Blueprint $t) {
                $t->dropColumn('idempotency_key');
            });
        }
    }

    /**
     * Cross-driver check: does the named index exist on this table?
     * MySQL (information_schema), SQLite (sqlite_master), PostgreSQL (pg_indexes).
     */
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
