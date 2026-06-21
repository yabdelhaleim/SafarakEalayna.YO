<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class HajjUmraSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Seed default liquidity accounts for Hajj/Umrah
        Account::updateOrCreate(
            ['name' => 'خزينة الحج والعمرة الرئيسية'],
            [
                'type' => 'cashbox',
                'module_type' => 'hajj_umra',
                'balance' => 50000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'بنك الحج والعمرة الأهلي'],
            [
                'type' => 'bank',
                'module_type' => 'hajj_umra',
                'balance' => 100000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'محفظة الحج والعمرة كاش'],
            [
                'type' => 'wallet',
                'module_type' => 'hajj_umra',
                'balance' => 10000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'wallet_provider' => 'vodafone_cash',
                'wallet_number' => '01022223333',
                'created_by' => 1,
            ]
        );
    }
}
