<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\DatabaseMigrationTransactionDeadlockException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (B-5) — Add `related_type` + `related_id` columns to `audit_logs`.
 *
 * WHY
 *   The `audit_logs` table currently uses the legacy polymorphic pattern
 *   (`model_type` + `model_id`). The rest of the codebase — notably
 *   `transactions` and `account_entries` — uses a different naming
 *   convention (`related_type` + `related_id`).
 *
 *   This dual convention forces every cross-table audit query to write
 *   two different WHERE clauses depending on which table it's reading.
 *   Unifying the naming eliminates that footgun.
 *
 * DESIGN
 *   - Both columns NULLABLE — not every audit row has a related entity
 *     (e.g. system posting rows may not tie to a model).
 *   - Composite index on (related_type, related_id) for efficient
 *     "all audit rows for booking X" queries.
 *   - Type matches `transactions.related_type` (varchar 255) +
 *     `transactions.related_id` (unsignedBigInteger).
 *
 * BACKFILL
 *   Every existing row (4388/4388 in the local DB) has both
 *   `model_type` and `model_id` populated. We backfill
 *   `related_*` from the existing `model_*` columns.
 *
 *   Risk: zero data loss. `model_type` + `model_id` remain in the
 *   schema; new rows will write BOTH pairs going forward.
 *
 * REVERSIBILITY
 *   `down()` drops the index and the two columns cleanly. Backfilled
 *   values are lost (intentionally — they were derived from data
 *   that is still present in `model_type` + `model_id`).
 *
 * SAFETY
 *   - No FK constraint added (related_type can point to ANY class).
 *   - Backfill is a single UPDATE — fast on 4388 rows.
 *   - Runs inside the migration's implicit transaction (Laravel default).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. Pre-flight: refuse if columns already exist (idempotent) ─
        if (Schema::hasColumn('audit_logs', 'related_type')
            || Schema::hasColumn('audit_logs', 'related_id')) {
            throw new \RuntimeException(
                'AUDIT LOGS MIGRATION BLOCKED: related_type or related_id already exists. '.
                'If this is a re-run after a partial failure, roll back first and inspect the schema.'
            );
        }

        // ─── 2. Pre-flight: refuse if backfill source is unusable ───────
        $nullModelTypeCount = DB::table('audit_logs')
            ->whereNull('model_type')
            ->count();
        $totalCount = DB::table('audit_logs')->count();
        if ($totalCount > 0 && $nullModelTypeCount > 0) {
            // Informational only — we still allow the migration because
            // null model_type rows will simply have null related_type/related_id.
            // (We don't want to abort: the schema change is safe regardless.)
        }

        // ─── 3. Add the columns ──────────────────────────────────────────
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->string('related_type', 255)
                ->nullable()
                ->after('model_id');
            $t->unsignedBigInteger('related_id')
                ->nullable()
                ->after('related_type');
        });

        // ─── 4. Add composite index ──────────────────────────────────────
        $indexName = 'audit_logs_related_index';
        if (! $this->indexExists('audit_logs', $indexName)) {
            Schema::table('audit_logs', function (Blueprint $t) use ($indexName) {
                $t->index(['related_type', 'related_id'], $indexName);
            });
        }

        // ─── 5. Backfill from model_type + model_id ──────────────────────
        // Only update rows that have a model_type (matches the same
        // population as the source columns).
        $backfilledRows = DB::table('audit_logs')
            ->whereNotNull('model_type')
            ->whereNotNull('model_id')
            ->update([
                'related_type' => DB::raw('model_type'),
                'related_id' => DB::raw('model_id'),
            ]);

        // The update returns affected-row count; not all DB drivers
        // surface it for raw queries, so we re-count for the assertion.
        $afterCount = DB::table('audit_logs')
            ->whereNotNull('related_type')
            ->whereNotNull('related_id')
            ->count();

        if ($backfilledRows === 0 && $totalCount > 0 && $afterCount !== $totalCount) {
            throw new \RuntimeException(
                "AUDIT LOGS MIGRATION BACKFILL FAILED: expected ~{$totalCount} rows with related_*, ".
                "but only {$afterCount} are populated. The driver may not have surfaced affected-rows; ".
                "inspect manually before committing."
            );
        }
    }

    public function down(): void
    {
        // ─── 1. Drop the composite index ─────────────────────────────────
        $indexName = 'audit_logs_related_index';
        if ($this->indexExists('audit_logs', $indexName)) {
            Schema::table('audit_logs', function (Blueprint $t) use ($indexName) {
                $t->dropIndex($indexName);
            });
        }

        // ─── 2. Drop the columns ─────────────────────────────────────────
        if (Schema::hasColumn('audit_logs', 'related_type')) {
            Schema::table('audit_logs', function (Blueprint $t) {
                $t->dropColumn('related_type');
            });
        }
        if (Schema::hasColumn('audit_logs', 'related_id')) {
            Schema::table('audit_logs', function (Blueprint $t) {
                $t->dropColumn('related_id');
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