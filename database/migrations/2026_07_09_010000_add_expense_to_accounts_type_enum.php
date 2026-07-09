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
        DB::statement("
            ALTER TABLE accounts
            MODIFY COLUMN type
            ENUM('cashbox', 'wallet', 'bank', 'treasury', 'expense') NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE accounts
            MODIFY COLUMN type
            ENUM('cashbox', 'wallet', 'bank', 'treasury') NOT NULL
        ");
    }
};
