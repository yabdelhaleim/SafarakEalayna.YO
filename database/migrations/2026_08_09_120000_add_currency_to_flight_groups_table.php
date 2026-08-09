<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * عملة المجموعة (EGP افتراضيًا) لتُستخدم في تسعير حجوزات المجموعة
     * مستقلة عن عملة الناقل المرتبط (`flight_carrier_id`).
     */
    public function up(): void
    {
        Schema::table('flight_groups', function (Blueprint $table) {
            $table->string('currency', 3)
                ->default('EGP')
                ->after('flight_carrier_id');
        });
    }

    public function down(): void
    {
        Schema::table('flight_groups', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
