<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * حركات AirlineTransaction تُستخدم لحسابات AirlineAccount (airline_account_id)
     * ولـ FlightCarrier (flight_carrier_id). السابق كان يسبب INSERT بعمود غير موجود.
     */
    public function up(): void
    {
        Schema::table('airline_transactions', function (Blueprint $table) {
            $table->foreignId('flight_carrier_id')
                ->nullable()
                ->after('airline_account_id')
                ->constrained('flight_carriers')
                ->nullOnDelete();
        });

        Schema::table('airline_transactions', function (Blueprint $table) {
            $table->dropForeign(['airline_account_id']);
        });

        Schema::table('airline_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('airline_account_id')->nullable()->change();
            $table->foreign('airline_account_id')
                ->references('id')
                ->on('airline_accounts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('airline_transactions', function (Blueprint $table) {
            $table->dropForeign(['flight_carrier_id']);
            $table->dropColumn('flight_carrier_id');
        });

        Schema::table('airline_transactions', function (Blueprint $table) {
            $table->dropForeign(['airline_account_id']);
        });

        Schema::table('airline_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('airline_account_id')->nullable(false)->change();
            $table->foreign('airline_account_id')
                ->references('id')
                ->on('airline_accounts')
                ->cascadeOnDelete();
        });
    }
};
