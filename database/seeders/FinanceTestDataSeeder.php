<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Canonical test data for browser-based finance E2E tests.
 *
 * Run with:
 *   php artisan migrate:fresh
 *   php artisan db:seed --class=FinanceTestDataSeeder
 *
 * Creates a deterministic dataset that the browser tests assert against:
 *   - Admin user:        admin@local.test / password
 *   - Office division:   1 EGP cashbox, 1 USD bank, 1 SAR wallet,
 *                        1 EGP customer AR, 1 EGP supplier AP
 *   - Tourism division:  1 EGP cashbox, 1 USD bank (mirrors)
 *   - Exchange rates:    EGP↔USD, EGP↔SAR, EGP↔KWD
 */
class FinanceTestDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Admin user with known credentials
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@local.test'],
            [
                'name' => 'Test Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $employee = User::query()->updateOrCreate(
            ['email' => 'employee@local.test'],
            [
                'name' => 'Test Employee',
                'password' => Hash::make('password'),
                'role' => 'employee',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 2. Accounts via LedgerBalanceMutationGuard so balance writes are allowed
        LedgerBalanceMutationGuard::run(function () use ($admin) {
            // Office division liquidity
            Account::query()->updateOrCreate(
                ['name' => 'TEST Office EGP Cashbox'],
                [
                    'type' => AccountType::Cashbox->value,
                    'currency' => 'EGP',
                    'balance' => 100000.00,
                    'is_active' => true,
                    'module_type' => 'office',
                    'module' => 'office',
                    'owner_type' => Account::OWNER_TYPE_OFFICE,
                    'created_by' => $admin->id,
                    'notes' => 'Seeded for browser tests',
                ]
            );

            Account::query()->updateOrCreate(
                ['name' => 'TEST Office USD Bank'],
                [
                    'type' => AccountType::Bank->value,
                    'currency' => 'USD',
                    'balance' => 5000.00,
                    'is_active' => true,
                    'module_type' => 'office',
                    'module' => 'office',
                    'owner_type' => Account::OWNER_TYPE_OFFICE,
                    'created_by' => $admin->id,
                    'notes' => 'Seeded for browser tests',
                ]
            );

            Account::query()->updateOrCreate(
                ['name' => 'TEST Office SAR Wallet'],
                [
                    'type' => AccountType::Wallet->value,
                    'currency' => 'SAR',
                    'balance' => 2000.00,
                    'is_active' => true,
                    'module_type' => 'office',
                    'module' => 'office',
                    'owner_type' => Account::OWNER_TYPE_OFFICE,
                    'wallet_provider' => WalletProvider::VodafoneCash->value,
                    'wallet_number' => '01000000001',
                    'created_by' => $admin->id,
                    'notes' => 'Seeded for browser tests',
                ]
            );

            // Tourism division liquidity
            Account::query()->updateOrCreate(
                ['name' => 'TEST Tourism EGP Cashbox'],
                [
                    'type' => AccountType::Cashbox->value,
                    'currency' => 'EGP',
                    'balance' => 80000.00,
                    'is_active' => true,
                    'module_type' => 'tourism',
                    'module' => 'tourism',
                    'owner_type' => Account::OWNER_TYPE_OFFICE,
                    'created_by' => $admin->id,
                    'notes' => 'Seeded for browser tests',
                ]
            );

            Account::query()->updateOrCreate(
                ['name' => 'TEST Tourism USD Bank'],
                [
                    'type' => AccountType::Bank->value,
                    'currency' => 'USD',
                    'balance' => 3000.00,
                    'is_active' => true,
                    'module_type' => 'tourism',
                    'module' => 'tourism',
                    'owner_type' => Account::OWNER_TYPE_OFFICE,
                    'created_by' => $admin->id,
                    'notes' => 'Seeded for browser tests',
                ]
            );

            // Subject accounts for richer UI.
            // Subject accounts (customer/supplier) MUST be at a SPECIFIC
            // module level (flights, bus, etc.) — not the division names
            // 'office'/'tourism' which are reserved for liquidity vaults.
            $customer = Account::query()->updateOrCreate(
                ['name' => 'TEST Customer AR'],
                [
                    'type' => AccountType::Customer->value,
                    'currency' => 'EGP',
                    'balance' => 0.00,
                    'is_active' => true,
                    'module_type' => 'flights',
                    'module' => 'flights',
                    'owner_type' => Account::OWNER_TYPE_OFFICE,
                    'created_by' => $admin->id,
                    'notes' => 'Seeded for browser tests',
                ]
            );

            $supplierAcct = Account::query()->updateOrCreate(
                ['name' => 'TEST Supplier AP'],
                [
                    'type' => AccountType::Supplier->value,
                    'currency' => 'EGP',
                    'balance' => 0.00,
                    'is_active' => true,
                    'module_type' => 'flights',
                    'module' => 'flights',
                    'owner_type' => Account::OWNER_TYPE_OFFICE,
                    'created_by' => $admin->id,
                    'notes' => 'Seeded for browser tests',
                ]
            );

            // Link the supplier to the Supplier record so recharge endpoint works.
            // The suppliers table has no `currency` column (currency is on the
            // linked Account row only).
            $supplier = Supplier::query()->updateOrCreate(
                ['name' => 'TEST Supplier'],
                [
                    'code' => 'TEST-SUP-001',
                    'account_id' => $supplierAcct->id,
                    'is_active' => true,
                    'payment_terms' => 'cash',
                    'created_by' => $admin->id,
                ]
            );
        });

        // 3. Exchange rates — needed for cross-currency transfer tests
        $today = now()->toDateString();
        $rates = [
            ['from' => 'EGP', 'to' => 'USD', 'rate' => 0.0204],
            ['from' => 'USD', 'to' => 'EGP', 'rate' => 49.00],
            ['from' => 'EGP', 'to' => 'SAR', 'rate' => 0.077],
            ['from' => 'SAR', 'to' => 'EGP', 'rate' => 13.00],
            ['from' => 'EGP', 'to' => 'KWD', 'rate' => 0.0063],
            ['from' => 'KWD', 'to' => 'EGP', 'rate' => 159.00],
            ['from' => 'USD', 'to' => 'SAR', 'rate' => 3.75],
        ];

        foreach ($rates as $r) {
            ExchangeRate::query()->updateOrCreate(
                [
                    'from_currency' => $r['from'],
                    'to_currency' => $r['to'],
                    'effective_date' => $today,
                ],
                [
                    'rate' => $r['rate'],
                    'is_active' => true,
                    'created_by' => $admin->id,
                ]
            );
        }

        $this->command->info('FinanceTestDataSeeder complete:');
        $this->command->info('  Admin: admin@local.test / password');
        $this->command->info('  Employee: employee@local.test / password');
        $this->command->info('  Accounts: '.Account::query()->where('name', 'like', 'TEST %')->count());
        $this->command->info('  Exchange rates: '.ExchangeRate::query()->count());
    }
}