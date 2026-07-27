<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Models\Wallet\WalletType;
use App\Services\Finance\LedgerClearingAccounts;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Production-readiness test seeder for the Wallet module — 2026-07-27 edition.
 *
 * Idempotent. Seeds:
 *   - Multi-currency wallet "provider" accounts (EGP, USD, SAR) tagged module_type='wallet_transfer'
 *   - Multi-currency settlement cashboxes (EGP, USD, SAR) tagged module_type='wallet_transfer'
 *   - 5 fresh test customers (Egyptian) — accounts auto-created by CustomerLedgerObserver
 *   - Wallet clearing accounts (income + expense) via LedgerClearingAccounts
 *   - Refresh module_type for any existing customer accounts to 'wallet_transfer'
 *     (so the dashboard surfaces them correctly under the wallet module)
 *
 * This seeder is used by tests/scripts/wallet_module_production_full_test.php.
 */
class WalletModuleProductionTestSeeder2026 extends Seeder
{
    public function run(): void
    {
        Auth::loginUsingId(User::first()?->id ?? 1);

        DB::transaction(function () {
            $this->seedWalletProviderAccounts();
            $this->seedSettlementCashboxes();
            $this->seedWalletCustomers();
            $this->seedExtraBankAccounts();
            $this->seedClearingAccounts();
        });

        $this->command->info('✅ WalletModuleProductionTestSeeder2026 completed.');
    }

    /**
     * 6 wallet provider accounts across 3 currencies — one per wallet type × currency.
     * Tag: type=wallet, module_type='office' (wallet_transfer is in the Office division —
     * per AccountModuleContract, liquidity accounts MUST use the division module_type).
     * The 'wallet_transfer' tag is reserved for SUBJECT accounts (customer AR).
     */
    protected function seedWalletProviderAccounts(): void
    {
        $providers = [
            // EGP
            ['name' => 'WL_EGP_Vodafone', 'currency' => 'EGP', 'balance' => 50000.00],
            ['name' => 'WL_EGP_InstaPay', 'currency' => 'EGP', 'balance' => 30000.00],
            // USD
            ['name' => 'WL_USD_Vodafone', 'currency' => 'USD', 'balance' => 2000.00],
            ['name' => 'WL_USD_InstaPay', 'currency' => 'USD', 'balance' => 1500.00],
            // SAR
            ['name' => 'WL_SAR_Vodafone', 'currency' => 'SAR', 'balance' => 5000.00],
            ['name' => 'WL_SAR_InstaPay', 'currency' => 'SAR', 'balance' => 3000.00],
        ];

        $created = 0;
        foreach ($providers as $p) {
            $existing = Account::where('name', $p['name'])->first();
            if ($existing) {
                continue;
            }
            LedgerBalanceMutationGuard::run(function () use ($p) {
                Account::create([
                    'name' => $p['name'],
                    'type' => AccountType::Wallet,
                    'balance' => $p['balance'],
                    'currency' => $p['currency'],
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'office',
                    'module' => 'wallet_transfer',
                    'is_module_vault' => false,
                    'notes' => 'محفظة إلكترونية - اختبار الإنتاج',
                    'created_by' => 1,
                ]);
            });
            $created++;
        }
        $this->command->info("  • Wallet provider accounts: {$created} created (idempotent)");
    }

    /**
     * 3 settlement cashboxes — one per currency. Used as cash_account_id in wallet transactions.
     */
    protected function seedSettlementCashboxes(): void
    {
        $cashboxes = [
            ['name' => 'WL_CASH_EGP', 'currency' => 'EGP', 'balance' => 100000.00],
            ['name' => 'WL_CASH_USD', 'currency' => 'USD', 'balance' => 5000.00],
            ['name' => 'WL_CASH_SAR', 'currency' => 'SAR', 'balance' => 10000.00],
        ];

        $created = 0;
        foreach ($cashboxes as $c) {
            if (Account::where('name', $c['name'])->exists()) {
                continue;
            }
            LedgerBalanceMutationGuard::run(function () use ($c) {
                Account::create([
                    'name' => $c['name'],
                    'type' => AccountType::Cashbox,
                    'balance' => $c['balance'],
                    'currency' => $c['currency'],
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'office',
                    'module' => 'wallet_transfer',
                    'is_module_vault' => false,
                    'notes' => 'خزينة المحافظ - اختبار الإنتاج',
                    'created_by' => 1,
                ]);
            });
            $created++;
        }
        $this->command->info("  • Settlement cashboxes: {$created} created (idempotent)");
    }

    /**
     * 5 fresh test customers (Egyptian) — auto-creates AR accounts via observer.
     */
    protected function seedWalletCustomers(): void
    {
        $customers = [
            [
                'full_name' => 'WALLET_TEST_CUST_Ahmed',
                'phone' => '01730032001',
                'email' => 'wallet_t1@example.com',
                'national_id' => '30301011234001',
                'type' => 'individual',
                'customer_tier' => 'STANDARD',
                'nationality' => 'EG',
                'city' => 'القاهرة',
            ],
            [
                'full_name' => 'WALLET_TEST_CUST_Sara',
                'phone' => '01730032002',
                'email' => 'wallet_t2@example.com',
                'national_id' => '30302021234002',
                'type' => 'individual',
                'customer_tier' => 'PREMIUM',
                'nationality' => 'EG',
                'city' => 'الإسكندرية',
            ],
            [
                'full_name' => 'WALLET_TEST_CUST_Youssef',
                'phone' => '01730032003',
                'email' => 'wallet_t3@example.com',
                'national_id' => '30303031234003',
                'type' => 'individual',
                'customer_tier' => 'VIP',
                'nationality' => 'EG',
                'city' => 'الجيزة',
            ],
            [
                'full_name' => 'WALLET_TEST_CUST_Omar',
                'phone' => '01730032004',
                'email' => 'wallet_t4@example.com',
                'national_id' => '30304041234004',
                'type' => 'individual',
                'customer_tier' => 'STANDARD',
                'nationality' => 'EG',
                'city' => 'المنصورة',
            ],
            [
                'full_name' => 'WALLET_TEST_CUST_Mona',
                'phone' => '01730032005',
                'email' => 'wallet_t5@example.com',
                'national_id' => '30305051234005',
                'type' => 'individual',
                'customer_tier' => 'PREMIUM',
                'nationality' => 'EG',
                'city' => 'أسيوط',
            ],
        ];

        foreach ($customers as $c) {
            Customer::updateOrCreate(['phone' => $c['phone']], $c);
        }

        // Re-tag any customer accounts so they're properly identified as wallet_transfer
        // (the CustomerLedgerObserver creates them with a default module_type that's
        // not 'wallet_transfer' — the WalletTransactionService retags on first use,
        // but we want to be deterministic at test start).
        $customerIds = Customer::whereIn('phone', array_column($customers, 'phone'))->pluck('id');
        $reTagged = 0;
        foreach (Customer::whereIn('id', $customerIds)->with('ledgerAccount')->get() as $cust) {
            if ($cust->ledgerAccount && $cust->ledgerAccount->module_type !== 'wallet_transfer') {
                LedgerBalanceMutationGuard::run(function () use ($cust) {
                    $cust->ledgerAccount->module_type = 'wallet_transfer';
                    $cust->ledgerAccount->save();
                });
                $reTagged++;
            }
        }
        $this->command->info("  • Wallet customers: 5 (or existing). Retagged {$reTagged} accounts to 'wallet_transfer'.");
    }

    /**
     * Extra bank accounts per currency — for settlement coverage (Bug #5 dashboard).
     */
    protected function seedExtraBankAccounts(): void
    {
        $banks = [
            ['name' => 'WL_BANK_EGP', 'currency' => 'EGP', 'balance' => 50000.00],
            ['name' => 'WL_BANK_USD', 'currency' => 'USD', 'balance' => 3000.00],
            ['name' => 'WL_BANK_SAR', 'currency' => 'SAR', 'balance' => 8000.00],
        ];

        $created = 0;
        foreach ($banks as $b) {
            if (Account::where('name', $b['name'])->exists()) {
                continue;
            }
            LedgerBalanceMutationGuard::run(function () use ($b) {
                Account::create([
                    'name' => $b['name'],
                    'type' => AccountType::Bank,
                    'balance' => $b['balance'],
                    'currency' => $b['currency'],
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'office',
                    'module' => 'wallet_transfer',
                    'is_module_vault' => false,
                    'notes' => 'حساب بنكي - اختبار الإنتاج',
                    'created_by' => 1,
                ]);
            });
            $created++;
        }
        $this->command->info("  • Bank accounts: {$created} created (idempotent)");
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
