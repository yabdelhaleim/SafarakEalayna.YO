<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `deleted_at` soft-delete column to the three Bus financial side-tables
 * so payment / refund rows can be hidden from views/reports while preserving
 * the audit trail.
 *
 * Affected tables (all in `app/Models/Bus/`):
 *   - `bus_payments`         — payments made against `BusBooking` rows
 *   - `bus_company_payments` — payments made against `BusInventory` deferred cost
 *   - `bus_refund_requests`  — refund rows produced by `BusBookingService::cancelBooking`
 *
 * Mirrors:
 *   - `2026_07_10_120000_add_soft_deletes_to_flight_financial_tables.php`
 *   - `2026_07_11_120000_add_soft_deletes_to_hajj_umra_payments_table.php`
 *   - `2026_07_11_130000_add_soft_deletes_to_visa_payments_table.php`
 *
 * IMPORTANT: `transactions` and `account_entries` must NEVER gain a deleted_at
 * column. Their reversals are always done by *adding* new reversal rows via
 * `TransactionService::reverseTransaction()` or `recordJournalTransfer()`,
 * never by deleting. This rule is shared across the project.
 */
return new class extends Migration {
    public function up(): void
    {
        $tables = [
            'bus_payments',
            'bus_company_payments',
            'bus_refund_requests',
        ];

        foreach ($tables as $tableName) {
            if (! Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'bus_payments',
            'bus_company_payments',
            'bus_refund_requests',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};