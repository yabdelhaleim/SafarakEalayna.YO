<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إصلاح فهارس تفرد العملاء
 *
 * المشكلة: القيد المركب (phone, national_id, is_active_for_unique) لا يحقق
 * التفرد المستقل لكل من رقم الهاتف والرقم القومي. يسمح بعميلين نشطين
 * بنفس الهاتف طالما الرقم القومي مختلف، ويسمح بتكرار NULL لأن MySQL
 * يعامل NULL != NULL في الفهارس الفريدة.
 *
 * الحل: استبدال القيد المركب بعمودين افتراضيين (Generated Virtual Columns)
 * وفهرسين فريدين مستقلين:
 *   - active_phone: يعرض رقم الهاتف فقط للعملاء النشطين، NULL للمحذوفين
 *   - active_national_id: يعرض الرقم القومي فقط للعملاء النشطين غير الفارغين
 *
 * بما أن MySQL يسمح بتكرار NULL في الفهارس الفريدة:
 *   - العملاء المحذوفون (active_phone = NULL) لا يتعارضون مع أحد
 *   - العملاء بدون رقم قومي (active_national_id = NULL) لا يتعارضون مع بعض
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // ---- 1. إزالة القيد المركب الخاطئ القديم ----

            // إزالة الفهرس المركب القديم الخاطئ
            if ($this->uniqueIndexExists('customers', 'customers_phone_national_id_active_unique')) {
                DB::statement("ALTER TABLE `customers` DROP INDEX `customers_phone_national_id_active_unique`");
            }

            // إزالة العمود الافتراضي القديم الذي كان يدعم القيد المركب
            if ($this->columnExists('customers', 'is_active_for_unique')) {
                DB::statement("ALTER TABLE `customers` DROP COLUMN `is_active_for_unique`");
            }

            // إزالة أي فهرس قديم للهاتف+الرقم القومي بدون مراعاة deleted_at
            if ($this->uniqueIndexExists('customers', 'customers_phone_national_id_unique')) {
                DB::statement("ALTER TABLE `customers` DROP INDEX `customers_phone_national_id_unique`");
            }

            // ---- 2. إضافة عمود افتراضي للهاتف النشط ----
            // يعرض رقم الهاتف فقط عندما يكون العميل نشطاً (deleted_at IS NULL)
            // يكون NULL عندما يكون العميل محذوفاً → لا يتعارض مع أحد
            if (! $this->columnExists('customers', 'active_phone')) {
                DB::statement(
                    "ALTER TABLE `customers`
                     ADD COLUMN `active_phone`
                         VARCHAR(255) COLLATE utf8mb4_unicode_ci
                         GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `phone`, NULL))
                         VIRTUAL"
                );
            }

            // ---- 3. إضافة عمود افتراضي للرقم القومي النشط ----
            // يعرض الرقم القومي فقط عندما يكون العميل نشطاً وغير فارغ
            // يكون NULL عند الحذف أو عند كون national_id فارغاً
            // → يسمح بتعدد NULL (شركات بدون رقم قومي، عملاء محذوفون)
            if (! $this->columnExists('customers', 'active_national_id')) {
                DB::statement(
                    "ALTER TABLE `customers`
                     ADD COLUMN `active_national_id`
                         VARCHAR(14) COLLATE utf8mb4_unicode_ci
                         GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `national_id`, NULL))
                         VIRTUAL"
                );
            }

            // ---- 4. إنشاء الفهرسين الفريدين المستقلين ----

            // فهرس تفرد الهاتف للعملاء النشطين
            if (! $this->uniqueIndexExists('customers', 'customers_active_phone_unique')) {
                DB::statement(
                    "ALTER TABLE `customers`
                     ADD UNIQUE INDEX `customers_active_phone_unique` (`active_phone`)"
                );
            }

            // فهرس تفرد الرقم القومي للعملاء النشطين
            // NULL مكرر مسموح به (شركات بدون رقم قومي)
            if (! $this->uniqueIndexExists('customers', 'customers_active_national_id_unique')) {
                DB::statement(
                    "ALTER TABLE `customers`
                     ADD UNIQUE INDEX `customers_active_national_id_unique` (`active_national_id`)"
                );
            }
        } else {
            // ---- SQLite (للاختبارات المحلية فقط) ----
            // SQLite يدعم Partial Indexes مباشرة بدون Generated Columns

            // إزالة الفهارس القديمة إن وُجدت
            DB::statement("DROP INDEX IF EXISTS customers_phone_national_id_active_unique");
            DB::statement("DROP INDEX IF EXISTS customers_phone_national_id_unique");
            DB::statement("DROP INDEX IF EXISTS customers_active_phone_unique");
            DB::statement("DROP INDEX IF EXISTS customers_active_national_id_unique");

            // فهرس جزئي: تفرد الهاتف للعملاء النشطين فقط
            DB::statement(
                "CREATE UNIQUE INDEX customers_active_phone_unique
                 ON customers (phone) WHERE deleted_at IS NULL"
            );

            // فهرس جزئي: تفرد الرقم القومي للعملاء النشطين غير الفارغين
            DB::statement(
                "CREATE UNIQUE INDEX customers_active_national_id_unique
                 ON customers (national_id) WHERE deleted_at IS NULL AND national_id IS NOT NULL"
            );
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // ---- إزالة الفهارس والأعمدة الجديدة ----

            if ($this->uniqueIndexExists('customers', 'customers_active_phone_unique')) {
                DB::statement("ALTER TABLE `customers` DROP INDEX `customers_active_phone_unique`");
            }

            if ($this->uniqueIndexExists('customers', 'customers_active_national_id_unique')) {
                DB::statement("ALTER TABLE `customers` DROP INDEX `customers_active_national_id_unique`");
            }

            if ($this->columnExists('customers', 'active_phone')) {
                DB::statement("ALTER TABLE `customers` DROP COLUMN `active_phone`");
            }

            if ($this->columnExists('customers', 'active_national_id')) {
                DB::statement("ALTER TABLE `customers` DROP COLUMN `active_national_id`");
            }

            // ---- إعادة القيد المركب القديم (للتراجع الكامل) ----
            // ملاحظة: لو في قاعدة البيانات الإنتاجية بيانات مكررة، هذا سيفشل
            // وهذا مقصود — التراجع غير آمن في الإنتاج بسبب البيانات المكررة الحالية

            // إعادة العمود الافتراضي القديم
            if (! $this->columnExists('customers', 'is_active_for_unique')) {
                DB::statement(
                    "ALTER TABLE `customers`
                     ADD COLUMN `is_active_for_unique`
                         TINYINT
                         GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, 1, NULL))
                         VIRTUAL"
                );
            }

            // إعادة الفهرس المركب القديم
            if (! $this->uniqueIndexExists('customers', 'customers_phone_national_id_active_unique')) {
                DB::statement(
                    "ALTER TABLE `customers`
                     ADD UNIQUE INDEX `customers_phone_national_id_active_unique`
                     (`phone`, `national_id`, `is_active_for_unique`)"
                );
            }
        } else {
            // SQLite
            DB::statement("DROP INDEX IF EXISTS customers_active_phone_unique");
            DB::statement("DROP INDEX IF EXISTS customers_active_national_id_unique");

            // إعادة الفهرس المركب القديم
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS customers_phone_national_id_active_unique
                 ON customers (phone, national_id) WHERE deleted_at IS NULL"
            );
        }
    }

    // ---------------------------------------------------------------
    // دوال مساعدة
    // ---------------------------------------------------------------

    /**
     * التحقق من وجود فهرس فريد في قاعدة البيانات
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

        // SQLite
        $rows = DB::select("PRAGMA index_list(\"{$table}\")");
        foreach ($rows as $row) {
            if (strtolower($row->name) === strtolower($indexName) && (int) $row->unique === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * التحقق من وجود عمود في جدول
     */
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

        // SQLite
        $rows = DB::select("PRAGMA table_info(\"{$table}\")");
        foreach ($rows as $row) {
            if (strtolower($row->name) === strtolower($columnName)) {
                return true;
            }
        }

        return false;
    }
};
