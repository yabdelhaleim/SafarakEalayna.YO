<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `accounts` MODIFY COLUMN `type` ENUM('cashbox','wallet','bank','treasury','customer','supplier') NOT NULL DEFAULT 'cashbox'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `accounts` MODIFY COLUMN `type` ENUM('cashbox','wallet','bank','treasury') NOT NULL DEFAULT 'cashbox'");
    }
};
