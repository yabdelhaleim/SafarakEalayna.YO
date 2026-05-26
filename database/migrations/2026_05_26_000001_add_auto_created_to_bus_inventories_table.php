<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bus_inventories', function (Blueprint $table) {
            // Flag to distinguish auto-created inventories (from Vue frontend)
            // vs manually curated ones (from Filament admin)
            $table->boolean('is_auto_created')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('bus_inventories', function (Blueprint $table) {
            $table->dropColumn('is_auto_created');
        });
    }
};
