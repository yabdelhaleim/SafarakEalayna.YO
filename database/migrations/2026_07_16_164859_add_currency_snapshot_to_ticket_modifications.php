<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug #B11 fix: Add `currency_snapshot` column to `ticket_modifications` so that
 * reversals (Phase 1v2 reversal) can recover the original modification currency
 * even after the booking currency has been mutated. Without this snapshot, a
 * booking whose currency changes between confirmation and reversal would cause
 * `AirlineAccountDebitService::creditBackForModification()` to use the wrong
 * currency when crediting the airline account back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_modifications', function (Blueprint $table) {
            $table->string('currency_snapshot', 3)
                ->nullable()
                ->after('currency')
                ->comment('لقطة العملة وقت التأكيد — للرجوع عند عكس التعديل');
        });

        // Backfill: existing rows without a snapshot get the value of `currency`.
        \DB::statement('UPDATE ticket_modifications SET currency_snapshot = currency WHERE currency_snapshot IS NULL');
    }

    public function down(): void
    {
        Schema::table('ticket_modifications', function (Blueprint $table) {
            $table->dropColumn('currency_snapshot');
        });
    }
};
