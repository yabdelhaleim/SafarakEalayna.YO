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
        Schema::create('visa_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visa_booking_id')->constrained()->onDelete('cascade');
            $table->string('payment_method');
            $table->decimal('amount', 15, 2);
            $table->string('currency')->default('EGP');
            $table->string('treasury_account');
            $table->string('transaction_reference')->nullable();
            $table->dateTime('payment_date');
            $table->string('paid_by');
            $table->timestamps();

            $table->index('payment_method');
            $table->index('payment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visa_payments');
    }
};