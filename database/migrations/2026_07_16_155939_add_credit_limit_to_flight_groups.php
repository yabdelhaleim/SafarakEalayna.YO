<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug #16 fix: Add `credit_limit` column to `flight_groups` so that
 * the booking flow can know the maximum debt a group is allowed to
 * accumulate before refusing further purchases on its account.
 *
 * Semantics (in `recordPurchaseFromGroup`):
 *   - Account balance > 0  → group has prepaid money available.
 *   - Account balance = 0  → group has no money.
 *   - Account balance < 0  → group owes us money (debt up to credit_limit).
 *
 * If `credit_limit` is 0 (default), the group cannot go into debt —
 * the booking is rejected as soon as the new balance would drop below zero.
 * If `credit_limit` > 0, the group may spend up to its prepaid balance
 * plus that credit limit before the booking is rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_groups', function (Blueprint $table) {
            $table->decimal('credit_limit', 15, 2)
                ->default(999999999)
                ->after('commission_rate')
                ->comment('الحد الأقصى للدين المسموح للمجموعة. الافتراضي كبير (999,999,999) للسماح بالأجل التلقائي.');
        });
    }

    public function down(): void
    {
        Schema::table('flight_groups', function (Blueprint $table) {
            $table->dropColumn('credit_limit');
        });
    }
};
