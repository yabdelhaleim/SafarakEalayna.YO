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
        Schema::create('flight_systems', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Amadeus, NDC, NDC X, TP3, Sabre, Galileo
            $table->string('code')->unique(); // AMA, NDC, NDCX, TP3, SAB, GAL
            $table->string('type')->default('gds'); // gds, ndc, other
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_systems');
    }
};
