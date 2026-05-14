<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // جدول أنواع عمليات فوري
        Schema::create('fawry_operation_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('color', 20)->default('#6B7280');
            $table->string('icon')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('order');
        });

        // جدول طرق دفع فوري مع تفاصيل إضافية
        Schema::create('fawry_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('color', 20)->default('#6B7280');
            $table->string('icon')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            // تفاصيل إضافية لطريقة الدفع
            $table->string('provider_name')->nullable(); // اسم المزود (مثل: فوري، كاش، إلخ)
            $table->string('account_number')->nullable(); // رقم الحساب
            $table->string('phone_number')->nullable(); // رقم المحفظة
            $table->string('bank_name')->nullable(); // اسم البنك
            $table->string('branch_name')->nullable(); // اسم الفرع
            $table->json('metadata')->nullable(); // بيانات إضافية (JSON)

            // الربط بحساب مالي افتراضي
            $table->foreignId('default_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('order');
            $table->index('default_account_id');
        });

        // جدول عملات فوري (يمكن استخدامه لإعدادات خاصة بفوري)
        Schema::create('fawry_currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained()->cascadeOnDelete();

            // إعدادات خاصة بفوري لهذه العملة
            $table->decimal('exchange_rate', 12, 4)->default(1.0); // سعر الصرف الخاص بفوري
            $table->decimal('min_amount', 12, 2)->nullable(); // الحد الأدنى للمعاملة
            $table->decimal('max_amount', 12, 2)->nullable(); // الحد الأقصى للمعاملة
            $table->decimal('fee_percent', 5, 2)->default(0); // نسبة الرسم
            $table->decimal('fixed_fee', 12, 2)->default(0); // رسم ثابت

            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index('currency_id');
            $table->index('is_active');
        });

        // بيانات افتراضية لأنواع العمليات
        DB::table('fawry_operation_types')->insert([
            [
                'code' => 'withdrawal',
                'name_ar' => 'سحب',
                'name_en' => 'Withdrawal',
                'color' => '#EF4444',
                'icon' => 'arrow-up-circle',
                'description_ar' => 'عمليات سحب فوري',
                'description_en' => 'Fawry withdrawal transactions',
                'is_active' => true,
                'order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'deposit',
                'name_ar' => 'إيداع',
                'name_en' => 'Deposit',
                'color' => '#10B981',
                'icon' => 'arrow-down-circle',
                'description_ar' => 'عمليات إيداع فوري',
                'description_en' => 'Fawry deposit transactions',
                'is_active' => true,
                'order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'payment',
                'name_ar' => 'سداد',
                'name_en' => 'Payment',
                'color' => '#3B82F6',
                'icon' => 'credit-card',
                'description_ar' => 'عمليات سداد فوري',
                'description_en' => 'Fawry payment transactions',
                'is_active' => true,
                'order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'travel_permit',
                'name_ar' => 'تصريح سفر',
                'name_en' => 'Travel Permit',
                'color' => '#F59E0B',
                'icon' => 'plane',
                'description_ar' => 'عمليات تصريح سفر عبر فوري',
                'description_en' => 'Fawry travel permit transactions',
                'is_active' => true,
                'order' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // بيانات افتراضية لطرق الدفع
        DB::table('fawry_payment_methods')->insert([
            [
                'code' => 'cash',
                'name_ar' => 'نقدي',
                'name_en' => 'Cash',
                'color' => '#10B981',
                'icon' => 'banknote',
                'description_ar' => 'دفع نقدي',
                'description_en' => 'Cash payment',
                'is_active' => true,
                'order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'bank_transfer',
                'name_ar' => 'تحويل بنكي',
                'name_en' => 'Bank Transfer',
                'color' => '#3B82F6',
                'icon' => 'building-bank',
                'description_ar' => 'تحويل بنكي',
                'description_en' => 'Bank transfer',
                'is_active' => true,
                'order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'cash_wallet',
                'name_ar' => 'محفظة كاش',
                'name_en' => 'Cash Wallet',
                'color' => '#8B5CF6',
                'icon' => 'wallet',
                'description_ar' => 'محفظة كاش',
                'description_en' => 'Cash wallet payment',
                'is_active' => true,
                'order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'office_safe',
                'name_ar' => 'خزينة المكتب',
                'name_en' => 'Office Safe',
                'color' => '#F59E0B',
                'icon' => 'safe',
                'description_ar' => 'خزينة المكتب',
                'description_en' => 'Office safe',
                'is_active' => true,
                'order' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'office_drawer',
                'name_ar' => 'درج المكتب',
                'name_en' => 'Office Drawer',
                'color' => '#6B7280',
                'icon' => 'archive',
                'description_ar' => 'درج المكتب',
                'description_en' => 'Office drawer',
                'is_active' => true,
                'order' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fawry_currencies');
        Schema::dropIfExists('fawry_payment_methods');
        Schema::dropIfExists('fawry_operation_types');
    }
};
