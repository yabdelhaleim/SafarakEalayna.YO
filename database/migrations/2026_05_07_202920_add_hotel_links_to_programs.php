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
        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('mecca_hotel_id')->nullable()->after('mecca_hotel_name')->constrained('hotels')->nullOnDelete();
            $table->foreignId('medina_hotel_id')->nullable()->after('medina_hotel_name')->constrained('hotels')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropForeign(['mecca_hotel_id']);
            $table->dropForeign(['medina_hotel_id']);
            $table->dropColumn(['mecca_hotel_id', 'medina_hotel_id']);
        });
    }
};
