<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * حالة عكس الـ P&L لطلب الاسترجاع.
     *
     * When a refund is processed the service:
     *   • posts a "memo" transaction (no account_entries) that reverses part
     *     of the recognised revenue  →  RefundService::reverseRevenueImpact();
     *   • marks the payment income rows as reversed (notes prefixed "عكس:")
     *     when the whole ticket price was refunded
     *     →  FlightBookingService::reverseFlightBookingRevenue().
     *
     * Both effects are P&L-only, so cancelling the refund request has to
     * restore them explicitly, otherwise the P&L keeps the refund impact
     * forever (revenue stays reversed / stays missing).
     *
     * Stored shape (json):
     *   {
     *     "memo_transaction_id": 12,               // the revenue-reversal memo
     *     "reversed_income_transaction_ids": [4]   // income rows marked "عكس:"
     *   }
     */
    public function up(): void
    {
        if (! Schema::hasColumn('refund_requests', 'reversal_state')) {
            Schema::table('refund_requests', function (Blueprint $table) {
                $table->json('reversal_state')->nullable()->after('credit_ledger_entry_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('refund_requests', 'reversal_state')) {
            Schema::table('refund_requests', function (Blueprint $table) {
                $table->dropColumn('reversal_state');
            });
        }
    }
};
