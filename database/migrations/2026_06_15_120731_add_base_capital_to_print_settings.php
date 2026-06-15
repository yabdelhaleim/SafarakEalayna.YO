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
        Schema::table('print_settings', function (Blueprint $table) {
            $table->decimal('base_capital', 15, 2)->default(1000000.00)->after('show_amount_due');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_settings', function (Blueprint $table) {
            $table->dropColumn('base_capital');
        });
    }
};
