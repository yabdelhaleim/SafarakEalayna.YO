<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fawry_machines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // e.g. fawry, aman, momtaz
            $table->decimal('balance', 12, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fawry_machines');
    }
};
