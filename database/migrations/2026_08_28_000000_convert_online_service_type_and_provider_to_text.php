<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert `online_transactions.service_type_id` and `provider_id`
 * (FK bigint columns) into free-text `*_code` columns.
 *
 * Why
 * ───
 * The Online Create page previously rendered both `نوع الخدمة`
 * (Service Type) and `المزود` (Provider) as `<select>` dropdowns
 * backed by the `online_service_types` and `online_service_providers`
 * master tables. The downside is that the Create page breaks (empty
 * dropdowns) on any environment where those tables have no rows.
 *
 * The Fawry module already solved this same problem with `operation_type`:
 * the value is stored as free text (`string`), and the master table
 * (`fawry_operation_types`) is kept only as an optional lookup for
 * displaying the Arabic name in lists/reports.
 *
 * This migration applies the same pattern to Online:
 *
 *   • `service_type_id`  (FK bigint) → `service_type_code` (string 80)
 *   • `provider_id`      (FK bigint) → `provider_code`     (string 80)
 *
 * Existing transactions are preserved: the *_code columns are
 * backfilled from the original *_id FKs via JOIN before the FK
 * columns are dropped.
 *
 * After this migration runs, the master tables
 * (`online_service_types`, `online_service_providers`) stay in the
 * schema but become *optional* lookup tables — Filament can still
 * manage them, but the Create page no longer requires rows to exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the new *_code columns as nullable so we can backfill safely.
        Schema::table('online_transactions', function (Blueprint $table) {
            $table->string('service_type_code', 80)->nullable()->after('service_type_id');
            $table->string('provider_code', 80)->nullable()->after('provider_id');
        });

        // 2. Backfill from the master tables (only when both tables exist).
        if (Schema::hasTable('online_service_types')) {
            DB::statement('
                UPDATE online_transactions t
                JOIN online_service_types s ON t.service_type_id = s.id
                SET t.service_type_code = s.code
                WHERE t.service_type_id IS NOT NULL
            ');
        }

        if (Schema::hasTable('online_service_providers')) {
            DB::statement('
                UPDATE online_transactions t
                JOIN online_service_providers p ON t.provider_id = p.id
                SET t.provider_code = p.code
                WHERE t.provider_id IS NOT NULL
            ');
        }

        // 3. Drop the old FK columns.
        Schema::table('online_transactions', function (Blueprint $table) {
            // Drop FKs only if they exist (idempotent / re-runnable).
            $foreignKeys = $this->listForeignKeys('online_transactions');
            if (in_array('online_transactions_service_type_id_foreign', $foreignKeys, true)) {
                $table->dropForeign(['service_type_id']);
            }
            if (in_array('online_transactions_provider_id_foreign', $foreignKeys, true)) {
                $table->dropForeign(['provider_id']);
            }
            $table->dropColumn(['service_type_id', 'provider_id']);
        });

        // 4. Tighten the *_code columns to NOT NULL only if every row
        //    has a value (i.e., the backfill succeeded for all rows).
        $unfilledService = (int) DB::table('online_transactions')
            ->whereNull('service_type_code')
            ->count();
        $unfilledProvider = (int) DB::table('online_transactions')
            ->whereNull('provider_code')
            ->count();

        if ($unfilledService === 0 && $unfilledProvider === 0 && DB::table('online_transactions')->count() > 0) {
            Schema::table('online_transactions', function (Blueprint $table) {
                $table->string('service_type_code', 80)->nullable(false)->change();
                $table->string('provider_code', 80)->nullable(false)->change();
            });
        }
        // If any rows have NULL *_code, leave the columns nullable
        // (it means some old transactions reference service types / providers
        // that no longer exist in the master tables). The application-level
        // StoreOnlineTransactionRequest will enforce the rule going forward.
    }

    public function down(): void
    {
        // Reverse: re-add *_id columns, backfill from *_code via JOIN,
        // then drop *_code columns.

        Schema::table('online_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('service_type_id')->nullable()->after('service_type_code');
            $table->unsignedBigInteger('provider_id')->nullable()->after('provider_code');
        });

        if (Schema::hasTable('online_service_types')) {
            DB::statement('
                UPDATE online_transactions t
                JOIN online_service_types s ON t.service_type_code = s.code
                SET t.service_type_id = s.id
                WHERE t.service_type_code IS NOT NULL
            ');
        }

        if (Schema::hasTable('online_service_providers')) {
            DB::statement('
                UPDATE online_transactions t
                JOIN online_service_providers p ON t.provider_code = p.code
                SET t.provider_id = p.id
                WHERE t.provider_code IS NOT NULL
            ');
        }

        Schema::table('online_transactions', function (Blueprint $table) {
            $table->dropColumn(['service_type_code', 'provider_code']);

            // Re-add FK constraints if the master tables exist.
            if (Schema::hasTable('online_service_types')) {
                $table->foreign('service_type_id')
                    ->references('id')->on('online_service_types')
                    ->cascadeOnUpdate();
            }
            if (Schema::hasTable('online_service_providers')) {
                $table->foreign('provider_id')
                    ->references('id')->on('online_service_providers')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * List foreign key constraint names on the given table.
     *
     * @return list<string>
     */
    private function listForeignKeys(string $table): array
    {
        $database = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT CONSTRAINT_NAME AS name
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, $table]
        );

        return array_map(static fn ($r) => $r->name, $rows);
    }
};
