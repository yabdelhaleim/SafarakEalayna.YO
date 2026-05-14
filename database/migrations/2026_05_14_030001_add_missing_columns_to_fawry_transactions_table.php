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
        Schema::table('fawry_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('fawry_transactions', 'client_id')) {
                $table->foreignId('client_id')->nullable()->constrained('customers')->nullOnDelete();
            }
            if (!Schema::hasColumn('fawry_transactions', 'currency_id')) {
                $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            }
            if (!Schema::hasColumn('fawry_transactions', 'payment_details')) {
                $table->json('payment_details')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fawry_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('fawry_transactions', 'payment_details')) {
                $table->dropColumn('payment_details');
            }
            if (Schema::hasColumn('fawry_transactions', 'currency_id')) {
                $table->dropForeign(['currency_id']);
                $table->dropColumn('currency_id');
            }
            if (Schema::hasColumn('fawry_transactions', 'client_id')) {
                $table->dropForeign(['client_id']);
                $table->dropColumn('client_id');
            }
        });
    }
};
