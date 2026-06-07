<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('amount_paid', 15, 2)->nullable()->after('total_amount');
        });

        // Set amount_paid to total_amount for all existing records so they are considered fully paid
        DB::table('wallet_transactions')->update([
            'amount_paid' => DB::raw('total_amount'),
        ]);

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('amount_paid', 15, 2)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });
    }
};
