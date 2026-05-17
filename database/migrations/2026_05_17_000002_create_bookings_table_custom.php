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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_ref')->unique(); // SAA-2025-00001
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->integer('passengers_count')->default(1);
            $table->json('passengers_data');   // بيانات الركاب
            $table->decimal('total_price', 10, 2);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->enum('payment_status', ['pending', 'partial', 'paid', 'refunded'])->default('pending');
            $table->enum('booking_status', ['pending', 'confirmed', 'checked_in', 'cancelled', 'no_show'])->default('pending');
            $table->string('payment_method')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('booking_ref');
            $table->index(['customer_id', 'booking_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
