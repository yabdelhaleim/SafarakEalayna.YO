<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_system_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_system_id')->constrained('flight_systems')->cascadeOnDelete();
            $table->foreignId('flight_booking_id')->nullable()->constrained('flight_bookings')->nullOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('flight_system_id');
            $table->index('flight_booking_id');
            $table->index('type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_system_transactions');
    }
};
