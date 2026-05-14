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
        Schema::create('visa_details', function (Blueprint $table) {
            $table->id();
            $table->string('visa_type'); // TOURIST, BUSINESS, VISIT, etc.
            $table->string('country');
            $table->string('duration');
            $table->string('entry_type'); // SINGLE, MULTIPLE, TRIPLE
            $table->date('validity_from')->nullable();
            $table->date('validity_to')->nullable();
            $table->string('executing_company');
            $table->string('executing_agent');
            $table->string('executing_agent_contact')->nullable();
            $table->date('submission_date')->nullable();
            $table->date('expected_result_date')->nullable();
            $table->string('visa_number')->nullable();
            $table->string('status'); // DRAFT, SUBMITTED, UNDER_REVIEW, APPROVED, REJECTED, CANCELLED
            $table->timestamps();
            $table->softDeletes();

            $table->index('visa_type');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visa_details');
    }
};