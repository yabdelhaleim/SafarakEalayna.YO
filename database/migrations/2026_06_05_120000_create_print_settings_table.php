<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name_ar')->nullable();
            $table->string('company_name_en')->nullable();
            $table->text('address')->nullable();
            $table->text('phones')->nullable();
            $table->string('finance_label')->default('المالية والمحاسب');
            $table->boolean('show_amount_due')->default(true);
            $table->json('modules')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_settings');
    }
};
