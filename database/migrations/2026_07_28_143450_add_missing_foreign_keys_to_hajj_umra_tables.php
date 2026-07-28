<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 — Database Integrity.
 *
 * Add missing foreign-key constraints to the 3 hajj_umra tables that were
 * created without referential integrity protection. Without these FKs:
 *  - hajj_umra_bookings rows can point to non-existent customers/programs/accounts
 *  - hajj_umra_payments can point to non-existent bookings
 *  - hajj_umra_executing_companies can point to non-existent accounts
 *
 * All three tables were empty at migration time (verified pre-deploy), so
 * the FKs add without data migration.
 *
 * The employee_id column is intentionally left without a FK — its meaning
 * is ambiguous (could be users.id or employees.id) and adding a FK would
 * be presumptive. Application code resolves it via the model accessor.
 *
 * NOT NULL is intentionally NOT added here either — booking creation may
 * happen before account assignment in some legacy flows. Application
 * code enforces the requirement via FormRequest rules.
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html
 */
return new class extends Migration
{
    public function up(): void
    {
        /* -----------------------------------------------------------------
         * hajj_umra_bookings
         * ----------------------------------------------------------------- */
        $this->addForeignKeyIfMissing('hajj_umra_bookings', 'customer_id', 'customers', 'id', 'RESTRICT');
        $this->addForeignKeyIfMissing('hajj_umra_bookings', 'program_id', 'programs', 'id', 'RESTRICT');
        $this->addForeignKeyIfMissing('hajj_umra_bookings', 'supplier_id', 'umrah_suppliers', 'id', 'SET NULL');
        $this->addForeignKeyIfMissing('hajj_umra_bookings', 'companion_customer_id', 'customers', 'id', 'SET NULL');
        $this->addForeignKeyIfMissing('hajj_umra_bookings', 'account_id', 'accounts', 'id', 'RESTRICT');
        $this->addForeignKeyIfMissing('hajj_umra_bookings', 'created_by', 'users', 'id', 'SET NULL');
        $this->addForeignKeyIfMissing('hajj_umra_bookings', 'income_transaction_id', 'transactions', 'id', 'SET NULL');
        $this->addForeignKeyIfMissing('hajj_umra_bookings', 'expense_transaction_id', 'transactions', 'id', 'SET NULL');

        /* -----------------------------------------------------------------
         * hajj_umra_payments
         * ----------------------------------------------------------------- */
        $this->addForeignKeyIfMissing('hajj_umra_payments', 'hajj_umra_booking_id', 'hajj_umra_bookings', 'id', 'CASCADE');
        $this->addForeignKeyIfMissing('hajj_umra_payments', 'account_id', 'accounts', 'id', 'RESTRICT');
        $this->addForeignKeyIfMissing('hajj_umra_payments', 'transaction_id', 'transactions', 'id', 'SET NULL');
        $this->addForeignKeyIfMissing('hajj_umra_payments', 'created_by', 'users', 'id', 'SET NULL');

        /* -----------------------------------------------------------------
         * hajj_umra_executing_companies
         * ----------------------------------------------------------------- */
        $this->addForeignKeyIfMissing('hajj_umra_executing_companies', 'account_id', 'accounts', 'id', 'SET NULL');
    }

    public function down(): void
    {
        $this->dropForeignKeyIfExists('hajj_umra_executing_companies', 'account_id');
        $this->dropForeignKeyIfExists('hajj_umra_payments', 'created_by');
        $this->dropForeignKeyIfExists('hajj_umra_payments', 'transaction_id');
        $this->dropForeignKeyIfExists('hajj_umra_payments', 'account_id');
        $this->dropForeignKeyIfExists('hajj_umra_payments', 'hajj_umra_booking_id');
        $this->dropForeignKeyIfExists('hajj_umra_bookings', 'expense_transaction_id');
        $this->dropForeignKeyIfExists('hajj_umra_bookings', 'income_transaction_id');
        $this->dropForeignKeyIfExists('hajj_umra_bookings', 'created_by');
        $this->dropForeignKeyIfExists('hajj_umra_bookings', 'account_id');
        $this->dropForeignKeyIfExists('hajj_umra_bookings', 'companion_customer_id');
        $this->dropForeignKeyIfExists('hajj_umra_bookings', 'supplier_id');
        $this->dropForeignKeyIfExists('hajj_umra_bookings', 'program_id');
        $this->dropForeignKeyIfExists('hajj_umra_bookings', 'customer_id');
    }

    /**
     * Add a foreign key only if it does not already exist.
     *
     * MySQL does not natively support "ADD CONSTRAINT IF NOT EXISTS", so we
     * query information_schema first. This makes the migration idempotent —
     * safe to re-run after partial failures.
     *
     * SQLite (test environment) does not have information_schema, so we fall
     * back to a try/catch that swallows duplicate-constraint errors.
     */
    private function addForeignKeyIfMissing(string $table, string $column, string $refTable, string $refColumn, string $onDelete): void
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

            if (! empty($existing)) {
                return; // already has a FK on this column
            }

            // MySQL doesn't allow ON DELETE SET NULL on a NOT NULL column.
            $columnInfo = DB::select(
                'SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );
            $isNullable = ($columnInfo[0]->IS_NULLABLE ?? 'YES') === 'YES';
            $effectiveOnDelete = ($onDelete === 'SET NULL' && ! $isNullable) ? 'RESTRICT' : $onDelete;
        } else {
            // SQLite: assume the FK doesn't exist (tests start fresh).
            $isNullable = true; // safe default
            $effectiveOnDelete = $onDelete;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $refTable, $refColumn, $effectiveOnDelete) {
                $blueprint->foreign($column, substr("{$column}_foreign", 0, 64))
                    ->references($refColumn)
                    ->on($refTable)
                    ->onDelete($effectiveOnDelete);
            });
        } catch (\Throwable $e) {
            // Idempotency for SQLite: swallow "constraint already exists" errors.
            if (! str_contains(strtolower($e->getMessage()), 'exists')
                && ! str_contains(strtolower($e->getMessage()), 'duplicate')) {
                throw $e;
            }
        }
    }

    /**
     * Drop a foreign key only if it exists.
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
        // SQLite: no-op if not present; ALTER TABLE will throw and rollback is harmless.

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable $e) {
            // Ignore: column may not have FK in SQLite test env.
        }
    }
};