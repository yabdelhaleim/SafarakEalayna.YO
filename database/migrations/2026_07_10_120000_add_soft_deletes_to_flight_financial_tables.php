<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `deleted_at` soft-delete column to financial tables in the Flight module
 * so they can be hidden from views/reports while preserving the audit trail.
 *
 * Tables affected:
 *  - flight_payments
 *  - refund_requests
 *  - ticket_modifications
 *  - airline_credits
 *
 * Tables already had softDeletes from a previous migration (flight_bookings, flight_carriers,
 * flight_systems, accounts), so we don't touch them here.
 *
 * IMPORTANT: `transactions` and `account_entries` must NEVER gain a deleted_at column.
 * Their reversals are always done by *adding* new reversal rows, never by deleting.
 */
return new class extends Migration {
    public function up(): void
    {
        $tables = [
            'flight_payments',
            'refund_requests',
            'ticket_modifications',
            'airline_credits',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'flight_payments',
            'refund_requests',
            'ticket_modifications',
            'airline_credits',
        ];

        foreach ($tables as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};
