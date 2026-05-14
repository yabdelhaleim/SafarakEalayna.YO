<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Hajj/Umra bookings → ربط محاسبي + حقول مفقودة
        Schema::table('hajj_umra_bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('hajj_umra_bookings', 'account_id')) {
                $table->foreignId('account_id')->nullable()->after('notes')
                    ->constrained('accounts')->nullOnDelete();
            }
            if (!Schema::hasColumn('hajj_umra_bookings', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->after('account_id')
                    ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('hajj_umra_bookings', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('employee_id')
                    ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('hajj_umra_bookings', 'expense_transaction_id')) {
                $table->foreignId('expense_transaction_id')->nullable()->after('created_by')
                    ->constrained('transactions')->nullOnDelete();
            }
            if (!Schema::hasColumn('hajj_umra_bookings', 'income_transaction_id')) {
                $table->foreignId('income_transaction_id')->nullable()->after('expense_transaction_id')
                    ->constrained('transactions')->nullOnDelete();
            }
        });

        // 2) Visa bookings → نفس الربط المحاسبي
        Schema::table('visa_bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('visa_bookings', 'account_id')) {
                $table->foreignId('account_id')->nullable()->after('notes')
                    ->constrained('accounts')->nullOnDelete();
            }
            if (!Schema::hasColumn('visa_bookings', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->after('account_id')
                    ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('visa_bookings', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('employee_id')
                    ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('visa_bookings', 'expense_transaction_id')) {
                $table->foreignId('expense_transaction_id')->nullable()->after('created_by')
                    ->constrained('transactions')->nullOnDelete();
            }
            if (!Schema::hasColumn('visa_bookings', 'income_transaction_id')) {
                $table->foreignId('income_transaction_id')->nullable()->after('expense_transaction_id')
                    ->constrained('transactions')->nullOnDelete();
            }
        });

        // 3) Hajj/Umra payments → ربط بحساب وقيد محاسبي
        Schema::table('hajj_umra_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('hajj_umra_payments', 'account_id')) {
                $table->foreignId('account_id')->nullable()->after('hajj_umra_booking_id')
                    ->constrained('accounts')->nullOnDelete();
            }
            if (!Schema::hasColumn('hajj_umra_payments', 'transaction_id')) {
                $table->foreignId('transaction_id')->nullable()->after('account_id')
                    ->constrained('transactions')->nullOnDelete();
            }
            if (!Schema::hasColumn('hajj_umra_payments', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('paid_by')
                    ->constrained('users')->nullOnDelete();
            }
        });

        // 4) Visa payments → ربط بحساب وقيد محاسبي
        Schema::table('visa_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('visa_payments', 'account_id')) {
                $table->foreignId('account_id')->nullable()->after('visa_booking_id')
                    ->constrained('accounts')->nullOnDelete();
            }
            if (!Schema::hasColumn('visa_payments', 'transaction_id')) {
                $table->foreignId('transaction_id')->nullable()->after('account_id')
                    ->constrained('transactions')->nullOnDelete();
            }
            if (!Schema::hasColumn('visa_payments', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('paid_by')
                    ->constrained('users')->nullOnDelete();
            }
        });

        // 5) Programs → تثبيت برنامج تكلفة + ربط الموظف المنفذ
        Schema::table('programs', function (Blueprint $table) {
            if (!Schema::hasColumn('programs', 'default_purchase_price')) {
                $table->decimal('default_purchase_price', 15, 2)->nullable()->after('program_price_tier');
            }
            if (!Schema::hasColumn('programs', 'default_selling_price')) {
                $table->decimal('default_selling_price', 15, 2)->nullable()->after('default_purchase_price');
            }
            if (!Schema::hasColumn('programs', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('default_selling_price');
            }
        });

        // 6) جدول مرجعي: شركات التأشيرات / الوكلاء (Filament-managed)
        if (!Schema::hasTable('visa_agents')) {
            Schema::create('visa_agents', function (Blueprint $table) {
                $table->id();
                $table->string('company_name'); // الوكيل المنفذ (الشركة)
                $table->string('contact_person')->nullable(); // اسم الوكيل (شخص الاتصال)
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('country')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index('is_active');
            });
        }

        // 7) جدول مرجعي: شركات الحج المنفذة (مختلف عن وكلاء التأشيرات)
        if (!Schema::hasTable('hajj_umra_executing_companies')) {
            Schema::create('hajj_umra_executing_companies', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('license_number')->nullable();
                $table->string('phone')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 8) جدول مرجعي: مشرفو الرحلات
        if (!Schema::hasTable('trip_supervisors')) {
            Schema::create('trip_supervisors', function (Blueprint $table) {
                $table->id();
                $table->string('full_name');
                $table->string('phone')->nullable();
                $table->string('national_id')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 9) جدول مرجعي: أنواع التسكين (رباعي/ثلاثي/مزدوج/فردي ...)
        if (!Schema::hasTable('accommodation_types')) {
            Schema::create('accommodation_types', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique(); // single, double, triple, quad, quintuple
                $table->string('name_ar');
                $table->string('name_en')->nullable();
                $table->unsignedTinyInteger('capacity')->default(1);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 10) جدول مرجعي: مدد/أنواع الدخول للتأشيرة (سنة متعدد، شهر واحد، إلخ)
        if (!Schema::hasTable('visa_durations')) {
            Schema::create('visa_durations', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique(); // 1m_single, 1y_multiple, 6m_single, ...
                $table->string('label_ar');
                $table->string('label_en')->nullable();
                $table->unsignedSmallInteger('months')->nullable();
                $table->string('entry_type')->nullable(); // single|multiple|triple
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 11) ربط جداول الحجز بالقوائم المرجعية الجديدة
        Schema::table('programs', function (Blueprint $table) {
            if (!Schema::hasColumn('programs', 'executing_company_id')) {
                $table->foreignId('executing_company_id')->nullable()->after('executing_company')
                    ->constrained('hajj_umra_executing_companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('programs', 'trip_supervisor_id')) {
                $table->foreignId('trip_supervisor_id')->nullable()->after('trip_supervisor')
                    ->constrained('trip_supervisors')->nullOnDelete();
            }
            if (!Schema::hasColumn('programs', 'accommodation_type_id')) {
                $table->foreignId('accommodation_type_id')->nullable()->after('accommodation_type')
                    ->constrained('accommodation_types')->nullOnDelete();
            }
        });

        Schema::table('visa_details', function (Blueprint $table) {
            if (!Schema::hasColumn('visa_details', 'visa_agent_id')) {
                $table->foreignId('visa_agent_id')->nullable()->after('executing_agent_contact')
                    ->constrained('visa_agents')->nullOnDelete();
            }
            if (!Schema::hasColumn('visa_details', 'visa_duration_id')) {
                $table->foreignId('visa_duration_id')->nullable()->after('duration')
                    ->constrained('visa_durations')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Drop foreign columns added on existing tables (lose-data is acceptable on rollback)
        Schema::table('hajj_umra_bookings', function (Blueprint $table) {
            $cols = ['expense_transaction_id', 'income_transaction_id', 'created_by', 'employee_id', 'account_id'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('hajj_umra_bookings', $col)) {
                    $table->dropConstrainedForeignKey($col);
                }
            }
        });

        Schema::table('visa_bookings', function (Blueprint $table) {
            $cols = ['expense_transaction_id', 'income_transaction_id', 'created_by', 'employee_id', 'account_id'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('visa_bookings', $col)) {
                    $table->dropConstrainedForeignKey($col);
                }
            }
        });

        Schema::table('hajj_umra_payments', function (Blueprint $table) {
            foreach (['transaction_id', 'account_id', 'created_by'] as $col) {
                if (Schema::hasColumn('hajj_umra_payments', $col)) {
                    $table->dropConstrainedForeignKey($col);
                }
            }
        });

        Schema::table('visa_payments', function (Blueprint $table) {
            foreach (['transaction_id', 'account_id', 'created_by'] as $col) {
                if (Schema::hasColumn('visa_payments', $col)) {
                    $table->dropConstrainedForeignKey($col);
                }
            }
        });

        Schema::table('programs', function (Blueprint $table) {
            foreach (['executing_company_id', 'trip_supervisor_id', 'accommodation_type_id'] as $col) {
                if (Schema::hasColumn('programs', $col)) {
                    $table->dropConstrainedForeignKey($col);
                }
            }
            foreach (['default_purchase_price', 'default_selling_price', 'is_active'] as $col) {
                if (Schema::hasColumn('programs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('visa_details', function (Blueprint $table) {
            foreach (['visa_agent_id', 'visa_duration_id'] as $col) {
                if (Schema::hasColumn('visa_details', $col)) {
                    $table->dropConstrainedForeignKey($col);
                }
            }
        });

        Schema::dropIfExists('visa_durations');
        Schema::dropIfExists('accommodation_types');
        Schema::dropIfExists('trip_supervisors');
        Schema::dropIfExists('hajj_umra_executing_companies');
        Schema::dropIfExists('visa_agents');
    }
};
