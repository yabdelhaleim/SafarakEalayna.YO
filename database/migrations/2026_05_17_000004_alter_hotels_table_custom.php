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
        Schema::table('hotels', function (Blueprint $table) {
            if (!Schema::hasColumn('hotels', 'country')) {
                $table->string('country')->nullable();
            }
            if (!Schema::hasColumn('hotels', 'stars')) {
                $table->tinyInteger('stars')->default(3);
            }
            if (!Schema::hasColumn('hotels', 'price_per_night')) {
                $table->decimal('price_per_night', 10, 2)->default(0);
            }
            if (!Schema::hasColumn('hotels', 'total_rooms')) {
                $table->integer('total_rooms')->default(0);
            }
            if (!Schema::hasColumn('hotels', 'available_rooms')) {
                $table->integer('available_rooms')->default(0);
            }
            if (!Schema::hasColumn('hotels', 'contact_phone')) {
                $table->string('contact_phone')->nullable();
            }
            if (!Schema::hasColumn('hotels', 'contact_email')) {
                $table->string('contact_email')->nullable();
            }
            if (!Schema::hasColumn('hotels', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('hotels', 'amenities')) {
                $table->json('amenities')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn([
                'country',
                'stars',
                'price_per_night',
                'total_rooms',
                'available_rooms',
                'contact_phone',
                'contact_email',
                'description',
                'amenities',
            ]);
        });
    }
};
