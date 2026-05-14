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
        Schema::table('employees', function (Blueprint $table) {
            // المعلومات الشخصية
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('national_id', 20)->nullable();
            $table->string('nationality')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();

            // معلومات التوظيف
            $table->date('hire_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->string('job_title')->nullable();
            $table->enum('employment_type', ['full_time', 'part_time', 'contract', 'temporary'])->default('full_time');
            $table->enum('employment_status', ['active', 'on_leave', 'terminated', 'resigned'])->default('active');

            // معلومات مالية
            $table->string('bank_account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('iban')->nullable();

            // معلومات الطوارئ
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();

            // الأداء
            $table->enum('performance_rating', ['excellent', 'good', 'average', 'poor'])->nullable();

            // المستندات
            $table->string('contract_path')->nullable();

            // Indexes
            $table->index('employment_status');
            $table->index('department');
            $table->index('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['employment_status']);
            $table->dropIndex(['department']);
            $table->dropIndex(['position']);
            $table->dropColumn(['first_name']);
            $table->dropColumn(['last_name']);
            $table->dropColumn(['full_name']);
            $table->dropColumn(['national_id']);
            $table->dropColumn(['nationality']);
            $table->dropColumn(['date_of_birth']);
            $table->dropColumn(['gender']);
            $table->dropColumn(['phone']);
            $table->dropColumn(['address']);
            $table->dropColumn(['city']);
            $table->dropColumn(['country']);
            $table->dropColumn(['hire_date']);
            $table->dropColumn(['termination_date']);
            $table->dropColumn(['position']);
            $table->dropColumn(['department']);
            $table->dropColumn(['job_title']);
            $table->dropColumn(['employment_type']);
            $table->dropColumn(['employment_status']);
            $table->dropColumn(['bank_account_number']);
            $table->dropColumn(['bank_name']);
            $table->dropColumn(['iban']);
            $table->dropColumn(['emergency_contact_name']);
            $table->dropColumn(['emergency_contact_phone']);
            $table->dropColumn(['performance_rating']);
            $table->dropColumn(['contract_path']);
        });
    }
};
