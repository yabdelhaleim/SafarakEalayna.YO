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
            if (! Schema::hasColumn('accounts', 'treasury_type')) {
                $table->string('treasury_type')->nullable()->after('type'); // cash_egp, cash_kwd, bank_egypt_airport_egp, etc.
            }
            if (! Schema::hasColumn('accounts', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('treasury_type'); // بنك مصر، الأهلي، سفرك علينا
            }
            if (! Schema::hasColumn('accounts', 'account_number')) {
                $table->string('account_number')->nullable()->after('bank_name'); // رقم الحساب
            }
            if (! Schema::hasColumn('accounts', 'branch_name')) {
                $table->string('branch_name')->nullable()->after('account_number'); // اسم الفرع
            }

            // Indexes - add only if not exists
            $indexes = Schema::getIndexes('accounts');
            $indexNames = array_column($indexes, 'name');
            if (! in_array('accounts_type_currency_treasury_type_index', $indexNames)) {
                $table->index(['type', 'currency', 'treasury_type']);
            }
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
