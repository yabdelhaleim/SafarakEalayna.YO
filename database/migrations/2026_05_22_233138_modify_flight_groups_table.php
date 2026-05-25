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
        Schema::table('flight_groups', function (Blueprint $table) {
            $table->foreignId('flight_carrier_id')->nullable()->change();
            $table->foreignId('account_id')->nullable()->after('flight_carrier_id')->constrained('accounts')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_groups', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
            $table->foreignId('flight_carrier_id')->nullable(false)->change();
        });
    }
};
