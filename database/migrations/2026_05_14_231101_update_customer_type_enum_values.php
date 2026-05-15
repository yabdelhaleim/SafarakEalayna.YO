<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, update any existing records if necessary, but here we just want to expand the ENUM
        DB::statement("ALTER TABLE customers MODIFY COLUMN type ENUM('individual', 'company', 'regular', 'counter') DEFAULT 'individual'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE customers MODIFY COLUMN type ENUM('regular', 'counter') DEFAULT 'regular'");
    }
};
