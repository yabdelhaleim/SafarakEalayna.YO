<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_settings', function (Blueprint $table) {
            $table->decimal('office_base_capital', 15, 2)->default(0.00)->after('base_capital');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_settings', function (Blueprint $table) {
            $table->dropColumn('office_base_capital');
        });
    }
};
