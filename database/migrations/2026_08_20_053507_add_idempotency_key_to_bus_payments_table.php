<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Level 2 / Problem 4 — Add `idempotency_key` column to bus_payments.
 *
 * Used by POST /bus/bookings/{id}/pay to detect double-submits:
 *   - The client sends an Idempotency-Key header (UUID).
 *   - The service looks up an existing payment with (booking_id, idempotency_key).
 *     If found → return the same result (no second debit).
 *
 * Nullable so legacy payments / safety-net-only flows can still write rows
 * without a key. Indexed for fast lookup.
 *
 * NOTE: this migration has NOT been run in production yet. After merge,
 * run `php artisan migrate` on staging/prod to apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bus_payments', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('notes');
            // Fast lookup index for the idempotent-replay path.
            $table->index(['booking_id', 'idempotency_key'], 'idx_bus_payments_booking_idem');
        });
    }

    public function down(): void
    {
        Schema::table('bus_payments', function (Blueprint $table) {
            $table->dropIndex('idx_bus_payments_booking_idem');
            $table->dropColumn('idempotency_key');
        });
    }
};