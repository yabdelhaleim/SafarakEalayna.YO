<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AccountsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // خزينة المالك (owner_type = 'owner')
        $ownerAccounts = [
            [
                'name' => 'بريد سفرك علينا - جاري',
                'type' => 'treasury',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ],
            [
                'name' => 'بريد سفرك علينا - فضي',
                'type' => 'treasury',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ],
            [
                'name' => 'بريد سفرك علينا - يوم بيوم',
                'type' => 'treasury',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ],
            [
                'name' => 'ياسر محمود - توفير',
                'type' => 'treasury',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ],
            [
                'name' => 'بنك مصر / سفرك علينا',
                'type' => 'bank',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ],
            [
                'name' => 'بنك الإسكندرية / عرفه أحمد',
                'type' => 'bank',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ],
            [
                'name' => 'البنك الأهلي / سفرك علينا',
                'type' => 'bank',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ],
            [
                'name' => 'نقدي مصري',
                'type' => 'cashbox',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ],
            [
                'name' => 'نقدي دينار',
                'type' => 'cashbox',
                'currency' => 'KWD',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ],
            [
                'name' => 'نقدي ريال',
                'type' => 'cashbox',
                'currency' => 'SAR',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ],
            [
                'name' => 'نقدي دولار',
                'type' => 'cashbox',
                'currency' => 'USD',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ],
            [
                'name' => 'محفظة كاش ياسر',
                'type' => 'wallet',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ],
        ];

        // خزينة المكتب (owner_type = 'office')
        $officeAccounts = [
            [
                'name' => 'درج المكتب',
                'type' => 'cashbox',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'office',
            ],
            [
                'name' => 'كاش المكتب',
                'type' => 'cashbox',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'office',
            ],
        ];

        // Merge all accounts
        $allAccounts = array_merge($ownerAccounts, $officeAccounts);

        // Create accounts
        foreach ($allAccounts as $account) {
            \App\Models\Account::create($account);
        }

        $this->command->info('✅ تم إنشاء ' . count($allAccounts) . ' حساب بنجاح');
    }
}
