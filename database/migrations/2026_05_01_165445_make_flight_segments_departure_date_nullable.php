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
        Schema::table('flight_segments', function (Blueprint $table) {
            // Make departure_date nullable to handle cases where date might not be provided
            $table->date('departure_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_segments', function (Blueprint $table) {
            // Revert to required
            $table->date('departure_date')->nullable(false)->change();
        });
    }
};
