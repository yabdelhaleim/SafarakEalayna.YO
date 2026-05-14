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
        Schema::create('flight_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_booking_id')->constrained()->onDelete('cascade');
            $table->string('from_airport');
            $table->string('to_airport');
            $table->date('departure_date');
            $table->time('departure_time');
            $table->time('arrival_time');
            $table->string('airline');
            $table->string('flight_number');
            $table->string('baggage')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('flight_booking_id');
            $table->index('from_airport');
            $table->index('to_airport');
            $table->index('departure_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_segments');
    }
};
