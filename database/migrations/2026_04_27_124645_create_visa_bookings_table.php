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
        Schema::create('visa_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('visa_detail_id')->constrained()->onDelete('cascade');
            $table->string('module')->default('VISA');
            $table->decimal('purchase_price', 15, 2);
            $table->decimal('selling_price', 15, 2);
            $table->decimal('service_fee', 15, 2)->nullable();
            $table->decimal('profit', 15, 2);
            $table->string('currency')->default('EGP');
            $table->string('status'); // PENDING, IN_PROGRESS, COMPLETED, REJECTED, REFUNDED, CANCELLED
            $table->string('agent_name');
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('module');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visa_bookings');
    }
};