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
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->string('flight_number')->unique();
            $table->string('airline');
            $table->string('origin');         // مدينة المغادرة
            $table->string('destination');    // مدينة الوصول
            $table->datetime('departure_at');
            $table->datetime('arrival_at');
            $table->enum('class', ['economy', 'business', 'first']);
            $table->integer('total_seats');
            $table->integer('available_seats');
            $table->decimal('base_price', 10, 2);
            $table->decimal('tax_percent', 5, 2)->default(14);
            $table->enum('status', ['scheduled', 'boarding', 'departed', 'arrived', 'cancelled'])->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['origin', 'destination']);
            $table->index('departure_at');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flights');
    }
};
