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
        Schema::table('passengers', function (Blueprint $table) {
            // إضافة حقول الأسماء بالإنجليزي
            $table->string('first_name_en')->nullable()->after('first_name');
            $table->string('last_name_en')->nullable()->after('last_name');

            // إضافة حقول العمر والتاريخ للمساعدة في التصنيف
            $table->date('birth_date')->nullable()->after('date_of_birth');

            // إIndexes
            $table->index('birth_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->dropIndex(['birth_date']);
            $table->dropColumn(['first_name_en', 'last_name_en', 'birth_date']);
        });
    }
};
