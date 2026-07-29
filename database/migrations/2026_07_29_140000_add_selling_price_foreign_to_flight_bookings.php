<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug #3 fix (FlightProductionFullE2ETest audit, 2026-07-29):
 *
 * The `selling_price` column on `flight_bookings` is ALWAYS stored in EGP
 * (per the 2026-07-23 fix — see `FlightBookingService::createBooking` line ~239:
 *  "selling_price is ALWAYS in EGP, regardless of booking currency").
 *
 * For non-EGP bookings (USD / SAR / KWD), this means the foreign-currency
 * selling price is lost at write time. When the booking is later CANCELLED,
 * `cancelBooking()` and `deleteBookingWithReversal()` need the foreign sale
 * amount to compute the refund in booking currency (since the refund account
 * is validated to be in booking currency — see Step 3.5 in `cancelBooking`).
 *
 * The previous fallback chain `original_amount ?: selling_price` is BROKEN
 * because `original_amount` is intentionally NULL whenever the customer paid
 * in the same currency as the booking (per the 2026-07-21 saving observer).
 * The fallback to `selling_price` (which is EGP) then gets multiplied by
 * `exchange_rate` AGAIN, producing wildly wrong refunds (e.g. 400,000 EGP
 * for a 10,000 EGP booking) — root cause of the negative wallet balances
 * in scenarios C and D of `FlightProductionFullE2ETest`.
 *
 * This migration adds a dedicated nullable column to hold the foreign-currency
 * selling price. It is populated by `FlightBookingService::createBooking()`
 * for every non-EGP booking and is the canonical source for all refund /
 * cancel / delete computations involving non-EGP bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->decimal('selling_price_foreign', 15, 2)
                ->nullable()
                ->after('selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->dropColumn('selling_price_foreign');
        });
    }
};
