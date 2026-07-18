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
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE accounts
                MODIFY COLUMN type
                ENUM('bank', 'cashbox', 'customer', 'owner', 'supplier', 'wallet', 'expense') NOT NULL
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // Rollback: يرجع الـ enum للوضع الأصلي (بدون expense)
            DB::statement("
                ALTER TABLE accounts
                MODIFY COLUMN type
                ENUM('bank', 'cashbox', 'customer', 'owner', 'supplier', 'wallet') NOT NULL
            ");
        }
    }
};
