<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent migration: seeds the default payment methods used by
 * ALL modules (Online, Fawry, Bus, etc.).
 *
 * Safe to run multiple times — uses INSERT IGNORE / ON DUPLICATE KEY UPDATE
 * so existing rows are never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now()->toDateTimeString();

        $methods = [
            ['code' => 'cash',            'name_ar' => 'نقدي',              'name_en' => 'Cash',             'color' => '#10B981', 'is_active' => 1, 'order' => 1],
            ['code' => 'bank_transfer',   'name_ar' => 'تحويل بنكي',        'name_en' => 'Bank Transfer',    'color' => '#3B82F6', 'is_active' => 1, 'order' => 2],
            ['code' => 'cash_wallet',     'name_ar' => 'محفظة كاش',         'name_en' => 'Cash Wallet',      'color' => '#F59E0B', 'is_active' => 1, 'order' => 3],
            ['code' => 'vodafone_cash',   'name_ar' => 'فودافون كاش',       'name_en' => 'Vodafone Cash',    'color' => '#EF4444', 'is_active' => 1, 'order' => 4],
            ['code' => 'instapay',        'name_ar' => 'إنستاباي',           'name_en' => 'InstaPay',         'color' => '#8B5CF6', 'is_active' => 1, 'order' => 5],
            ['code' => 'credit_card',     'name_ar' => 'بطاقة ائتمان',      'name_en' => 'Credit Card',      'color' => '#0EA5E9', 'is_active' => 1, 'order' => 6],
            ['code' => 'postal_transfer', 'name_ar' => 'حوالة بريدية',      'name_en' => 'Postal Transfer',  'color' => '#6366F1', 'is_active' => 1, 'order' => 7],
            ['code' => 'office_safe',     'name_ar' => 'خزينة المكتب',      'name_en' => 'Office Safe',      'color' => '#06B6D4', 'is_active' => 1, 'order' => 8],
            ['code' => 'debit_card',      'name_ar' => 'بطاقة خصم',         'name_en' => 'Debit Card',       'color' => '#0284C7', 'is_active' => 1, 'order' => 9],
            ['code' => 'mobile_wallet',   'name_ar' => 'محفظة موبايل',      'name_en' => 'Mobile Wallet',    'color' => '#7C3AED', 'is_active' => 1, 'order' => 10],
        ];

        foreach ($methods as $method) {
            DB::table('payment_methods')->updateOrInsert(
                ['code' => $method['code']],
                array_merge($method, ['created_at' => $now, 'updated_at' => $now])
            );
        }
    }

    public function down(): void
    {
        // Do not delete — these are operational records.
        // Rolling back this migration is a no-op by design.
    }
};
