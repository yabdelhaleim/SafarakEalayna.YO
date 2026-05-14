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
        Schema::create('flight_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_reference')->unique();
            $table->string('booking_channel_type');
            $table->string('booking_channel_provider');
            $table->string('status')->default('PENDING');
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('agent_name');
            $table->text('notes')->nullable();
            
            // Flight details
            $table->string('origin');
            $table->string('destination');
            $table->date('departure_date');
            $table->string('departure_time');
            $table->date('return_date')->nullable();
            $table->string('return_time')->nullable();
            $table->string('trip_type');
            $table->string('airline');
            $table->integer('passenger_count');
            $table->integer('baggage_allowance_kg')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index('booking_reference');
            $table->index('status');
            $table->index('customer_id');
            $table->index('departure_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_bookings');
    }
};
