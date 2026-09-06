<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Corrects legacy Wallet Receive settlement transactions that were recorded
     * with type='expense' instead of type='transfer'. Paying cash to the customer
     * (or destination account) upon receiving a wallet transfer is a balance-sheet
     * settlement, NOT an operating expense. Storing them as type='expense' caused
     * GL P&L reports to double-deduct the cost (once as COGS and once as operating_expense).
     */
    public function up(): void
    {
        DB::table('transactions')
            ->where('module', 'wallet')
            ->where('related_type', 'App\\Models\\Wallet\\WalletTransaction')
            ->where('type', 'expense')
            ->where(function ($query) {
                $query->where('notes', 'like', 'استقبال %: دفعة نقدية مسددة%')
                    ->orWhere('notes', 'like', 'دفعة نقدية مسددة للعميل%')
                    ->orWhere('notes', 'like', 'إعادة تسجيل دفعة نقدية مسددة إلى حساب الاستقبال%');
            })
            ->update([
                'type' => 'transfer',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('transactions')
            ->where('module', 'wallet')
            ->where('related_type', 'App\\Models\\Wallet\\WalletTransaction')
            ->where('type', 'transfer')
            ->where(function ($query) {
                $query->where('notes', 'like', 'استقبال %: دفعة نقدية مسددة%')
                    ->orWhere('notes', 'like', 'دفعة نقدية مسددة للعميل%')
                    ->orWhere('notes', 'like', 'إعادة تسجيل دفعة نقدية مسددة إلى حساب الاستقبال%');
            })
            ->update([
                'type' => 'expense',
                'updated_at' => now(),
            ]);
    }
};
