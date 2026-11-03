<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إصلاح فهارس تفرد العملاء (Additive First)
 *
 * المشكلة السابقة:
 * القيد المركب (phone, national_id, is_active_for_unique) كان يسمح بعميلين نشطين
 * بنفس الهاتف طالما الرقم القومي مختلف، ويسمح بتكرار NULL لأن MySQL
 * يعامل NULL != NULL في الفهارس الفريدة.
 *
 * التصميم الآمن (Additive First):
 * 1. فحص استباقي للبيانات يمنع بدء التعديل في حال وجود تكرار.
 * 2. إضافة الأعمدة الافتراضية الجديدة أولاً (active_phone, active_national_id).
 * 3. إنشاء الفهارس الفريدة الجديدة المستقلة والتأكد من نجاحها تماماً.
 * 4. فقط بعد نجاح الفهارس الجديدة، يتم حذف الفهرس المركب القديم والعمود القديم.
 * 5. في down(): لا يتم حذف الفهارس الجديدة إلا بعد التحقق من سلامة البيانات
 *    وإعادة بناء الفهرس القديم بنجاح.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // ============================================================
            // الخطوة 0: فحص استباقي لمنع Partial Failure لو وُجدت بيانات مكررة
            // ============================================================
            $dupPhones = DB::select(
                'SELECT phone, COUNT(*) AS cnt
                 FROM customers
                 WHERE deleted_at IS NULL
                 GROUP BY phone
                 HAVING COUNT(*) > 1'
            );
            if (! empty($dupPhones)) {
                $count = count($dupPhones);
                $examples = collect($dupPhones)->take(3)->pluck('phone')->implode(', ');
                throw new \RuntimeException(
                    "تعذر تطبيق Migration تفرد العملاء: يوجد {$count} رقم هاتف نشط مكرر في جدول customers (أمثلة: {$examples}). " .
                    "يجب معالجة وتصحيح السجلات المكررة أولاً قبل تطبيق الفهرس الفريد."
                );
            }

            $dupNatIds = DB::select(
                'SELECT national_id, COUNT(*) AS cnt
                 FROM customers
                 WHERE deleted_at IS NULL AND national_id IS NOT NULL
                 GROUP BY national_id
                 HAVING COUNT(*) > 1'
            );
            if (! empty($dupNatIds)) {
                $count = count($dupNatIds);
                throw new \RuntimeException(
                    "تعذر تطبيق Migration تفرد العملاء: يوجد {$count} رقم قومي نشط مكرر في جدول customers. " .
                    "يجب معالجة وتصحيح السجلات المكررة أولاً قبل تطبيق الفهرس الفريد."
                );
            }

            // ============================================================
            // المرحلة الأولى (Additive First): إنشاء الأعمدة الافتراضية الجديدة
            // ============================================================
            if (! $this->columnExists('customers', 'active_phone')) {
                DB::statement(
                    "ALTER TABLE `customers`
                     ADD COLUMN `active_phone`
                         VARCHAR(255) COLLATE utf8mb4_unicode_ci
                         GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `phone`, NULL))
                         VIRTUAL"
                );
            }

            if (! $this->columnExists('customers', 'active_national_id')) {
                DB::statement(
                    "ALTER TABLE `customers`
                     ADD COLUMN `active_national_id`
                         VARCHAR(14) COLLATE utf8mb4_unicode_ci
                         GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `national_id`, NULL))
                         VIRTUAL"
                );
            }

            // ============================================================
            // المرحلة الثانية: إنشاء الفهارس الفريدة الجديدة المستقلة
            // ============================================================
            if (! $this->uniqueIndexExists('customers', 'customers_active_phone_unique')) {
                DB::statement(
                    "ALTER TABLE `customers`
                     ADD UNIQUE INDEX `customers_active_phone_unique` (`active_phone`)"
                );
            }

            if (! $this->uniqueIndexExists('customers', 'customers_active_national_id_unique')) {
                DB::statement(
                    "ALTER TABLE `customers`
                     ADD UNIQUE INDEX `customers_active_national_id_unique` (`active_national_id`)"
                );
            }

            // ============================================================
            // المرحلة الثالثة: التحقق الإلزامي من وجود الفهارس الجديدة
            // ============================================================
            $hasActivePhoneIndex = $this->uniqueIndexExists('customers', 'customers_active_phone_unique');
            $hasActiveNatIdIndex = $this->uniqueIndexExists('customers', 'customers_active_national_id_unique');

            if (! $hasActivePhoneIndex || ! $hasActiveNatIdIndex) {
                throw new \RuntimeException(
                    "فشل التحقق من إنشاء الفهارس الجديدة (active_phone: " .
                    ($hasActivePhoneIndex ? 'OK' : 'MISSING') . ", active_national_id: " .
                    ($hasActiveNatIdIndex ? 'OK' : 'MISSING') . "). تم الإيقاف دون حذف أي قيود قديمة."
                );
            }

            // ============================================================
            // المرحلة الرابعة: حذف القيود القديمة بعد الاطمئنان الكامل على الجديدة
            // ============================================================
            if ($this->uniqueIndexExists('customers', 'customers_phone_national_id_active_unique')) {
                DB::statement("ALTER TABLE `customers` DROP INDEX `customers_phone_national_id_active_unique`");
            }

            if ($this->columnExists('customers', 'is_active_for_unique')) {
                DB::statement("ALTER TABLE `customers` DROP COLUMN `is_active_for_unique`");
            }

            if ($this->uniqueIndexExists('customers', 'customers_phone_national_id_unique')) {
                DB::statement("ALTER TABLE `customers` DROP INDEX `customers_phone_national_id_unique`");
            }
        } else {
            // SQLite (لبيئة الاختبارات)
            // 1. إنشاء الفهارس الجزئية أولاً
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS customers_active_phone_unique
                 ON customers (phone) WHERE deleted_at IS NULL"
            );

            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS customers_active_national_id_unique
                 ON customers (national_id) WHERE deleted_at IS NULL AND national_id IS NOT NULL"
            );

            // 2. التحقق من نجاحها
            if (! $this->uniqueIndexExists('customers', 'customers_active_phone_unique') ||
                ! $this->uniqueIndexExists('customers', 'customers_active_national_id_unique')) {
                throw new \RuntimeException("فشل إنشاء فهارس SQLite الفريدة.");
            }

            // 3. حذف الفهارس القديمة
            DB::statement("DROP INDEX IF EXISTS customers_phone_national_id_active_unique");
            DB::statement("DROP INDEX IF EXISTS customers_phone_national_id_unique");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // ============================================================
            // الخطوة 0: فحص سلامة البيانات قبل التراجع
            // إذا كانت هناك بيانات تمنع إعادة بناء الفهرس القديم، نرفض Rollback
            // لمنع ترك الجدول بحالة جزئية دون فهارس
            // ============================================================
            $dupPairs = DB::select(
                'SELECT phone, national_id, COUNT(*) AS cnt
                 FROM customers
                 WHERE deleted_at IS NULL AND national_id IS NOT NULL
                 GROUP BY phone, national_id
                 HAVING COUNT(*) > 1'
            );

            if (! empty($dupPairs)) {
                throw new \RuntimeException(
                    "Rollback غير آمن ومرفوض: توجد سجلات نشطة مكررة في (phone, national_id) " .
                    "ستمنع إعادة بناء الفهرس المركب القديم. لم يتم حذف أي فهرس جديد."
                );
            }

            // ============================================================
            // المرحلة الأولى: إعادة بناء القيد القديم أولاً (Additive in reverse)
            // ============================================================
            if (! $this->columnExists('customers', 'is_active_for_unique')) {
                DB::statement(
                    "ALTER TABLE `customers`
                     ADD COLUMN `is_active_for_unique`
                         TINYINT
                         GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, 1, NULL))
                         VIRTUAL"
                );
            }

            if (! $this->uniqueIndexExists('customers', 'customers_phone_national_id_active_unique')) {
                DB::statement(
                    "ALTER TABLE `customers`
                     ADD UNIQUE INDEX `customers_phone_national_id_active_unique`
                     (`phone`, `national_id`, `is_active_for_unique`)"
                );
            }

            // التحقق من أن الفهرس القديم أُعيد بنجاح
            if (! $this->uniqueIndexExists('customers', 'customers_phone_national_id_active_unique')) {
                throw new \RuntimeException(
                    "فشل إعادة إنشاء الفهرس المركب القديم customers_phone_national_id_active_unique. " .
                    "تم إيقاف التراجع لحماية الفهارس الجديدة من الحذف."
                );
            }

            // ============================================================
            // المرحلة الثانية: حذف الفهارس والأعمدة الجديدة بعد الاطمئنان على القديمة
            // ============================================================
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
        } else {
            // SQLite
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS customers_phone_national_id_active_unique
                 ON customers (phone, national_id) WHERE deleted_at IS NULL"
            );

            DB::statement("DROP INDEX IF EXISTS customers_active_phone_unique");
            DB::statement("DROP INDEX IF EXISTS customers_active_national_id_unique");
        }
    }

    // ---------------------------------------------------------------
    // دوال مساعدة
    // ---------------------------------------------------------------

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
