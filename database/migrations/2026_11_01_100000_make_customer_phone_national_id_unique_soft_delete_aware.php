<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // 1. إسقاط القيد القديم غير المشروط إن وُجد
            if ($this->uniqueIndexExists('customers', 'customers_phone_national_id_unique')) {
                Schema::table('customers', function (Blueprint $table) {
                    $table->dropUnique('customers_phone_national_id_unique');
                });
            }

            // 2. إضافة عمود افتراضي (Virtual Generated Column)
            if (! $this->columnExists('customers', 'is_active_for_unique')) {
                DB::statement("ALTER TABLE `customers` ADD COLUMN `is_active_for_unique` TINYINT GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, 1, NULL)) VIRTUAL");
            }

            // 3. إنشاء الفهرس الفريد الجديد الذي يراعي deleted_at
            if (! $this->uniqueIndexExists('customers', 'customers_phone_national_id_active_unique')) {
                DB::statement("ALTER TABLE `customers` ADD UNIQUE INDEX `customers_phone_national_id_active_unique` (`phone`, `national_id`, `is_active_for_unique`)");
            }
        } else {
            // SQLite (للاختبارات)
            if ($this->uniqueIndexExists('customers', 'customers_phone_national_id_unique')) {
                Schema::table('customers', function (Blueprint $table) {
                    $table->dropUnique('customers_phone_national_id_unique');
                });
            }

            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS customers_phone_national_id_active_unique ON customers (phone, national_id) WHERE deleted_at IS NULL");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            if ($this->uniqueIndexExists('customers', 'customers_phone_national_id_active_unique')) {
                DB::statement("ALTER TABLE `customers` DROP INDEX `customers_phone_national_id_active_unique`");
            }

            if ($this->columnExists('customers', 'is_active_for_unique')) {
                DB::statement("ALTER TABLE `customers` DROP COLUMN `is_active_for_unique`");
            }

            if (! $this->uniqueIndexExists('customers', 'customers_phone_national_id_unique')) {
                Schema::table('customers', function (Blueprint $table) {
                    $table->unique(['phone', 'national_id'], 'customers_phone_national_id_unique');
                });
            }
        } else {
            DB::statement("DROP INDEX IF EXISTS customers_phone_national_id_active_unique");

            if (! $this->uniqueIndexExists('customers', 'customers_phone_national_id_unique')) {
                Schema::table('customers', function (Blueprint $table) {
                    $table->unique(['phone', 'national_id'], 'customers_phone_national_id_unique');
                });
            }
        }
    }

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

        $rows = DB::select("PRAGMA index_list(\"{$table}\")");
        foreach ($rows as $row) {
            if (strtolower($row->name) === strtolower($indexName) && (int) $row->unique === 1) {
                return true;
            }
        }

        return false;
    }

    private function columnExists(string $table, string $columnName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?',
                [$table, $columnName]
            );

            return ! empty($rows);
        }

        $rows = DB::select("PRAGMA table_info(\"{$table}\")");
        foreach ($rows as $row) {
            if (strtolower($row->name) === strtolower($columnName)) {
                return true;
            }
        }

        return false;
    }
};
