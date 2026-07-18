<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 4: Add 'writeoff' to transactions.type ENUM
     *
     * السبب: Phase 3b v3 write-off بيستخدم 'type' = 'writeoff' للـ transaction
     *        الـ ENUM الحالي فيه بس: ['income', 'expense', 'transfer', 'refund']
     *
     * @see App\Enums\TransactionType
     * @see phase3b_v3_writeoff_7desyncs.php
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE transactions
                MODIFY COLUMN type
                ENUM('income', 'expense', 'transfer', 'refund', 'writeoff') NOT NULL
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // Rollback: يرجع الـ enum للوضع الأصلي (بدون writeoff)
            DB::statement("
                ALTER TABLE transactions
                MODIFY COLUMN type
                ENUM('income', 'expense', 'transfer', 'refund') NOT NULL
            ");
        }
    }
};
