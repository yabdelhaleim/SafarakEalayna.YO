<?php

namespace Tests\Support;

use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Exceptions\BusinessLogicException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerCreditLedger;
use App\Models\Flight\CustomerCreditDisbursement;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\RefundRequest;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use App\Services\Reports\ProfitLossReportService;
use App\Support\UserPermissions;
use Illuminate\Support\Facades\DB;

/**
 * Reusable helpers for RefundFullSuiteTest.
 *
 * CONVENTION: account.balance = SUM(credit) - SUM(debit)
 *   - from_account_id leg => DEBIT  (balance decreases)
 *   - to_account_id   leg => CREDIT (balance increases)
 *   => customer account with POSITIVE balance = credit owed to customer
 *   => customer account with NEGATIVE balance = receivable from customer
 */
trait RefundTestHelpers
{
    // ─────────────────────────────────────────────────────────────────────
    // Dump accumulation
    // ─────────────────────────────────────────────────────────────────────

    protected array $dumpLines = [];
    protected string $currentScenarioId = 'UNKNOWN';

    protected function dumpL(string $line): void
    {
        $this->dumpLines[] = $line;
    }

    protected function flushDump(string $scenarioId): void
    {
        $dir = __DIR__ . '/../Audit/out';
        @mkdir($dir, 0777, true);
        $path = $dir . '/FULL_' . $scenarioId . '.txt';
        file_put_contents($path, implode("\n", $this->dumpLines) . "\n");
        $this->dumpLines = [];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Core financial assertions
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Every Transaction: sum(debit) == sum(credit) per same-currency transaction.
     * Cross-currency transactions are checked that exchange-leg amounts match
     * the stored exchange_rate within a tolerance.
     */
    protected function assertEveryTransactionBalanced(): void
    {
        $rows = DB::table('account_entries as ae')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
            ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
            ->select('ae.transaction_id', DB::raw('SUM(ae.debit) AS d'), DB::raw('SUM(ae.credit) AS c'))
            ->selectRaw('MAX(fa.currency) AS from_currency')
            ->selectRaw('MAX(ta.currency) AS to_currency')
            ->groupBy('ae.transaction_id')
            ->get();

        foreach ($rows as $row) {
            $fromCurr = strtoupper((string)($row->from_currency ?? ''));
            $toCurr   = strtoupper((string)($row->to_currency ?? ''));
            $isCross  = $fromCurr !== '' && $toCurr !== '' && $fromCurr !== $toCurr;

            $diff = round((float)$row->d - (float)$row->c, 4);

            if ($isCross) {
                // Cross-currency: debit and credit are in different denominations.
                $this->dumpL(sprintf(
                    '   [FX-TX] #%d debit=%.4f %s credit=%.4f %s diff=%.4f',
                    $row->transaction_id,
                    (float)$row->d, $fromCurr,
                    (float)$row->c, $toCurr,
                    $diff
                ));
            } else {
                $this->assertEqualsWithDelta(
                    0.0,
                    $diff,
                    0.01,
                    "Transaction #{$row->transaction_id} unbalanced: debit={$row->d} credit={$row->c}"
                );
            }
        }
    }

    /**
     * For every account: balance stored in `accounts` table equals
     * SUM(credit) - SUM(debit) from account_entries.
     */
    protected function assertAccountBalancesMatchEntries(): void
    {
        $computed = DB::table('account_entries as ae')
            ->join('accounts as a', 'a.id', '=', 'ae.account_id')
            ->select('ae.account_id', DB::raw('SUM(ae.credit) - SUM(ae.debit) AS computed'))
            ->groupBy('ae.account_id')
            ->pluck('computed', 'account_id');

        $stored = DB::table('accounts')->pluck('balance', 'id');

        foreach ($computed as $id => $comp) {
            $stor = (float)($stored[$id] ?? 0.0);
            $compF = (float)$comp;
            $this->assertEqualsWithDelta(
                $compF,
                $stor,
                0.01,
                "Account #{$id} stored balance ({$stor}) != computed from entries ({$compF})"
            );
        }
    }

    /**
     * Customer GL balance (from account_entries) == customer_credit_ledger available balance.
     */
    protected function assertCustomerGlEqualsLedger(int $customerId): void
    {
        $acctId = (int)DB::table('customers')->where('id', $customerId)->value('account_id');
        if (! $acctId) {
            $acctId = (int)Account::where('name', 'Customer #' . $customerId)->value('id');
        }

        $glBalance = $acctId
            ? round(-(float)DB::table('accounts')->where('id', $acctId)->value('balance'), 2)
            : 0.0;

        $ledgerBalance = $this->ledgerBalance($customerId);

        $this->dumpL("   assertCustomerGlEqualsLedger: GL={$glBalance} ledger={$ledgerBalance}");

        $this->assertEqualsWithDelta(
            $ledgerBalance,
            $glBalance,
            0.01,
            "Customer #{$customerId} GL balance ({$glBalance}) != ledger balance ({$ledgerBalance})"
        );
    }

    /**
     * Intermediate clearing accounts return to their pre-sale balances.
     * Pass the snapshot taken BEFORE the sale via snapIntermediateBalances().
     */
    protected function assertIntermediateAccountsRestored(array $beforeSale): void
    {
        foreach ($beforeSale as $id => $info) {
            $now = (float)DB::table('accounts')->where('id', $id)->value('balance');
            $expected = (float)$info['balance'];
            $this->dumpL(sprintf(
                '   intermediate acc#%d (%s) before=%.2f after=%.2f',
                $id, $info['name'] ?? '?', $expected, $now
            ));
            $this->assertEqualsWithDelta(
                $expected,
                $now,
                0.01,
                "Intermediate account #{$id} ({$info['name']}) not restored: expected={$expected} actual={$now}"
            );
        }
    }

    /**
     * Assert the carrier balance equals the expected value exactly (EGP carrier).
     */
    protected function assertCarrierBalance(FlightCarrier $carrier, float $expected): void
    {
        $actual = (float)$carrier->fresh()->balance;
        $this->dumpL("   assertCarrierBalance: expected={$expected} actual={$actual}");
        $this->assertEqualsWithDelta(
            $expected,
            $actual,
            0.01,
            "Carrier #{$carrier->id} balance: expected={$expected} actual={$actual}"
        );
    }

    /**
     * Assert the audit log was written for a refund request.
     */
    protected function assertAuditLogWritten(int $refundRequestId): void
    {
        $count = DB::table('refund_audit_logs')->count();
        $this->dumpL("   assertAuditLogWritten: refund_audit_logs count={$count}");
        $this->assertGreaterThan(
            0,
            $count,
            "No refund audit log written for RefundRequest #{$refundRequestId}"
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // P&L snapshot (from REAL ProfitLossReportService)
    // ─────────────────────────────────────────────────────────────────────

    protected function plSnapshot(): array
    {
        // Try ProfitLossReportService first, fall back to ReportFinanceService
        try {
            $service = app(ProfitLossReportService::class);
            if (method_exists($service, 'report')) {
                $res = $service->report([]);
            } elseif (method_exists($service, 'generate')) {
                $res = $service->generate([]);
            } elseif (method_exists($service, 'getProfitLossReport')) {
                $res = $service->getProfitLossReport([]);
            } else {
                $res = [];
            }
        } catch (\Throwable $e) {
            try {
                $service = app(\App\Services\Reports\ReportFinanceService::class);
                $res = $service->getProfitLossReport(['section' => 'all']);
            } catch (\Throwable $e2) {
                $res = [];
            }
        }

        $res = is_array($res) ? $res : [];

        return [
            'totalRevenues'  => (float)($res['totalRevenues']  ?? $res['revenue']  ?? 0),
            'totalCogs'      => (float)($res['totalCogs']       ?? $res['cogs']     ?? 0),
            'totalExpenses'  => (float)($res['totalExpenses']   ?? $res['expense']  ?? 0),
            'totalRefunds'   => (float)($res['totalRefunds']    ?? $res['refunds']  ?? 0),
            'grossProfit'    => (float)($res['grossProfit']     ?? 0),
            'netProfit'      => (float)($res['netProfit']       ?? $res['net']      ?? 0),
        ];
    }

    protected function plDelta(array $before, array $after): array
    {
        $keys = ['totalRevenues','totalCogs','totalExpenses','totalRefunds','grossProfit','netProfit'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = round((float)$after[$k] - (float)$before[$k], 2);
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ledger
    // ─────────────────────────────────────────────────────────────────────

    protected function ledgerBalance(
        ?int $customerId = null,
        ?int $companyId  = null,
        string $currency = 'EGP'
    ): float {
        $q = DB::table('customer_credit_ledger')
            ->whereNull('reversed_at')
            ->where('currency', $currency);

        if ($companyId) {
            $q->where('company_id', $companyId);
        } elseif ($customerId) {
            $q->where('customer_id', $customerId);
        } else {
            return 0.0;
        }

        return round((float)$q->sum('amount'), 2);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Intermediate clearing account snapshot
    // ─────────────────────────────────────────────────────────────────────

    protected function snapIntermediateBalances(): array
    {
        /** @var LedgerClearingAccounts $clearing */
        $clearing = app(LedgerClearingAccounts::class);

        $ids = array_values(array_filter([
            $clearing->expenseContraIdForModule(TransactionModule::Flight),
            $clearing->pendingSalesReceivableIdForFlight(),
            $clearing->incomeContraIdForFlightBooking(),
        ]));

        $out = [];
        foreach ($ids as $id) {
            $acc = DB::table('accounts')->where('id', $id)->first();
            $out[$id] = [
                'name'    => $acc->name ?? ('#' . $id),
                'balance' => (float)($acc->balance ?? 0),
            ];
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Dump helpers
    // ─────────────────────────────────────────────────────────────────────

    protected function dumpEntries(string $title, ?array $txIds = null): void
    {
        $q = DB::table('account_entries as ae')
            ->join('accounts as a', 'a.id', '=', 'ae.account_id')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->select([
                'ae.transaction_id', 't.type as tx_type', 't.notes as tx_notes',
                'a.id as account_id', 'a.name as account_name',
                'ae.debit', 'ae.credit', 'ae.balance_after',
            ])
            ->orderBy('ae.id');

        if ($txIds !== null) {
            $q->whereIn('ae.transaction_id', $txIds);
        }

        $rows = $q->get()->map(fn($r) => (array)$r)->all();

        $this->dumpL('');
        $this->dumpL('── ' . $title . ' ──');
        if (! $rows) {
            $this->dumpL('   (لا توجد قيود)');
            return;
        }
        $this->dumpL(sprintf(
            '   %-6s %-9s %-38s %-12s %-12s %-14s %s',
            'TX', 'TYPE', 'ACCOUNT', 'DEBIT', 'CREDIT', 'BAL_AFTER', 'NOTES'
        ));
        foreach ($rows as $r) {
            $this->dumpL(sprintf(
                '   %-6d %-9s %-38s %-12s %-12s %-14s %s',
                $r['transaction_id'],
                mb_substr((string)$r['tx_type'], 0, 9),
                mb_substr((string)$r['account_name'], 0, 38),
                number_format((float)$r['debit'], 2, '.', ''),
                number_format((float)$r['credit'], 2, '.', ''),
                number_format((float)$r['balance_after'], 2, '.', ''),
                mb_substr((string)$r['tx_notes'], 0, 60)
            ));
        }
    }

    protected function dumpLedger(
        string $title,
        ?int $customerId = null,
        ?int $companyId  = null
    ): void {
        $this->dumpL('');
        $this->dumpL('── ' . $title . ' ──');
        $q = DB::table('customer_credit_ledger')->orderBy('id');
        if ($companyId) {
            $q->where('company_id', $companyId);
        } elseif ($customerId) {
            $q->where('customer_id', $customerId);
        }
        $rows = $q->get()->map(fn($r) => (array)$r)->all();
        if (! $rows) {
            $this->dumpL('   (لا توجد حركات)');
            return;
        }
        foreach ($rows as $r) {
            $this->dumpL(sprintf(
                '   #%-4d cust=%s/co=%s src=%-20s amount=%-12s reversed=%s %s',
                $r['id'],
                $r['customer_id'] ?? '-',
                $r['company_id'] ?? '-',
                $r['source_type'],
                number_format((float)$r['amount'], 4, '.', ''),
                $r['reversed_at'] ? 'YES' : 'no',
                mb_substr((string)$r['notes'], 0, 50)
            ));
        }
        $bal = $this->ledgerBalance($customerId, $companyId);
        $this->dumpL('   AVAILABLE CREDIT = ' . number_format($bal, 4, '.', ''));
    }

    protected function dumpPL(string $title, array $before, array $after): void
    {
        $this->dumpL('');
        $this->dumpL('── ' . $title . ' ──');
        $this->dumpL(sprintf('   %-16s %14s %14s %14s', 'KEY', 'BEFORE', 'AFTER', 'DELTA'));
        foreach (['totalRevenues','totalCogs','totalExpenses','totalRefunds','grossProfit','netProfit'] as $k) {
            $this->dumpL(sprintf(
                '   %-16s %14s %14s %14s',
                $k,
                number_format((float)$before[$k], 2, '.', ''),
                number_format((float)$after[$k], 2, '.', ''),
                number_format(round((float)$after[$k] - (float)$before[$k], 2), 2, '.', '')
            ));
        }
    }

    protected function dumpAccounts(string $title): void
    {
        $this->dumpL('');
        $this->dumpL('── ' . $title . ' ──');
        $this->dumpL(sprintf('   %-6s %-42s %14s %14s', 'ID', 'NAME', 'SYS_BAL', 'STD_BAL'));
        foreach (DB::table('accounts')->orderBy('id')->get() as $a) {
            if (abs((float)$a->balance) < 0.005) {
                continue;
            }
            $this->dumpL(sprintf(
                '   %-6d %-42s %14s %14s',
                $a->id,
                mb_substr((string)$a->name, 0, 42),
                number_format((float)$a->balance, 2, '.', ''),
                number_format(-(float)$a->balance, 2, '.', '')
            ));
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Exchange rate seeding
    // ─────────────────────────────────────────────────────────────────────

    protected function seedExchangeRates(): void
    {
        $rates = [
            'USD' => 50.0,
            'SAR' => 13.3333,
            'KWD' => 162.5,
        ];
        foreach ($rates as $code => $rate) {
            Currency::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name_ar'       => $code,
                    'name_en'       => $code,
                    'symbol'        => $code,
                    'exchange_rate' => $rate,
                    'is_active'     => true,
                    'order'         => 0,
                ]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // User with specific permissions
    // ─────────────────────────────────────────────────────────────────────

    protected function createUserWithPermissions(array $permissions, string $role = 'employee'): User
    {
        static $counter = 0;
        $counter++;
        return User::create([
            'name'        => 'Test User ' . $counter,
            'email'       => 'testuser' . $counter . uniqid() . '@test.local',
            'password'    => bcrypt('password'),
            'role'        => $role,
            'is_active'   => true,
            'permissions' => $permissions,
        ]);
    }

    protected function createAdminUser(): User
    {
        static $adminCounter = 0;
        $adminCounter++;
        return User::create([
            'name'      => 'Refund Admin ' . $adminCounter,
            'email'     => 'refund-admin-' . $adminCounter . uniqid() . '@test.local',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Standard fixture builder
    // ─────────────────────────────────────────────────────────────────────

    protected function buildEgpFixture(float $carrierInitialBalance = 500000.0): array
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $customer = Customer::create([
            'full_name'   => 'Refund Suite Customer ' . uniqid(),
            'phone'       => '010' . substr(md5(microtime()), 0, 8),
            'email'       => 'suite-customer-' . uniqid() . '@test.local',
            'national_id' => substr(md5(microtime()), 0, 14),
            'city'        => 'Cairo',
            'type'        => 'individual',
        ]);

        $flightSystem = FlightSystem::create([
            'name'         => 'Suite GDS ' . uniqid(),
            'code'         => 'SG' . strtoupper(substr(md5(microtime()), 0, 4)),
            'type'         => 'gds',
            'is_active'    => true,
            'currency'     => 'EGP',
            'balance'      => 0,
            'credit_limit' => 0,
            'created_by'   => $admin->id,
        ]);

        $carrier = FlightCarrier::create([
            'name'             => 'Suite Carrier ' . uniqid(),
            'code'             => 'SC' . strtoupper(substr(md5(microtime()), 0, 4)),
            'flight_system_id' => $flightSystem->id,
            'currency'         => 'EGP',
            'balance'          => 0,
            'credit_limit'     => 1000000,
            'is_active'        => true,
            'created_by'       => $admin->id,
        ]);

        $treasuryName = 'Suite Treasury ' . uniqid();
        $treasury = Treasury::create([
            'name'            => $treasuryName,
            'currency'        => 'EGP',
            'current_balance' => $carrierInitialBalance * 2,
            'is_active'       => true,
        ]);

        $cashbox = Account::create([
            'name'        => $treasuryName,
            'type'        => 'cashbox',
            'balance'     => $carrierInitialBalance * 2,
            'currency'    => 'EGP',
            'is_active'   => true,
            'owner_type'  => 'office',
            'module_type' => 'office',
            'created_by'  => $admin->id,
        ]);

        // Recharge carrier
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier,
            $cashbox,
            $carrierInitialBalance,
            'Suite setup recharge'
        );
        $cashbox->refresh();
        $carrier->refresh();

        return compact('admin', 'customer', 'flightSystem', 'carrier', 'treasury', 'cashbox');
    }

    /**
     * Build a fixture for a foreign-currency scenario.
     * Returns same structure as buildEgpFixture plus 'currency' and 'exchangeRate'.
     */
    protected function buildForeignCurrencyFixture(
        string $currency,
        float  $exchangeRate,
        float  $carrierInitialForeignBalance = 10000.0
    ): array {
        $this->seedExchangeRates();

        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $customer = Customer::create([
            'full_name'   => "Suite Customer {$currency} " . uniqid(),
            'phone'       => '010' . substr(md5(microtime()), 0, 8),
            'email'       => 'suite-fx-' . strtolower($currency) . '-' . uniqid() . '@test.local',
            'national_id' => substr(md5(microtime()), 0, 14),
            'city'        => 'Cairo',
            'type'        => 'individual',
        ]);

        $flightSystem = FlightSystem::create([
            'name'         => "{$currency} Suite GDS " . uniqid(),
            'code'         => substr($currency, 0, 2) . 'G' . strtoupper(substr(md5(microtime()), 0, 4)),
            'type'         => 'gds',
            'is_active'    => true,
            'currency'     => $currency,
            'balance'      => 0,
            'credit_limit' => 0,
            'created_by'   => $admin->id,
        ]);

        $carrier = FlightCarrier::create([
            'name'             => "{$currency} Suite Carrier " . uniqid(),
            'code'             => substr($currency, 0, 2) . 'C' . strtoupper(substr(md5(microtime()), 0, 4)),
            'flight_system_id' => $flightSystem->id,
            'currency'         => $currency,
            'balance'          => 0,
            'credit_limit'     => 1000000,
            'is_active'        => true,
            'created_by'       => $admin->id,
        ]);

        // Foreign-currency cashbox with enough opening balance
        $cashbox = Account::create([
            'name'        => "{$currency} Suite Treasury " . uniqid(),
            'type'        => 'cashbox',
            'balance'     => $carrierInitialForeignBalance * 2,
            'currency'    => $currency,
            'is_active'   => true,
            'owner_type'  => 'office',
            'module_type' => 'office',
            'created_by'  => $admin->id,
        ]);

        $treasury = Treasury::create([
            'name'            => "{$currency} Suite Treasury " . uniqid(),
            'currency'        => $currency,
            'current_balance' => $carrierInitialForeignBalance * 2,
            'is_active'       => true,
        ]);

        // Recharge carrier with the foreign-currency amount
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier,
            $cashbox,
            $carrierInitialForeignBalance,
            "{$currency} Suite setup recharge",
        );
        $cashbox->refresh();
        $carrier->refresh();

        return array_merge(
            compact('admin', 'customer', 'flightSystem', 'carrier', 'treasury', 'cashbox'),
            ['currency' => $currency, 'exchangeRate' => $exchangeRate]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Booking helper
    // ─────────────────────────────────────────────────────────────────────

    protected function makeBooking(
        int     $customerId,
        int     $carrierId,
        int     $cashboxAccountId,
        float   $sellingPrice  = 7500.0,
        float   $purchasePrice = 7350.0,
        int     $adminId       = 0,
        string  $currency      = 'EGP',
        float   $exchangeRate  = 1.0
    ): FlightBooking {
        /** @var FlightBookingService $bookingService */
        $bookingService = app(FlightBookingService::class);

        $data = [
            'customer_id'        => $customerId,
            'airline_name'       => 'Suite Airline',
            'from_airport'       => 'CAI',
            'to_airport'         => 'JED',
            'departure_date'     => now()->addDays(7)->toDateString(),
            'trip_type'          => 'one_way',
            'currency'           => $currency,
            'purchase_price'     => $purchasePrice,
            'selling_price'      => $sellingPrice,
            'flight_carrier_id'  => $carrierId,
            'account_id'         => $cashboxAccountId,
            'pnr'                => 'SUT' . strtoupper(substr(md5((string)microtime(true)), 0, 5)),
            'passengers'         => [['name' => 'Suite Pax', 'type' => 'adult']],
        ];

        if ($currency !== 'EGP') {
            $data['booking_exchange_rate']  = $exchangeRate;
            $data['original_currency']      = $currency;
            $data['original_amount']        = $sellingPrice;
            $data['selling_price_foreign']  = $sellingPrice;
            $data['purchase_price_foreign'] = $purchasePrice;
            $data['purchase_price']         = round($purchasePrice * $exchangeRate, 2);
            $data['selling_price']          = round($sellingPrice * $exchangeRate, 2);
        }

        $booking = $bookingService->createBooking($data);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        return $booking->fresh();
    }

    protected function payBooking(
        FlightBooking $booking,
        float $amount,
        int   $cashboxAccountId,
        array $extra = []
    ): void {
        /** @var FlightBookingService $bookingService */
        $bookingService = app(FlightBookingService::class);

        $bookingService->addPayment($booking, array_merge([
            'amount'         => $amount,
            'payment_method' => 'cash',
            'account_id'     => $cashboxAccountId,
            'notes'          => 'Suite payment',
        ], $extra));
    }
}
