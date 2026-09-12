<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D3 FIX (2026-08-15) — Flight payments idempotency.
 * ==================================================
 *
 * Adds an `idempotency_key` column to `flight_payments` and enforces
 * a UNIQUE constraint on `(flight_booking_id, idempotency_key)`.
 *
 * Why a NEW column instead of re-using an existing field:
 *
 *   1. `transaction_reference` is a free-text label and changes don't
 *      imply a replay. Mixing identity with display is fragile.
 *   2. The new column is OPT-IN: callers that don't supply it get NULL,
 *      and MySQL UNIQUE indexes treat NULLs as distinct, so multiple
 *      NULL-keyed rows coexist. Legacy callers (and the existing
 *      single-payment happy path) are unaffected.
 *
 * Scope check (before adding the UNIQUE):
 *
 *   SELECT duplicate keys (booking_id, idempotency_key) from existing
 *   data. If duplicates already exist, the migration STOPS and reports
 *   the blocker — per the spec rule "do not silently clean financial
 *   history".
 *
 *   On safarak_stress this is always empty (the schema is fresh-migrated),
 *   so the constraint applies cleanly.
 *
 * Defense-in-depth rationale:
 *
 *   The unique index is the LAST LINE — even if the service-level
 *   pre-check is bypassed (race condition, buggy client, raw SQL), the
 *   DB will reject the second INSERT with MySQL error 1062 / SQLSTATE
 *   23000. The service catches that and returns the existing payment
 *   (idempotent return).
 *
 * IMPORTANT:
 *   - Existing rows are not modified, deleted, or rewritten.
 *   - The migration is REVERSIBLE via `down()`.
 */
return new class extends Migration {
    public function up(): void
    {
        // ─── Step 1: pre-flight check ───────────────────────────────────
        // Surface any existing duplicate keys before the UNIQUE constraint
        // would silently fail with a generic SQL error.
        if (Schema::hasColumn('flight_payments', 'idempotency_key')) {
            $duplicates = DB::table('flight_payments')
                ->select('flight_booking_id', 'idempotency_key', DB::raw('COUNT(*) as n'))
                ->whereNotNull('idempotency_key')
                ->groupBy('flight_booking_id', 'idempotency_key')
                ->having('n', '>', 1)
                ->get();

            if ($duplicates->isNotEmpty()) {
                throw new \RuntimeException(
                    'D3 IDEMPOTENCY MIGRATION BLOCKED: ' .
                    $duplicates->count() . ' duplicate (flight_booking_id, idempotency_key) row(s) already exist. ' .
                    'Investigate and remediate manually before adding the unique constraint. ' .
                    'First few duplicates: ' . $duplicates->take(5)->toJson()
                );
            }
        }

        // ─── Step 2: add the column ─────────────────────────────────────
        if (! Schema::hasColumn('flight_payments', 'idempotency_key')) {
            Schema::table('flight_payments', function (Blueprint $t) {
                $t->string('idempotency_key', 100)
                    ->nullable()
                    ->after('transaction_reference');
            });
        }

        // ─── Step 3: add the unique index ───────────────────────────────
        $indexName = 'fp_idem_uniq';
        if (! $this->indexExists('flight_payments', $indexName)) {
            Schema::table('flight_payments', function (Blueprint $t) use ($indexName) {
                $t->unique(
                    ['flight_booking_id', 'idempotency_key'],
                    $indexName
                );
            });
        }
    }

    public function down(): void
    {
        $indexName = 'fp_idem_uniq';
        if ($this->indexExists('flight_payments', $indexName)) {
            Schema::table('flight_payments', function (Blueprint $t) use ($indexName) {
                $t->dropUnique($indexName);
            });
        }
        if (Schema::hasColumn('flight_payments', 'idempotency_key')) {
            Schema::table('flight_payments', function (Blueprint $t) {
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
