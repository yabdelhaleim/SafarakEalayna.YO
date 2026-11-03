<?php

namespace Tests\Audit;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Independent QA harness for the flight ticket-refund + unified customer credit cycle.
 *
 * CONVENTION (system): account.balance = SUM(credit) - SUM(debit)
 *   - from_account_id leg => DEBIT  (balance decreases)
 *   - to_account_id   leg => CREDIT (balance increases)
 *   => a customer account with a NEGATIVE balance owes us money (receivable).
 *   => a customer account with a POSITIVE balance is money we owe them (credit).
 *
 * This mirrors the Arabic "المحاسبي المعتاد" convention inverted: in standard
 * accounting a customer with a credit balance is a liability for us; here the
 * same economic reality is stored with the opposite sign because of the
 * SUM(credit)-SUM(debit) formula. Both are printed side by side in every dump.
 */
abstract class AuditTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Customer $customer;

    protected FlightSystem $flightSystem;

    protected FlightCarrier $carrier;

    protected Account $cashbox;

    protected Treasury $treasury;

    protected FlightBookingService $bookingService;

    protected RefundService $refundService;

    protected LedgerClearingAccounts $clearing;

    /** @var array<int,string> */
    protected array $dump = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingService = app(FlightBookingService::class);
        $this->refundService = app(RefundService::class);
        $this->clearing = app(LedgerClearingAccounts::class);

        $this->admin = User::create([
            'name' => 'Audit Admin',
            'email' => 'audit-admin@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'full_name' => 'Audit Customer',
            'phone' => '0100000001',
            'email' => 'audit-customer@test.local',
            'national_id' => '99988877766655',
            'city' => 'Cairo',
            'type' => 'individual',
        ]);

        $this->flightSystem = FlightSystem::create([
            'name' => 'Audit GDS',
            'code' => 'AUD'.substr(md5((string) microtime(true)), 0, 6),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'name' => 'Audit Airline',
            'code' => 'AU'.substr(md5((string) microtime(true)), 0, 6),
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 500000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->treasury = Treasury::create([
            'name' => 'Audit Treasury',
            'currency' => 'EGP',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $this->cashbox = Account::create([
            'name' => 'Audit Treasury',
            'type' => 'cashbox',
            'balance' => 500000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        // Give the carrier prepaid balance so the purchase leg can be debited.
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $this->carrier,
            $this->cashbox,
            500000.00,
            'Audit setup recharge'
        );
        $this->cashbox->refresh();
    }

    // ─────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────

    protected string $dumpName = 'audit';

    protected function tearDown(): void
    {
        if ($this->dump) {
            $this->writeDump($this->dumpName);
        }
        parent::tearDown();
    }

    protected function makeBooking(array $overrides = []): FlightBooking
    {
        $data = array_merge([
            'customer_id' => $this->customer->id,
            'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 7350,
            'selling_price' => 7500,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->cashbox->id,
            'pnr' => 'AUD'.strtoupper(substr(md5((string) microtime(true)), 0, 5)),
            'passengers' => [
                ['name' => 'Audit Pax', 'type' => 'adult'],
            ],
        ], $overrides);

        $booking = $this->bookingService->createBooking($data);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        return $booking->fresh();
    }

    protected function payBooking(FlightBooking $booking, float $amount, array $extra = []): void
    {
        $this->bookingService->addPayment($booking, array_merge([
            'amount' => $amount,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'notes' => 'Audit payment',
        ], $extra));
    }

    // ─────────────────────────────────────────────────────────────────
    // Extraction helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Every account_entry row for a set of transactions, joined to accounts,
     * in insertion order. Returns one row per journal leg.
     */
    protected function extractEntries(?array $transactionIds = null): array
    {
        $q = DB::table('account_entries as ae')
            ->join('accounts as a', 'a.id', '=', 'ae.account_id')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->select([
                'ae.transaction_id',
                't.type as tx_type',
                't.module',
                't.notes as tx_notes',
                'a.id as account_id',
                'a.name as account_name',
                'a.type as account_type',
                'ae.debit',
                'ae.credit',
                'ae.balance_after',
            ])
            ->orderBy('ae.id');

        if ($transactionIds !== null) {
            $q->whereIn('ae.transaction_id', $transactionIds);
        }

        return $q->get()->map(fn ($r) => (array) $r)->all();
    }

    /** Balance of every account, keyed by id. */
    protected function accountBalances(): array
    {
        return DB::table('accounts')->orderBy('id')->pluck('balance', 'id')
            ->map(fn ($v) => (float) $v)->all();
    }

    protected function accountBalance(int $accountId): float
    {
        return (float) DB::table('accounts')->where('id', $accountId)->value('balance');
    }

    /**
     * Sum of debit/credit per transaction — must be balanced.
     *
     * Cross-currency transactions (from-account currency ≠ to-account currency)
     * are reported separately: each leg is posted in the currency of its own
     * account, so the numeric debit/credit sums differ by the FX rate. This is
     * the project's own convention — see
     * tests/Feature/Flight/FlightMultiCurrencyDeepTest::assertEveryTransactionBalanced()
     * which skips cross-currency rows for exactly that reason.
     */
    protected function transactionBalanceCheck(): array
    {
        return DB::table('account_entries as e')
            ->join('transactions as t', 't.id', '=', 'e.transaction_id')
            ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
            ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
            ->select('e.transaction_id', DB::raw('SUM(e.debit) AS d'), DB::raw('SUM(e.credit) AS c'))
            ->selectRaw('MAX(fa.currency) AS from_currency')
            ->selectRaw('MAX(ta.currency) AS to_currency')
            ->groupBy('e.transaction_id')
            ->orderBy('e.transaction_id')
            ->get()
            ->map(fn ($r) => [
                'transaction_id' => (int) $r->transaction_id,
                'debit' => (float) $r->d,
                'credit' => (float) $r->c,
                'diff' => round((float) $r->d - (float) $r->c, 2),
                'from_currency' => strtoupper((string) ($r->from_currency ?? '')),
                'to_currency' => strtoupper((string) ($r->to_currency ?? '')),
                'cross_currency' => strtoupper((string) ($r->from_currency ?? '')) !== strtoupper((string) ($r->to_currency ?? '')),
            ])
            ->all();
    }

    protected function ledgerBalance(?int $customerId = null, ?int $companyId = null, string $currency = 'EGP'): float
    {
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

        return round((float) $q->sum('amount'), 2);
    }

    protected function ledgerRows(?int $customerId = null, ?int $companyId = null): array
    {
        $q = DB::table('customer_credit_ledger')->orderBy('id');
        if ($companyId) {
            $q->where('company_id', $companyId);
        } elseif ($customerId) {
            $q->where('customer_id', $customerId);
        }

        return $q->get()->map(fn ($r) => (array) $r)->all();
    }

    protected function customerAccountId(): int
    {
        return (int) $this->customer->fresh()->account_id;
    }

    protected function carrierBalance(): float
    {
        return (float) $this->carrier->fresh()->balance;
    }

    protected function treasuryBalance(): float
    {
        return (float) $this->treasury->fresh()->current_balance;
    }

    /** Intermediate clearing accounts the task asks us to watch. */
    protected function intermediateAccountIds(): array
    {
        return array_values(array_filter([
            $this->clearing->expenseContraIdForModule(\App\Enums\TransactionModule::Flight),
            $this->clearing->pendingSalesReceivableIdForFlight(),
            $this->clearing->incomeContraIdForFlightBooking(),
        ]));
    }

    protected function intermediateBalances(): array
    {
        $out = [];
        foreach ($this->intermediateAccountIds() as $id) {
            $acc = DB::table('accounts')->where('id', $id)->first();
            $out[$id] = [
                'name' => $acc->name ?? ('#'.$id),
                'balance' => (float) ($acc->balance ?? 0),
            ];
        }

        return $out;
    }

    /** P&L as reported by the real system service. */
    protected function profitLoss(array $filters = []): array
    {
        $service = app(\App\Services\Reports\ReportFinanceService::class);
        $res = $service->getProfitLossReport($filters + ['section' => 'all']);

        return is_array($res) ? $res : [];
    }

    protected function plTotals(): array
    {
        $pl = $this->profitLoss();
        $out = [];
        foreach ($pl as $k => $v) {
            if (is_array($v) && isset($v['revenue'])) {
                $out[$k] = $v;
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Flat numeric snapshot of the REAL system P&L report, used to compute
     * before/after deltas. Keys: revenue / cogs / expense / refund / net.
     */
    protected function plSnapshot(): array
    {
        $pl = $this->profitLoss();

        return [
            'totalRevenues' => (float) ($pl['totalRevenues'] ?? 0),
            'totalCogs' => (float) ($pl['totalCogs'] ?? 0),
            'totalExpenses' => (float) ($pl['totalExpenses'] ?? 0),
            'totalRefunds' => (float) ($pl['totalRefunds'] ?? 0),
            'grossProfit' => (float) ($pl['grossProfit'] ?? 0),
            'netProfit' => (float) ($pl['netProfit'] ?? 0),
            'revenuesList' => $pl['revenuesList'] ?? [],
            'cogsList' => $pl['cogsList'] ?? [],
            'expensesList' => $pl['expensesList'] ?? [],
            'refundsList' => $pl['refundsList'] ?? [],
            'meta' => $pl['meta'] ?? [],
        ];
    }

    /** Numeric delta between two P&L snapshots. */
    protected function plDelta(array $before, array $after): array
    {
        $keys = ['totalRevenues', 'totalCogs', 'totalExpenses', 'totalRefunds', 'grossProfit', 'netProfit'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = round(((float) $after[$k]) - ((float) $before[$k]), 2);
        }

        return $out;
    }

    protected function dumpPL(string $title, array $before, array $after): void
    {
        $this->dumpLine('');
        $this->dumpLine('── '.$title.' ──');
        $this->dumpLine(sprintf(
            '   %-16s %14s %14s %14s',
            'PL',
            'BEFORE',
            'AFTER',
            'DELTA'
        ));
        foreach (['totalRevenues', 'totalCogs', 'totalExpenses', 'totalRefunds', 'grossProfit', 'netProfit'] as $k) {
            $this->dumpLine(sprintf(
                '   %-16s %14s %14s %14s',
                $k,
                number_format((float) $before[$k], 2, '.', ''),
                number_format((float) $after[$k], 2, '.', ''),
                number_format(round((float) $after[$k] - (float) $before[$k], 2), 2, '.', '')
            ));
        }
        $this->dumpLine('   meta before='.json_encode($before['meta'] ?? []).' after='.json_encode($after['meta'] ?? []));
        $this->dumpLine('   revenuesList before='.json_encode($before['revenuesList'] ?? []));
        $this->dumpLine('   revenuesList after ='.json_encode($after['revenuesList'] ?? []));
        $this->dumpLine('   cogsList before='.json_encode($before['cogsList'] ?? []));
        $this->dumpLine('   cogsList after ='.json_encode($after['cogsList'] ?? []));
    }

    /** Transactions with no account_entries, split into memo-only vs real. */
    protected function classifyOrphanTransactions(): array
    {
        $orphans = $this->transactionsWithoutEntries();
        $memo = [];
        $other = [];
        foreach ($orphans as $o) {
            $toName = DB::table('accounts')->where('id', $o['to_account_id'])->value('name');
            $o['to_account_name'] = $toName;
            if ($toName && str_contains((string) $toName, 'إقفال')) {
                $memo[] = $o;
            } else {
                $other[] = $o;
            }
        }

        return ['memo' => $memo, 'other' => $other, 'all' => $orphans];
    }

    // ─────────────────────────────────────────────────────────────────
    // Dump helpers
    // ─────────────────────────────────────────────────────────────────

    protected function dumpLine(string $s): void
    {
        $this->dump[] = $s;
    }

    protected function dumpEntries(string $title, ?array $txIds = null): void
    {
        $rows = $this->extractEntries($txIds);
        $this->dumpLine('');
        $this->dumpLine('── '.$title.' ──');
        if (! $rows) {
            $this->dumpLine('   (لا توجد قيود)');

            return;
        }
        $this->dumpLine(sprintf('   %-6s %-4s %-9s %-38s %-12s %-12s %-14s %s', 'TX', 'ACCT', 'TYPE', 'ACCOUNT', 'DEBIT', 'CREDIT', 'BAL_AFTER', 'NOTES'));
        foreach ($rows as $r) {
            $this->dumpLine(sprintf(
                '   %-6d %-4d %-9s %-38s %-12s %-12s %-14s %s',
                $r['transaction_id'],
                $r['account_id'],
                mb_substr((string) $r['tx_type'], 0, 9),
                mb_substr((string) $r['account_name'], 0, 38),
                number_format((float) $r['debit'], 2, '.', ''),
                number_format((float) $r['credit'], 2, '.', ''),
                number_format((float) $r['balance_after'], 2, '.', ''),
                mb_substr((string) $r['tx_notes'], 0, 60)
            ));
        }
    }

    protected function dumpBalances(string $title): void
    {
        $this->dumpLine('');
        $this->dumpLine('── '.$title.' ──');
        foreach ($this->accountBalances() as $id => $bal) {
            if (abs($bal) < 0.005 && ! $this->isInterestingAccount((int) $id)) {
                continue;
            }
            $name = DB::table('accounts')->where('id', $id)->value('name');
            $this->dumpLine(sprintf('   acc#%-4d %-40s %14s', $id, mb_substr((string) $name, 0, 40), number_format($bal, 2, '.', '')));
        }
    }

    protected function isInterestingAccount(int $id): bool
    {
        $ids = array_merge(
            $this->intermediateAccountIds(),
            [$this->customerAccountId()],
            [$this->cashbox->id]
        );

        return in_array($id, $ids, true);
    }

    protected function dumpLedger(string $title, ?int $customerId = null, ?int $companyId = null): void
    {
        $this->dumpLine('');
        $this->dumpLine('── '.$title.' ──');
        $rows = $this->ledgerRows($customerId, $companyId);
        if (! $rows) {
            $this->dumpLine('   (لا توجد حركات)');

            return;
        }
        foreach ($rows as $r) {
            $this->dumpLine(sprintf(
                '   #%-4d %-20s src=%-20s amount=%-12s balance_after=%-12s %s',
                $r['id'],
                'cust='.($r['customer_id'] ?? '-').'/co='.($r['company_id'] ?? '-'),
                $r['source_type'],
                number_format((float) $r['amount'], 2, '.', ''),
                number_format((float) $r['balance_after'], 2, '.', ''),
                mb_substr((string) $r['notes'], 0, 50)
            ));
        }
        $this->dumpLine('   AVAILABLE CREDIT = '.number_format($this->ledgerBalance($customerId, $companyId), 2, '.', ''));
    }

    protected function writeDump(string $name): void
    {
        $path = __DIR__.'/out/'.$name.'.txt';
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, implode("\n", $this->dump)."\n");
    }

    /** Number of rows in refund_audit_logs (the refund-domain audit trail). */
    protected function refundAuditCount(): int
    {
        return DB::table('refund_audit_logs')->count();
    }

    protected function refundAuditRows(): array
    {
        return DB::table('refund_audit_logs')->orderBy('id')->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    protected function auditLogRows(): array
    {
        return DB::table('audit_logs')->orderBy('id')->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'user_id' => $r->user_id,
                'action' => $r->action,
                'model_type' => $r->model_type,
                'model_id' => $r->model_id,
                'created_at' => $r->created_at,
                'notes' => $r->notes,
            ])->all();
    }

    /** Transactions that exist but have NO account_entries (broken double-entry). */
    protected function transactionsWithoutEntries(): array
    {
        return DB::table('transactions as t')
            ->leftJoin('account_entries as ae', 'ae.transaction_id', '=', 't.id')
            ->whereNull('ae.id')
            ->select('t.id', 't.type', 't.amount', 't.from_account_id', 't.to_account_id', 't.notes')
            ->orderBy('t.id')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    protected function assertBalanced(string $context = ''): void
    {
        foreach ($this->transactionBalanceCheck() as $row) {
            if ($row['cross_currency']) {
                // Each leg is posted in the currency of its own account, so the
                // numeric sums differ by the FX rate — the project's own
                // convention (FlightMultiCurrencyDeepTest skips these rows).
                // The difference is still dumped so the numbers stay visible.
                $this->dumpLine(sprintf(
                    '   [FX] Transaction #%d %s: debit=%.2f %s / credit=%.2f %s (فرق سعر الصرف=%.2f)',
                    $row['transaction_id'],
                    $context,
                    $row['debit'],
                    $row['from_currency'],
                    $row['credit'],
                    $row['to_currency'],
                    $row['diff']
                ));
                continue;
            }
            $this->assertEqualsWithDelta(
                0.0,
                $row['diff'],
                0.01,
                "Transaction #{$row['transaction_id']} غير متوازن {$context} (دائن={$row['debit']} مدين={$row['credit']})"
            );
        }
    }

    /** GL customer-account balance expressed as "money we owe the customer" (positive = credit). */
    protected function glCustomerCredit(): float
    {
        $acct = $this->customerAccountId();
        if (! $acct) {
            return 0.0;
        }
        // System balance = credit - debit. Customer credit owed to customer is
        // the negated GL balance (GL shows negative when the customer is owed).
        return round(-$this->accountBalance($acct), 2);
    }
}
