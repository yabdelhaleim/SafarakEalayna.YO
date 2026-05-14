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
        Schema::table('flight_payments', function (Blueprint $table) {
            // حقول إضافية للتحويل البنكي
            $table->string('bank_name')->nullable()->after('payment_method');
            $table->string('account_holder_name')->nullable()->after('bank_name');

            // حقول إضافية للمحفظة
            $table->string('wallet_number')->nullable()->after('account_holder_name');
            $table->string('wallet_holder')->nullable()->after('wallet_number');

            // حقول إضافية للبريد
            $table->string('postal_office')->nullable()->after('wallet_holder');

            // ربط بحساب الخزنة المحدد
            $table->foreignId('treasury_account_id')->nullable()->constrained('accounts')->onDelete('set null')->after('amount');

            //Indexes
            $table->index('treasury_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_payments', function (Blueprint $table) {
            $table->dropIndex(['treasury_account_id']);
            $table->dropForeign(['treasury_account_id']);
            $table->dropColumn([
                'bank_name',
                'account_holder_name',
                'wallet_number',
                'wallet_holder',
                'postal_office',
                'treasury_account_id'
            ]);
        });
    }
};
