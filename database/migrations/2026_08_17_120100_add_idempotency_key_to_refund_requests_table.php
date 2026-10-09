<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `idempotency_key` column to `refund_requests` and enforces a UNIQUE
 * constraint on `(flight_booking_id, idempotency_key)`.
 *
 * Mirrors the D3 idempotency pattern used for flight_payments
 * (2026_08_15_150000_add_idempotency_key_to_flight_payments.php):
 *   1. Pre-flight duplicate check — refuse if duplicates already exist.
 *   2. Add the column (nullable — legacy callers unaffected).
 *   3. Add the unique index as the LAST LINE of defense.
 *
 * Why a NEW column:
 *   - `notes` is free-text and changes don't imply a replay.
 *   - The new column is OPT-IN: callers that don't supply a key get NULL,
 *     and MySQL/SQLite UNIQUE indexes treat NULLs as distinct, so multiple
 *     NULL-keyed rows coexist. The existing single-refund happy path is
 *     not affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Step 1: pre-flight check ───────────────────────────────────
        if (Schema::hasColumn('refund_requests', 'idempotency_key')) {
            $duplicates = DB::table('refund_requests')
                ->select('flight_booking_id', 'idempotency_key', DB::raw('COUNT(*) as n'))
                ->whereNotNull('idempotency_key')
                ->groupBy('flight_booking_id', 'idempotency_key')
                ->having('n', '>', 1)
                ->get();

            if ($duplicates->isNotEmpty()) {
                throw new \RuntimeException(
                    'REFUND IDEMPOTENCY MIGRATION BLOCKED: ' .
                    $duplicates->count() . ' duplicate (flight_booking_id, idempotency_key) row(s) already exist. ' .
                    'Investigate and remediate manually before adding the unique constraint. ' .
                    'First few duplicates: ' . $duplicates->take(5)->toJson()
                );
            }
        }

        // ─── Step 2: add the column ─────────────────────────────────────
        if (! Schema::hasColumn('refund_requests', 'idempotency_key')) {
            Schema::table('refund_requests', function (Blueprint $t) {
                $t->string('idempotency_key', 100)
                    ->nullable()
                    ->after('notes');
            });
        }

        // ─── Step 3: add the unique index ──────────────────────────────
        $indexName = 'rr_idem_uniq';
        if (! $this->indexExists('refund_requests', $indexName)) {
            Schema::table('refund_requests', function (Blueprint $t) use ($indexName) {
                $t->unique(
                    ['flight_booking_id', 'idempotency_key'],
                    $indexName
                );
            });
        }
    }

    public function down(): void
    {
        $indexName = 'rr_idem_uniq';
        if ($this->indexExists('refund_requests', $indexName)) {
            Schema::table('refund_requests', function (Blueprint $t) use ($indexName) {
                $t->dropUnique($indexName);
            });
        }
        if (Schema::hasColumn('refund_requests', 'idempotency_key')) {
            Schema::table('refund_requests', function (Blueprint $t) {
                $t->dropColumn('idempotency_key');
            });
        }
    }

    /**
     * Cross-driver check: does the named index exist on this table?
     * Works on MySQL (via information_schema), SQLite (via sqlite_master),
     * and PostgreSQL (via information_schema / pg_indexes).
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