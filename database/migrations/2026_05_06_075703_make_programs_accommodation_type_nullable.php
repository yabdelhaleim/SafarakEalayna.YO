<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Filament يعتمد على accommodation_type_id؛ العمود النصي القديم accommodation_type كان NOT NULL بدون قيمة افتراضية.
     */
    public function up(): void
    {
        if (! Schema::hasTable('programs') || ! Schema::hasColumn('programs', 'accommodation_type')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `programs` MODIFY `accommodation_type` VARCHAR(255) NULL');
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE programs ALTER COLUMN accommodation_type DROP NOT NULL');
        }

        // SQLite: لا يدعم MODIFY بسهولة؛ الاختبارات تعتمد غالباً على Program::factory أو حقل مُعبأ من النموذج.
    }

    public function down(): void
    {
        if (! Schema::hasTable('programs') || ! Schema::hasColumn('programs', 'accommodation_type')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('UPDATE `programs` SET `accommodation_type` = \'\' WHERE `accommodation_type` IS NULL');
            DB::statement('ALTER TABLE `programs` MODIFY `accommodation_type` VARCHAR(255) NOT NULL');
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE programs ALTER COLUMN accommodation_type SET NOT NULL');
        }
    }
};
