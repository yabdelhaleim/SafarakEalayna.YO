<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, update existing data to map old values to new ones
        DB::statement("UPDATE flight_payments SET payment_method = 'bank_transfer' WHERE payment_method = 'transfer'");

        Schema::table('flight_payments', function (Blueprint $table) {
            $table->enum('payment_method', [
                'cash',
                'bank_transfer',
                'cash_wallet',
                'postal_transfer',
                'office_safe',
                'office_drawer',
                'mixed'
            ])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_payments', function (Blueprint $table) {
            $table->enum('payment_method', ['cash', 'transfer', 'mixed'])->change();
        });

        // Revert data mapping
        DB::statement("UPDATE flight_payments SET payment_method = 'transfer' WHERE payment_method = 'bank_transfer'");
    }
};
