<?php

namespace Database\Seeders;

use App\Models\Flight\AirlineAccount;
use Illuminate\Database\Seeder;

class AirlineAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            [
                'name' => 'EgyptAir',
                'code' => 'MS',
                'system_type' => 'Amadeus',
                'currency' => 'EGP',
                'balance' => 500000.00,
                'credit_limit' => 200000.00,
                'is_active' => true,
                'notes' => 'حساب شركة مصر للطيران - النظام الرئيسي',
            ],
            [
                'name' => 'Saudi Arabian Airlines',
                'code' => 'SV',
                'system_type' => 'Amadeus',
                'currency' => 'SAR',
                'balance' => 300000.00,
                'credit_limit' => 150000.00,
                'is_active' => true,
                'notes' => 'حساب الخطوط السعودية',
            ],
            [
                'name' => 'Emirates',
                'code' => 'EK',
                'system_type' => 'NDC',
                'currency' => 'AED',
                'balance' => 400000.00,
                'credit_limit' => 100000.00,
                'is_active' => true,
                'notes' => 'حساب طيران الإمارات',
            ],
            [
                'name' => 'Qatar Airways',
                'code' => 'QR',
                'system_type' => 'Amadeus',
                'currency' => 'QAR',
                'balance' => 250000.00,
                'credit_limit' => 100000.00,
                'is_active' => true,
                'notes' => 'حساب الخطوط القطرية',
            ],
            [
                'name' => 'Turkish Airlines',
                'code' => 'TK',
                'system_type' => 'Amadeus',
                'currency' => 'USD',
                'balance' => 150000.00,
                'credit_limit' => 50000.00,
                'is_active' => true,
                'notes' => 'حساب الخطوط التركية',
            ],
            [
                'name' => 'Flynas',
                'code' => 'XY',
                'system_type' => 'NDC',
                'currency' => 'SAR',
                'balance' => 100000.00,
                'credit_limit' => 50000.00,
                'is_active' => true,
                'notes' => 'حساب طيران ناس',
            ],
        ];

        foreach ($accounts as $account) {
            AirlineAccount::create($account);
        }

        $this->command->info('✅ تم إنشاء حسابات شركات الطيران بنجاح');
    }
}
