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
        Schema::create('flight_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_booking_id')->constrained()->onDelete('cascade');
            $table->string('payment_method');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('EGP');
            $table->string('treasury_account');
            $table->string('transaction_reference')->nullable();
            $table->dateTime('payment_date');
            $table->string('paid_by');
            $table->timestamps();

            $table->index('flight_booking_id');
            $table->index('payment_method');
            $table->index('treasury_account');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_payments');
    }
};
