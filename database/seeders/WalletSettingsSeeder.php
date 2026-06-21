<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Wallet\WalletType;
use App\Enums\AccountType;
use App\Enums\WalletProvider;
use Illuminate\Database\Seeder;

class WalletSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Wallet Types
        $walletTypes = [
            [
                'name' => 'فودافون كاش',
                'code' => 'vodafone_cash',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'إنستاباي',
                'code' => 'instapay',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'أورنج كاش',
                'code' => 'orange_cash',
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'اتصالات كاش',
                'code' => 'etisalat_cash',
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'name' => 'WE Pay',
                'code' => 'we_pay',
                'is_active' => true,
                'sort_order' => 5,
            ],
        ];

        foreach ($walletTypes as $wt) {
            WalletType::updateOrCreate(
                ['code' => $wt['code']],
                [
                    'name' => $wt['name'],
                    'is_active' => $wt['is_active'],
                    'sort_order' => $wt['sort_order'],
                ]
            );
        }

        // 2. Seed default liquidity accounts for wallet_transfer
        // We need cashbox and wallet accounts with module_type = 'wallet_transfer'

        Account::updateOrCreate(
            ['name' => 'خزينة تحويلات المحافظ'],
            [
                'type' => AccountType::Cashbox->value,
                'module_type' => 'wallet_transfer',
                'balance' => 25000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'محفظة فودافون كاش - الوكالة'],
            [
                'type' => AccountType::Wallet->value,
                'module_type' => 'wallet_transfer',
                'balance' => 50000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'wallet_provider' => WalletProvider::VodafoneCash->value,
                'wallet_number' => '01012345678',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'محفظة إنستاباي - الوكالة'],
            [
                'type' => AccountType::Wallet->value,
                'module_type' => 'wallet_transfer',
                'balance' => 100000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'wallet_provider' => WalletProvider::Instapay->value,
                'wallet_number' => 'agency@instapay',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'محفظة أورنج كاش - الوكالة'],
            [
                'type' => AccountType::Wallet->value,
                'module_type' => 'wallet_transfer',
                'balance' => 15000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'wallet_provider' => WalletProvider::OrangeCash->value,
                'wallet_number' => '01212345678',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'محفظة اتصالات كاش - الوكالة'],
            [
                'type' => AccountType::Wallet->value,
                'module_type' => 'wallet_transfer',
                'balance' => 15000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'wallet_provider' => WalletProvider::EtisalatCash->value,
                'wallet_number' => '01112345678',
                'created_by' => 1,
            ]
        );
    }
}
