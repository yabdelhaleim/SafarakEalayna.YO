<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_transactions', function (Blueprint $table) {
            $table->decimal('amount_paid', 12, 2)->nullable()->after('selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('online_transactions', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });
    }
};
