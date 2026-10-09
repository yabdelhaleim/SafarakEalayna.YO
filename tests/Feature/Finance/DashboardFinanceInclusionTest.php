<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Services\Reports\ReportFinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression: dashboard finance methods must include transfer-type
 * tourism revenue and COGS.
 *
 * Pre-fix, `ReportFinanceService::getIncomeByModule`,
 * `getExpenseByModule` and `getDailyFinancialChart` queried
 * `WHERE type='income'` / `WHERE type='expense'`. Tourism revenue
 * (flight / hajj / visa) is recorded cash-basis as `type='transfer'`
 * via `recordJournalTransfer()`, so the previous queries silently
 * returned zero for the entire tourism division on the dashboard.
 *
 * The companion dashboard chart widget `DashboardChartWidget` and
 * the per-module revenue cards in `getIncomeByModule` flow through
 * these methods — testing this service directly covers both call sites.
 *
 * See `.zcode/plans/FLIGHT_DASHBOARD_FIXES_20260824.md` for context.
 */
class DashboardFinanceInclusionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Admin',
            'email' => 'dashboard-inclusion@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    /**
     * Tourism revenue: a transfer row FROM the flight income-clearing
     * account TO a cashbox (cash-basis recognition via
     * `recordJournalTransfer`) MUST show up in `getIncomeByModule`
     * under the `flight` bucket. Pre-fix this returned 0 because the
     * old query filtered `WHERE type='income'` only.
     */
    public function test_get_income_by_module_includes_transfer_type_flight_revenue(): void
    {
        // Account names must match config/accounting.php exactly,
        // because ProfitLossReportService resolves income_clearing
        // accounts by name.
        $cashboxId = $this->seedAccount('CASHBOX-FLIGHT-EGP', 'cashbox', 'tourism', 'EGP', 0.0);
        $incomeClearingId = $this->seedAccount(
            config('accounting.clearing.income.flight', 'إقفال مبيعات الطيران (نظام)'),
            'income',
            'tourism',
            'EGP',
            0.0
        );
        $userId = $this->user->id;

        // Cash-basis revenue recognition: income_clearing → cashbox.
        // This is what FlightBookingService posts in FIN-2 cash-basis
        // recognition via recordJournalTransfer.
        $txId = DB::table('transactions')->insertGetId([
            'type' => 'transfer',
            'module' => 'flight',
            'amount' => 2500.0,
            'currency' => 'EGP',
            'from_account_id' => $incomeClearingId,
            'to_account_id' => $cashboxId,
            'notes' => null,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('account_entries')->insert([
            ['transaction_id' => $txId, 'account_id' => $incomeClearingId, 'debit' => 2500.0, 'credit' => 0.0, 'balance_after' => 0.0, 'created_at' => now(), 'updated_at' => now()],
            ['transaction_id' => $txId, 'account_id' => $cashboxId, 'debit' => 0.0, 'credit' => 2500.0, 'balance_after' => 2500.0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $service = app(ReportFinanceService::class);
        $result = $service->getIncomeByModule([
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'category' => 'tourism',
        ]);

        $this->assertGreaterThan(
            0.0,
            (float) ($result['flight'] ?? 0),
            'transfer-type flight revenue must appear in getIncomeByModule.flight'
        );
        $this->assertEquals(2500.0, round((float) $result['flight'], 2));
    }

    /**
     * `getExpenseByModule` must see transfer rows that are COGS:
     * `prepaid_asset → expense_clearing`. Pre-fix this returned 0 for
     * the entire airline / hajj / visa spend side.
     */
    public function test_get_expense_by_module_includes_transfer_type_cogs(): void
    {
        $prepaidName = config('accounting.clearing.prepaid.flight_carrier', 'رصيد مسبق — ناقلو الطيران');
        $expenseName = config('accounting.clearing.expense.flight', 'إقفال تكاليف الطيران');

        $prepaidId = $this->seedAccount($prepaidName, 'prepaid_asset', 'tourism', 'EGP', 50000.0);
        $expenseClearingId = $this->seedAccount($expenseName, 'expense', 'tourism', 'EGP', 0.0);
        $userId = $this->user->id;

        // COGS consumption when issuing a ticket: prepaid → expense_clearing.
        $txId = DB::table('transactions')->insertGetId([
            'type' => 'transfer',
            'module' => 'flight',
            'amount' => 1200.0,
            'currency' => 'EGP',
            'from_account_id' => $prepaidId,
            'to_account_id' => $expenseClearingId,
            'notes' => null,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('account_entries')->insert([
            ['transaction_id' => $txId, 'account_id' => $prepaidId, 'debit' => 0.0, 'credit' => 1200.0, 'balance_after' => 48800.0, 'created_at' => now(), 'updated_at' => now()],
            ['transaction_id' => $txId, 'account_id' => $expenseClearingId, 'debit' => 1200.0, 'credit' => 0.0, 'balance_after' => 1200.0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $service = app(ReportFinanceService::class);
        $result = $service->getExpenseByModule([
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'category' => 'tourism',
        ]);

        $this->assertGreaterThan(
            0.0,
            (float) ($result['flight'] ?? 0),
            'transfer-type flight COGS must appear in getExpenseByModule.flight'
        );
        $this->assertEquals(1200.0, round((float) $result['flight'], 2));
    }

    /**
     * Daily chart: a transfer-type flight revenue recognition (clearing
     * → cashbox) must be counted as `total_income` for its date. Pre-fix
     * the chart series stayed flat at zero for tourism revenue.
     */
    public function test_get_daily_financial_chart_counts_transfer_type_revenue(): void
    {
        $cashboxId = $this->seedAccount('CASHBOX-DAILY', 'cashbox', 'tourism', 'EGP', 0.0);
        $incomeClearingId = $this->seedAccount(
            config('accounting.clearing.income.flight', 'إقفال مبيعات الطيران (نظام)'),
            'income',
            'tourism',
            'EGP',
            0.0
        );
        $userId = $this->user->id;

        $today = now()->toDateString();

        $txId = DB::table('transactions')->insertGetId([
            'type' => 'transfer',
            'module' => 'flight',
            'amount' => 800.0,
            'currency' => 'EGP',
            'from_account_id' => $incomeClearingId,
            'to_account_id' => $cashboxId,
            'notes' => null,
            'created_by' => $userId,
            'created_at' => $today.' 10:00:00',
            'updated_at' => now(),
        ]);

        DB::table('account_entries')->insert([
            ['transaction_id' => $txId, 'account_id' => $incomeClearingId, 'debit' => 800.0, 'credit' => 0.0, 'balance_after' => 0.0, 'created_at' => now(), 'updated_at' => now()],
            ['transaction_id' => $txId, 'account_id' => $cashboxId, 'debit' => 0.0, 'credit' => 800.0, 'balance_after' => 800.0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $service = app(ReportFinanceService::class);
        $rows = $service->getDailyFinancialChart([
            'from_date' => $today,
            'to_date' => $today,
        ]);

        $todayRow = $rows->firstWhere('date', $today);
        $this->assertNotNull($todayRow, "Expected a row for {$today}");
        $this->assertEquals(
            800.0,
            round((float) ($todayRow['total_income'] ?? 0), 2),
            'transfer-type revenue must show in daily-chart income'
        );
    }

    /**
     * Module-key coverage: `getIncomeByModule` must include hajj_umra
     * and visa modules. Pre-fix the result array was
     * `[flight, bus, service, online, general]` — so Hajj/Umra revenue
     * was structurally missing even when there was data for it.
     */
    public function test_get_income_by_module_includes_all_tourism_keys(): void
    {
        $service = app(ReportFinanceService::class);
        $result = $service->getIncomeByModule([
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
        ]);

        foreach (['flight', 'hajj_umra', 'visa', 'tourism', 'bus', 'fawry', 'online', 'wallet', 'general'] as $key) {
            $this->assertArrayHasKey(
                $key,
                $result,
                "getIncomeByModule result missing '{$key}' bucket — pre-fix this omitted hajj/visa modules entirely"
            );
        }
    }

    private function seedAccount(string $name, string $type, string $moduleType, string $currency, float $balance): int
    {
        return DB::table('accounts')->insertGetId([
            'name' => $name,
            'type' => $type,
            'module_type' => $moduleType,
            'module' => $moduleType === 'tourism' ? 'tourism' : null,
            'currency' => $currency,
            'balance' => $balance,
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
