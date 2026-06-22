<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Customer;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightCarrier;
use App\Services\Flight\FlightBookingService;
use App\Services\Finance\LedgerClearingAccounts;
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
            'module_type' => 'fawry',
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
            'module_type' => 'general',
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
        $system = \App\Models\Flight\FlightSystem::create([
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

        // 4. Create a group booking using FlightBookingService
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
                ]
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

        // 6. Check P&L report
        $report = app(ProfitLossReportService::class)->report([]);

        $this->assertEquals(22000.0, $report['totalRevenues']);
        $this->assertEquals(20000.0, $report['totalCogs']);
        $this->assertEquals(2000.0, $report['grossProfit']);
        $this->assertEquals(2000.0, $report['netProfit']);

        // 7. Verify cancellation logic reverses everything
        app(FlightBookingService::class)->cancelBooking($booking, ['airline_penalty' => 0, 'office_penalty' => 0]);

        $account->refresh();
        $this->assertEquals(0.0, (float) $account->balance);

        $reportAfterCancel = app(ProfitLossReportService::class)->report([]);
        $this->assertEquals(0.0, $reportAfterCancel['totalRevenues']);
        $this->assertEquals(0.0, $reportAfterCancel['totalCogs']);
        $this->assertEquals(0.0, $reportAfterCancel['netProfit']);
    }
}

