<?php

namespace Tests\Feature\Wallet;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Models\Wallet\WalletType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base class for the Wallet & Transfers audit.
 *
 * - Uses RefreshDatabase — every test starts with a fresh in-memory SQLite DB.
 * - Seeds the wallet-module clearing accounts BEFORE every test
 *   (these are NOT created by the model's boot hooks; they are looked up by
 *   name+module_type by LedgerClearingAccounts).
 * - Provides factory-style helpers for deterministic fixtures (no Faker random).
 * - Provides a role-based `actingAs` helper.
 */
abstract class WalletTestCase extends TestCase
{
    use RefreshDatabase;

    /** Wallet-module income/expense clearing account names per config/accounting.php. */
    protected const WALLET_INCOME_CLEARING = 'إقفال إيرادات المحافظات';

    protected const WALLET_EXPENSE_CLEARING = 'إقفال تكاليف المحافظات';

    protected User $admin;

    protected User $cashier;

    protected User $manager;

    protected WalletType $walletType;

    protected Account $walletAccountEgp;     // Vodafone Cash-style liquidity (EGP, office)

    protected Account $cashboxEgp;           // Main cashbox liquidity (EGP, office)

    protected Account $cashboxUsd;           // USD cashbox for FX testing

    protected Account $cashboxSar;           // SAR cashbox for FX testing

    protected Account $walletIncomeClearing; // Required by recordIncome(strict=true)

    protected Account $walletExpenseClearing; // Required by recordExpense(strict=true)

    protected Customer $customerEgp;

    protected Customer $customer2;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // CREATE USERS FIRST — accounts reference users.id via FK `created_by`.
        $this->admin = User::factory()->create([
            'name' => 'Test Admin',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->manager = User::factory()->create([
            'name' => 'Test Manager',
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->cashier = User::factory()->create([
            'name' => 'Test Cashier',
            'role' => 'employee',
            'is_active' => true,
        ]);

        // Seed wallet-module clearing accounts. The application reads them by
        // name+module_type via LedgerClearingAccounts.
        $this->walletIncomeClearing = Account::create([
            'name' => self::WALLET_INCOME_CLEARING,
            'type' => AccountType::Revenue->value,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'tourism',
            'is_module_vault' => false,
            'notes' => 'Auto-seeded by WalletTestCase.',
            'created_by' => $this->admin->id,
        ]);

        $this->walletExpenseClearing = Account::create([
            'name' => self::WALLET_EXPENSE_CLEARING,
            'type' => AccountType::Expense->value,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'tourism',
            'is_module_vault' => false,
            'notes' => 'Auto-seeded by WalletTestCase.',
            'created_by' => $this->admin->id,
        ]);

        // Standard liquidity fixtures — EGP cashboxes/wallets, USD + SAR cashboxes.
        $this->walletAccountEgp = $this->makeAccount(
            type: AccountType::Wallet,
            name: 'Vodafone Cash - Agency',
            currency: 'EGP',
            balance: 10000.00,
            moduleType: 'office',
        );

        $this->cashboxEgp = $this->makeAccount(
            type: AccountType::Cashbox,
            name: 'Main Cashbox EGP',
            currency: 'EGP',
            balance: 5000.00,
            moduleType: 'office',
        );

        $this->cashboxUsd = $this->makeAccount(
            type: AccountType::Cashbox,
            name: 'Main Cashbox USD',
            currency: 'USD',
            balance: 1000.00,
            moduleType: 'office',
        );

        $this->cashboxSar = $this->makeAccount(
            type: AccountType::Cashbox,
            name: 'Main Cashbox SAR',
            currency: 'SAR',
            balance: 1000.00,
            moduleType: 'office',
        );

        // Wallet type master (one record suffices for filtering).
        $this->walletType = WalletType::firstOrCreate(
            ['code' => 'vodafone_cash'],
            [
                'name' => 'فودافون كاش',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        // Customers (separate customers for IDOR tests).
        $this->customerEgp = $this->makeCustomer('أحمد محمود');
        $this->customer2 = $this->makeCustomer('سامي عبدالله');

        // Default employee for FK reference on tests that need one.
        $this->employee = Employee::query()->create([
            'full_name' => 'موظف اختبار',
            'national_id' => '29908150101010',
            'is_active' => true,
        ]);
    }

    protected function makeAccount(AccountType $type, string $name, string $currency, float $balance, string $moduleType, bool $isActive = true, ?string $module = null, ?string $walletNumber = null): Account
    {
        return Account::create([
            'name' => $name,
            'type' => $type->value,
            'currency' => $currency,
            'balance' => $balance,
            'is_active' => $isActive,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => $moduleType,
            'module' => $module,
            'is_module_vault' => false,
            'notes' => 'WalletTestCase fixture',
            'created_by' => $this->admin->id,
            'wallet_number' => $walletNumber,
        ]);
    }

    protected function makeCustomer(string $fullName, ?string $phone = null): Customer
    {
        return Customer::query()->create([
            'full_name' => $fullName,
            'phone' => $phone ?? '+9665'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $fullName)).'@audit.local',
        ]);
    }

    /** Deterministic send payload for a registered customer. */
    protected function sendPayloadRegistered(Customer $customer, float $amount = 500.00, float $fee = 10.00): array
    {
        return [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'wallet_number' => '01012345678',
            'type' => 'send',
            'amount' => $amount,
            'service_fee' => $fee,
            'wallet_account_id' => $this->walletAccountEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'audit send registered',
        ];
    }

    /** Deterministic send payload for an anonymous walk-in (no customer_id). */
    protected function sendPayloadWalkIn(float $amount = 500.00, float $fee = 10.00): array
    {
        return [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => null,
            'customer_name' => 'عميل عابر',
            'wallet_number' => '01011112222',
            'type' => 'send',
            'amount' => $amount,
            'service_fee' => $fee,
            'wallet_account_id' => $this->walletAccountEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'audit send walk-in',
        ];
    }

    /** Deterministic receive payload for a registered customer. */
    protected function receivePayloadRegistered(Customer $customer, float $amount = 300.00, float $fee = 8.00): array
    {
        return [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'wallet_number' => '01098765432',
            'type' => 'receive',
            'amount' => $amount,
            'service_fee' => $fee,
            'wallet_account_id' => $this->walletAccountEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'audit receive registered',
        ];
    }

    protected function asAdmin(): self
    {
        return $this->actingAs($this->admin, 'sanctum');
    }

    protected function asCashier(): self
    {
        return $this->actingAs($this->cashier, 'sanctum');
    }

    protected function asManager(): self
    {
        return $this->actingAs($this->manager, 'sanctum');
    }
}
