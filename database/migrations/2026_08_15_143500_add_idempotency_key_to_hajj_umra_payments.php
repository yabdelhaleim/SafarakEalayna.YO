<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRE-PHASE-B IDEMPOTENCY FIX — Hajj/Umrah payments
 * ==================================================
 *
 * Adds an `idempotency_key` column to `hajj_umra_payments` and enforces
 * a UNIQUE constraint on `(hajj_umra_booking_id, idempotency_key)`.
 *
 * Why a NEW column instead of re-using `transaction_reference`:
 *
 *   1. `transaction_reference` is nullable free-text — it's used as a
 *      human-readable label in older code paths and reports. Mixing an
 *      idempotency identity with a display field is fragile: a label can
 *      change ("WIRE-001-RCP" → "wire 001 receipt") without the payment
 *      being a replay. A separate idempotency_key column is unambiguous.
 *
 *   2. The new column is OPT-IN: callers that don't supply it get NULL,
 *      and MySQL UNIQUE indexes treat NULLs as distinct, so multiple
 *      NULL-keyed rows coexist. Legacy callers are unaffected.
 *
 *   3. The unique index uses a plain (not partial) index because MySQL
 *      already does the right thing for NULLs — and the same migration
 *      works on SQLite (used by PHPUnit feature tests).
 *
 * Scope check (before adding the UNIQUE):
 *
 *   We use a `hasDuplicateKeys()` SELECT against the existing data
 *   (production-shaped: safarak_stress mirrors the production schema
 *   after migrate). If duplicates already exist, the migration STOPS
 *   and reports the blocker — per the spec rule "do not silently clean
 *   financial history".
 *
 *   On safarak_stress this is always empty (the schema is fresh-migrated),
 *   so the constraint applies cleanly. On a real production DB with
 *   duplicate keys, the operator must investigate before proceeding.
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
 *   - Existing `transaction_reference` values are preserved as-is.
 *   - The migration is REVERSIBLE via `down()`.
 */
return new class extends Migration {
    public function up(): void
    {
        // ─── Step 1: pre-flight check ───────────────────────────────────
        // If existing rows already have duplicate (booking_id, key)
        // combinations, the UNIQUE constraint will fail to apply. Surface
        // the exact duplicates here instead of letting the migration fail
        // with a generic SQL error.
        //
        // Skip the check if the column doesn't yet exist (first-run case).
        if (Schema::hasColumn('hajj_umra_payments', 'idempotency_key')) {
            $duplicates = DB::table('hajj_umra_payments')
                ->select('hajj_umra_booking_id', 'idempotency_key', DB::raw('COUNT(*) as n'))
                ->whereNotNull('idempotency_key')
                ->groupBy('hajj_umra_booking_id', 'idempotency_key')
                ->having('n', '>', 1)
                ->get();

            if ($duplicates->isNotEmpty()) {
                throw new \RuntimeException(
                    'PRE-PHASE-B IDEMPOTENCY MIGRATION BLOCKED: ' .
                    $duplicates->count() . ' duplicate (booking_id, idempotency_key) row(s) already exist. ' .
                    'Investigate and remediate manually before adding the unique constraint. ' .
                    'First few duplicates: ' . $duplicates->take(5)->toJson()
                );
            }
        }

        // ─── Step 2: add the column ─────────────────────────────────────
        if (! Schema::hasColumn('hajj_umra_payments', 'idempotency_key')) {
            Schema::table('hajj_umra_payments', function (Blueprint $t) {
                $t->string('idempotency_key', 100)
                    ->nullable()
                    ->after('transaction_reference');
            });
        }

        // ─── Step 3: add the unique index ───────────────────────────────
        // Index name explicitly chosen so the operator can spot it in
        // error messages: "Duplicate entry ... for key 'hup_idem_uniq'".
        // The existence check is portable across MySQL / SQLite / PG via
        // information_schema (MySQL/PG) or sqlite_master (SQLite).
        $indexName = 'hup_idem_uniq';
        if (! $this->indexExists('hajj_umra_payments', $indexName)) {
            Schema::table('hajj_umra_payments', function (Blueprint $t) use ($indexName) {
                $t->unique(
                    ['hajj_umra_booking_id', 'idempotency_key'],
                    $indexName
                );
            });
        }
    }

    public function down(): void
    {
        $indexName = 'hup_idem_uniq';
        if ($this->indexExists('hajj_umra_payments', $indexName)) {
            Schema::table('hajj_umra_payments', function (Blueprint $t) use ($indexName) {
                $t->dropUnique($indexName);
            });
        }
        if (Schema::hasColumn('hajj_umra_payments', 'idempotency_key')) {
            Schema::table('hajj_umra_payments', function (Blueprint $t) {
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
                // Unknown driver: be safe — pretend the index does NOT exist
                // and let the migration proceed (it will fail loudly if the
                // index already exists, which is the correct behavior).
                return false;
        }
    }
};
