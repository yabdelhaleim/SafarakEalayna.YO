<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fawry_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('fawry_transactions', 'account_id')) {
                $table->foreignId('account_id')->nullable()->constrained('accounts');
            }
            if (! Schema::hasColumn('fawry_transactions', 'expense_transaction_id')) {
                $table->foreignId('expense_transaction_id')->nullable()->constrained('transactions');
            }
            if (! Schema::hasColumn('fawry_transactions', 'income_transaction_id')) {
                $table->foreignId('income_transaction_id')->nullable()->constrained('transactions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fawry_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('fawry_transactions', 'income_transaction_id')) {
                $table->dropForeign(['income_transaction_id']);
                $table->dropColumn('income_transaction_id');
            }
            if (Schema::hasColumn('fawry_transactions', 'expense_transaction_id')) {
                $table->dropForeign(['expense_transaction_id']);
                $table->dropColumn('expense_transaction_id');
            }
            if (Schema::hasColumn('fawry_transactions', 'account_id')) {
                $table->dropForeign(['account_id']);
                $table->dropColumn('account_id');
            }
        });
    }
};
