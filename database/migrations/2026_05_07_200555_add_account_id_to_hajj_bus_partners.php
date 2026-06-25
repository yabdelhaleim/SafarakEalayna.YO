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
        if (Schema::hasTable('bus_companies') && ! Schema::hasColumn('bus_companies', 'account_id')) {
            Schema::table('bus_companies', function (Blueprint $table) {
                $table->foreignId('account_id')->nullable()->after('phone')->constrained('accounts')->nullOnDelete();
            });
        }

        if (Schema::hasTable('hajj_umra_executing_companies') && ! Schema::hasColumn('hajj_umra_executing_companies', 'account_id')) {
            Schema::table('hajj_umra_executing_companies', function (Blueprint $table) {
                $table->foreignId('account_id')->nullable()->after('phone')->constrained('accounts')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bus_companies', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });

        Schema::table('hajj_umra_executing_companies', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};
