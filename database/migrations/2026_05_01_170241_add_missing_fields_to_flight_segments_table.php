<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('flight_segments', function (Blueprint $table) {
            // Add missing columns that are used in the code
            $table->string('flight_class')->default('economy')->after('baggage');
            $table->integer('duration_minutes')->nullable()->after('flight_class');
            $table->boolean('is_stop')->default(false)->after('duration_minutes');
            $table->integer('stop_duration_minutes')->nullable()->after('is_stop');
            $table->text('notes')->nullable()->after('stop_duration_minutes');

            // Add index for frequently queried fields
            $table->index('flight_class');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_segments', function (Blueprint $table) {
            $table->dropIndex(['flight_class']);
            $table->dropColumn(['flight_class', 'duration_minutes', 'is_stop', 'stop_duration_minutes', 'notes']);
        });
    }
};
