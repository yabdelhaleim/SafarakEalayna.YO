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
        Schema::create('flight_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_booking_id')->constrained('flight_bookings')->cascadeOnDelete();
            $table->string('currency', 10)->default('EGP');
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->decimal('profit', 12, 2)->default(0);
            $table->string('booking_currency', 10)->nullable();
            $table->decimal('amount_in_foreign_currency', 12, 2)->nullable();
            $table->decimal('exchange_rate_used', 12, 4)->nullable();
            $table->decimal('purchase_price_egp', 12, 2)->nullable();
            $table->decimal('selling_price_egp', 12, 2)->nullable();
            $table->decimal('profit_egp', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_pricings');
    }
};
