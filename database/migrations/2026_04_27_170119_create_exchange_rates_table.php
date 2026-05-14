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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 3); // EGP, KWD, SAR, USD
            $table->string('to_currency', 3);   // EGP, KWD, SAR, USD
            $table->decimal('rate', 15, 6); // مثال: 1 KWD = 175.5 EGP
            $table->date('effective_date'); // تاريخ فعالية السعر
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            // Unique constraint: لا يكرر نفس الزوج في نفس اليوم
            $table->unique(['from_currency', 'to_currency', 'effective_date']);

            $table->index(['from_currency', 'to_currency']);
            $table->index('effective_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
