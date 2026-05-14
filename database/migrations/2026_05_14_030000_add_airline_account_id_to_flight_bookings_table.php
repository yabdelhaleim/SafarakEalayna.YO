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
            if (!Schema::hasColumn('flight_bookings', 'airline_account_id')) {
                $table->foreignId('airline_account_id')
                    ->nullable()
                    ->constrained('airline_accounts')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('flight_bookings', 'airline_account_id')) {
                $table->dropForeign(['airline_account_id']);
                $table->dropColumn('airline_account_id');
            }
        });
    }
};
