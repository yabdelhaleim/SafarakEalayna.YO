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
        Schema::create('hajj_umra_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('program_id')->constrained()->onDelete('cascade');
            $table->string('module')->default('HAJJ_UMRA');
            $table->foreignId('companion_customer_id')->nullable()->constrained('customers')->onDelete('set null');
            $table->decimal('purchase_price', 15, 2);
            $table->decimal('selling_price', 15, 2);
            $table->decimal('profit', 15, 2);
            $table->string('currency')->default('EGP');
            $table->boolean('per_person')->default(true);
            $table->string('status'); // PENDING, CONFIRMED, CANCELLED, REFUNDED
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
        Schema::dropIfExists('hajj_umra_bookings');
    }
};