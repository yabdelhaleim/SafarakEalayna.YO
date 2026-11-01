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
        if (Schema::hasTable('transactions') && ! Schema::hasColumn('transactions', 'deleted_at')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->softDeletes()->after('updated_at');
            });
        }

        if (Schema::hasTable('transfers') && ! Schema::hasColumn('transfers', 'deleted_at')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->softDeletes()->after('updated_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'deleted_at')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('transfers') && Schema::hasColumn('transfers', 'deleted_at')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
