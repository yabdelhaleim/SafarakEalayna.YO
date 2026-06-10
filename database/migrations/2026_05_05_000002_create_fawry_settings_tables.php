<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    }

    public function down(): void
    {
        Schema::dropIfExists('fawry_currencies');
        Schema::dropIfExists('fawry_payment_methods');
        Schema::dropIfExists('fawry_operation_types');
    }
};
