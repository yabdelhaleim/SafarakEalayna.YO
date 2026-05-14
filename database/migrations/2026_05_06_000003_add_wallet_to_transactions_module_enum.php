<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `transactions` MODIFY `module` ENUM('flight','bus','service','fawry','online','hajj_umra','visa','wallet','general')");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `transactions` MODIFY `module` ENUM('flight','bus','fawry','online','hajj_umra','visa','general')");
    }
};
