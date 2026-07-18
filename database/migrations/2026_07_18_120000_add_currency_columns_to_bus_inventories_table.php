<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add multi-currency support to `bus_inventories`.
 *
 * Adds:
 *   - `currency`               — ISO 4217 (default 'EGP')
 *   - `exchange_rate_to_egp`   — multiplier from booking currency to EGP (default 1.0)
 *
 * Existing rows are backfilled with 'EGP' / 1.0 so all the financial migration is
 * loss-less and the existing tests continue to pass.
 *
 * Bus inventory currency governs:
 *   1. The booking's `currency` and `exchange_rate_to_egp` (mirrored on create)
 *   2. The currency used for `cost_per_ticket` / `selling_price` columns
 *   3. The customer's account currency (auto-created in that currency)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bus_inventories', 'currency')) {
            Schema::table('bus_inventories', function (Blueprint $t) {
                $t->string('currency', 3)->default('EGP')->after('selling_price');
                $t->decimal('exchange_rate_to_egp', 12, 6)->default(1.0)->after('currency');
                $t->index('currency');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bus_inventories', 'currency')) {
            Schema::table('bus_inventories', function (Blueprint $t) {
                $t->dropIndex(['currency']);
                $t->dropColumn(['currency', 'exchange_rate_to_egp']);
            });
        }
    }
};
