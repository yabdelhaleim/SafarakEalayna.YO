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
        Schema::create('airline_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // اسم الشركة
            $table->string('code')->unique(); // كود مختصر
            $table->string('system_type');    // Amadeus, NDC, الجزيرة إلخ
            $table->string('currency')->default('EGP');
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('system_type');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airline_accounts');
    }
};
