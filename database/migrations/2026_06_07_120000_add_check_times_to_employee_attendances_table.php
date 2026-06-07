<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->time('check_in')->nullable()->after('status');
            $table->time('check_out')->nullable()->after('check_in');
        });
    }

    public function down(): void
    {
        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->dropColumn(['check_in', 'check_out']);
        });
    }
};
