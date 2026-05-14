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
        Schema::create('airline_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_carrier_id')->constrained('flight_carriers')->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('set null');
            $table->string('currency', 3);
            $table->decimal('amount', 15, 2);
            $table->date('expiry_date')->nullable();
            $table->foreignId('flight_booking_id')->constrained('flight_bookings')->onDelete('cascade');
            $table->foreignId('refund_request_id')->constrained('refund_requests')->onDelete('cascade');
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airline_credits');
    }
};
