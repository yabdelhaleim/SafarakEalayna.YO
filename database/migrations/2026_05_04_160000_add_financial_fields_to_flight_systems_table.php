<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_systems', function (Blueprint $table) {
            $table->string('currency', 3)->default('KWD')->after('is_active');
            $table->decimal('balance', 15, 2)->default(0)->after('currency');
            $table->decimal('credit_limit', 15, 2)->default(0)->after('balance');

            $table->index('currency');
        });
    }

    public function down(): void
    {
        Schema::table('flight_systems', function (Blueprint $table) {
            $table->dropIndex(['currency']);
            $table->dropColumn(['currency', 'balance', 'credit_limit']);
        });
    }
};
