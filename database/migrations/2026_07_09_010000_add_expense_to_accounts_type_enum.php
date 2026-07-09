<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5: Add 'expense' to accounts.type enum
     *
     * السبب: Phase 3b v3 write-off محتاج account نوع 'expense' لتسجيل
     *        الخسائر في الـ P&L. الـ enum القديم فيه بس:
     *        ['cashbox', 'wallet', 'bank', 'treasury']
     *
     * @see App\Services\Flight\Phase3bV3WriteoffService
     */
    public function up(): void
    {
        // ⚠️ IMPORTANT: لازم تضم كل القيم الموجودة فعلاً في الـ DB + 'expense'
        // القيم الفعلية (من query على staging 2026-07-09):
        //   bank: 5, cashbox: 17, customer: 23, owner: 1, supplier: 12, wallet: 3
        // (مش treasury — حد غيّر الـ enum قبل كده)
        DB::statement("
            ALTER TABLE accounts
            MODIFY COLUMN type
            ENUM('bank', 'cashbox', 'customer', 'owner', 'supplier', 'wallet', 'expense') NOT NULL
        ");
    }

    public function down(): void
    {
        // Rollback: يرجع الـ enum للوضع الأصلي (بدون expense)
        DB::statement("
            ALTER TABLE accounts
            MODIFY COLUMN type
            ENUM('bank', 'cashbox', 'customer', 'owner', 'supplier', 'wallet') NOT NULL
        ");
    }
};
