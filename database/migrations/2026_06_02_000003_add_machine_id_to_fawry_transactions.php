<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fawry_transactions', function (Blueprint $table) {
            $table->foreignId('fawry_machine_id')->nullable()->constrained('fawry_machines')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('fawry_transactions', function (Blueprint $table) {
            $table->dropForeign(['fawry_machine_id']);
            $table->dropColumn('fawry_machine_id');
        });
    }
};
