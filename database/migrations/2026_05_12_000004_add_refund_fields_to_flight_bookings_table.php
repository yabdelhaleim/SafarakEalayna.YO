<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->string('original_currency', 3)->nullable();
            $table->decimal('original_amount', 15, 2)->nullable();
            $table->decimal('booking_exchange_rate', 15, 6)->nullable();
            $table->decimal('base_currency_amount', 15, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->dropColumn([
                'original_currency',
                'original_amount',
                'booking_exchange_rate',
                'base_currency_amount',
            ]);
        });
    }
};
