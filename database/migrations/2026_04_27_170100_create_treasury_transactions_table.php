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
        Schema::create('treasury_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('from_treasury')->nullable();
            $table->string('to_treasury')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('EGP');
            $table->string('reason');
            $table->foreignId('flight_booking_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('hajj_umra_booking_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('visa_booking_id')->nullable()->constrained()->onDelete('set null');
            $table->string('agent_name');
            $table->timestamps();

            $table->index('from_treasury');
            $table->index('to_treasury');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('treasury_transactions');
    }
};
