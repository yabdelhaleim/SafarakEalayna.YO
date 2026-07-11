<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `deleted_at` soft-delete column to `hajj_umra_payments` so the
 * financial payment rows can be hidden from views/reports while preserving
 * the audit trail.
 *
 * Mirrors `2026_07_10_120000_add_soft_deletes_to_flight_financial_tables.php`
 * which added the same column to `flight_payments` (the Flight counterpart).
 *
 * IMPORTANT: `transactions` and `account_entries` must NEVER gain a deleted_at
 * column. Their reversals are always done by *adding* new reversal rows,
 * never by deleting. This rule is shared across the project.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('hajj_umra_payments', 'deleted_at')) {
            Schema::table('hajj_umra_payments', function (Blueprint $t) {
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hajj_umra_payments', 'deleted_at')) {
            Schema::table('hajj_umra_payments', function (Blueprint $t) {
                $t->dropSoftDeletes();
            });
        }
    }
};
