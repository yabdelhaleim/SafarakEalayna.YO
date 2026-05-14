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
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_booking_id')->constrained('flight_bookings')->onDelete('cascade');
            $table->string('refund_type');
            $table->string('original_currency', 3);
            $table->decimal('original_amount', 15, 2);
            $table->decimal('cancellation_fee', 15, 2)->default(0);
            $table->decimal('refund_amount', 15, 2);
            $table->string('refund_currency', 3);
            $table->decimal('refund_exchange_rate', 15, 6)->default(1);
            $table->decimal('base_currency_refund', 15, 2)->default(0);
            $table->decimal('currency_difference', 15, 2)->default(0);
            $table->string('destination');
            $table->foreignId('treasury_id')->nullable()->constrained('treasuries')->onDelete('set null');
            $table->decimal('airline_credit_balance', 15, 2)->nullable();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
