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
        Schema::create('flight_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_carrier_id')->constrained()->onDelete('cascade');
            $table->string('name'); // الشعلة، فرياج، العلا، المهاجر، لوجانو
            $table->string('code')->unique(); // SHA, VOY, ALA, MUH, LUG
            $table->string('contact_person')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->decimal('commission_rate', 5, 2)->default(0); // نسبة العمولة
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->index('flight_carrier_id');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_groups');
    }
};
