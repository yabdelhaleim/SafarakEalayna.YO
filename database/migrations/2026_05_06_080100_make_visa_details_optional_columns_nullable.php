<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visa_details', function (Blueprint $table) {
            $table->string('executing_company')->nullable()->change();
            $table->string('executing_agent')->nullable()->change();
            $table->string('duration')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('visa_details', function (Blueprint $table) {
            $table->string('executing_company')->nullable(false)->change();
            $table->string('executing_agent')->nullable(false)->change();
            $table->string('duration')->nullable(false)->change();
        });
    }
};
