<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        // Seed defaults so the module is usable out of the box
        $now = now();

        DB::table('online_service_types')->insert([
            [
                'code' => 'travel_permit',
                'name_ar' => 'تصريح سفر',
                'name_en' => 'Travel Permit',
                'description_ar' => 'إصدار / تجديد تصاريح السفر',
                'description_en' => 'Issue / renew travel permits',
                'color' => '#F59E0B',
                'icon' => 'heroicon-o-identification',
                'is_active' => true,
                'order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'license_renewal',
                'name_ar' => 'تجديد رخصة',
                'name_en' => 'License Renewal',
                'description_ar' => 'تجديد الرخص المرورية والمدنية',
                'description_en' => 'Traffic / civil license renewals',
                'color' => '#3B82F6',
                'icon' => 'heroicon-o-document-check',
                'is_active' => true,
                'order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'civil_records',
                'name_ar' => 'وثائق أحوال مدنية',
                'name_en' => 'Civil Records',
                'description_ar' => 'استخراج بطاقات / شهادات ميلاد / سجلات مدنية',
                'description_en' => 'Civil status records (IDs, birth certificates...)',
                'color' => '#A855F7',
                'icon' => 'heroicon-o-identification',
                'is_active' => true,
                'order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'utility_bill',
                'name_ar' => 'سداد فواتير',
                'name_en' => 'Utility Bills',
                'description_ar' => 'سداد فواتير الكهرباء / الغاز / المياه / التليفون',
                'description_en' => 'Utility bills payments',
                'color' => '#F97316',
                'icon' => 'heroicon-o-bolt',
                'is_active' => true,
                'order' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('online_service_providers')->insert([
            [
                'code' => 'fawry',
                'name_ar' => 'فوري',
                'name_en' => 'Fawry',
                'description_ar' => 'مزود خدمات فوري للدفع الإلكتروني',
                'description_en' => 'Fawry electronic payments provider',
                'color' => '#F59E0B',
                'icon' => 'heroicon-o-bolt',
                'is_active' => true,
                'order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'cash',
                'name_ar' => 'كاش',
                'name_en' => 'Cash',
                'description_ar' => 'خدمات يتم تنفيذها نقدًا (كاش ياسر / مزودين آخرين)',
                'description_en' => 'Cash-handled services',
                'color' => '#10B981',
                'icon' => 'heroicon-o-banknotes',
                'is_active' => true,
                'order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'gov_portal',
                'name_ar' => 'بوابات حكومية',
                'name_en' => 'Government Portal',
                'description_ar' => 'البوابات الحكومية للخدمات الإلكترونية',
                'description_en' => 'Government e-services portals',
                'color' => '#3B82F6',
                'icon' => 'heroicon-o-building-library',
                'is_active' => true,
                'order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('online_transactions');
        Schema::dropIfExists('online_service_providers');
        Schema::dropIfExists('online_service_types');
    }
};
