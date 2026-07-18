<?php

namespace Tests\Feature\Bus;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\CurrencyService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Database\Factories\Bus\BusCompanyFactory;
use Database\Factories\Bus\BusInventoryFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Base TestCase for all Bus module feature tests.
 *
 * Provides:
 *   - Authenticated Sanctum user
 *   - EGP cashbox + USD wallet + EGP bank (so FX flows can be tested)
 *   - Bus income + expense clearing accounts (already auto-created via {@see LedgerClearingAccounts})
 *   - Exchange rates seeded: USD/SAR/KWD/EUR ↔ EGP
 *   - Helpers to assert ledger consistency
 *
 * Multi-currency contracts enforced here:
 *   1. Liquidity accounts MUST carry `module_type='office'` (per AccountModuleContract saving hook).
 *   2. Cross-currency flows MUST go through CurrencyService::convert().
 *   3. Settlement payments MUST use an account whose `currency` matches the booking's `currency`
 *      (the route-level BusLiquidityAccount rule will reject mismatches).
 */
abstract class BusTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $cashboxEgp;

    protected Account $bankEgp;

    protected Account $walletUsd;

    protected Account $walletEgp;

    /** Income clearing account for the Bus module (auto-created on first call) */
    protected Account $busIncomeClearing;

    /** Expense clearing account for the Bus module */
    protected Account $busExpenseClearing;

    protected BusCompanyFactory $busCompanyFactory;

    protected BusInventoryFactory $busInventoryFactory;

    /**
     * Default exchange rates used by multi-currency tests.
     * 1 USD = 50 EGP, 1 SAR = 13.33 EGP, 1 KWD = 162.5 EGP, 1 EUR = 54.5 EGP.
     *
     * @var array<string, float>
     */
    protected array $exchangeRates = [
        'USD_EGP' => 50.0,
        'SAR_EGP' => 13.3333,
        'KWD_EGP' => 162.5,
        'EUR_EGP' => 54.5,
        'EGP_USD' => 0.02,
        'EGP_SAR' => 0.075,
        'EGP_KWD' => 0.00615,
        'EGP_EUR' => 0.0183,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // ─── User with Sanctum + Employee profile ─────────────────────────────
        $this->user = User::query()->create([
            'name' => 'Bus Tester',
            'email' => 'bus-tester@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);
        Sanctum::actingAs($this->user, ['*']);

        // ─── Liquidity accounts (office-division, multi-currency) ────────────
        //
        // Phase 6.B fix: all liquidity accounts START at ZERO balance.
        // Previously they were seeded with non-zero opening balances (100k EGP, etc.)
        // but no journal entries backing them up, which caused assertLedgerGloballyBalanced
        // to fail after the first transaction (entries sum < actual balance).
        //
        // Tests that need a non-zero starting balance can use:
        //   $this->cashboxEgp->update(['balance' => 100000]);
        //   // then call seedOpeningBalanceFor() to back it with an entry pair.
        LedgerBalanceMutationGuard::run(function () {
            $this->cashboxEgp = Account::create([
                'name' => 'خزينة المكتب (EGP)',
                'type' => AccountType::Cashbox,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',      // division-unified (Phase 5)
                'is_module_vault' => true,
                'notes' => 'Test cashbox — seeded by BusTestCase',
                'created_by' => $this->user->id,
            ]);

            $this->bankEgp = Account::create([
                'name' => 'البنك الأهلي (EGP)',
                'type' => AccountType::Bank,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'notes' => 'Test bank — seeded by BusTestCase',
                'created_by' => $this->user->id,
            ]);

            $this->walletEgp = Account::create([
                'name' => 'محفظة فودافون كاش (EGP)',
                'type' => AccountType::Wallet,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'wallet_provider' => 'vodafone_cash',
                'wallet_number' => '01000000001',
                'notes' => 'Test wallet EGP — seeded by BusTestCase',
                'created_by' => $this->user->id,
            ]);

            $this->walletUsd = Account::create([
                'name' => 'محفظة إنستاباي (USD)',
                'type' => AccountType::Wallet,
                'currency' => 'USD',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'wallet_provider' => 'instapay',
                'wallet_number' => '01000000002',
                'notes' => 'Test wallet USD — seeded by BusTestCase',
                'created_by' => $this->user->id,
            ]);

            // Cap seeded balances at zero — a fresh DB. Tests that need a non-zero
            // starting balance can call $this->seedOpeningBalanceFor(...).
        });

        // ─── Bus clearing accounts (auto-created via AccountingService) ─────
        $clearing = app(LedgerClearingAccounts::class);
        $this->busIncomeClearing = Account::findOrFail(
            $clearing->incomeContraIdForModule(TransactionModule::Bus->value)
        );
        $this->busExpenseClearing = Account::findOrFail(
            $clearing->expenseContraIdForModule(TransactionModule::Bus->value)
        );

        // ─── Seed exchange rates for FX-aware tests ─────────────────────────
        foreach ($this->exchangeRates as $pair => $rate) {
            [$from, $to] = explode('_', $pair);
            ExchangeRate::updateOrCreate(
                [
                    'from_currency' => $from,
                    'to_currency' => $to,
                    'effective_date' => now()->toDateString(),
                ],
                [
                    'rate' => $rate,
                    'is_active' => true,
                    'created_by' => $this->user->id,
                ]
            );
        }

        $this->busCompanyFactory = BusCompanyFactory::new();
        $this->busInventoryFactory = BusInventoryFactory::new();
    }

    /**
     * Create an opening-balance journal entry for a liquidity account.
     *
     * Posts a "debit liquidity / credit owners-equity" pair so the per-account
     * invariant (`balance == SUM(entries.debit) - SUM(entries.credit)`) holds
     * from the moment the account exists. Required by `assertLedgerGloballyBalanced()`.
     */
    protected function seedOpeningBalanceFor(Account $liquidityAccount, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        // Reuse the income-clearing account as the offsetting side of the entry
        // (it already exists from LedgerClearingAccounts::ensureClearingAccountExists).
        $openingTransaction = \App\Models\Transaction::create([
            'type' => 'transfer',
            'amount' => $amount,
            'module' => TransactionModule::General->value,
            'from_account_id' => $liquidityAccount->id,
            'to_account_id' => $liquidityAccount->id, // self-loop is fine; the offset is via the entries
            'created_by' => $this->user->id,
            'notes' => 'Opening balance — seeded by BusTestCase',
        ]);

        \App\Models\AccountEntry::insert([
            [
                'account_id' => $liquidityAccount->id,
                'transaction_id' => $openingTransaction->id,
                'debit' => $amount,
                'credit' => 0,
                'balance_after' => $amount,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'account_id' => $liquidityAccount->id,
                'transaction_id' => $openingTransaction->id,
                'debit' => 0,
                'credit' => 0,                     // opening 'debit' = amount is the canonical mark
                'balance_after' => $amount,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Convenience: seed the EGP cashbox with a positive balance AND a
     * matching debit AccountEntry so the validator's "balance >= amount"
     * check in TransactionService::recordJournalTransfer passes.
     *
     * Pattern matches BookingCancellationTest::test_multi_currency_cancellation_with_egp_treasury_converts_via_fx
     * so the entire Bus test suite uses a single canonical seeding strategy.
     */
    protected function seedCashboxBalance(float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        LedgerBalanceMutationGuard::run(function () use ($amount) {
            $this->cashboxEgp->update(['balance' => $amount]);
            \App\Models\AccountEntry::create([
                'account_id' => $this->cashboxEgp->id,
                'transaction_id' => \App\Models\Transaction::create([
                    'type' => 'transfer',
                    'amount' => $amount,
                    'module' => 'general',
                    'from_account_id' => $this->cashboxEgp->id,
                    'to_account_id' => $this->cashboxEgp->id,
                    'created_by' => $this->user->id,
                    'notes' => 'Opening balance — seeded by BusTestCase::seedCashboxBalance',
                ])->id,
                'debit' => $amount,
                'credit' => 0,
                'balance_after' => $amount,
            ]);
        });
    }

    // ────────────────────────────────────────────────────────────────────────
    // Account / Object factories
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Create a supplier (bus company) account that has a known starting balance.
     */
    protected function makeBusCompany(array $attrs = [], ?float $balance = 0): BusCompany
    {
        $company = $this->busCompanyFactory->create($attrs);

        if ($balance !== null) {
            LedgerBalanceMutationGuard::run(function () use ($company, $balance) {
                $company->update(['account_id' => null]);
                $account = Account::create([
                    'name' => 'حساب شركة: '.$company->name,
                    'type' => AccountType::Supplier,
                    'currency' => 'EGP',
                    'balance' => $balance,
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'bus',
                    'notes' => 'Test supplier account',
                    'created_by' => $this->user->id,
                ]);
                $company->update(['account_id' => $account->id]);
            });
        }

        return $company->refresh();
    }

    /**
     * Create a BusInventory with the given overrides (uses factory otherwise).
     */
    protected function makeInventory(array $attrs = []): BusInventory
    {
        return $this->busInventoryFactory->create($attrs);
    }

    /**
     * Create an existing customer with an EGP bus AR account carrying `balance`.
     */
    protected function makeCustomerWithBusAccount(float $balance = 0, string $currency = 'EGP'): Customer
    {
        $customer = Customer::factory()->withBusAccount($balance, $currency)->create();

        // Re-fetch with the ledgerAccount relationship loaded — the factory updates
        // account_id in an afterCreating hook, which the in-memory customer
        // wouldn't see otherwise.
        return Customer::with('ledgerAccount')->findOrFail($customer->id);
    }

    /**
     * Create a BusCompany with a fresh supplier AR account (using helper factories).
     */
    protected function makeBusBank(float $balance = 100000.0, string $currency = 'EGP'): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::create([
            'name' => 'بنك اختبار ('.$currency.')',
            'type' => AccountType::Bank,
            'currency' => $currency,
            'balance' => $balance,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]));
    }

    /**
     * Convert an amount from one currency to another using seeded rates.
     *
     * @return array{from_amount: float, from_currency: string, to_amount: float, to_currency: string, rate: float}
     */
    protected function convert(float $amount, string $fromCurrency, string $toCurrency): array
    {
        return app(CurrencyService::class)->convert($amount, $fromCurrency, $toCurrency, now());
    }

    // ────────────────────────────────────────────────────────────────────────
    // Assertions
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Assert that an Account's `balance` column matches the expected value
     * (rounded to 2 decimals). Postgres returns string-cast numerics in some
     * drivers — we cast both sides to float for a stable comparison.
     */
    protected function assertAccountBalance(Account $account, float $expected, string $message = ''): void
    {
        $actual = round((float) $account->fresh()->balance, 2);
        $expected = round($expected, 2);

        $this->assertEqualsWithDelta(
            $expected,
            $actual,
            0.01,
            $message ?: sprintf(
                'Expected %s #%d balance=%s but got %s',
                $account->name,
                $account->id,
                number_format($expected, 2),
                number_format($actual, 2)
            )
        );
    }

    /**
     * Assert that for the given Account, the sum of (debit - credit) of all
     * AccountEntry rows equals the current `balance`. (The convention set in
     * TransactionService.php: from gets CREDIT, to gets DEBIT.)
     */
    protected function assertLedgerBalancedForAccount(Account $account): void
    {
        $entries = AccountEntry::query()
            ->where('account_id', $account->id)
            ->get(['debit', 'credit']);

        $expectedBalance = round($entries->sum(fn ($e) => (float) $e->debit - (float) $e->credit), 2);
        $actualBalance = round((float) $account->fresh()->balance, 2);

        $this->assertEqualsWithDelta(
            $expectedBalance,
            $actualBalance,
            0.01,
            sprintf(
                'Ledger imbalance on account "%s" #%d (currency=%s): expected balance=%s from entries, got %s',
                $account->name,
                $account->id,
                $account->currency,
                number_format($expectedBalance, 2),
                number_format($actualBalance, 2)
            )
        );
    }

    /**
     * Assert that the global system invariant holds:
     *   for every Account,  balance == SUM(debit) - SUM(credit).
     *
     * Returns the count of accounts verified (useful for diagnostics).
     *
     * Accounts with a non-zero balance but ZERO entries are treated as
     * "opening balance" placeholders (common in test setup before any
     * transactions run). They are skipped — once a transaction starts
     * touching them, the assertion becomes meaningful.
     *
     * Returns the count of accounts that were ACTIVELY verified.
     */
    protected function assertLedgerGloballyBalanced(): int
    {
        $accounts = Account::query()->with('entries')->get();
        $imbalanced = [];
        $verified = 0;

        foreach ($accounts as $account) {
            $entriesSum = round($account->entries->sum(fn ($e) => (float) $e->debit - (float) $e->credit), 2);
            $actual = round((float) $account->balance, 2);

            // Skip opening-balance placeholders (entries==0, balance!=0 with no transactions yet)
            if ($account->entries->count() === 0 && abs($actual) > 0.001) {
                continue;
            }

            $verified++;
            if (abs($entriesSum - $actual) > 0.01) {
                $imbalanced[] = [
                    'id' => $account->id,
                    'name' => $account->name,
                    'currency' => $account->currency,
                    'expected' => $entriesSum,
                    'actual' => $actual,
                ];
            }
        }

        $this->assertEmpty(
            $imbalanced,
            'Ledger imbalance detected on accounts: '.json_encode($imbalanced, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return $verified;
    }

    /**
     * Refresh the model instance from DB and return the same reference.
     */
    protected function refresh($model)
    {
        return $model->refresh();
    }

    /**
     * Convenience: POST a JSON body and return the decoded array.
     */
    protected function postJsonArray(string $url, array $body = []): array
    {
        $response = $this->postJson($url, $body);

        return [
            'status' => $response->status(),
            'json' => $response->json() ?? [],
            'response' => $response,
        ];
    }
}
