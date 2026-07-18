<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `module_type` to customers table to fix Phase 3.5 misclassification.
 *
 * Background:
 *   `CustomerLedgerObserver` was hard-coding `module_type='bus'` on every
 *   Customer AR mirror account. That meant a customer first booked via the
 *   flight module showed up under "bus" in the unified Treasury reports
 *   until `FlightBookingService::createBooking` silently retagged the
 *   account to `'flights'`. The retag path was wrapped in
 *   `LedgerBalanceMutationGuard` to bypass the balance guard, which is a
 *   code smell — and it still left bus/hajj/visa customers misclassified
 *   until *their* booking service ran the same dance.
 *
 * Resolution:
 *   Store the customer's primary module on the customers row itself, so
 *   the AR mirror account can be tagged correctly at creation time.
 *   Callers that create a customer in a specific module context
 *   (Flight API, Hajj API, Visa API, Bus API, etc.) MUST set this column.
 *   For backward compatibility with the Filament UI (which still creates
 *   a customer with no module hint), the observer falls back to
 *   `AccountModuleContract::OFFICE_MODULE_TYPE` — the shared "office"
 *   division that contains bus/fawry/online/wallet_transfer.
 *
 *   The existing retag in FlightBookingService continues to work; it's
 *   now just a safety net for legacy customer rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // nullable — null means "shared office AR (bus/fawry/online/wallet_transfer)"
            $table->string('module_type', 50)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('module_type');
        });
    }
};