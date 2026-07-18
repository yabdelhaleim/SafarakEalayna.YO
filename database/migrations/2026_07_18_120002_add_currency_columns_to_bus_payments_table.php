<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add multi-currency support to `bus_payments`.
 *
 * Adds:
 *   - `currency`               — payment currency (default 'EGP')
 *   - `exchange_rate_to_egp`   — FX rate to EGP at payment time (default 1.0)
 *
 * Each payment carries an FX snapshot because the office cash box is in EGP but
 * a single booking might be partially paid in USD and later topped-up in SAR.
 * The snapshots make reconciliation deterministic.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bus_payments', 'currency')) {
            Schema::table('bus_payments', function (Blueprint $t) {
                $t->string('currency', 3)->default('EGP')->after('transaction_id');
                $t->decimal('exchange_rate_to_egp', 12, 6)->default(1.0)->after('currency');
                $t->index('currency');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bus_payments', 'currency')) {
            Schema::table('bus_payments', function (Blueprint $t) {
                $t->dropIndex(['currency']);
                $t->dropColumn(['currency', 'exchange_rate_to_egp']);
            });
        }
    }
};
