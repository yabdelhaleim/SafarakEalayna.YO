<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add multi-currency support to `bus_bookings`.
 *
 * Adds:
 *   - `currency`               — booking currency (default 'EGP' for legacy rows)
 *   - `exchange_rate_to_egp`   — FX rate to EGP at booking time (default 1.0)
 *
 * The booking's currency governs the customer AR account currency and the
 * payment-side wallet/bank currency. A separate migration for `bus_payments`
 * captures the per-payment snapshot if multiple currencies are mixed.
 *
 * Mirrors `add_currency_columns_to_bus_inventories_table` (Phase A.2 migration
 * 2026_07_18_120000).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bus_bookings', 'currency')) {
            Schema::table('bus_bookings', function (Blueprint $t) {
                $t->string('currency', 3)->default('EGP')->after('transaction_id');
                $t->decimal('exchange_rate_to_egp', 12, 6)->default(1.0)->after('currency');
                $t->index('currency');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bus_bookings', 'currency')) {
            Schema::table('bus_bookings', function (Blueprint $t) {
                $t->dropIndex(['currency']);
                $t->dropColumn(['currency', 'exchange_rate_to_egp']);
            });
        }
    }
};
