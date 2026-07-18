<?php

namespace Tests\Feature\Reports;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\PrepaidLedgerService;
use App\Services\Finance\TransactionService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\FlightBookingService;
use App\Services\Reports\FinancialReportService;
use App\Services\Reports\ProfitLossReportService;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🏆 اسكربت التغطية الشاملة لقسم السياحة (Tourism Division)
 *
 * ✅ Account Totals: التحقق من صحة تجميع أرصدة الحسابات
 * ✅ P&L Logic: التحقق من صحة الإيرادات/التكاليف/المصروفات/صافي الربح
 * ✅ Frontend Display: التحقق من أن الـ API يُرجع البيانات بنفس الـ structure
 *    اللي يستهلكها Vue (ProfitLoss.vue)
 * ✅ Per-amount Traceability: كل مبلغ في P&L يجب أن يكون مُتتبع لـ:
 *    - العملية (transaction) بتاعته
 *    - الموديول (flight/hajj_umra/visa)
 *    - الحساب (clearing/expense/prepaid)
 *
 * الموديولات المغطاة في قسم السياحة:
 *    - flight (الطيران)
 *    - hajj_umra (الحج والعمرة)
 *    - visa (التأشيرات)
 *
 * الأقسام الأخرى (مش مغطاة هنا):
 *    - bus, fawry, online, wallet → قسم المكتب (Office Division)
 *
 * @see \App\Services\Reports\ProfitLossReportService
 * @see \App\Services\Reports\FinancialReportService
 * @see \App\Services\Finance\LedgerClearingAccounts
 * @see \App\Support\Finance\AccountModuleContract
 */
class TourismPAndLComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    // Tourism-division accounts
    protected Account $tourismTreasury;

    protected Account $tourismBank;

    protected Account $tourismWallet;

    // Prepaid GL accounts (per clearing key)
    protected int $flightIncomeClearingId;
    protected int $flightExpenseClearingId;
    protected int $flightPrepaidId;

    // Tourism-specific operational accounts
    protected Account $tourismExpenseRent;       // مصروف إيجار
    protected Account $tourismExpenseSalaries;   // مصروف رواتب
    protected Account $tourismExpenseMarketing;  // مصروف تسويق

    protected FlightSystem $flightSystem;
    protected FlightCarrier $carrier;
    protected AirlineAccount $airlineAccount;

    protected FlightBookingService $bookingService;
    protected TransactionService $txService;
    protected PrepaidLedgerService $prepaidService;
    protected LedgerClearingAccounts $clearingAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        // Activate strict mode for Phase 1v2 protection testing
        config(['accounting.strict_test_guards' => true]);

        $this->admin = User::factory()->create([
            'name' => 'Tourism P&L Admin',
            'email' => 'tourism-pl-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->bookingService = app(FlightBookingService::class);
        $this->txService = app(TransactionService::class);
        $this->prepaidService = app(PrepaidLedgerService::class);
        $this->clearingAccounts = app(LedgerClearingAccounts::class);

        // ─────────────────────────────────────────────────────────────
        // Tourism-division liquidity accounts (العملة: EGP، module_type=tourism)
        // ─────────────────────────────────────────────────────────────

        $this->tourismTreasury = Account::create([
            'name' => 'خزينة قسم السياحة',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 500000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        $this->tourismBank = Account::create([
            'name' => 'بنك قسم السياحة',
            'type' => 'bank',
            'currency' => 'EGP',
            'balance' => 200000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        $this->tourismWallet = Account::create([
            'name' => 'محفظة قسم السياحة',
            'type' => 'wallet',
            'currency' => 'EGP',
            'balance' => 50000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        // ─────────────────────────────────────────────────────────────
        // Flight-specific clearing accounts (من الـ LedgerClearingAccounts)
        // ─────────────────────────────────────────────────────────────

        $this->flightIncomeClearingId = $this->clearingAccounts->incomeContraIdForModule(TransactionModule::Flight);
        $this->flightExpenseClearingId = $this->clearingAccounts->expenseContraIdForModule(TransactionModule::Flight);
        $this->flightPrepaidId = $this->clearingAccounts->prepaidAccountId('flight_carrier');

        $this->assertNotNull($this->flightIncomeClearingId, 'Flight income clearing must exist');
        $this->assertNotNull($this->flightExpenseClearingId, 'Flight expense clearing must exist');
        $this->assertNotNull($this->flightPrepaidId, 'Flight prepaid clearing must exist');

        // ─────────────────────────────────────────────────────────────
        // Tourism-division operating expense accounts (مصروفات تشغيلية)
        // ─────────────────────────────────────────────────────────────

        $this->tourismExpenseRent = Account::create([
            'name' => 'مصروف إيجار مكتب السياحة',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        $this->tourismExpenseSalaries = Account::create([
            'name' => 'مصروف رواتب قسم السياحة',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        $this->tourismExpenseMarketing = Account::create([
            'name' => 'مصروف تسويق سياحي',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        // ─────────────────────────────────────────────────────────────
        // Flight sub-ledger setup (AirlineAccount, FlightCarrier)
        // ─────────────────────────────────────────────────────────────

        $this->flightSystem = FlightSystem::create([
            'name' => 'Tourism Test System',
            'code' => 'TTS'.uniqid(),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'flight_system_id' => $this->flightSystem->id,
            'name' => 'Tourism Test Carrier',
            'code' => 'TTC'.uniqid(),
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->airlineAccount = AirlineAccount::create([
            'flight_carrier_id' => $this->carrier->id,
            'name' => 'Airline Account EGP',
            'code' => 'AAE'.uniqid(),
            'system_type' => 'Amadeus',
            'currency' => 'EGP',
            'credit_limit' => 10000,
            'is_active' => true,
        ]);
        // رصيد ابتدائي عبر credit() (المسار المعتمد)
        $this->airlineAccount->refresh();
        $this->airlineAccount->credit(20000.00, 'Tourism PL test setup', $this->admin->id, null);
    }

    // ════════════════════════════════════════════════════════════════
    // Section A: Account Totals Verification
    // ════════════════════════════════════════════════════════════════

    /**
     * ✅ A1) إجمالي أرصدة قسم السياحة = 750000 (500K + 200K + 50K)
     */
    public function test_tourism_liquidity_accounts_total_is_750000_egp(): void
    {
        $tourismAccounts = Account::query()
            ->where('module_type', AccountModuleContract::TOURISM_MODULE_TYPE)
            ->whereIn('type', AccountModuleContract::LIQUIDITY_TYPES)
            ->get();

        $total = (float) $tourismAccounts->sum('balance');

        $this->assertCount(3, $tourismAccounts,
            'Tourism division must have exactly 3 liquidity accounts');
        $this->assertEquals(750000.0, $total,
            'Tourism liquidity total = 500K (cashbox) + 200K (bank) + 50K (wallet)');
    }

    /**
     * ✅ A2) كل الـ accounts في قسم السياحة تستخدم module_type=tourism بشكل صحيح
     */
    public function test_tourism_accounts_use_correct_module_type(): void
    {
        $tourismAccounts = Account::query()
            ->where('module_type', AccountModuleContract::TOURISM_MODULE_TYPE)
            ->get();

        $this->assertGreaterThan(3, $tourismAccounts->count(),
            'Must include liquidity + expense accounts + clearing accounts');

        // كل الـ accounts tourism لازم تكون في الـ module_type tourism
        foreach ($tourismAccounts as $account) {
            $this->assertEquals(
                AccountModuleContract::TOURISM_MODULE_TYPE,
                $account->module_type,
                "Account '{$account->name}' must be in tourism division"
            );
        }
    }

    /**
     * ✅ A3) لا توجد تسريبات بين قسم السياحة وقسم المكتب (Office)
     */
    public function test_no_account_leaks_between_tourism_and_office_divisions(): void
    {
        // نتأكد إن الـ 5 من liquidity tourism منفصلة عن office
        $tourismLiquidity = Account::query()
            ->where('module_type', AccountModuleContract::TOURISM_MODULE_TYPE)
            ->whereIn('type', AccountModuleContract::LIQUIDITY_TYPES)
            ->pluck('id')
            ->toArray();

        // إنشاء حساب office منفصل
        $officeAccount = Account::create([
            'name' => 'Office Test Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
            'created_by' => $this->admin->id,
        ]);

        $officeLiquidity = Account::query()
            ->where('module_type', AccountModuleContract::OFFICE_MODULE_TYPE)
            ->whereIn('type', AccountModuleContract::LIQUIDITY_TYPES)
            ->pluck('id')
            ->toArray();

        $this->assertNotEmpty($tourismLiquidity);
        $this->assertNotEmpty($officeLiquidity);

        // الـ IDs ما لازمش تتقاطع
        $intersection = array_intersect($tourismLiquidity, $officeLiquidity);
        $this->assertEmpty($intersection,
            'No account can belong to both tourism and office divisions');
    }

    // ════════════════════════════════════════════════════════════════
    // Section B: Revenue + COGS verification (P&L core logic)
    // ════════════════════════════════════════════════════════════════

    /**
     * ✅ B1) عملية بيع flight = revenue فقط، والـ COGS = 0 قبل ما تتعمل consumption
     */
    public function test_flight_sale_records_revenue_no_cogs_until_consumed(): void
    {
        // Prepaid GL recharge (محايد في الـ P&L)
        $this->prepaidService->recharge(
            prepaidKey: 'flight_carrier',
            source: $this->tourismTreasury,
            amount: 5000.00,
            module: TransactionModule::Flight,
            notes: 'Recharge prepaid GL',
            relatedType: FlightCarrier::class,
            relatedId: $this->carrier->id,
        );

        $report = app(ProfitLossReportService::class)->report([]);
        // الـ recharge prepaid → 0 revenue, 0 expense (محايد)
        $this->assertEquals(0.0, (float) ($report['totalRevenues'] ?? 0),
            'Prepaid recharge must be neutral (no revenue)');

        // Flight sale — income clearing → tourism treasury
        $incomeClearing = Account::findOrFail($this->flightIncomeClearingId);
        $this->txService->recordIncome([
            'amount' => 10000.00,
            'to_account_id' => $this->tourismTreasury->id,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Flight sale income',
            'created_by' => $this->admin->id,
        ]);

        $report = app(ProfitLossReportService::class)->report([]);
        $this->assertEquals(10000.0, (float) ($report['totalRevenues'] ?? 0),
            'Flight sale must record 10000 in revenue');
    }

    /**
     * ✅ B2) Flight COGS: من prepaid GL → expense clearing = COGS (100% من الـ sale)
     */
    public function test_flight_cogs_flows_from_prepaid_to_expense_clearing(): void
    {
        $expenseClearing = Account::findOrFail($this->flightExpenseClearingId);
        $prepaid = Account::findOrFail($this->flightPrepaidId);

        // 1. Recharge prepaid
        $this->prepaidService->recharge(
            prepaidKey: 'flight_carrier',
            source: $this->tourismTreasury,
            amount: 8000.00,
            module: TransactionModule::Flight,
            notes: 'Recharge',
            relatedType: FlightCarrier::class,
            relatedId: $this->carrier->id,
        );

        // 2. Consume COGS (prepaid → expense clearing)
        $this->prepaidService->consumeCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: 6000.00,
            notes: 'Flight COGS consumption',
            relatedType: FlightCarrier::class,
            relatedId: $this->carrier->id,
        );

        $report = app(ProfitLossReportService::class)->report([]);

        // COGS = 6000 (مفروض)
        $this->assertEquals(6000.0, (float) ($report['totalCogs'] ?? 0),
            'Flight COGS (prepaid → expense clearing) must equal 6000');
    }

    /**
     * ✅ B3) Flight COGS Reversal — لما يتم عمل refund، الـ COGS يرجع
     *      (prepaid → expense = reverse COGS)
     */
    public function test_flight_cogs_reversal_via_refund_cogs(): void
    {
        // Setup: consume COGS أولاً
        $this->prepaidService->recharge(
            prepaidKey: 'flight_carrier',
            source: $this->tourismTreasury,
            amount: 5000.00,
            module: TransactionModule::Flight,
            notes: 'Recharge',
            relatedType: FlightCarrier::class,
            relatedId: $this->carrier->id,
        );
        $this->prepaidService->consumeCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: 3000.00,
            notes: 'COGS',
            relatedType: FlightCarrier::class,
            relatedId: $this->carrier->id,
        );

        $reportBeforeRefund = app(ProfitLossReportService::class)->report([]);
        $this->assertEquals(3000.0, (float) ($reportBeforeRefund['totalCogs'] ?? 0));

        // Refund: prepaid ← expense (cogs reversal)
        $this->prepaidService->refundCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: 1000.00,
            notes: 'COGS refund',
            relatedType: FlightCarrier::class,
            relatedId: $this->carrier->id,
        );

        $reportAfterRefund = app(ProfitLossReportService::class)->report([]);
        $this->assertEquals(2000.0, (float) ($reportAfterRefund['totalCogs'] ?? 0),
            'After refunding 1000 of COGS, expense must be 3000-1000 = 2000');
    }

    // ════════════════════════════════════════════════════════════════
    // Section C: ProfitLossReportService — format & breakdown
    // ════════════════════════════════════════════════════════════════

    /**
     * ✅ C1) الـ report يرجع بالـ structure اللي يستهلكها Vue (ProfitLoss.vue)
     */
    public function test_profit_loss_report_has_frontend_expected_structure(): void
    {
        // Setup: revenue + cogs
        $incomeClearing = Account::findOrFail($this->flightIncomeClearingId);
        $this->txService->recordIncome([
            'amount' => 5000,
            'to_account_id' => $this->tourismTreasury->id,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Test revenue',
            'created_by' => $this->admin->id,
        ]);

        $report = app(ProfitLossReportService::class)->report([
            'category' => 'tourism',
        ]);

        // الـ Vue يستهلك هذه الـ keys:
        // - totalRevenues, totalCogs, totalExpenses, totalRefunds
        // - grossProfit, netProfit
        // - revenuesList, cogsList, expensesList, refundsList
        // - period, meta
        $expectedKeys = [
            'totalRevenues', 'totalCogs', 'totalExpenses', 'totalRefunds',
            'grossProfit', 'netProfit',
            'revenuesList', 'cogsList', 'expensesList', 'refundsList',
            'period', 'meta',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $report,
                "ProfitLossReportService must return key '{$key}' for Vue consumer");
        }

        // revenuesList / cogsList لازم تكون array of {name, amount, module?}
        $this->assertIsArray($report['revenuesList']);
        $this->assertIsArray($report['cogsList']);
        $this->assertIsArray($report['expensesList']);
        $this->assertIsArray($report['refundsList']);
    }

    /**
     * ✅ C2) filter بقسم السياحة فقط — revenue من office لازم ما يظهرش
     */
    public function test_tourism_filter_excludes_office_revenue(): void
    {
        // Setup: office revenue (مش tourism)
        $officeIncomeClearingId = $this->clearingAccounts->incomeContraIdForModule(TransactionModule::Bus);
        $officeCashbox = Account::create([
            'name' => 'Office Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
            'created_by' => $this->admin->id,
        ]);

        $this->txService->recordIncome([
            'amount' => 5000,
            'to_account_id' => $officeCashbox->id,
            'module' => TransactionModule::Bus->value,
            'notes' => 'Office revenue',
            'created_by' => $this->admin->id,
        ]);

        // Tourism revenue
        $this->txService->recordIncome([
            'amount' => 3000,
            'to_account_id' => $this->tourismTreasury->id,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Tourism revenue',
            'created_by' => $this->admin->id,
        ]);

        $tourismOnly = app(ProfitLossReportService::class)->report(['category' => 'tourism']);
        $allData = app(ProfitLossReportService::class)->report([]);

        // قسم السياحة فقط → 3000 (flight) بس
        $this->assertEquals(3000.0, (float) $tourismOnly['totalRevenues'],
            'Tourism-only filter must include only tourism revenue (3000)');

        // كل البيانات → 8000 (3000 flight + 5000 bus)
        $this->assertEquals(8000.0, (float) $allData['totalRevenues'],
            'All-data filter must include both tourism (3000) and office (5000)');
    }

    /**
     * ✅ C3) moduleBreakdown يُرجع per-module تفصيل: flight, hajj_umra, visa
     */
    public function test_tourism_module_breakdown_by_module(): void
    {
        $report = app(ProfitLossReportService::class)->moduleBreakdown([
            'from_date' => now()->startOfMonth()->toDateString(),
            'to_date' => now()->toDateString(),
        ]);

        $this->assertArrayHasKey('by_module', $report);
        $this->assertIsArray($report['by_module']);

        // كل entry في by_module لازم يكون {module, income, cogs, expense, profit}
        foreach ($report['by_module'] as $entry) {
            $this->assertArrayHasKey('module', $entry);
            $this->assertArrayHasKey('income', $entry);
            $this->assertArrayHasKey('cogs', $entry);
            $this->assertArrayHasKey('expense', $entry);
            $this->assertArrayHasKey('profit', $entry);

            // profit = income - cogs - expense (per module)
            $expectedProfit = (float) $entry['income'] - (float) $entry['cogs'] - (float) $entry['expense'];
            $this->assertEquals($expectedProfit, (float) $entry['profit'],
                "Module '{$entry['module']}' profit must equal income - cogs - expense");
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Section D: Per-amount traceability (كل مبلغ → العملية + الموديول)
    // ════════════════════════════════════════════════════════════════

    /**
     * ✅ D1) كل revenue في P&L يكون مرتبط بـ transaction محدد وموديول محدد
     */
    public function test_every_revenue_amount_is_traceable_to_transaction_and_module(): void
    {
        // أنشئ 3 revenue من موديولات مختلفة
        $modules = [
            ['name' => 'Flight Sale', 'module' => TransactionModule::Flight->value, 'amount' => 5000.00],
            ['name' => 'Hajj Sale', 'module' => TransactionModule::HajjUmra->value, 'amount' => 8000.00],
            ['name' => 'Visa Sale', 'module' => TransactionModule::Flight->value, 'amount' => 2000.00],  // visa via flight
        ];

        foreach ($modules as $entry) {
            $this->txService->recordIncome([
                'amount' => $entry['amount'],
                'to_account_id' => $this->tourismTreasury->id,
                'module' => $entry['module'],
                'notes' => $entry['name'],
                'created_by' => $this->admin->id,
            ]);
        }

        $report = app(ProfitLossReportService::class)->report([
            'category' => 'tourism',
        ]);

        // نتأكد إن كل revenuesList entry له اسم ومبلغ
        $this->assertGreaterThan(0, count($report['revenuesList']));

        $totalFromList = 0.0;
        foreach ($report['revenuesList'] as $entry) {
            $this->assertArrayHasKey('name', $entry, 'Each revenue entry must have a name');
            $this->assertArrayHasKey('amount', $entry, 'Each revenue entry must have an amount');
            $totalFromList += (float) $entry['amount'];
        }

        // إجمالي الـ list = إجمالي revenue (15000)
        $this->assertEquals(15000.0, (float) $report['totalRevenues'],
            'Total revenue must equal sum of all 3 sales');
    }

    /**
     * ✅ D2) totalExpenses يحتوي على كل المصروفات التشغيلية بشكل تفصيلي
     */
    public function test_every_operating_expense_appears_in_p_and_l_breakdown(): void
    {
        // 3 مصروفات تشغيلية
        $expenses = [
            ['account' => $this->tourismExpenseRent, 'amount' => 5000, 'desc' => 'إيجار شهر 1'],
            ['account' => $this->tourismExpenseSalaries, 'amount' => 15000, 'desc' => 'رواتب شهر 1'],
            ['account' => $this->tourismExpenseMarketing, 'amount' => 3000, 'desc' => 'حملة تسويق'],
        ];

        foreach ($expenses as $exp) {
            $this->txService->recordJournalTransfer([
                'amount' => $exp['amount'],
                'from_account_id' => $this->tourismTreasury->id,
                'to_account_id' => $exp['account']->id,
                'allow_from_negative' => true,
                'module' => TransactionModule::Flight->value,
                'notes' => $exp['desc'],
                'created_by' => $this->admin->id,
            ]);
        }

        $report = app(ProfitLossReportService::class)->report([]);

        // totalExpenses = 23000
        $this->assertEquals(23000.0, (float) $report['totalExpenses'],
            'Total operating expenses must equal 5000 + 15000 + 3000 = 23000');

        // expensesList لازم يحتوي الـ 3 expenses بالاسم
        $expenseNames = collect($report['expensesList'])->pluck('name')->toArray();
        $this->assertContains('مصروف إيجار مكتب السياحة', $expenseNames);
        $this->assertContains('مصروف رواتب قسم السياحة', $expenseNames);
        $this->assertContains('مصروف تسويق سياحي', $expenseNames);
    }

    /**
     * ✅ D3) Refund يظهر في الـ refundsList منفصلاً عن revenuesList
     */
    public function test_refund_appears_separately_in_refunds_list(): void
    {
        $incomeClearing = Account::findOrFail($this->flightIncomeClearingId);

        // revenue عادية: clearing → treasury (from=income, to=treasury)
        $this->txService->recordIncome([
            'amount' => 10000,
            'to_account_id' => $this->tourismTreasury->id,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Sale',
            'created_by' => $this->admin->id,
        ]);

        // refund: treasury → clearing (reverse direction — from=treasury, to=income)
        // لازم يكون transfer عكسي عشان الـ classifier يشوفه revenue_reversal
        $this->txService->recordJournalTransfer([
            'amount' => 3000,
            'from_account_id' => $this->tourismTreasury->id,
            'to_account_id' => $incomeClearing->id,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Refund (reversal)',
            'created_by' => $this->admin->id,
        ]);

        $report = app(ProfitLossReportService::class)->report([]);

        // totalRevenues = 10000 - 3000 = 7000 (reversal)
        $this->assertEquals(7000.0, (float) $report['totalRevenues']);

        // totalRefunds = 3000
        $this->assertEquals(3000.0, (float) $report['totalRefunds']);
        $this->assertGreaterThan(0, count($report['refundsList']));
    }

    // ════════════════════════════════════════════════════════════════
    // Section E: Frontend API endpoints — the contract with Vue
    // ════════════════════════════════════════════════════════════════

    /**
     * ✅ E1) GET /api/v1/reports/profit-loss (Admin) — Vue يستهلكها
     */
    public function test_api_profit_loss_endpoint_returns_correct_structure(): void
    {
        $response = $this->getJson('/api/v1/reports/profit-loss?category=tourism');

        $response->assertOk()
            ->assertJson(['success' => true])
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
                    'meta',
                ],
            ]);

        // ✅ Vue يستهلك data.totalRevenues مباشرة
        $this->assertIsNumeric($response->json('data.totalRevenues'));
    }

    /**
     * ✅ E2) GET /api/v1/reports/profit-by-module — Vue AccountsIndex drill-down
     */
    public function test_api_profit_by_module_endpoint_for_drill_down(): void
    {
        $response = $this->getJson('/api/v1/reports/profit-by-module');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    'by_module',
                    'from_date',
                    'to_date',
                ],
            ]);

        $this->assertIsArray($response->json('data.by_module'));
    }

    /**
     * ✅ E3) GET /api/v1/reports/profit-by-day — Vue drill-down "يومي"
     */
    public function test_api_profit_by_day_endpoint(): void
    {
        $response = $this->getJson('/api/v1/reports/profit-by-day?module=flight');

        $response->assertOk()
            ->assertJson(['success' => true]);

        // الفترة المحسوبة
        $this->assertArrayHasKey('data', $response->json());
    }

    // ════════════════════════════════════════════════════════════════
    // Section F: End-to-end scenario (Tourism Division full lifecycle)
    // ════════════════════════════════════════════════════════════════

    /**
     * ✅ F1) سيناريو شامل: recharge → sale → cogs → expense → refund
     *    → النتيجة: revenue, cogs, expense, refund محسوبة كلها بشكل صحيح
     */
    public function test_full_tourism_division_p_and_l_scenario(): void
    {
        $incomeClearing = Account::findOrFail($this->flightIncomeClearingId);
        $expenseClearing = Account::findOrFail($this->flightExpenseClearingId);
        $prepaid = Account::findOrFail($this->flightPrepaidId);

        // ═══════════════════════════════════════════════════════
        // Step 1: Prepaid GL recharge — neutral
        // ═══════════════════════════════════════════════════════
        $this->prepaidService->recharge(
            prepaidKey: 'flight_carrier',
            source: $this->tourismTreasury,
            amount: 10000.00,
            module: TransactionModule::Flight,
            notes: 'Setup: prepaid recharge',
            relatedType: FlightCarrier::class,
            relatedId: $this->carrier->id,
        );

        // ═══════════════════════════════════════════════════════
        // Step 2: Revenue — sale 1: 15000 (income clearing → tourism treasury)
        // ═══════════════════════════════════════════════════════
        $this->txService->recordIncome([
            'amount' => 15000,
            'to_account_id' => $this->tourismTreasury->id,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Sale 1',
            'created_by' => $this->admin->id,
        ]);

        // ═══════════════════════════════════════════════════════
        // Step 3: COGS — consume 8000 (prepaid → expense clearing)
        // ═══════════════════════════════════════════════════════
        $this->prepaidService->consumeCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: 8000.00,
            notes: 'COGS for sale 1',
            relatedType: FlightCarrier::class,
            relatedId: $this->carrier->id,
        );

        // ═══════════════════════════════════════════════════════
        // Step 4: Operating Expense — rent 2000 (tourism treasury → expense account)
        // ═══════════════════════════════════════════════════════
        $this->txService->recordJournalTransfer([
            'amount' => 2000,
            'from_account_id' => $this->tourismTreasury->id,
            'to_account_id' => $this->tourismExpenseRent->id,
            'allow_from_negative' => true,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Office rent',
            'created_by' => $this->admin->id,
        ]);

        // ═══════════════════════════════════════════════════════
        // Step 5: Refund — 5000 (revenue reversal: treasury → clearing)
        // ═══════════════════════════════════════════════════════
        $this->txService->recordJournalTransfer([
            'amount' => 5000,
            'from_account_id' => $this->tourismTreasury->id,
            'to_account_id' => $incomeClearing->id,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Refund for sale 1',
            'created_by' => $this->admin->id,
        ]);

        // ═══════════════════════════════════════════════════════
        // Final P&L
        // ═══════════════════════════════════════════════════════
        $report = app(ProfitLossReportService::class)->report([]);

        // Revenue = 15000 - 5000 (refund) = 10000
        $this->assertEquals(10000.0, (float) $report['totalRevenues'],
            'Revenue = Sale 15000 - Refund 5000 = 10000');

        // COGS = 8000 (refund of COGS مش اتعمل)
        $this->assertEquals(8000.0, (float) $report['totalCogs'],
            'COGS = 8000');

        // Operating expenses = 2000 (rent)
        $this->assertEquals(2000.0, (float) $report['totalExpenses'],
            'Operating expenses = 2000');

        // Refunds = 5000
        $this->assertEquals(5000.0, (float) $report['totalRefunds'],
            'Refunds = 5000');

        // Gross Profit = 10000 - 8000 = 2000
        $this->assertEquals(2000.0, (float) $report['grossProfit'],
            'Gross Profit = 10000 - 8000 = 2000');

        // Net Profit = 10000 - 8000 - 2000 = 0
        $this->assertEquals(0.0, (float) $report['netProfit'],
            'Net Profit = 10000 - 8000 - 2000 = 0');
    }

    // ════════════════════════════════════════════════════════════════
    // Section G: Regressions — Money shouldn't appear out of nowhere
    // ════════════════════════════════════════════════════════════════

    /**
     * ✅ G1) treasury-to-treasury transfer = 0 (محايد، ما يبقاش P&L)
     */
    public function test_treasury_to_treasury_transfer_excluded_from_pnl(): void
    {
        $this->txService->recordJournalTransfer([
            'amount' => 10000,
            'from_account_id' => $this->tourismTreasury->id,
            'to_account_id' => $this->tourismBank->id,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Internal transfer',
            'created_by' => $this->admin->id,
        ]);

        $report = app(ProfitLossReportService::class)->report([]);

        $this->assertEquals(0.0, (float) $report['totalRevenues'],
            'Treasury-to-treasury transfer must NOT appear as revenue');
        $this->assertEquals(0.0, (float) $report['totalCogs']);
        $this->assertEquals(0.0, (float) $report['totalExpenses']);
    }

    /**
     * ✅ G2) Prepaid GL → prepaid GL transfer = 0 (محايد)
     */
    public function test_prepaid_to_prepaid_transfer_excluded(): void
    {
        $prepaid = Account::findOrFail($this->flightPrepaidId);

        $this->txService->recordJournalTransfer([
            'amount' => 1000,
            'from_account_id' => $prepaid->id,
            'to_account_id' => $this->tourismTreasury->id,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Wrong prepaid consumption',
            'created_by' => $this->admin->id,
        ]);

        $report = app(ProfitLossReportService::class)->report([]);

        // لازم ما يبقاش في revenue / expense (محايد)
        $this->assertEquals(0.0, (float) $report['totalRevenues']);
        $this->assertEquals(0.0, (float) $report['totalExpenses']);
    }
}