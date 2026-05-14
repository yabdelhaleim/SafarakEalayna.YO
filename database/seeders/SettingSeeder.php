<?php

namespace Database\Seeders;

use App\Models\Setting\Currency;
use App\Models\Setting\OperationType;
use App\Models\Setting\PaymentMethod;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $paymentMethods = [
            ['code' => 'cash', 'name_ar' => 'نقدي', 'name_en' => 'Cash', 'color' => 'success', 'is_active' => true, 'order' => 1],
            ['code' => 'vodafone_cash', 'name_ar' => 'فودافون كاش', 'name_en' => 'Vodafone Cash', 'color' => 'red', 'is_active' => true, 'order' => 2],
            ['code' => 'instapay', 'name_ar' => 'إنستا باي', 'name_en' => 'InstaPay', 'color' => 'purple', 'is_active' => true, 'order' => 3],
            ['code' => 'bank_transfer', 'name_ar' => 'تحويل بنكي', 'name_en' => 'Bank Transfer', 'color' => 'info', 'is_active' => true, 'order' => 4],
            ['code' => 'office_drawer', 'name_ar' => 'درج المكتب', 'name_en' => 'Office Drawer', 'color' => 'gray', 'is_active' => true, 'order' => 5],
        ];

        PaymentMethod::upsert(
            array_map(fn (array $row) => array_merge($row, [
                'created_at' => $now,
                'updated_at' => $now,
            ]), $paymentMethods),
            ['code'],
            ['name_ar', 'name_en', 'color', 'is_active', 'order', 'updated_at']
        );

        $operationTypes = [
            ['code' => 'withdrawal', 'name_ar' => 'سحب', 'name_en' => 'Withdrawal', 'color' => 'error', 'is_active' => true, 'order' => 1],
            ['code' => 'deposit', 'name_ar' => 'إيداع', 'name_en' => 'Deposit', 'color' => 'success', 'is_active' => true, 'order' => 2],
            ['code' => 'payment', 'name_ar' => 'سداد', 'name_en' => 'Payment', 'color' => 'info', 'is_active' => true, 'order' => 3],
            ['code' => 'travel_permit', 'name_ar' => 'تصريح سفر', 'name_en' => 'Travel Permit', 'color' => 'warning', 'is_active' => true, 'order' => 4],
        ];

        OperationType::upsert(
            array_map(fn (array $row) => array_merge($row, [
                'created_at' => $now,
                'updated_at' => $now,
            ]), $operationTypes),
            ['code'],
            ['name_ar', 'name_en', 'color', 'is_active', 'order', 'updated_at']
        );

        $currencies = [
            ['code' => 'EGP', 'name_ar' => 'جنيه مصري', 'name_en' => 'Egyptian Pound', 'symbol' => 'ج.م', 'exchange_rate' => 1.0000, 'is_active' => true, 'order' => 1],
            ['code' => 'USD', 'name_ar' => 'دولار أمريكي', 'name_en' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 48.5000, 'is_active' => true, 'order' => 2],
            ['code' => 'KWD', 'name_ar' => 'دينار كويتي', 'name_en' => 'Kuwaiti Dinar', 'symbol' => 'د.ك', 'exchange_rate' => 157.5000, 'is_active' => true, 'order' => 3],
            ['code' => 'SAR', 'name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal', 'symbol' => 'ر.س', 'exchange_rate' => 12.9000, 'is_active' => true, 'order' => 4],
            ['code' => 'EUR', 'name_ar' => 'يورو', 'name_en' => 'Euro', 'symbol' => '€', 'exchange_rate' => 52.3000, 'is_active' => true, 'order' => 5],
            ['code' => 'GBP', 'name_ar' => 'جنيه إسترليني', 'name_en' => 'British Pound', 'symbol' => '£', 'exchange_rate' => 61.2000, 'is_active' => true, 'order' => 6],
        ];

        foreach ($currencies as $row) {
            $existing = Currency::query()->where('code', $row['code'])->first();
            if ($existing) {
                $existing->update([
                    'name_ar' => $row['name_ar'],
                    'name_en' => $row['name_en'],
                    'symbol' => $row['symbol'],
                    'order' => $row['order'],
                    'is_active' => $row['is_active'],
                ]);

                continue;
            }

            Currency::query()->insert(array_merge($row, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }
}
