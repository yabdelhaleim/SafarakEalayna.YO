<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug fix (audit FLIGHT_CURRENCY_AUDIT_20260721_150700.json):
 *
 * The `original_currency` and `original_amount` columns on flight_bookings represent
 * the CUSTOMER's actual payment currency/amount — only meaningful when the customer
 * paid in a different currency than the booking's sale currency.
 *
 * Bug: FlightBookingService::createBooking() was unconditionally writing
 *     original_currency = booking.currency  (always)
 *     original_amount   = booking.selling_price (always)
 * This polluted 8 of 13 bookings in the database (61%) with redundant data.
 *
 * Fix:
 *   - Service layer: only set original_currency/original_amount when customer's payment
 *     currency differs from booking sale currency.
 *   - Model: saving observer auto-nullifies when original_currency == currency.
 *   - Form Request: rejects original_currency == currency with a clear error.
 *
 * This migration back-fills: NULL out original_currency/original_amount where they
 * are redundant (i.e. original_currency == currency). Safe — no data loss (the
 * original booking currency/amount are preserved in `currency`/`selling_price`).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Use UPPER() comparison because some rows might have inconsistent casing.
        // Update both columns atomically.
        $affected = DB::table('flight_bookings')
            ->whereNotNull('original_currency')
            ->whereNotNull('currency')
            ->whereRaw('UPPER(TRIM(original_currency)) = UPPER(TRIM(currency))')
            ->update([
                'original_currency' => null,
                'original_amount' => null,
            ]);

        // Log to laravel.log for visibility (no UI side-effect)
        \Illuminate\Support\Facades\Log::info('flight_bookings cleanup: nullified redundant original_currency/original_amount', [
            'rows_affected' => $affected,
            'migration' => '2026_07_21_181700_clean_redundant_original_currency_on_flight_bookings',
        ]);
    }

    public function down(): void
    {
        // Down is intentionally a no-op: we don't have the original customer-payment
        // currency/amount (it was never recorded). We can't reconstruct it from here.
        // The booking's sale currency/selling_price are still preserved in
        // `currency`/`selling_price`, so no information is lost — we only removed
        // the redundant duplication.
    }
};