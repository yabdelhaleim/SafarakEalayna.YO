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
        Schema::create('airports', function (Blueprint $table) {
            $table->id();
            $table->string('iata_code', 4)->unique(); // CAI, JED, KWI, DXB, etc.
            $table->string('icao_code', 4)->nullable(); // HECA, OEJN, OKBK, etc.
            $table->string('city_name_ar'); // القاهرة، جدة، الكويت
            $table->string('city_name_en'); // Cairo, Jeddah, Kuwait
            $table->string('airport_name_ar'); // مطار القاهرة الدولي
            $table->string('airport_name_en'); // Cairo International Airport
            $table->string('country_code', 2); // EG, SA, KW, AE
            $table->string('country_name_ar'); // مصر، السعودية، الكويت
            $table->string('country_name_en'); // Egypt, Saudi Arabia, Kuwait
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('timezone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('iata_code');
            $table->index('city_name_ar');
            $table->index('city_name_en');
            $table->index('country_code');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airports');
    }
};
