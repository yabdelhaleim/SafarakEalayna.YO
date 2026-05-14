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
        Schema::table('accounts', function (Blueprint $table) {
            // إضافة حقول إضافية لتصنيف الخزنة بدقة
            $table->string('treasury_type')->nullable()->after('type'); // cash_egp, cash_kwd, bank_egypt_airport_egp, etc.
            $table->string('bank_name')->nullable()->after('treasury_type'); // بنك مصر، الأهلي، سفرك علينا
            $table->string('account_number')->nullable()->after('bank_name'); // رقم الحساب
            $table->string('branch_name')->nullable()->after('account_number'); // اسم الفرع

            //Indexes
            $table->index(['type', 'currency', 'treasury_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['type', 'currency', 'treasury_type']);
            $table->dropColumn(['treasury_type', 'bank_name', 'account_number', 'branch_name']);
        });
    }
};
