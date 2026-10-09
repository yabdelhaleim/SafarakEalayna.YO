<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Reports\ProfitLossReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Full financial reconciliation tests for the Tourism P&L engine.
 *
 * Required by PNL/TOURISM-FIX task spec sections 10-14:
 *   - Section 10: financial reconciliation per module (independently calculated
 *                 expected values, not derived from the implementation).
 *   - Section 11: negative / exclusion tests (office modules MUST not appear
 *                 in tourism report).
 *   - Section 12: duplication tests (one transaction MUST contribute exactly
 *                 once even if multiple SQL branches match).
 *   - Section 13: date filter tests (boundary dates).
 *   - Section 14: soft-delete tests.
 *
 * Expected values are calculated independently from the spec (Revenue, COGS,
 * Expense → Profit = R − C − E) and asserted against the public service API,
 * NOT against any internal state.
 */
class PnlTourismReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    /** Income clearing accounts, keyed by module key (flight/hajj_umra/visa/tourism). */
    protected array $incomeClearings = [];

    /** Expense clearing accounts, keyed by module key. */
    protected array $expenseClearings = [];

    /** Single operating-expense account. */
    protected Account $officeExpenseAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Reconciliation Tester',
            'email' => 'recon-test@example.com',
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
            'name' => 'Reconciliation Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100_000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        // Resolve clearing accounts for tourism modules + a few office
        // modules used in negative/exclusion tests.
        $clearing = app(LedgerClearingAccounts::class);
        foreach (['flight', 'hajj_umra', 'visa', 'tourism'] as $mod) {
            $incomeId = $clearing->incomeContraIdForModule($mod);
            $expenseId = $clearing->expenseContraIdForModule($mod);
            $this->assertNotNull($incomeId, "income clearing for $mod must exist");
            $this->assertNotNull($expenseId, "expense clearing for $mod must exist");
            $this->incomeClearings[$mod] = Account::query()->findOrFail($incomeId);
            $this->expenseClearings[$mod] = Account::query()->findOrFail($expenseId);
        }

        $this->officeExpenseAccount = Account::create([
            'name' => 'مصروف إيجار المكتب',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * Section 10 — Financial reconciliation per module.
     *
     * Flight scenario from the spec:
     *   Revenue = 10,000
     *   COGS    =  6,000
     *   Expense =  1,000
     *   Expected Profit = 3,000
     */
    public function test_flight_module_reconciles_to_expected_profit(): void
    {
        $this->revenue($this->incomeClearings['flight'], 10000, 'flight', 'بيع تذكرة');
        $this->cogs($this->expenseClearings['flight'], 6000, 'flight', 'تكلفة حجز طيران');
        $this->operatingExpense(1000, 'flight', 'عمولة المكتب');

        $breakdown = $this->byModule('flight');

        $this->assertSame(10000.0, $breakdown['income'], 'flight revenue');
        $this->assertSame(6000.0, $breakdown['cogs'], 'flight cogs');
        $this->assertSame(1000.0, $breakdown['expense'], 'flight opex');
        $this->assertSame(3000.0, $breakdown['profit'], 'flight profit = R − C − E');
    }

    /**
     * Section 10 — Visa scenario from the spec:
     *   Revenue = 5,000, COGS = 2,000, Expense = 500 → Profit = 2,500
     */
    public function test_visa_module_reconciles_to_expected_profit(): void
    {
        $this->revenue($this->incomeClearings['visa'], 5000, 'visa', 'تأشيرة سفر');
        $this->cogs($this->expenseClearings['visa'], 2000, 'visa', 'تكلفة تأشيرة');
        $this->operatingExpense(500, 'visa', 'رسوم وكيل');

        $breakdown = $this->byModule('visa');

        $this->assertSame(5000.0, $breakdown['income']);
        $this->assertSame(2000.0, $breakdown['cogs']);
        $this->assertSame(500.0, $breakdown['expense']);
        $this->assertSame(2500.0, $breakdown['profit']);
    }

    /**
     * Section 10 — Hajj/Umra scenario (independently calculated):
     *   Revenue = 8,000, COGS = 4,000, Expense = 800 → Profit = 3,200
     */
    public function test_hajj_umra_module_reconciles_to_expected_profit(): void
    {
        $this->revenue($this->incomeClearings['hajj_umra'], 8000, 'hajj_umra', 'باقة عمرة');
        $this->cogs($this->expenseClearings['hajj_umra'], 4000, 'hajj_umra', 'تكلفة فندق وتأشيرة');
        $this->operatingExpense(800, 'hajj_umra', 'مكاتب خدمات');

        $breakdown = $this->byModule('hajj_umra');

        $this->assertSame(8000.0, $breakdown['income']);
        $this->assertSame(4000.0, $breakdown['cogs']);
        $this->assertSame(800.0, $breakdown['expense']);
        $this->assertSame(3200.0, $breakdown['profit']);
    }

    /**
     * Section 10 — Tourism total = sum of subsidiary modules.
     *
     * Setup is the sum of all 3 above plus a standalone 'tourism' revenue
     * of 1000 (no cogs, no expense).
     *
     * Independent calculation:
     *   Revenue = 10000 + 5000 + 8000 + 1000 = 24000
     *   COGS    =  6000 + 2000 + 4000 +    0 = 12000
     *   Expense =  1000 +  500 +  800 +    0 =  2300
     *   Profit  = 24000 − 12000 − 2300 = 9700
     */
    public function test_tourism_total_equals_sum_of_subsidiary_modules(): void
    {
        $this->revenue($this->incomeClearings['flight'], 10000, 'flight', 'flight revenue');
        $this->cogs($this->expenseClearings['flight'], 6000, 'flight', 'flight cogs');
        $this->operatingExpense(1000, 'flight', 'flight opex');

        $this->revenue($this->incomeClearings['visa'], 5000, 'visa', 'visa revenue');
        $this->cogs($this->expenseClearings['visa'], 2000, 'visa', 'visa cogs');
        $this->operatingExpense(500, 'visa', 'visa opex');

        $this->revenue($this->incomeClearings['hajj_umra'], 8000, 'hajj_umra', 'hajj_umra revenue');
        $this->cogs($this->expenseClearings['hajj_umra'], 4000, 'hajj_umra', 'hajj_umra cogs');
        $this->operatingExpense(800, 'hajj_umra', 'hajj_umra opex');

        // Standalone tourism revenue (module='tourism', not a sub-module).
        $this->revenue($this->incomeClearings['tourism'], 1000, 'tourism', 'standalone tourism');

        $report = app(ProfitLossReportService::class)->report(['category' => 'tourism']);

        $this->assertSame(24000.0, $report['totalRevenues'],
            'Tourism total revenue = sum of subsidiary modules per spec');
        $this->assertSame(12000.0, $report['totalCogs'],
            'Tourism total cogs = sum of subsidiary modules per spec');
        $this->assertSame(2300.0, $report['totalExpenses'],
            'Tourism total opex = sum of subsidiary modules per spec');
        $this->assertSame(9700.0, $report['netProfit'],
            'Tourism net profit = Revenue − COGS − Expenses');
    }

    /**
     * Section 10 — Tourism net profit identity holds individually too:
     *   Profit[tourism] = R − C − E
     */
    public function test_tourism_net_profit_equals_revenue_minus_cogs_minus_expenses(): void
    {
        $this->revenue($this->incomeClearings['flight'], 12345, 'flight', 'flight');
        $this->cogs($this->expenseClearings['flight'], 4321, 'flight', 'flight cogs');
        $this->operatingExpense(678, 'flight', 'flight opex');

        $report = app(ProfitLossReportService::class)->report(['category' => 'tourism']);

        $expected = round((float) $report['totalRevenues'] - (float) $report['totalCogs'] - (float) $report['totalExpenses'], 2);
        $this->assertSame($expected, (float) $report['netProfit']);
        $this->assertSame(round($expected, 2), (float) $report['grossProfit'] - (float) $report['totalExpenses']);
    }

    /**
     * Section 11 — Office transactions MUST be excluded from tourism P&L.
     */
    public function test_office_transactions_excluded_from_tourism_report(): void
    {
        $clearing = app(LedgerClearingAccounts::class);

        $fawryIncomeId = $clearing->incomeContraIdForModule('fawry');
        $fawryExpenseId = $clearing->expenseContraIdForModule('fawry');
        $busIncomeId = $clearing->incomeContraIdForModule('bus');
        $busExpenseId = $clearing->expenseContraIdForModule('bus');
        $onlineIncomeId = $clearing->incomeContraIdForModule('online');
        $walletIncomeId = $clearing->incomeContraIdForModule('wallet');

        // Office revenue + cogs + expenses across fawry, bus, online, wallet.
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'fawry', 'amount' => 9000,
            'from_account_id' => $fawryIncomeId, 'to_account_id' => $this->treasury->id,
            'created_by' => $this->user->id, 'notes' => 'fawry sale',
        ]);
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'fawry', 'amount' => 3000,
            'from_account_id' => $this->treasury->id, 'to_account_id' => $fawryExpenseId,
            'created_by' => $this->user->id, 'notes' => 'fawry cogs',
        ]);
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'bus', 'amount' => 4000,
            'from_account_id' => $busIncomeId, 'to_account_id' => $this->treasury->id,
            'created_by' => $this->user->id, 'notes' => 'bus revenue',
        ]);
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'bus', 'amount' => 1500,
            'from_account_id' => $this->treasury->id, 'to_account_id' => $busExpenseId,
            'created_by' => $this->user->id, 'notes' => 'bus cogs',
        ]);
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'online', 'amount' => 2500,
            'from_account_id' => $onlineIncomeId, 'to_account_id' => $this->treasury->id,
            'created_by' => $this->user->id, 'notes' => 'online revenue',
        ]);
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'wallet', 'amount' => 1500,
            'from_account_id' => $walletIncomeId, 'to_account_id' => $this->treasury->id,
            'created_by' => $this->user->id, 'notes' => 'wallet revenue',
        ]);

        // Now add a tourism revenue — this MUST show up.
        $this->revenue($this->incomeClearings['flight'], 5000, 'flight', 'flight');

        $tourismReport = app(ProfitLossReportService::class)->report(['category' => 'tourism']);
        $officeReport = app(ProfitLossReportService::class)->report(['category' => 'office']);

        // Tourism must only carry the 5000 flight revenue — none of the office flows.
        $this->assertSame(5000.0, $tourismReport['totalRevenues'],
            'Tourism report must NOT include any office-module revenue');
        $this->assertSame(0.0, $tourismReport['totalCogs'],
            'Tourism report must NOT include any office-module cogs');

        // Office report must carry the office flows but NOT the flight one.
        // 9000 fawry + 4000 bus + 2500 online + 1500 wallet = 17000 revenue.
        $this->assertSame(17000.0, $officeReport['totalRevenues']);
        $this->assertSame(4500.0, $officeReport['totalCogs'], 'fawry 3000 + bus 1500');
    }

    /**
     * Section 11 — Tourism transactions MUST be excluded from office P&L.
     */
    public function test_tourism_transactions_excluded_from_office_report(): void
    {
        // Tourism revenue
        $this->revenue($this->incomeClearings['flight'], 7000, 'flight', 'flight');
        $this->revenue($this->incomeClearings['visa'], 3000, 'visa', 'visa');

        // Office revenue
        $clearing = app(LedgerClearingAccounts::class);
        $fawryIncomeId = $clearing->incomeContraIdForModule('fawry');
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'fawry', 'amount' => 5000,
            'from_account_id' => $fawryIncomeId, 'to_account_id' => $this->treasury->id,
            'created_by' => $this->user->id, 'notes' => 'fawry sale',
        ]);

        $officeReport = app(ProfitLossReportService::class)->report(['category' => 'office']);

        // Office report must only carry the 5000 fawry, NOT the 10000 tourism flows.
        $this->assertSame(5000.0, $officeReport['totalRevenues']);
        $this->assertSame(0.0, $officeReport['totalCogs']);
    }

    /**
     * Section 12 — Duplication: when a tourism transaction matches MULTIPLE
     * applyCategorySqlFilter branches (module=flight AND from=income_clearing),
     * it must contribute exactly once to revenue.
     */
    public function test_revenue_counted_exactly_once_when_sql_matches_multiple_branches(): void
    {
        // One revenue transfer that hits BOTH branches:
        //   - t.module IN (TOURISM_MODULES) — 'flight' matches
        //   - from_account_id IN (tourism clearing IDs) — flight income clearing matches
        $this->revenue($this->incomeClearings['flight'], 4000, 'flight', 'flight sale');

        $tourismReport = app(ProfitLossReportService::class)->report(['category' => 'tourism']);

        $this->assertSame(4000.0, $tourismReport['totalRevenues'],
            'Revenue must be counted ONCE even when transaction matches multiple SQL branches');

        // Also verify via breakdown
        $breakdown = $this->byModule('flight');
        $this->assertSame(4000.0, $breakdown['income']);
    }

    /**
     * Section 12 — Duplication: COGS counted exactly once when matching
     * multiple branches.
     */
    public function test_cogs_counted_exactly_once_when_sql_matches_multiple_branches(): void
    {
        // COGS transfer: treasury → expense_clearing (t.module='flight', to_account in clearing IDs)
        $this->cogs($this->expenseClearings['flight'], 2500, 'flight', 'flight cogs');

        $tourismReport = app(ProfitLossReportService::class)->report(['category' => 'tourism']);

        $this->assertSame(2500.0, $tourismReport['totalCogs'],
            'COGS must be counted ONCE even when SQL matches multiple branches');
    }

    /**
     * Section 13 — Date filter: from_date excludes earlier transactions.
     */
    public function test_from_date_excludes_earlier_transactions(): void
    {
        $today = now()->startOfDay();
        $yesterday = $today->copy()->subDay();

        // Use raw DB insert to control created_at precisely (Eloquent's
        // auto-timestamp + attribute-fill interaction makes override flaky).
        \DB::table('transactions')->insert([
            'type' => 'transfer', 'module' => 'flight', 'amount' => 5000,
            'from_account_id' => $this->incomeClearings['flight']->id,
            'to_account_id' => $this->treasury->id,
            'created_by' => $this->user->id,
            'notes' => 'two days ago sale',
            'currency' => 'EGP',
            'created_at' => $today->copy()->subDays(2)->toDateTimeString(),
            'updated_at' => $today->copy()->subDays(2)->toDateTimeString(),
        ]);

        // Revenue today (must be included).
        $this->revenue($this->incomeClearings['flight'], 3000, 'flight', 'today sale');

        $report = app(ProfitLossReportService::class)->report([
            'category' => 'tourism',
            'from_date' => $yesterday->toDateString(),
            'to_date' => $today->copy()->addDay()->toDateString(),
        ]);

        $this->assertSame(3000.0, $report['totalRevenues'],
            'Only today revenue should be included when from_date = yesterday');
    }

    /**
     * Section 13 — Date filter: to_date excludes later transactions.
     */
    public function test_to_date_excludes_later_transactions(): void
    {
        $today = now()->startOfDay();

        \DB::table('transactions')->insert([
            'type' => 'transfer', 'module' => 'flight', 'amount' => 4000,
            'from_account_id' => $this->incomeClearings['flight']->id,
            'to_account_id' => $this->treasury->id,
            'created_by' => $this->user->id,
            'notes' => 'two days later sale',
            'currency' => 'EGP',
            'created_at' => $today->copy()->addDays(2)->toDateTimeString(),
            'updated_at' => $today->copy()->addDays(2)->toDateTimeString(),
        ]);

        $this->revenue($this->incomeClearings['flight'], 1500, 'flight', 'today sale');

        $report = app(ProfitLossReportService::class)->report([
            'category' => 'tourism',
            'from_date' => $today->copy()->subDay()->toDateString(),
            'to_date' => $today->copy()->addDay()->toDateString(),
        ]);

        $this->assertSame(1500.0, $report['totalRevenues'],
            'Two-days-later revenue must be excluded when to_date = today');
    }

    /**
     * Section 13 — Date filter boundary: same-day transactions included.
     */
    public function test_boundary_dates_include_same_day_transactions(): void
    {
        $today = now()->startOfDay();
        $todayString = $today->toDateString();

        \DB::table('transactions')->insert([
            'type' => 'transfer', 'module' => 'flight', 'amount' => 2000,
            'from_account_id' => $this->incomeClearings['flight']->id,
            'to_account_id' => $this->treasury->id,
            'created_by' => $this->user->id,
            'notes' => 'morning sale',
            'currency' => 'EGP',
            'created_at' => $today->copy()->setTime(0, 0, 1)->toDateTimeString(),
            'updated_at' => $today->copy()->setTime(0, 0, 1)->toDateTimeString(),
        ]);
        \DB::table('transactions')->insert([
            'type' => 'transfer', 'module' => 'flight', 'amount' => 3000,
            'from_account_id' => $this->incomeClearings['flight']->id,
            'to_account_id' => $this->treasury->id,
            'created_by' => $this->user->id,
            'notes' => 'evening sale',
            'currency' => 'EGP',
            'created_at' => $today->copy()->setTime(23, 59, 59)->toDateTimeString(),
            'updated_at' => $today->copy()->setTime(23, 59, 59)->toDateTimeString(),
        ]);

        $report = app(ProfitLossReportService::class)->report([
            'category' => 'tourism',
            'from_date' => $todayString,
            'to_date' => $todayString,
        ]);

        // Both sales on the same day must be included.
        $this->assertSame(5000.0, $report['totalRevenues']);
    }

    /**
     * Section 14 — Soft-deleted flight booking must exclude its revenue from
     * the P&L (applySoftDeleteExclusion).
     *
     * Implementation note: rather than fight FlightBookingService / observer
     * side-effects, we insert a minimal raw flight_bookings row (satisfying
     * the table's NOT NULL constraints), then DELETE via raw UPDATE.
     * The P&L exclusion engine only requires the related_type and related_id
     * on the transaction + a soft-deletable parent row.
     */
    public function test_soft_deleted_flight_booking_excluded_from_revenue(): void
    {
        // Create a customer (flight_bookings.customer_id is NOT NULL).
        $customerId = \DB::table('customers')->insertGetId([
            'full_name' => 'Soft Delete Customer',
            'phone' => '01000000099',
            'type' => 'individual',
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $now = now();
        $bookingId = \DB::table('flight_bookings')->insertGetId([
            'booking_number' => 'FLT-SOFTDEL-1',
            'booking_reference' => 'REF-SOFTDEL-1',
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'Direct',
            'system_type' => 'manual',
            'status' => 'CONFIRMED',
            'passenger_count' => 1,
            'agent_name' => 'Test Agent',
            'airline' => 'Test Air',
            'airline_name' => 'Test Air',
            'origin' => 'CAI',
            'from_airport' => 'CAI',
            'destination' => 'JED',
            'to_airport' => 'JED',
            'departure_date' => $now->copy()->addDays(5)->toDateString(),
            'departure_time' => $now->copy()->addDays(5)->setTime(10, 0)->toDateTimeString(),
            'trip_type' => 'one_way',
            'customer_id' => $customerId,
            'purchase_price' => 0,
            'selling_price' => 5000,
            'currency' => 'EGP',
            'created_by' => $this->user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Revenue transaction linked to this booking.
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'flight', 'amount' => 5000,
            'from_account_id' => $this->incomeClearings['flight']->id,
            'to_account_id' => $this->treasury->id,
            'related_type' => FlightBooking::class,
            'related_id' => $bookingId,
            'created_by' => $this->user->id,
            'notes' => 'revenue before delete',
        ]);

        // Sanity: revenue is included before soft-delete.
        $before = app(ProfitLossReportService::class)->report(['category' => 'tourism']);
        $this->assertSame(5000.0, $before['totalRevenues']);

        // Soft-delete the booking by directly setting deleted_at.
        \DB::table('flight_bookings')
            ->where('id', $bookingId)
            ->update(['deleted_at' => $now, 'updated_at' => $now]);
        $this->assertNotNull(\DB::table('flight_bookings')->where('id', $bookingId)->value('deleted_at'));

        // After soft-delete, revenue must be excluded.
        $after = app(ProfitLossReportService::class)->report(['category' => 'tourism']);
        $this->assertSame(0.0, $after['totalRevenues'],
            'Soft-deleted flight booking\'s revenue must be excluded from P&L');
    }

    // ----- Helpers -----

    /**
     * Record a "revenue" transfer: income_clearing → treasury.
     * (Clearing side → treasury, classifier labels it 'revenue'.)
     */
    protected function revenue(Account $incomeClearing, float $amount, string $module, string $notes): Transaction
    {
        return Transaction::query()->create([
            'type' => 'transfer', 'module' => $module, 'amount' => $amount,
            'from_account_id' => $incomeClearing->id,
            'to_account_id' => $this->treasury->id,
            'created_by' => $this->user->id, 'notes' => $notes,
        ]);
    }

    /**
     * Record a "COGS" transfer: treasury → expense_clearing.
     */
    protected function cogs(Account $expenseClearing, float $amount, string $module, string $notes): Transaction
    {
        return Transaction::query()->create([
            'type' => 'transfer', 'module' => $module, 'amount' => $amount,
            'from_account_id' => $this->treasury->id,
            'to_account_id' => $expenseClearing->id,
            'created_by' => $this->user->id, 'notes' => $notes,
        ]);
    }

    /**
     * Record an "operating expense" transfer: treasury → expense account (type='expense').
     */
    protected function operatingExpense(float $amount, string $module, string $notes): Transaction
    {
        return Transaction::query()->create([
            'type' => 'expense', 'module' => $module, 'amount' => $amount,
            'from_account_id' => $this->treasury->id,
            'to_account_id' => $this->officeExpenseAccount->id,
            'created_by' => $this->user->id, 'notes' => $notes,
        ]);
    }

    /**
     * Extract the by_module row for the given module.
     */
    protected function byModule(string $module): array
    {
        $breakdown = app(ProfitLossReportService::class)->moduleBreakdown();
        foreach (($breakdown['by_module'] ?? []) as $row) {
            if (($row['module'] ?? null) === $module) {
                return $row;
            }
        }

        return ['module' => $module, 'income' => 0.0, 'cogs' => 0.0, 'expense' => 0.0, 'profit' => 0.0];
    }
}
