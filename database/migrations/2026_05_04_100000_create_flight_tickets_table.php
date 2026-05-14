<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_booking_id')->constrained('flight_bookings')->cascadeOnDelete();
            $table->foreignId('passenger_id')->nullable()->constrained('passengers')->nullOnDelete();
            $table->string('ticket_number', 64)->unique();
            $table->string('status', 32)->default('issued');
            $table->timestamps();

            $table->index(['flight_booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_tickets');
    }
};
