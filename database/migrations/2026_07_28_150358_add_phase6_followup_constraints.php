<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Follow-up — Tighten integrity invariants identified during audit.
 *
 * Two changes:
 *
 *  1. NOT NULL on `hajj_umra_bookings.account_id`
 *     The DB previously allowed inserting a booking without an account_id,
 *     even though the app-level HajjUmraLiquidityAccount rule enforces it.
 *     This left a window where direct-DB access (artisan commands, raw
 *     SQL reports) could create bookings that broke financial reports.
 *
 *     Audit findings:
 *     - App-level HajjUmraBookingService always sets $accountId
 *     - StoreHajjUmraBookingRequest has 'account_id' => 'required'
 *     - 0 production rows currently have NULL account_id
 *     - 4 tests had to be patched to add account_id (covered separately)
 *
 *  2. Composite UNIQUE on `customers(phone, national_id)`
 *     Prevents the same person being created twice with identical
 *     phone + national_id (a real AR-splitting bug source), while still
 *     allowing:
 *     - Family members with the same phone but different national_id
 *     - Multiple NULL national_ids (MySQL treats NULL != NULL in
 *       unique indexes — 19 existing NULL rows stay valid)
 *
 * Both changes are idempotent (no-op if already applied) and cross-
 * compatible between MySQL (production) and SQLite (test environment).
 */
return new class extends Migration
{
    public function up(): void
    {
        /* -------------------------------------------------------------
         * 1. Drop + recreate FK on hajj_umra_bookings.account_id
         *
         * The previous migration used ON DELETE SET NULL, which is
         * incompatible with NOT NULL. We must change the FK to
         * RESTRICT (can't delete an account if bookings exist) before
         * we can apply the NOT NULL constraint.
         * ------------------------------------------------------------- */
        $this->dropForeignKeyIfExists('hajj_umra_bookings', 'account_id');

        Schema::table('hajj_umra_bookings', function (Blueprint $table) {
            $table->foreign('account_id', 'hajj_umra_bookings_account_id_foreign')
                ->references('id')->on('accounts')
                ->onDelete('RESTRICT');
        });

        /* -------------------------------------------------------------
         * 2. NOT NULL on hajj_umra_bookings.account_id
         * ------------------------------------------------------------- */
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // Safety net: refuse to add NOT NULL if data would be lost.
            $nulls = DB::select(
                'SELECT COUNT(*) cnt FROM hajj_umra_bookings WHERE account_id IS NULL'
            );
            if ($nulls[0]->cnt > 0) {
                throw new \RuntimeException(
                    "Refusing to add NOT NULL: hajj_umra_bookings has {$nulls[0]->cnt} rows "
                    ."with NULL account_id. Backfill these before applying this migration."
                );
            }
        }

        if (! $this->columnIsNotNull('hajj_umra_bookings', 'account_id')) {
            Schema::table('hajj_umra_bookings', function (Blueprint $table) {
                $table->unsignedBigInteger('account_id')->nullable(false)->change();
            });
        }

        /* -------------------------------------------------------------
         * 3. Composite UNIQUE on customers(phone, national_id)
         * ------------------------------------------------------------- */
        if (! $this->uniqueIndexExists('customers', 'customers_phone_national_id_unique')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->unique(['phone', 'national_id'], 'customers_phone_national_id_unique');
            });
        }
    }

    /**
     * Drop a foreign key only if it exists.
     * Cross-driver (MySQL + SQLite).
     */
    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $existing = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                   AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$table, $column]
            );
            if (empty($existing)) {
                return;
            }
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable $e) {
            // SQLite: column may not have FK — ignore.
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_phone_national_id_unique');
        });

        Schema::table('hajj_umra_bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable()->change();
        });

        // Restore FK to SET NULL behavior (matches previous Phase 6 migration)
        $this->dropForeignKeyIfExists('hajj_umra_bookings', 'account_id');
        Schema::table('hajj_umra_bookings', function (Blueprint $table) {
            $table->foreign('account_id', 'hajj_umra_bookings_account_id_foreign')
                ->references('id')->on('accounts')
                ->onDelete('SET NULL');
        });
    }

    /**
     * Check whether a column already has NOT NULL.
     *
     * MySQL: query information_schema.COLUMNS.IS_NULLABLE
     * SQLite: query PRAGMA table_info
     */
    private function columnIsNotNull(string $table, string $column): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );

            return ! empty($rows) && $rows[0]->IS_NULLABLE === 'NO';
        }

        // SQLite: PRAGMA returns cid, name, type, notnull, dflt_value, pk
        $rows = DB::select("PRAGMA table_info(\"{$table}\")");
        foreach ($rows as $row) {
            if (strtolower($row->name) === strtolower($column)) {
                return (int) $row->notnull === 1;
            }
        }

        return false;
    }

    /**
     * Check whether a unique index already exists.
     *
     * MySQL: query information_schema.STATISTICS
     * SQLite: query PRAGMA index_list
     */
    private function uniqueIndexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND INDEX_NAME = ?
                   AND NON_UNIQUE = 0',
                [$table, $indexName]
            );

            return ! empty($rows);
        }

        // SQLite: PRAGMA index_list returns seq, name, unique, origin, partial
        $rows = DB::select("PRAGMA index_list(\"{$table}\")");
        foreach ($rows as $row) {
            if (strtolower($row->name) === strtolower($indexName) && (int) $row->unique === 1) {
                return true;
            }
        }

        return false;
    }
};