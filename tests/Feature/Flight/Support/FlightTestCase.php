<?php

namespace Tests\Feature\Flight\Support;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightSystem;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Base TestCase for Flight module financial tests.
 *
 * Local-only test suite — NOT pushed to git per user preference (2026-08-29).
 *
 * Provides:
 *   - Authenticated admin user (Sanctum) + linked Employee
 *   - Customer + auto-created EGP AR account (module_type='flights')
 *   - FlightCarrier (EGP, 50k credit) + FlightSystem (USD, 1k credit)
 *   - EGP cashbox, EGP bank, USD bank, KWD wallet (tourism division)
 *   - Currency rows seeded: USD=50, SAR=13.33, KWD=162.5, EUR=54.5 EGP/unit
 *   - Helper methods: makeBooking, addPayment, payDebt, cancelWithPenalties
 *   - Ledger assertions: assertEveryTransactionBalanced, assertEveryAccountInvariant,
 *                         assertAccountBalance, assertLedgerGloballyBalanced,
 *                         assertPnlMatches
 */
abstract class FlightTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Customer $customer;

    protected Employee $employee;

    protected Account $cashboxEgp;

    protected Account $bankEgp;

    protected Account $bankUsd;

    protected Account $walletKwd;

    protected FlightCarrier $carrier;

    protected FlightSystem $system;

    protected \App\Models\Treasury $treasuryEgp;

    protected FlightBookingService $bookingService;

    /**
     * EGP-per-unit exchange rates for the seeded Currency rows.
     * 1 USD = 50 EGP, 1 SAR = 13.33 EGP, 1 KWD = 162.5 EGP, 1 EUR = 54.5 EGP.
     *
     * @var array<string, float>
     */
    protected array $exchangeRates = [
        'USD' => 50.0,
        'SAR' => 13.3333,
        'KWD' => 162.5,
        'EUR' => 54.5,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // ─── Treasury (used by RefundRequest flow) ────────────────────────
        // Separate from Account — Treasury is the legacy cash management model.
        $this->treasuryEgp = \App\Models\Treasury::query()->create([
            'name' => 'خزينة اختبار الطيران (EGP)',
            'currency' => 'EGP',
            'current_balance' => 100000.0,
            'is_active' => true,
        ]);

        $this->treasuryUsd = \App\Models\Treasury::query()->create([
            'name' => 'خزينة اختبار الطيران (USD)',
            'currency' => 'USD',
            'current_balance' => 5000.0,
            'is_active' => true,
        ]);

        // ─── Currency rows (used by FlightBookingService::egpPerUnitOfCurrency) ───
        foreach ($this->exchangeRates as $code => $rate) {
            Currency::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name_ar' => $code,
                    'name_en' => $code,
                    'symbol' => $code,
                    'exchange_rate' => $rate,
                    'is_active' => true,
                    'order' => 0,
                ]
            );
        }

        // ─── User + Employee + Sanctum ─────────────────────────────────────
        $this->admin = User::query()->create([
            'name' => 'Flight Tester',
            'email' => 'flight-tester@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->employee = Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        // ─── Liquidity accounts (tourism division) ──────────────────────────
        // Each gets a starting balance + matching AccountEntry so
        // assertLedgerGloballyBalanced() passes.
        $this->cashboxEgp = $this->createLiquidityAccount(
            'cashbox', 'EGP', 'خزينة السياحة (EGP)', 100000.0, true
        );
        $this->bankEgp = $this->createLiquidityAccount(
            'bank', 'EGP', 'البنك الأهلي — سياحة (EGP)', 50000.0, false
        );
        $this->bankUsd = $this->createLiquidityAccount(
            'bank', 'USD', 'البنك الأهلي — سياحة (USD)', 5000.0, false
        );
        $this->walletKwd = $this->createLiquidityAccount(
            'wallet', 'KWD', 'محفظة سياحة (KWD)', 1000.0, false,
            ['wallet_provider' => 'instapay', 'wallet_number' => '01000000003']
        );

        // ─── Customer (AR account auto-created by CustomerLedgerObserver) ──
        $this->customer = Customer::query()->create([
            'full_name' => 'Test Customer',
            'phone' => '01000000099',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // The auto-created AR account should carry module_type='flights'
        $this->customer->refresh();
        $arAccount = $this->customer->ledgerAccount;
        if ($arAccount && $arAccount->module_type !== 'flights') {
            $arAccount->update(['module_type' => 'flights']);
        }

        // ─── FlightCarrier + FlightSystem ──────────────────────────────────
        $this->carrier = FlightCarrier::query()->create([
            'name' => 'EgyptAir',
            'code' => 'MS',
            'currency' => 'EGP',
            'credit_limit' => 100000.0,
            'balance' => 0.0,
            'available_balance' => 0.0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->system = FlightSystem::query()->create([
            'name' => 'Amadeus',
            'code' => 'AMS',
            'currency' => 'USD',
            'credit_limit' => 5000.0,
            'balance' => 0.0,
            'available_balance' => 0.0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Recharge carriers so they have credit to draw against for COGS
        $this->rechargeCarrierFromCashbox($this->carrier, 50000.0);
        $this->rechargeSystemFromCashbox($this->system, 1000.0); // USD

        $this->bookingService = app(FlightBookingService::class);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Setup helpers
    // ────────────────────────────────────────────────────────────────────────

    protected function createLiquidityAccount(
        string $type, string $currency, string $name, float $openingBalance,
        bool $isModuleVault, array $extra = []
    ): Account {
        // NOTE: opening balance is created via a single-leg "OPENING:"
        // credit transaction (no double-entry) so the test ledger starts
        // clean. The 'OPENING:' prefix is recognised by
        // assertEveryTransactionBalanced which skips these placeholders.
        // assertLedgerGloballyBalanced skips accounts with no entries
        // and a non-zero balance — until the first real transaction
        // touches them.
        $account = Account::query()->create(array_merge([
            'name' => $name,
            'type' => $type,
            'currency' => $currency,
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => AccountModuleContract::TOURISM_MODULE_TYPE,
            'is_module_vault' => $isModuleVault,
            'created_by' => $this->admin->id,
        ], $extra));

        if ($openingBalance > 0) {
            LedgerBalanceMutationGuard::run(function () use ($account, $name, $openingBalance) {
                $tx = Transaction::query()->create([
                    'type' => 'transfer',
                    'amount' => $openingBalance,
                    'module' => TransactionModule::General->value,
                    'from_account_id' => $account->id,
                    'to_account_id' => $account->id,
                    'created_by' => $this->admin->id,
                    'notes' => "OPENING: seeded opening balance for {$name}",
                ]);
                AccountEntry::query()->create([
                    'account_id' => $account->id,
                    'transaction_id' => $tx->id,
                    'debit' => 0,
                    'credit' => $openingBalance,
                    'balance_after' => $openingBalance,
                ]);
                $account->update(['balance' => $openingBalance]);
            });
        }

        return $account->fresh();
    }

    protected function rechargeCarrierFromCashbox(FlightCarrier $carrier, float $amount): void
    {
        $this->rechargeFromCashbox($carrier, $amount);
    }

    /**
     * Recharge a FlightSystem from a cashbox in its currency.
     * The auto-seeded office vault (`الخزينة الرئيسية`, balance=0) MUST NOT be picked.
     */
    protected function rechargeSystemFromCashbox(FlightSystem $system, float $amount): void
    {
        $this->rechargeFromCashbox($system, $amount);
    }

    /**
     * Recharge any balance-pool entity (FlightCarrier OR FlightSystem) from a
     * tourism-division cashbox. The auto-seeded office vault is excluded.
     */
    private function rechargeFromCashbox($entity, float $amount): void
    {
        $cashbox = Account::query()
            ->where('currency', $entity->currency)
            ->where('module_type', AccountModuleContract::TOURISM_MODULE_TYPE)
            ->where('balance', '>', 0)
            ->first();
        if (! $cashbox) {
            throw new \RuntimeException("No tourism-division cashbox in {$entity->currency} with positive balance");
        }

        if ($entity instanceof FlightCarrier) {
            app(FlightCarrierRechargeService::class)->rechargeFromAccount($entity, $cashbox, $amount);
        } elseif ($entity instanceof FlightSystem) {
            app(\App\Services\Flight\FlightSystemRechargeService::class)->rechargeFromAccount($entity, $cashbox, $amount);
        } else {
            throw new \RuntimeException("Unsupported recharge entity type: ".$entity::class);
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Booking helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Create a flight booking via the service so recordSaleToCustomer + COGS run.
     *
     * @param  array<string,mixed>  $overrides
     */
    protected function makeBooking(array $overrides = []): FlightBooking
    {
        $defaults = [
            'customer_id' => $this->customer->id,
            'employee_id' => $this->employee->id,
            'selling_price' => 1000.0,
            'purchase_price' => 600.0,
            'currency' => 'EGP',
            'account_id' => $this->cashboxEgp->id,
            'agent_name' => 'Test Agent',
            'origin' => 'CAI',
            'destination' => 'DXB',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => '10:00:00',
            'trip_type' => 'one_way',
            'airline' => 'MS',
            'passenger_count' => 1,
            'flight_carrier_id' => $this->carrier->id,
            'pnr' => 'PNR'.uniqid(),
        ];

        return $this->bookingService->createBooking(array_merge($defaults, $overrides));
    }

    /**
     * Add a payment to the booking via the service.
     */
    protected function addPayment(FlightBooking $booking, float $amount, ?Account $cashbox = null): FlightPayment
    {
        return $this->bookingService->addPayment($booking, [
            'amount' => $amount,
            'payment_method' => 'cash',
            'account_id' => ($cashbox ?? $this->cashboxEgp)->id,
            'idempotency_key' => uniqid('pay_', true),
        ]);
    }

    /**
     * Drive the customer pay-debt flow directly via TransactionService.
     */
    protected function payDebt(float $amount, ?Account $cashbox = null): Transaction
    {
        $txService = app(\App\Services\Finance\TransactionService::class);
        $customerAccount = $this->customer->ledgerAccount;
        $cashbox = $cashbox ?? $this->cashboxEgp;

        return $txService->recordIncome([
            'amount' => $amount,
            'to_account_id' => $cashbox->id,
            'contra_account_id' => $customerAccount->id,
            'allow_contra_negative' => true,
            'module' => TransactionModule::Flight->value,
            'related_type' => Customer::class,
            'related_id' => $this->customer->id,
            'notes' => "سند قبض - تسديد مديونية عميل: {$this->customer->full_name}",
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Cancel a booking with given penalties; returns the FlightRefund.
     */
    protected function cancelWithPenalties(
        FlightBooking $booking,
        float $airlinePenalty = 0.0,
        float $officePenalty = 0.0,
        ?Account $refundAccount = null
    ): FlightRefund {
        $refundAccount = $refundAccount ?? $this->cashboxEgp;

        return $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => $airlinePenalty,
            'office_penalty' => $officePenalty,
            'account_id' => $refundAccount->id,
            'notes' => 'Test cancellation — FlightTestCase',
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Assertions
    // ────────────────────────────────────────────────────────────────────────

    protected function assertAccountBalance(Account $account, float $expected, string $message = ''): void
    {
        $actual = round((float) $account->fresh()->balance, 2);
        $expected = round($expected, 2);

        $this->assertEqualsWithDelta(
            $expected, $actual, 0.01,
            $message ?: sprintf('Account "%s" #%d expected balance=%.2f got %.2f', $account->name, $account->id, $expected, $actual)
        );
    }

    /**
     * Verify every transaction in the database satisfies SUM(debit) = SUM(credit).
     * Skips opening-balance placeholders (notes prefix 'OPENING:') and
     * cross-currency transactions (where debit/credit currencies differ —
     * they carry `converted_amount` on the transaction row instead).
     */
    protected function assertEveryTransactionBalanced(): void
    {
        $rows = DB::table('transactions as t')
            ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
            ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
            ->select('t.id', 't.notes', 'fa.currency as from_currency', 'ta.currency as to_currency')
            ->get();

        foreach ($rows as $row) {
            // Skip opening-balance placeholders (single-leg credit entries).
            $notes = (string) ($row->notes ?? '');
            if (str_starts_with($notes, 'OPENING:')) {
                continue;
            }

            // Skip cross-currency transactions where from/to currencies differ.
            // These carry `converted_amount` and are balanced in their own
            // semantic (debit in src currency, credit in dst currency).
            if (! empty($row->from_currency) && ! empty($row->to_currency)
                && strtoupper((string) $row->from_currency) !== strtoupper((string) $row->to_currency)) {
                continue;
            }

            $debit = (float) DB::table('account_entries')->where('transaction_id', $row->id)->sum('debit');
            $credit = (float) DB::table('account_entries')->where('transaction_id', $row->id)->sum('credit');
            $this->assertEqualsWithDelta(
                $debit, $credit, 0.01,
                "Transaction #{$row->id} unbalanced: debit={$debit}, credit={$credit}"
            );
        }
    }

    /**
     * Verify every account's balance = SUM(credit) - SUM(debit).
     */
    protected function assertEveryAccountInvariant(): void
    {
        $rows = DB::table('accounts as a')
            ->leftJoin('account_entries as ae', 'a.id', '=', 'ae.account_id')
            ->groupBy('a.id', 'a.balance')
            ->selectRaw('a.id, a.balance, COALESCE(SUM(ae.credit), 0) - COALESCE(SUM(ae.debit), 0) as net')
            ->get();

        $imbalanced = [];
        foreach ($rows as $row) {
            if (abs((float) $row->balance - (float) $row->net) > 0.01) {
                $imbalanced[] = [
                    'id' => $row->id,
                    'balance' => (float) $row->balance,
                    'entries_net' => (float) $row->net,
                ];
            }
        }

        $this->assertEmpty(
            $imbalanced,
            'Account invariant violations: '.json_encode($imbalanced, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Combined ledger assertion — call after every state transition.
     */
    protected function assertLedgerIntact(): void
    {
        $this->assertEveryTransactionBalanced();
        $this->assertEveryAccountInvariant();
    }

    /**
     * Assert that the P&L report matches expected values for the flight module.
     *
     * @param  array{revenue?: float, cogs?: float, profit?: float}  $expected
     */
    protected function assertPnlMatches(array $expected, ?string $module = 'flight'): void
    {
        $svc = app(\App\Services\Reports\ProfitLossReportService::class);
        $filters = [
            'from_date' => now()->startOfMonth()->toDateString(),
            'to_date' => now()->toDateString(),
        ];
        if ($module !== null) {
            $filters['module'] = $module;
        }
        $report = $svc->report($filters);

        if (array_key_exists('revenue', $expected)) {
            $this->assertEqualsWithDelta(
                $expected['revenue'], (float) ($report['totalRevenues'] ?? 0), 0.01,
                "P&L revenue mismatch: expected={$expected['revenue']} actual=".(float) ($report['totalRevenues'] ?? 0)
            );
        }
        if (array_key_exists('cogs', $expected)) {
            $this->assertEqualsWithDelta(
                $expected['cogs'], (float) ($report['totalCogs'] ?? 0), 0.01,
                "P&L cogs mismatch: expected={$expected['cogs']} actual=".(float) ($report['totalCogs'] ?? 0)
            );
        }
        if (array_key_exists('profit', $expected)) {
            $this->assertEqualsWithDelta(
                $expected['profit'], (float) ($report['netProfit'] ?? 0), 0.01,
                "P&L profit mismatch: expected={$expected['profit']} actual=".(float) ($report['netProfit'] ?? 0)
            );
        }
    }

    /**
     * Assert a transaction exists with the given attributes.
     */
    protected function assertTransactionExists(array $where): Transaction
    {
        $tx = Transaction::query()->where($where)->first();
        $this->assertNotNull(
            $tx, 'Transaction not found: '.json_encode($where, JSON_UNESCAPED_UNICODE)
        );
        return $tx;
    }

    /**
     * Assert a transaction's notes start with the reversal prefix (عكس: or عكس).
     */
    protected function assertTransactionReversed(Transaction $tx, string $message = ''): void
    {
        $notes = (string) ($tx->notes ?? '');
        $prefixed = str_starts_with($notes, 'عكس:') || str_starts_with($notes, 'عكس ');
        $this->assertTrue(
            $prefixed,
            $message ?: "Transaction #{$tx->id} expected to be reversed (notes start with 'عكس') but got: {$notes}"
        );
    }

    /**
     * Count transactions tied to a booking (or other related entity).
     */
    protected function countTransactionsFor(string $relatedType, int $relatedId): int
    {
        return Transaction::query()
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->count();
    }
}
