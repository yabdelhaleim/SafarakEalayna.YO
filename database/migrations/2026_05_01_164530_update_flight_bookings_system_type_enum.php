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
        Schema::table('flight_bookings', function (Blueprint $table) {
            // Change system_type column to ENUM with all required values
            $table->enum('system_type', [
                'NDC',
                'Amadeus',
                'Sabre',
                'Galileo',
                'manual',
                'online',
                'gds',
                'api',
                'other'
            ])->default('manual')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            // Revert to original ENUM values
            $table->enum('system_type', ['manual', 'online', 'gds', 'api'])->default('manual')->change();
        });
    }
};
