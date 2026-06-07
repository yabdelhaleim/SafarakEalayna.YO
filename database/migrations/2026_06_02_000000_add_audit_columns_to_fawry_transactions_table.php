<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fawry_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('fawry_transactions', 'created_by_user_id')) {
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('fawry_transactions', 'updated_by_user_id')) {
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('fawry_transactions', 'client_ip')) {
                $table->string('client_ip', 45)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fawry_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('fawry_transactions', 'client_ip')) {
                $table->dropColumn('client_ip');
            }
            if (Schema::hasColumn('fawry_transactions', 'updated_by_user_id')) {
                $table->dropForeign(['updated_by_user_id']);
                $table->dropColumn('updated_by_user_id');
            }
            if (Schema::hasColumn('fawry_transactions', 'created_by_user_id')) {
                $table->dropForeign(['created_by_user_id']);
                $table->dropColumn('created_by_user_id');
            }
        });
    }
};
