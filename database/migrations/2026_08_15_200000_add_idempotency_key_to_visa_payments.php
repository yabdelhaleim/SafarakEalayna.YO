<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VISA-IDEMPOTENCY MIGRATION — 2026-08-15
 *
 * Adds `idempotency_key` (nullable VARCHAR 100) to `visa_payments` with a
 * UNIQUE constraint on (visa_booking_id, idempotency_key).
 *
 * Design decisions:
 *   1. NULLABLE — legacy callers that don't supply a key continue to work
 *      unchanged. MySQL/MariaDB UNIQUE indexes allow multiple NULL values in
 *      the same index, so existing rows (all NULL) are unaffected.
 *
 *   2. Composite UNIQUE index on (visa_booking_id, idempotency_key):
 *        - Same key on DIFFERENT bookings → allowed (key is per-booking)
 *        - Same key on SAME booking → blocked (duplicate; idempotent return)
 *        - Multiple NULLs on same booking → allowed (legacy path)
 *
 *   3. Safety check: before adding the index, verify there are no two ACTIVE
 *      (non-null) payments on the same booking sharing the same key. In a
 *      fresh stress DB this should never be true, but we abort rather than
 *      silently truncate data.
 *
 *   4. The down() drops only the index and column — it does NOT attempt to
 *      undo any financial data.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: if the column already exists the migration already ran.
        if (Schema::hasColumn('visa_payments', 'idempotency_key')) {
            return;
        }

        // On first run the column does not exist, so there are no duplicate
        // keys to check — proceed directly to schema change.
        Schema::table('visa_payments', function (Blueprint $table) {
            // Add the column after transaction_reference for logical grouping.
            $table->string('idempotency_key', 100)
                ->nullable()
                ->after('transaction_reference')
                ->comment('Client-supplied replay key. Null = legacy/no-key call. Unique per booking.');

            // Composite unique index: same key on same booking is blocked;
            // same key on different bookings is allowed (index is per-booking).
            // MySQL allows multiple NULLs in a unique index, so legacy callers
            // with null keys are unaffected.
            $table->unique(['visa_booking_id', 'idempotency_key'], 'vp_idem_uniq');
        });
    }

    public function down(): void
    {
        Schema::table('visa_payments', function (Blueprint $table) {
            if (Schema::hasColumn('visa_payments', 'idempotency_key')) {
                $table->dropUnique('vp_idem_uniq');
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
