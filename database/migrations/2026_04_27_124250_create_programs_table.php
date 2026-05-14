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
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('program_name');
            $table->string('program_type'); // UMRA, HAJJ
            $table->string('season')->nullable();
            $table->integer('total_nights');
            $table->string('accommodation_type'); // SINGLE, DOUBLE, TRIPLE, QUAD
            $table->string('mecca_hotel_name');
            $table->integer('mecca_nights');
            $table->string('medina_hotel_name')->nullable();
            $table->integer('medina_nights')->nullable();
            $table->date('departure_date');
            $table->date('return_date');
            $table->string('airline');
            $table->string('trip_supervisor')->nullable();
            $table->string('executing_company');
            $table->string('departure_point');
            $table->string('booking_status')->default('PENDING'); // PENDING, CONFIRMED, WAITLIST, CANCELLED
            $table->string('program_price_tier')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('program_type');
            $table->index('booking_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};