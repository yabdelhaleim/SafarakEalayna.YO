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
        Schema::create('passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_booking_id')->constrained()->onDelete('cascade');
            $table->string('first_name');
            $table->string('last_name');
            $table->enum('type', ['adult', 'child', 'infant'])->default('adult');
            $table->date('date_of_birth')->nullable();
            $table->string('relation_to_customer')->nullable();
            $table->foreignId('responsible_adult_id')->nullable()->constrained('passengers')->onDelete('set null');
            $table->timestamps();

            $table->index('flight_booking_id');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passengers');
    }
};
