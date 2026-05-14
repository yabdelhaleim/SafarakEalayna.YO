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
        Schema::create('airline_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('flight_booking_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('type', ['credit', 'debit', 'refund']);
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index('airline_account_id');
            $table->index('flight_booking_id');
            $table->index('type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airline_transactions');
    }
};
