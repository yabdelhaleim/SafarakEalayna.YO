<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Production-readiness test seeder for the Wallet module.
 *
 * Idempotent. Seeds:
 *   - Wallet "provider" accounts (Vodafone Cash, InstaPay, Orange Cash, Etisalat Cash, We Pay)
 *     backed by regular Account rows (used as wallet_account_id)
 *   - Wallet "settlement" accounts (used as cash_account_id)
 *   - 5 fresh test customers (with auto-created AR accounts)
 *   - Wallet clearing accounts (income + expense)
 */
class WalletModuleProductionTestSeeder extends Seeder
{
    public function run(): void
    {
        Auth::loginUsingId(User::first()?->id ?? 1);

        DB::transaction(function () {
            $this->seedWalletProviderAccounts();
            $this->seedSettlementCashboxes();
            $this->seedWalletCustomers();
            $this->seedClearingAccounts();
        });

        $this->command->info('✅ WalletModuleProductionTestSeeder completed.');
    }

    protected function seedWalletProviderAccounts(): void
    {
        $providers = [
            [
                'name' => 'محفظة فودافون كاش - المكتب',
                'currency' => 'EGP',
                'balance' => 50000.00,
                'is_active' => true,
                'module_type' => 'office',
            ],
            [
                'name' => 'محفظة إنستاباي - المكتب',
                'currency' => 'EGP',
                'balance' => 30000.00,
                'is_active' => true,
                'module_type' => 'office',
            ],
            [
                'name' => 'محفظة أورنج كاش - المكتب',
                'currency' => 'EGP',
                'balance' => 20000.00,
                'is_active' => true,
                'module_type' => 'office',
            ],
            [
                'name' => 'محفظة اتصالات كاش - المكتب',
                'currency' => 'EGP',
                'balance' => 15000.00,
                'is_active' => true,
                'module_type' => 'office',
            ],
            [
                'name' => 'محفظة WE Pay - المكتب',
                'currency' => 'EGP',
                'balance' => 10000.00,
                'is_active' => true,
                'module_type' => 'office',
            ],
        ];

        foreach ($providers as $p) {
            LedgerBalanceMutationGuard::run(function () use ($p) {
                Account::firstOrCreate(
                    ['name' => $p['name']],
                    array_merge($p, [
                        'type' => AccountType::Wallet,
                        'owner_type' => Account::OWNER_TYPE_OWNER,
                        'is_module_vault' => false,
                        'notes' => 'محفظة الكترونية مملوكة للمكتب',
                        'created_by' => 1,
                    ])
                );
            });
        }
        $this->command->info('  • Wallet provider accounts: 5 (Vodafone, InstaPay, Orange, Etisalat, We Pay) @ ' . number_format(125000, 0) . ' EGP total');
    }

    protected function seedSettlementCashboxes(): void
    {
        LedgerBalanceMutationGuard::run(function () {
            Account::firstOrCreate(
                ['name' => 'خزينة المحافظ النقدية'],
                [
                    'type' => AccountType::Cashbox,
                    'balance' => 40000.00,
                    'currency' => 'EGP',
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'office',
                    'is_module_vault' => false,
                    'notes' => 'خزينة المحافظ لاستلام تحصيلات العملاء',
                    'created_by' => 1,
                ]
            );
        });
        $this->command->info('  • Wallet settlement cashbox: EGP 40,000');
    }

    protected function seedWalletCustomers(): void
    {
        $customers = [
            [
                'full_name' => 'عميل محفظة - أحمد علي',
                'phone' => '01730030001',
                'email' => 'wallet1@example.com',
                'national_id' => '30301011234567',
                'type' => 'individual',
                'customer_tier' => 'STANDARD',
                'nationality' => 'EG',
                'city' => 'القاهرة',
            ],
            [
                'full_name' => 'عميل محفظة - سارة حسن',
                'phone' => '01730030002',
                'email' => 'wallet2@example.com',
                'national_id' => '30302021234567',
                'type' => 'individual',
                'customer_tier' => 'PREMIUM',
                'nationality' => 'EG',
                'city' => 'الإسكندرية',
            ],
            [
                'full_name' => 'عميل محفظة - يوسف محمد',
                'phone' => '01730030003',
                'email' => 'wallet3@example.com',
                'national_id' => '30303031234567',
                'type' => 'individual',
                'customer_tier' => 'VIP',
                'nationality' => 'EG',
                'city' => 'الجيزة',
            ],
        ];

        foreach ($customers as $c) {
            Customer::updateOrCreate(['phone' => $c['phone']], $c);
        }
        $this->command->info('  • Wallet customers: 3 (EG)');
    }

    protected function seedClearingAccounts(): void
    {
        $lc = app(LedgerClearingAccounts::class);
        $incomeId = $lc->incomeContraIdForModule(TransactionModule::Wallet);
        $expenseId = $lc->expenseContraIdForModule(TransactionModule::Wallet);

        $this->command->info(sprintf(
            '  • Wallet clearing accounts: income=#%s, expense=#%s',
            $incomeId ?? 'NULL',
            $expenseId ?? 'NULL'
        ));
    }
}
