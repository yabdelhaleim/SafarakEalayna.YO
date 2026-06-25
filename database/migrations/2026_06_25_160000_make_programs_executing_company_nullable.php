<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Filament يستخدم executing_company_id؛ العمود النصي القديم كان NOT NULL.
     */
    public function up(): void
    {
        if (! Schema::hasTable('programs') || ! Schema::hasColumn('programs', 'executing_company')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `programs` MODIFY `executing_company` VARCHAR(255) NULL');
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE programs ALTER COLUMN executing_company DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('programs') || ! Schema::hasColumn('programs', 'executing_company')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("UPDATE `programs` SET `executing_company` = 'غير محدد' WHERE `executing_company` IS NULL");
            DB::statement('ALTER TABLE `programs` MODIFY `executing_company` VARCHAR(255) NOT NULL');
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE programs ALTER COLUMN executing_company SET NOT NULL');
        }
    }
};
