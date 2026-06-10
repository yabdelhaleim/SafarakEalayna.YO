<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('online_transactions');
        Schema::dropIfExists('online_service_types');
        Schema::dropIfExists('online_services');

        Schema::create('online_service_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('color', 20)->default('#6B7280');
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('order');
        });

        Schema::create('online_service_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('color', 20)->default('#6B7280');
            $table->string('icon')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_account')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('default_purchase_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('order');
            $table->index('default_purchase_account_id');
        });

        Schema::create('online_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_type_id')->constrained('online_service_types')->cascadeOnUpdate();
            $table->foreignId('provider_id')->nullable()->constrained('online_service_providers')->nullOnDelete();

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_country')->nullable();

            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->decimal('profit', 12, 2)->default(0);

            $table->string('payment_method', 80);
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnUpdate();
            $table->string('reference_number')->nullable();

            $table->foreignId('expense_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('income_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->enum('status', ['pending', 'completed', 'failed'])->default('completed');
            $table->text('failure_reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('service_type_id');
            $table->index('provider_id');
            $table->index('customer_id');
            $table->index('employee_id');
            $table->index('payment_method');
            $table->index('account_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_transactions');
        Schema::dropIfExists('online_service_providers');
        Schema::dropIfExists('online_service_types');
    }
};
