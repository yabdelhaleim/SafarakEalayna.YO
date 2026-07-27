<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit columns for the soft-cancel flow on Online transactions.
     *
     * Phase 10: every cancellation records:
     *  - `cancelled_by` — user id (FK users) that triggered the cancel.
     *  - `cancelled_at` — timestamp of the cancel.
     *  - Composite index on (status, deleted_at) to make "cancelled" listings
     *    efficient without a full table scan.
     */
    public function up(): void
    {
        Schema::table('online_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('failure_reason');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');

            $table->foreign('cancelled_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['status', 'deleted_at'], 'online_tx_status_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('online_transactions', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropIndex('online_tx_status_deleted_idx');
            $table->dropColumn(['cancelled_by', 'cancelled_at']);
        });
    }
};
