<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug #B13 fix: Add `original_amount` column to `flight_payments` to preserve the
 * actual amount paid in the payment's original currency (foreign for non-EGP
 * bookings paid in foreign currency via auto-conversion). The existing `amount`
 * column stores the EGP-equivalent for ledger reporting, but `currency` is always
 * 'EGP' — losing the actual payment currency information.
 *
 * After this migration:
 *   - `amount`         = EGP-equivalent (used by ledger and total-paid calculations)
 *   - `currency`       = will store the ACTUAL payment currency (not always EGP)
 *   - `original_amount` = the actual amount paid in `currency` (foreign for non-EGP)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_payments', function (Blueprint $table) {
            $table->decimal('original_amount', 15, 4)
                ->nullable()
                ->after('amount')
                ->comment('المبلغ الأصلي المدفوع بعملة payment.currency (foreign أو EGP)');
        });
    }

    public function down(): void
    {
        Schema::table('flight_payments', function (Blueprint $table) {
            $table->dropColumn('original_amount');
        });
    }
};
