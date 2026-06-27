<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            // وقت تسجيل السفر الفعلي — null = لم يسافر بعد
            $table->timestamp('traveled_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->dropColumn('traveled_at');
        });
    }
};
