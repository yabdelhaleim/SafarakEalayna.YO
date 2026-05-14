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
        Schema::create('flight_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_booking_id')->constrained()->onDelete('cascade');
            $table->string('currency', 3)->default('EGP');
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->decimal('profit', 15, 2)->default(0);
            
            // Foreign Currency Fields
            $table->string('booking_currency', 3)->nullable();
            $table->decimal('amount_in_foreign_currency', 15, 2)->nullable();
            $table->decimal('exchange_rate_used', 15, 4)->nullable();
            $table->decimal('purchase_price_egp', 15, 2)->nullable();
            $table->decimal('selling_price_egp', 15, 2)->nullable();
            $table->decimal('profit_egp', 15, 2)->nullable();
            
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('flight_booking_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_pricing');
    }
};
