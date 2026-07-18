<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds 'cancelled' as a valid status value for online_transactions.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `online_transactions` MODIFY COLUMN `status` ENUM('pending', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'completed'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("UPDATE `online_transactions` SET `status` = 'failed' WHERE `status` = 'cancelled'");
            DB::statement("ALTER TABLE `online_transactions` MODIFY COLUMN `status` ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'completed'");
        }
    }
};
