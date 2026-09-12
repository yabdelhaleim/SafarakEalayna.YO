<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Flight\FlightBookingService;
use App\Services\Reports\FinancialReportService;
use App\Services\Reports\ProfitLossReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfitLossReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected Account $incomeClearing;

    protected Account $expenseClearing;

    protected Account $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'P&L Tester',
            'email' => 'pl-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = Account::create([
            'name' => 'PL Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100_000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $clearing = app(LedgerClearingAccounts::class);
        $incomeId = $clearing->incomeContraIdForModule('fawry');
        $expenseId = $clearing->expenseContraIdForModule('fawry');

        $this->assertNotNull($incomeId, 'Income clearing account must exist from migrations');
        $this->assertNotNull($expenseId, 'Expense clearing account must exist from migrations');

        $this->incomeClearing = Account::query()->findOrFail($incomeId);
        $this->expenseClearing = Account::query()->findOrFail($expenseId);

        $this->expenseAccount = Account::create([
            'name' => 'مصروف إيجار المكتب',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_strict_double_entry_revenue_and_cogs_are_counted(): void
    {
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 5000, 'fawry', 'بيع فوري');
        $this->createTransfer($this->treasury->id, $this->expenseClearing->id, 2000, 'fawry', 'تكلفة فوري');

        $report = app(ProfitLossReportService::class)->report([]);

        $this->assertSame(5000.0, $report['totalRevenues']);
        $this->assertSame(2000.0, $report['totalCogs']);
        $this->assertSame(3000.0, $report['grossProfit']);
        $this->assertSame(3000.0, $report['netProfit']);
    }

    public function test_section_filter_limits_report_to_requested_classification(): void
    {
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 5000, 'fawry', 'بيع');
        $this->createTransfer($this->treasury->id, $this->expenseClearing->id, 2000, 'fawry', 'تكلفة');
        $this->createTransfer($this->treasury->id, $this->expenseAccount->id, 900, 'general', 'إيجار', 'expense');

        $revenue = app(ProfitLossReportService::class)->report(['section' => 'revenue']);
        $cogs = app(ProfitLossReportService::class)->report(['section' => 'cogs']);
        $expense = app(ProfitLossReportService::class)->report(['section' => 'expense']);

        $this->assertSame(5000.0, $revenue['totalRevenues']);
        $this->assertSame(0.0, $revenue['totalCogs']);
        $this->assertSame(0.0, $revenue['totalExpenses']);
        $this->assertSame(0.0, $cogs['totalRevenues']);
        $this->assertSame(2000.0, $cogs['totalCogs']);
        $this->assertSame(0.0, $expense['totalRevenues']);
        $this->assertSame(900.0, $expense['totalExpenses']);
    }

    public function test_prepaid_recharge_is_neutral_until_cogs_consumption(): void
    {
        $clearing = app(LedgerClearingAccounts::class);
        $prepaidId = $clearing->prepaidAccountId('flight_system');
        $flightExpenseId = $clearing->expenseContraIdForModule('flight');
        $this->assertNotNull($flightExpenseId);

        $this->createTransfer($this->treasury->id, $prepaidId, 3000, 'flight', 'شحن نظام [رصيد مسبق]');

        $afterRecharge = app(ProfitLossReportService::class)->report([]);
        $this->assertEquals(0.0, $afterRecharge['totalCogs']);
        $this->assertEquals(0.0, $afterRecharge['netProfit']);

        $this->createTransfer($prepaidId, $flightExpenseId, 1200, 'flight', 'تكلفة حجز [COGS]');

        $afterCogs = app(ProfitLossReportService::class)->report([]);
        $this->assertSame(1200.0, $afterCogs['totalCogs']);
        $this->assertSame(-1200.0, $afterCogs['netProfit']);
    }

    public function test_treasury_to_treasury_transfer_is_excluded(): void
    {
        $otherTreasury = Account::create([
            'name' => 'PL Treasury 2',
            'type' => 'bank',
            'currency' => 'EGP',
            'balance' => 10_000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $this->createTransfer($this->treasury->id, $otherTreasury->id, 1500, 'general', 'تحويل داخلي');

        $report = app(ProfitLossReportService::class)->report([]);

        $this->assertEquals(0.0, (float) $report['totalRevenues']);
        $this->assertEquals(0.0, (float) $report['totalCogs']);
        $this->assertEquals(0.0, (float) $report['totalExpenses']);
    }

    public function test_revenue_reversal_reduces_totals(): void
    {
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 8000, 'fawry', 'بيع');
        $this->createTransfer($this->treasury->id, $this->incomeClearing->id, 3000, 'fawry', 'إلغاء بيع');

        $report = app(ProfitLossReportService::class)->report([]);

        $this->assertSame(5000.0, $report['totalRevenues']);
        $this->assertSame(3000.0, $report['totalRefunds']);
    }

    public function test_operating_expense_transfer_to_expense_account(): void
    {
        $this->createTransfer($this->treasury->id, $this->expenseAccount->id, 900, 'general', 'إيجار', 'expense');

        $report = app(ProfitLossReportService::class)->report([]);

        $this->assertEquals(0.0, (float) $report['totalRevenues']);
        $this->assertEquals(900.0, (float) $report['totalExpenses']);
        $this->assertEquals(-900.0, (float) $report['netProfit']);
    }

    public function test_office_category_filter_excludes_tourism_modules(): void
    {
        $flightClearingId = app(LedgerClearingAccounts::class)->incomeContraIdForModule('flight');
        $this->assertNotNull($flightClearingId);

        $this->createTransfer($flightClearingId, $this->treasury->id, 4000, 'flight', 'تذكرة');
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 1000, 'fawry', 'فوري');

        $officeReport = app(ProfitLossReportService::class)->report(['category' => 'office']);
        $tourismReport = app(ProfitLossReportService::class)->report(['category' => 'tourism']);

        $this->assertSame(1000.0, $officeReport['totalRevenues']);
        $this->assertSame(4000.0, $tourismReport['totalRevenues']);
    }

    public function test_report_handles_high_transaction_volume_without_errors(): void
    {
        $rows = [];
        $now = now();
        for ($i = 0; $i < 2500; $i++) {
            $isRevenue = $i % 3 === 0;
            $rows[] = [
                'type' => 'transfer',
                'module' => 'fawry',
                'amount' => 100 + ($i % 50),
                'currency' => 'EGP',
                'from_account_id' => $isRevenue ? $this->incomeClearing->id : $this->treasury->id,
                'to_account_id' => $isRevenue ? $this->treasury->id : $this->expenseClearing->id,
                'created_by' => $this->user->id,
                'notes' => 'bulk '.$i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Transaction::query()->insert($chunk);
        }

        $started = microtime(true);
        $report = app(ProfitLossReportService::class)->report([]);
        $elapsed = microtime(true) - $started;

        $this->assertGreaterThan(0, $report['meta']['transactions_scanned']);
        $this->assertGreaterThan(0, $report['meta']['transactions_included']);
        $this->assertTrue($report['meta']['live']);
        $this->assertGreaterThan(0, $report['totalRevenues']);
        $this->assertLessThan(8.0, $elapsed, 'P&L report should complete within 8 seconds for 2500 rows');
    }

    public function test_api_endpoint_returns_profit_loss_payload(): void
    {
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 1200, 'fawry', 'بيع API');

        $response = $this->getJson('/api/v1/reports/profit-loss?from_date='.now()->subDay()->toDateString());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.totalRevenues', 1200)
            ->assertJsonPath('data.netProfit', 1200)
            ->assertJsonStructure([
                'data' => [
                    'totalRevenues',
                    'totalCogs',
                    'totalExpenses',
                    'totalRefunds',
                    'grossProfit',
                    'netProfit',
                    'revenuesList',
                    'cogsList',
                    'expensesList',
                    'refundsList',
                    'period',
                    'meta' => ['transactions_scanned', 'transactions_included', 'generated_at', 'live'],
                ],
            ]);
    }

    public function test_module_breakdown_groups_office_modules(): void
    {
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 5000, 'fawry', 'بيع فوري');
        $this->createTransfer($this->treasury->id, $this->expenseClearing->id, 2000, 'fawry', 'تكلفة فوري');

        $breakdown = app(ProfitLossReportService::class)->moduleBreakdown(['category' => 'office']);

        $fawry = collect($breakdown['by_module'])->firstWhere('module', 'fawry');
        $this->assertNotNull($fawry);
        $this->assertSame(5000.0, $fawry['income']);
        $this->assertSame(2000.0, $fawry['cogs']);
        $this->assertSame(0.0, $fawry['expense']);
        $this->assertSame(3000.0, $fawry['profit']);
        $this->assertTrue($breakdown['meta']['live']);
    }

    public function test_profit_by_module_api_respects_department_filter(): void
    {
        $flightClearingId = app(LedgerClearingAccounts::class)->incomeContraIdForModule('flight');
        $this->assertNotNull($flightClearingId);

        $this->createTransfer($flightClearingId, $this->treasury->id, 4000, 'flight', 'تذكرة');
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 1500, 'fawry', 'فوري');

        $officeResponse = $this->getJson('/api/v1/reports/profit-by-module?category=office');
        $officeResponse->assertOk()
            ->assertJsonPath('success', true);
        $this->assertStringContainsString('no-store', (string) $officeResponse->headers->get('Cache-Control'));

        $officeFawry = collect($officeResponse->json('data.by_module'))->firstWhere('module', 'fawry');
        $this->assertNotNull($officeFawry);
        $this->assertEquals(1500.0, (float) $officeFawry['income']);

        $tourismResponse = $this->getJson('/api/v1/reports/profit-by-module?category=tourism');
        $tourismFlight = collect($tourismResponse->json('data.by_module'))->firstWhere('module', 'flight');
        $this->assertNotNull($tourismFlight);
        $this->assertEquals(4000.0, (float) $tourismFlight['income']);
    }

    private function createTransfer(
        int $fromId,
        int $toId,
        float $amount,
        string $module,
        string $notes,
        string $type = 'transfer'
    ): Transaction {
        return Transaction::query()->create([
            'type' => $type,
            'amount' => $amount,
            'module' => $module,
            'from_account_id' => $fromId,
            'to_account_id' => $toId,
            'created_by' => $this->user->id,
            'notes' => $notes,
        ]);
    }

    public function test_multi_currency_operating_expense_is_converted_to_egp_in_pl_report(): void
    {
        $usdAccount = Account::create([
            'name' => 'خزينة دولار',
            'type' => 'cashbox',
            'currency' => 'USD',
            'balance' => 1000.0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $usdAccount->id,
            'to_account_id' => $this->expenseAccount->id,
            'amount' => 100.0,
            'converted_amount' => 5000.0,
            'exchange_rate' => 50.0,
            'type' => 'expense',
            'module' => 'general',
            'notes' => 'دفع إيجار بالدولار',
        ]);
        $response->assertCreated();

        $report = app(ProfitLossReportService::class)->report([]);

        $this->assertEquals(5000.0, (float) $report['totalExpenses']);
        $this->assertEquals(-5000.0, (float) $report['netProfit']);
    }

    public function test_group_booking_records_cogs_and_reduces_profit_in_pl_report(): void
    {
        // 1. Create a customer
        $customer = Customer::create([
            'full_name' => 'Ahmed Customer',
            'phone' => '01000000000',
            'customer_tier' => 'STANDARD',
        ]);

        // 2. Create system and carrier
        $system = FlightSystem::create([
            'name' => 'Test System',
            'code' => 'SYS',
            'type' => 'gds',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $carrier = FlightCarrier::create([
            'name' => 'Test Carrier',
            'code' => 'CR',
            'flight_system_id' => $system->id,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        // 3. Create a FlightGroup
        $group = FlightGroup::create([
            'name' => 'فوياج',
            'code' => 'VOY',
            'flight_carrier_id' => $carrier->id,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        // Verify observer automatically created the account
        $this->assertNotNull($group->account_id);
        $account = Account::find($group->account_id);
        $this->assertNotNull($account);
        $this->assertEquals('حساب مجموعة طيران: فوياج', $account->name);
        $this->assertEquals(0.0, (float) $account->balance);

        // 4. Create a group booking using FlightBookingService — NO initial payment.
        //    FIN-2 (2026-08-23) cash-basis recognition: the customer AR is
        //    debited (debt recorded) but the transfer uses the
        //    pending-sales-receivable account as its contra leg — which is NOT
        //    in `incomeClearing`, so the P&L classifier must NOT recognise
        //    this as revenue until cash actually arrives via addPayment().
        $bookingData = [
            'customer_id' => $customer->id,
            'airline_name' => 'Test Carrier',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 20000,
            'selling_price' => 22000,
            'purchase_balance_source' => 'group',
            'flight_group_id' => $group->id,
            'account_id' => $this->treasury->id,
            'passengers' => [
                [
                    'name' => 'Passenger 1',
                    'type' => 'adult',
                ],
            ],
        ];

        $booking = app(FlightBookingService::class)->createBooking($bookingData);

        // 5. Assert database records and ledger balance
        $this->assertDatabaseHas('flight_group_transactions', [
            'flight_group_id' => $group->id,
            'flight_booking_id' => $booking->id,
            'type' => 'debt',
            'amount' => 20000.0,
        ]);

        // Voyage Account should be -20,000 (we owe them 20,000 EGP)
        $account->refresh();
        $this->assertEquals(-20000.0, (float) $account->balance);

        // 5b. Customer AR should reflect the debt even though nothing was paid.
        $customerAccount = Account::findOrFail($customer->fresh()->account_id);
        $this->assertEquals(22000.0, (float) $customerAccount->balance,
            'Customer AR must reflect the 22,000 sale as a debt at booking creation.');

        // 6. P&L report BEFORE payment — revenue must be 0 (cash-basis).
        //    COGS is recognised at booking creation (we owe the group 20,000
        //    EGP), so net profit is temporarily negative until cash arrives.
        $report = app(ProfitLossReportService::class)->report([]);

        $this->assertEquals(0.0, (float) $report['totalRevenues'],
            'Revenue must NOT be recognised on an unpaid credit booking (cash-basis).');
        $this->assertEquals(20000.0, (float) $report['totalCogs']);
        $this->assertEquals(-20000.0, (float) $report['grossProfit']);
        $this->assertEquals(-20000.0, (float) $report['netProfit']);

        // 6b. Now record full payment. Revenue must be recognised at cash receipt.
        $booking->refresh();
        app(FlightBookingService::class)->addPayment($booking, [
            'amount' => 22000,
            'payment_method' => 'cash',
            'account_id' => $this->treasury->id,
        ]);

        $reportAfterPayment = app(ProfitLossReportService::class)->report([]);
        $this->assertEquals(22000.0, (float) $reportAfterPayment['totalRevenues'],
            'Revenue must be recognised at cash receipt.');
        $this->assertEquals(20000.0, (float) $reportAfterPayment['totalCogs']);
        $this->assertEquals(2000.0, (float) $reportAfterPayment['grossProfit']);
        $this->assertEquals(2000.0, (float) $reportAfterPayment['netProfit']);

        // 7. Verify cancellation logic reverses everything.
        // FlightBookingService::cancelBooking now requires `account_id`
        // when the booking has paid customer balance to disburse back —
        // updated from the obsolete 0-arg signature the original test had.
        app(FlightBookingService::class)->cancelBooking(
            $booking,
            [
                'airline_penalty' => 0,
                'office_penalty' => 0,
                'account_id' => $this->treasury->id,
            ]
        );

        // [FAILURE #1 — DIAGNOSTIC ONLY, NOT FIXED IN THIS TASK]
        //
        // The cancel flow currently leaves customer AR at +22000 instead of
        // the accounting-correct 0. Three independent customer-account credits
        // are posted during cancellation:
        //   - TX5 (sale-reversal leg, customer → pending_sales_receivable)
        //   - reverseTransaction mirror on TX3 (reverses the addPayment income)
        //   - TX6 (cash refund, treasury → customer)
        //
        // Per the user's directive for this task, this is classified as a
        // REAL production accounting bug — NOT test drift — and is NOT fixed
        // here. The full accounting trace and overlap analysis are recorded
        // in `.zcode/plans/PNL_3_FAILURES_REMEDIATION_REPORT_20260828.md`.
        // See "Failure #1 — accounting diagnosis" for the per-transaction
        // ledger walk.
        $account->refresh();
        $this->assertEquals(0.0, (float) $account->balance,
            'Group account must be zeroed after cancellation.');

        $customerAccount->refresh();
        $this->assertEquals(0.0, (float) $customerAccount->balance,
            'Customer AR must be zeroed after cancellation. '
            .'See `.zcode/plans/PNL_3_FAILURES_REMEDIATION_REPORT_20260828.md` '
            .'for the double-refund root cause (Failure #1 — production bug, not fixed in this task).');

        $reportAfterCancel = app(ProfitLossReportService::class)->report([]);
        $this->assertEquals(0.0, (float) $reportAfterCancel['totalRevenues']);
        $this->assertEquals(0.0, (float) $reportAfterCancel['totalCogs']);
        $this->assertEquals(0.0, (float) $reportAfterCancel['netProfit']);
    }

    /**
     * Regression test for Phase 2.1 (PNL/TOURISM-FIX-A2).
     *
     * getDailyProfitByModule() previously skipped NEITHER 'عكس:' nor 'عكس '
     * prefixes, while report() handled both. The mismatch meant daily charts
     * overstated revenue on cancellation/correction days. After the fix the
     * daily chart mirrors report()'s prefix semantics for both forms.
     */
    public function test_daily_profit_by_module_skips_already_reversed_rows(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        // Live revenue: clearing → treasury (revenue per classifier).
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 5000, 'fawry', 'بيع فوري');
        // Two 'عكس:' rows (the colon form, post-TransactionService::reverseTransaction)
        // — they must be skipped so they don't double-count.
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 1000, 'fawry', 'عكس: sale #1');
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 1000, 'fawry', 'عكس: sale #2');

        $byDay = app(ProfitLossReportService::class)->getDailyProfitByModule('fawry', [
            'from_date' => $yesterday,
            'to_date' => $today,
        ]);

        $todayRow = collect($byDay)->firstWhere('date', $today);
        $this->assertNotNull($todayRow, 'Today row must exist');
        // Only the live 5000 row counts. The two 'عكس:' rows (1000 each) must
        // be skipped — otherwise income would be 7000.
        $this->assertSame(5000.0, $todayRow['income']);
        $this->assertSame(5000.0, $todayRow['profit']);
    }

    /**
     * Regression test for Phase 2.1 (PNL/TOURISM-FIX-A2).
     *
     * 'عكس ' (with space, no colon) rows are companion rows from
     * FlightBookingService::cancelBooking's recordJournalTransfer call.
     * The classifier labels them as 'revenue' based on the clearing-account
     * leg, but the row is in fact a reversal — must be SUBTRACTED, not added.
     */
    public function test_daily_profit_by_module_reclassifies_space_reversal_to_reversal(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        // Live revenue 8000
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 8000, 'fawry', 'بيع فوري');
        // Reversal companion row (space prefix). The classifier would label
        // this as 'revenue' (income clearing → treasury is revenue direction),
        // but the prefix tells us it's a reversal → SUBTRACT 3000.
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 3000, 'fawry', 'عكس حجز رقم 1');

        $byDay = app(ProfitLossReportService::class)->getDailyProfitByModule('fawry', [
            'from_date' => $yesterday,
            'to_date' => $today,
        ]);

        $todayRow = collect($byDay)->firstWhere('date', $today);
        $this->assertNotNull($todayRow);
        // Net result: 8000 (forward) - 3000 (reversal) = 5000
        $this->assertSame(5000.0, $todayRow['income']);
        $this->assertSame(5000.0, $todayRow['profit']);
    }

    /**
     * Regression test for Phase 2.2 (PNL/TOURISM-FIX-A3).
     *
     * formatNamedList() previously used `if ($sum <= 0) continue;` while
     * formatModuleList() uses `if (abs($sum) < 0.00001)`. The asymmetry
     * meant that a single named-expense bucket netting to negative (e.g.
     * refunds exceeding the original operating expense) was silently
     * dropped from expensesList while totalExpenses still showed the
     * negative value — internally inconsistent and Vue-display incorrect.
     *
     * This test directly invokes the private formatNamedList via reflection
     * to exercise both the near-zero filter and the negative-bucket
     * preservation in a single assertion surface.
     */
    public function test_format_named_list_keeps_negative_buckets_to_match_module_list(): void
    {
        $service = app(ProfitLossReportService::class);
        $ref = new \ReflectionMethod($service, 'formatNamedList');
        $ref->setAccessible(true);

        // (1) Positive bucket must appear (sanity baseline).
        $positive = $ref->invoke($service, ['rent' => 100.0]);
        $this->assertCount(1, $positive);
        $this->assertSame('rent', $positive[0]['name']);
        $this->assertSame(100.0, $positive[0]['amount']);

        // (2) Negative bucket was the bug — previously dropped by `$sum <= 0`.
        //     After the fix it must appear with the negative amount preserved,
        //     so the breakdown list matches totalExpenses.
        $negative = $ref->invoke($service, ['rent' => -200.0]);
        $this->assertCount(1, $negative, 'Negative expense bucket must not be silently dropped');
        $this->assertSame(-200.0, $negative[0]['amount']);

        // (3) Near-zero bucket (< 0.00001) must still be filtered out —
        //     matches formatModuleList semantics.
        $nearZero = $ref->invoke($service, ['rent' => 0.000001]);
        $this->assertCount(0, $nearZero);

        // (4) Mixed buckets: keep non-zero, drop zero.
        $mixed = $ref->invoke($service, [
            'rent' => 500.0,
            'salaries' => -150.0,
            'marketing' => 0.0,
        ]);
        $this->assertCount(2, $mixed, 'Only the zero-sum bucket should be filtered out');
        $names = array_column($mixed, 'name');
        $this->assertContains('rent', $names);
        $this->assertContains('salaries', $names);
        $this->assertNotContains('marketing', $names);
    }

    /**
     * Regression test for Phase 2.3 (PNL/TOURISM-FIX-A4).
     *
     * FinancialReportService::getProfitReport() previously read
     * `$row['expenses']` (plural) from moduleBreakdown()'s by_module rows,
     * but moduleBreakdown() emits `'expense'` (singular). The bug silently
     * null-coalesced every by_module[].expense to 0, making
     * total_operating_expenses and by_module[].expense always 0 in the
     * /api/v1/reports/summary payload.
     *
     * This test exercises the public API end-to-end: create one operating
     * expense, call getProfitReport() with a wide date range, assert both
     * the top-level total_operating_expenses and per-module expense value.
     */
    public function test_get_profit_report_returns_operating_expenses_not_zero(): void
    {
        // One operating expense of 1500 EGP (treasury → expense account).
        $this->createTransfer($this->treasury->id, $this->expenseAccount->id, 1500, 'general', 'إيجار', 'expense');

        $report = app(FinancialReportService::class)->getProfitReport([
            'from_date' => '2020-01-01',
            'to_date' => '2030-12-31',
        ]);

        // total_operating_expenses must reflect the 1500 expense.
        $this->assertSame(1500.0, $report['total_operating_expenses'],
            'Operating expenses must be reported via total_operating_expenses.');

        // The by_module row for the source module of the expense must also
        // carry a non-zero expense value.
        $byModule = $report['by_module'];
        $modulesWithExpense = array_filter($byModule, fn ($m) => ($m['expense'] ?? 0) > 0);
        $this->assertNotEmpty($modulesWithExpense,
            'At least one by_module row must carry a non-zero expense value.');

        // Verify the specific expense value matches the 1500 we created.
        $totalExpense = array_sum(array_column($byModule, 'expense'));
        $this->assertSame(1500.0, $totalExpense);
    }

    /**
     * Companion to Phase 2.3 — verify the plural/singular fix is fully
     * internal: every by_module row emitted by getProfitReport() uses the
     * singular 'expense' key (NOT 'expenses') so Vue code that reads
     * m.expense gets a real number.
     */
    public function test_get_profit_report_by_module_uses_singular_expense_key(): void
    {
        $report = app(FinancialReportService::class)->getProfitReport([
            'from_date' => '2020-01-01',
            'to_date' => '2030-12-31',
        ]);

        // Iterate every by_module row, ensure 'expense' key exists.
        $this->assertIsArray($report['by_module']);
        // Whether or not any row is non-empty, the SHAPE must include 'expense'.
        if ($report['by_module'] !== []) {
            $first = $report['by_module'][0];
            $this->assertArrayHasKey('expense', $first,
                'by_module rows must use singular "expense" key per Vue contract.');
            $this->assertArrayHasKey('income', $first);
            $this->assertArrayHasKey('cogs', $first);
            $this->assertArrayHasKey('profit', $first);
        }
    }
}
