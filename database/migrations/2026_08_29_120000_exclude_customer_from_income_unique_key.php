<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REMEDIATION (2026-08-29) — Exclude Customer::class from income UNIQUE guard.
 * ============================================================================
 *
 * Background:
 *   Migration `2026_08_12_120000_add_income_unique_key_to_transactions.php`
 *   installed a STORED generated column:
 *
 *       income_unique_key = IF(type = 'income', related_id, NULL)
 *
 *   with a UNIQUE index on `(related_type, income_unique_key)`. This guard
 *   protects booking-level sales (one ACTIVE income per booking) but it
 *   ALSO blocks legitimate customer debt installments — a customer can
 *   pay their outstanding balance in multiple receipts.
 *
 *   Commit `940cda1` added an app-level exception in
 *   `TransactionService::recordJournalTransfer()` for `Customer::class`,
 *   but did NOT touch the DB-level constraint, so the second and later
 *   `payDebt` calls still hit:
 *
 *       SQLSTATE[23000]: 1062 Duplicate entry 'App\Models\Customer-4'
 *       for key 'transactions_income_unique_key_unique'
 *
 * Fix:
 *   Recreate `income_unique_key` so it evaluates to NULL when
 *   `related_type = 'App\Models\Customer'`, allowing multiple Customer-keyed
 *   income transactions while preserving the booking-level guard.
 *
 *       income_unique_key = IF(
 *           type = 'income' AND related_type <> 'App\Models\Customer',
 *           related_id,
 *           NULL
 *       )
 *
 * Behaviour matrix after this migration:
 *   type='income', related_type='App\Models\Customer'         -> NULL (multi allowed)
 *   type='income', related_type='App\Models\FlightBooking'    -> related_id (unique)
 *   type='income', related_type='App\Models\HajjUmraBooking'  -> related_id (unique)
 *   type='income', related_type='App\Models\VisaBooking'      -> related_id (unique)
 *   type='income', related_type=NULL                          -> NULL (multi allowed)
 *   type<>'income'                                            -> NULL (multi allowed)
 *
 * Driver behaviour:
 *   - MySQL    : STORED generated columns supported — full effect.
 *   - SQLite   : STORED generated columns NOT supported — skipped;
 *                app-level guard in TransactionService remains the only
 *                protection (matches parent migration policy).
 *   - Postgres : STORED generated columns supported — full effect
 *                (uses the same SQL syntax).
 *
 * Idempotency:
 *   - Index drop / column drop are guarded by `indexExists()` /
 *     `Schema::hasColumn()` so this migration can be re-run safely after
 *     partial failures.
 *   - Re-creation always targets a deterministic column name.
 *
 * Reversibility:
 *   - `down()` recreates the ORIGINAL expression (no Customer exclusion)
 *     so a rollback restores the strict guard. Run `migrate:rollback` only
 *     if there are NO existing duplicate Customer-keyed income rows, or
 *     cleanup script `scripts/fix_dup_bus_income.php` style logic must
 *     run first.
 *
 * @audit-fix BUG-CUSTOMER-PAYDEBT-MULTI-2026-08-29
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // SQLite does not support STORED generated columns. Skip on SQLite;
        // the app-level guard in TransactionService::recordJournalTransfer
        // remains the only protection on that driver (same convention as the
        // parent migration `2026_08_12_120000_add_income_unique_key_to_transactions.php`).
        if ($driver === 'sqlite') {
            return;
        }

        $table = 'transactions';
        $indexName = 'transactions_income_unique_key_unique';
        $columnName = 'income_unique_key';

        // Step 1: drop the UNIQUE index if it exists (defensive against
        // partial previous runs).
        if ($this->indexExists($table, $indexName)) {
            Schema::table($table, function (Blueprint $t) use ($indexName) {
                $t->dropUnique($indexName);
            });
        }

        // Step 2: drop the generated column if it exists.
        if (Schema::hasColumn($table, $columnName)) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN {$columnName}");
        }

        // Step 3: re-add the column with Customer excluded. PHP single-quoted
        // string `App\Models\Customer` is sent verbatim to MySQL; with the
        // default sql_mode (`\` is the escape char), MySQL stores
        // `App\Models\Customer` — matching the morph class value Laravel
        // persists (Customer::class has no morphMap alias in this app).
        $excludedClass = 'App\\Models\\Customer';
        DB::statement("
            ALTER TABLE {$table}
            ADD COLUMN {$columnName} BIGINT UNSIGNED
            GENERATED ALWAYS AS (
                IF(type = 'income' AND related_type <> '{$excludedClass}', related_id, NULL)
            ) STORED
        ");

        // Step 4: re-add the UNIQUE index.
        Schema::table($table, function (Blueprint $t) use ($indexName, $columnName) {
            $t->unique(['related_type', $columnName], $indexName);
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        $table = 'transactions';
        $indexName = 'transactions_income_unique_key_unique';
        $columnName = 'income_unique_key';

        // Drop the UNIQUE index.
        if ($this->indexExists($table, $indexName)) {
            Schema::table($table, function (Blueprint $t) use ($indexName) {
                $t->dropUnique($indexName);
            });
        }

        // Drop the generated column.
        if (Schema::hasColumn($table, $columnName)) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN {$columnName}");
        }

        // Recreate with the ORIGINAL expression (no Customer exclusion).
        // WARNING: this will fail if there are existing duplicate
        // Customer-keyed income rows. Run a cleanup pass first.
        DB::statement("
            ALTER TABLE {$table}
            ADD COLUMN {$columnName} BIGINT UNSIGNED
            GENERATED ALWAYS AS (
                IF(type = 'income', related_id, NULL)
            ) STORED
        ");

        Schema::table($table, function (Blueprint $t) use ($indexName, $columnName) {
            $t->unique(['related_type', $columnName], $indexName);
        });
    }

    /**
     * Cross-driver index existence check.
     * Mirrors the helper used in
     * `2026_08_21_010000_add_idempotency_key_to_online_transactions.php`.
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
