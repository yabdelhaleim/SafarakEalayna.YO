<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HJ-004 — follow-up migration for HJ-UMRAH audit defect.
 *
 * Migration `2026_07_28_143450_add_missing_foreign_keys_to_hajj_umra_tables.php`
 * was designed to upgrade hajj_umra_bookings.customer_id and program_id from
 * ON DELETE CASCADE (set in the original `2026_04_27_124551_create_hajj_umra_bookings_table.php`)
 * to ON DELETE RESTRICT, in order to protect financial history from accidental
 * cascading destruction when a customer or program is deleted.
 *
 * Its `addForeignKeyIfMissing()` helper, however, only adds a FK when the
 * target column has NO existing FK. Since the original CASCADE FKs were already
 * in place from the create-table migration, the helper saw the column as
 * "already has a FK" and skipped the upgrade. Net effect on production MySQL:
 *
 *   hajj_umra_bookings.customer_id   → ON DELETE CASCADE  (financial-data risk)
 *   hajj_umra_bookings.program_id    → ON DELETE CASCADE  (financial-data risk)
 *
 * On SQLite (test environment) the same helper succeeds at ADDING a second FK
 * because Laravel names the new constraint differently
 * (`customer_id_foreign` vs the original `hajj_umra_bookings_customer_id_foreign`),
 * which leaves both CASCADE and RESTRICT constraints on the same column. The
 * enforced behaviour in SQLite when both are present is the most restrictive
 * action (RESTRICT wins), so SQLite tests happened to mask the production defect.
 *
 * This migration closes the gap by:
 *   1) Dropping every existing FK on hajj_umra_bookings.customer_id and
 *      hajj_umra_bookings.program_id (one CASCADE-only FK on MySQL, two
 *      duplicated FKs on SQLite — handled the same way for simplicity).
 *   2) Adding a single fresh ON DELETE RESTRICT FK on each of those columns,
 *      with a unique constraint name so it can coexist with any future FK
 *      migrations without colliding.
 *
 * Idempotency: each step is wrapped in try/catch so re-running this migration
 * after a previous successful run is a no-op (dropForeign throws "not found",
 * addForeign throws "already exists" — both are swallowed).
 *
 * Scope of change: schema only. No application code is touched.
 *
 * @see tests/Feature/HajjUmra/HajjUmraDatabaseIntegrityTest::test_bookings_fk_to_customers
 * @see tests/Feature/HajjUmra/HajjUmraDatabaseIntegrityTest::test_bookings_fk_to_programs
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuildCustomerIdFk();
        $this->rebuildProgramIdFk();
    }

    /**
     * Ensure hajj_umra_bookings.customer_id has exactly one ON DELETE RESTRICT
     * FK to customers.id.
     */
    private function rebuildCustomerIdFk(): void
    {
        // 1) Drop every existing FK on the column.
        $this->dropAllFksOnColumn('hajj_umra_bookings', 'customer_id');

        // 2) Add a single fresh RESTRICT FK.
        $this->addRestrictFk(
            'hajj_umra_bookings',
            'customer_id',
            'customers',
            'id',
            'hu_bookings_customer_id_restrict'
        );
    }

    /**
     * Ensure hajj_umra_bookings.program_id has exactly one ON DELETE RESTRICT
     * FK to programs.id.
     */
    private function rebuildProgramIdFk(): void
    {
        $this->dropAllFksOnColumn('hajj_umra_bookings', 'program_id');

        $this->addRestrictFk(
            'hajj_umra_bookings',
            'program_id',
            'programs',
            'id',
            'hu_bookings_program_id_restrict'
        );
    }

    /**
     * Drop every FK that targets the given column.
     *
     * On MySQL: identifies all constraint names via information_schema, drops
     * each by name (ALTER TABLE … DROP FOREIGN KEY).
     *
     * On SQLite: dropForeign() with the column name triggers a table rebuild
     * that drops ALL FKs on that column in one shot. This is the only safe
     * way to remove FKs in SQLite (no native DROP FOREIGN KEY).
     */
    private function dropAllFksOnColumn(string $table, string $column): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                   AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$table, $column]
            );
            foreach ($rows as $row) {
                $constraint = $row->CONSTRAINT_NAME;
                try {
                    DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
                } catch (\Throwable $e) {
                    // ignore — already gone
                }
            }
            return;
        }

        // SQLite: dropForeign on a column rebuilds the table and drops ALL FKs
        // on that column. Wrap in try/catch so an "already gone" state is a no-op.
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Add a single ON DELETE RESTRICT FK with a unique constraint name.
     */
    private function addRestrictFk(string $table, string $column, string $refTable, string $refColumn, string $constraintName): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // Idempotency: skip if a FK with this exact constraint name already exists.
            $existing = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND CONSTRAINT_NAME = ?
                   AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$table, $constraintName]
            );
            if (! empty($existing)) {
                return;
            }
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $refTable, $refColumn, $constraintName) {
                $blueprint->foreign($column, $constraintName)
                    ->references($refColumn)
                    ->on($refTable)
                    ->onDelete('restrict');
            });
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (! str_contains($msg, 'exists')
                && ! str_contains($msg, 'duplicate')) {
                throw $e;
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally empty — rolling back would re-introduce the CASCADE FK
     * defect this migration fixed. The original migrations
     * (`2026_04_27_124551_create_hajj_umra_bookings_table.php` and
     * `2026_07_28_143450_add_missing_foreign_keys_to_hajj_umra_tables.php`)
     * are the canonical sources of truth for fresh schema recreation.
     */
    public function down(): void
    {
        // intentionally empty
    }
};
