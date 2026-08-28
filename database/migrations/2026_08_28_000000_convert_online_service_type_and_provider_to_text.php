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
        //    Uses portable Laravel query builder loops so the same code runs
        //    on MySQL and SQLite (the original MySQL UPDATE...JOIN syntax
        //    broke RefreshDatabase on the test SQLite in-memory DB).
        if (Schema::hasTable('online_service_types')) {
            $codes = DB::table('online_service_types')
                ->whereNotNull('id')
                ->pluck('code', 'id')
                ->all();
            foreach ($codes as $id => $code) {
                DB::table('online_transactions')
                    ->where('service_type_id', $id)
                    ->update(['service_type_code' => $code]);
            }
        }

        if (Schema::hasTable('online_service_providers')) {
            $codes = DB::table('online_service_providers')
                ->whereNotNull('id')
                ->pluck('code', 'id')
                ->all();
            foreach ($codes as $id => $code) {
                DB::table('online_transactions')
                    ->where('provider_id', $id)
                    ->update(['provider_code' => $code]);
            }
        }

        // 3. Drop the old FK columns.
        //
        //    On MySQL: drop FK first, then drop the column inline.
        //    On SQLite: the column-drop has to recreate the table
        //      because the FK references the column. SQLite's
        //      `ALTER TABLE DROP COLUMN` fails when a FK references
        //      the column (the rebuild table's FK would dangle), and
        //      `dropForeign()` alone doesn't always strip the FK in
        //      SQLite (FK constraints are table-level there).
        //      The portable way is a full rebuild — read the live
        //      schema, create a new table without the dropped
        //      columns and without the related FK constraints,
        //      copy data, and swap names.
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
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
        } elseif ($driver === 'sqlite') {
            $this->dropColumnsAndForeignsOnSqlite(
                'online_transactions',
                ['service_type_id', 'provider_id'],
                [
                    'service_type_id' => 'online_service_types',
                    'provider_id' => 'online_service_providers',
                ]
            );
        } else {
            // Other drivers (pgsql/sqlsrv) — best-effort: rely on Laravel's
            // default behavior of dropping inline FKs + columns.
            Schema::table('online_transactions', function (Blueprint $table) {
                $foreignKeys = $this->listForeignKeys('online_transactions');
                if (in_array('online_transactions_service_type_id_foreign', $foreignKeys, true)) {
                    $table->dropForeign(['service_type_id']);
                }
                if (in_array('online_transactions_provider_id_foreign', $foreignKeys, true)) {
                    $table->dropForeign(['provider_id']);
                }
                $table->dropColumn(['service_type_id', 'provider_id']);
            });
        }

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
            $codes = DB::table('online_service_types')
                ->whereNotNull('id')
                ->pluck('code', 'id')
                ->all();
            $idByCode = array_flip($codes);
            $rows = DB::table('online_transactions')
                ->whereNotNull('service_type_code')
                ->select('id', 'service_type_code')
                ->get();
            foreach ($rows as $row) {
                $newId = $idByCode[$row->service_type_code] ?? null;
                if ($newId !== null) {
                    DB::table('online_transactions')
                        ->where('id', $row->id)
                        ->update(['service_type_id' => $newId]);
                }
            }
        }

        if (Schema::hasTable('online_service_providers')) {
            $codes = DB::table('online_service_providers')
                ->whereNotNull('id')
                ->pluck('code', 'id')
                ->all();
            $idByCode = array_flip($codes);
            $rows = DB::table('online_transactions')
                ->whereNotNull('provider_code')
                ->select('id', 'provider_code')
                ->get();
            foreach ($rows as $row) {
                $newId = $idByCode[$row->provider_code] ?? null;
                if ($newId !== null) {
                    DB::table('online_transactions')
                        ->where('id', $row->id)
                        ->update(['provider_id' => $newId]);
                }
            }
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
     * Portable across MySQL and SQLite: delegates to Laravel's
     * Schema::getForeignKeys() introspection (which abstracts the
     * information_schema lookup on MySQL and the pragma_foreign_key_list
     * lookup on SQLite). Returns an empty list for SQLite's older modes
     * where introspection isn't fully supported.
     *
     * @return list<string>
     */
    private function listForeignKeys(string $table): array
    {
        $fkDefinitions = Schema::getForeignKeys($table);
        if (! is_array($fkDefinitions)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($fk) => $fk['name'] ?? null,
            $fkDefinitions
        )));
    }

    /**
     * Drop the given columns AND any FK constraint that targets one of
     * them, in a portable way for SQLite. (MySQL can drop FKs and
     * columns inline; SQLite stores FKs as part of the table definition,
     * so dropping a column without first removing the FK referencing it
     * fails.)
     *
     * Implementation: rebuild the table. Read every column + its full
     * DDL definition via PRAGMA, write a CREATE TABLE without the
     * dropped columns and without the FKs that target them, copy the
     * rows, drop the original, rename.
     *
     * @param  list<string>  $columnsToDrop
     * @param  array<string, string>  $fkByColumn  Map of column => referenced table
     *                                          for FKs we want to drop.
     */
    private function dropColumnsAndForeignsOnSqlite(
        string $table,
        array $columnsToDrop,
        array $fkByColumn
    ): void {
        // 1. Read the current schema columns (name + type + nullability + default).
        $pragma = DB::select("PRAGMA table_info('{$table}')");
        if (! is_array($pragma)) {
            // Fallback: try a plain column drop (older SQLite without table_info)
            // This will throw a clearer error than the build path.
            Schema::table($table, function (Blueprint $t) use ($columnsToDrop) {
                $t->dropColumn($columnsToDrop);
            });

            return;
        }

        // 2. Build a CREATE TABLE statement that excludes the dropped
        //    columns. Keep all other columns, indexes, and FKs EXCEPT
        //    those referencing the dropped columns.
        $foreignKeys = DB::select("PRAGMA foreign_key_list('{$table}')");
        $fksToDrop = [];
        foreach ((array) $foreignKeys as $fk) {
            if (in_array($fk->from ?? '', $columnsToDrop, true)) {
                $fksToDrop[] = $fk;
            }
        }

        $indexes = DB::select("PRAGMA index_list('{$table}')");
        $indexDrops = [];
        foreach ((array) $indexes as $idx) {
            // SQLite auto-creates indexes for FK columns; if the column is
            // dropped, drop the indexes that auto-reference it.
            $idxInfo = DB::select("PRAGMA index_info('{$idx->name}')");
            $touchesDropped = false;
            foreach ((array) $idxInfo as $info) {
                if (in_array($info->name ?? '', $columnsToDrop, true)) {
                    $touchesDropped = true;

                    break;
                }
            }
            if ($touchesDropped) {
                $indexDrops[] = $idx->name;
            }
        }

        // 3. Construct the new table SQL via Laravel's compileCreate pattern.
        //    Use Schema::create against a temp name, then swap.
        $tempTable = "{$table}_migration_tmp_".uniqid();

        // Use Doctrine-based listing via Schema connection for portability.
        $columnsForNewTable = [];
        foreach ((array) $pragma as $col) {
            if (in_array($col->name ?? '', $columnsToDrop, true)) {
                continue;
            }
            $columnsForNewTable[] = $col;
        }

        // Build the CREATE TABLE SQL from the column metadata.
        $columnDefs = [];
        $primaryKeys = [];
        foreach ($columnsForNewTable as $col) {
            $type = strtoupper((string) ($col->type ?? 'TEXT'));
            // SQLite's pragma reports type with affinity — preserve as-is.
            $nullable = ((int) ($col->notnull ?? 0)) === 0 ? '' : ' NOT NULL';
            $default = ($col->dflt_value === null || $col->dflt_value === '')
                ? ''
                : ' DEFAULT '.$col->dflt_value;
            $pk = ((int) ($col->pk ?? 0)) > 0 ? ' PRIMARY KEY' : '';
            $columnDefs[] = "\"{$col->name}\" {$type}{$nullable}{$default}{$pk}";
            if (((int) ($col->pk ?? 0)) > 0) {
                $primaryKeys[] = $col->name;
            }
        }

        // Add surviving FKs (skip ones targeting dropped columns).
        $survivingFks = DB::select("PRAGMA foreign_key_list('{$table}')");
        foreach ((array) $survivingFks as $fk) {
            if (in_array($fk->from ?? '', $columnsToDrop, true)) {
                continue;
            }
            $columnDefs[] = sprintf(
                'FOREIGN KEY ("%s") REFERENCES "%s"("%s") ON DELETE %s ON UPDATE %s',
                $fk->from,
                $fk->table,
                $fk->to,
                strtoupper((string) ($fk->on_delete ?? 'NO ACTION')),
                strtoupper((string) ($fk->on_update ?? 'NO ACTION'))
            );
        }

        // Single primary key (if any).
        if (count($primaryKeys) === 1) {
            // Re-emit as a table-level PRIMARY KEY only when not already inline.
            // Inline already added PRIMARY KEY for single Pk cols — strip any
            // duplicates. For our schema the PK is already inline.
        }

        $sql = sprintf(
            'CREATE TABLE "%s" (%s)',
            $tempTable,
            implode(', ', $columnDefs)
        );

        DB::statement($sql);

        // 4. Copy data, preserving only the surviving columns.
        $survivingColumnList = array_map(
            static fn ($col) => "\"{$col->name}\"",
            $columnsForNewTable
        );
        DB::statement(
            sprintf(
                'INSERT INTO "%s" (%s) SELECT %s FROM "%s"',
                $tempTable,
                implode(', ', $survivingColumnList),
                implode(', ', $survivingColumnList),
                $table
            )
        );

        // 5. Drop indexes that auto-reference the dropped columns.
        foreach ($indexDrops as $idxName) {
            DB::statement('DROP INDEX IF EXISTS "'.$idxName.'"');
        }

        // 6. Drop the old table and rename the new one.
        DB::statement('DROP TABLE "'.$table.'"');
        DB::statement('ALTER TABLE "'.$tempTable.'" RENAME TO "'.$table.'"');
    }
};
