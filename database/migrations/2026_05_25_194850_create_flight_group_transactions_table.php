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
        Schema::create('flight_group_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_group_id')->constrained('flight_groups')->onDelete('cascade');
            $table->foreignId('flight_booking_id')->nullable()->constrained('flight_bookings')->onDelete('set null');
            $table->enum('type', ['debt', 'payment']);
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_group_transactions');
    }
};
