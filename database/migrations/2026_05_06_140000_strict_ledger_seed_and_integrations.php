<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds module clearing accounts (strict double-entry offset legs),
 * links banks & customers to the accounts ledger, aligns treasury_transactions with Ledger + DB columns used in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $systemUserId = DB::table('users')->orderBy('id')->value('id');

        $definitions = [
            ['name' => 'ضبط حركات الخزينة (نظام)', 'notes' => 'حساب تسوية لحركات الخزينة غير المصنّفة — يسمح برصيد سالب جزئياً لتعادل القيود مع accounts.balance'],
            ['name' => 'إقفال إيرادات الباصات', 'notes' => 'جانب الدائن الموازي لكل تحصيل باص في الحسابات النقدية'],
            ['name' => 'إقفال تكاليف الباصات', 'notes' => 'جانب المدين الموازي لمصروفات/توريد الباص من الخزائن'],
            ['name' => 'إقفال إيرادات الحج والعمرة', 'notes' => null],
            ['name' => 'إقفال تكاليف الحج والعمرة', 'notes' => null],
            ['name' => 'إقفال إيرادات التأشيرات', 'notes' => null],
            ['name' => 'إقفال تكاليف التأشيرات', 'notes' => null],
            ['name' => 'إقفال إيرادات الخدمات الإلكترونية', 'notes' => null],
            ['name' => 'إقفال تكاليف الخدمات الإلكترونية', 'notes' => null],
            ['name' => 'إقفال إيرادات فوري', 'notes' => null],
            ['name' => 'إقفال تكاليف فوري', 'notes' => null],
            ['name' => 'إقفال إيرادات المحافظ', 'notes' => null],
            ['name' => 'إقفال تكاليف المحافظ', 'notes' => null],
            ['name' => 'إقفال إيراد عام (نظام)', 'notes' => null],
            ['name' => 'إقفال تكلفة عامة (نظام)', 'notes' => null],
            ['name' => 'إقفال تكاليف الطيران', 'notes' => 'موازِن مصاريف الشراء للطيران تجاه حساب الخزينة/السيستم أو الحسابات الأخرى'],
        ];

        foreach ($definitions as $def) {
            if (DB::table('accounts')->where('name', $def['name'])->exists()) {
                continue;
            }

            DB::table('accounts')->insert([
                'name' => $def['name'],
                'type' => 'treasury',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'office',
                'notes' => $def['notes'],
                'created_by' => $systemUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('banks', function (Blueprint $table): void {
            if (! Schema::hasColumn('banks', 'account_id')) {
                $table->foreignId('account_id')->nullable()->after('id')->constrained('accounts')->nullOnDelete();
            }
        });

        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'account_id')) {
                $table->foreignId('account_id')->nullable()->after('id')->constrained('accounts')->nullOnDelete();
            }
        });

        Schema::table('treasury_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('treasury_transactions', 'transaction_type')) {
                $table->string('transaction_type', 32)->nullable()->after('id');
            }
            if (! Schema::hasColumn('treasury_transactions', 'account_id')) {
                $table->foreignId('account_id')->nullable()->after('transaction_type')->constrained('accounts')->nullOnDelete();
            }
            if (! Schema::hasColumn('treasury_transactions', 'balance_before')) {
                $table->decimal('balance_before', 15, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('treasury_transactions', 'balance_after')) {
                $table->decimal('balance_after', 15, 2)->nullable()->after('balance_before');
            }
            if (! Schema::hasColumn('treasury_transactions', 'reference_number')) {
                $table->string('reference_number')->nullable()->after('agent_name');
            }
            if (! Schema::hasColumn('treasury_transactions', 'ledger_transaction_id')) {
                $table->foreignId('ledger_transaction_id')->nullable()->after('reference_number')->constrained('transactions')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('treasury_transactions', function (Blueprint $table): void {
            foreach (['ledger_transaction_id', 'reference_number', 'balance_after', 'balance_before', 'account_id', 'transaction_type'] as $col) {
                if (! Schema::hasColumn('treasury_transactions', $col)) {
                    continue;
                }

                try {
                    if (str_contains($col, '_id')) {
                        $table->dropConstrainedForeignId($col);
                    } else {
                        $table->dropColumn($col);
                    }
                } catch (\Throwable) {
                    // tolerate partial state
                }
            }
        });

        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'account_id')) {
                $table->dropConstrainedForeignId('account_id');
            }
        });

        Schema::table('banks', function (Blueprint $table): void {
            if (Schema::hasColumn('banks', 'account_id')) {
                $table->dropConstrainedForeignId('account_id');
            }
        });

        foreach ([
            'ضبط حركات الخزينة (نظام)',
            'إقفال إيرادات الباصات',
            'إقفال تكاليف الباصات',
            'إقفال إيرادات الحج والعمرة',
            'إقفال تكاليف الحج والعمرة',
            'إقفال إيرادات التأشيرات',
            'إقفال تكاليف التأشيرات',
            'إقفال إيرادات الخدمات الإلكترونية',
            'إقفال تكاليف الخدمات الإلكترونية',
            'إقفال إيرادات فوري',
            'إقفال تكاليف فوري',
            'إقفال إيرادات المحافظ',
            'إقفال تكاليف المحافظ',
            'إقفال إيراد عام (نظام)',
            'إقفال تكلفة عامة (نظام)',
            'إقفال تكاليف الطيران',
        ] as $name) {
            DB::table('accounts')->where('name', $name)->delete();
        }
    }
};
