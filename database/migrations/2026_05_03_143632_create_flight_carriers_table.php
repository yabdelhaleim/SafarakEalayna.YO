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
        Schema::create('flight_carriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_system_id')->constrained()->onDelete('cascade');
            $table->string('name'); // الجزيرة، العربية، نسما، أبو كابيرو، اير كايرو
            $table->string('code')->unique(); // JZ, SV, NS, ABK, MSR
            $table->string('iata_code')->nullable(); // KWI, JED, CAI, etc.
            $table->string('currency')->default('KWD'); // KWD, SAR, EGP, etc.
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->index('flight_system_id');
            $table->index('is_active');
            $table->index('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_carriers');
    }
};
