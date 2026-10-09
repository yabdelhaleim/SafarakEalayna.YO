<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Reports\ProfitLossReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests for the four tourism-Dashboard P&L bugs found in the
 * audit (2026-09-01). Each test below is named after the hypothesis it
 * guards so a future regression points straight at the bug class.
 *
 *   H1 — moduleBreakdown() used to skip both 'عكس:' AND 'عكس '
 *        (space) transactions, leaving per-module profit inflated after
 *        cancellations. Now mirrors report() — reclassifies 'عكس ' as
 *        revenue_reversal / cogs_reversal so the subtraction path runs.
 *
 *   H2 — resolveAmountEGP() used to fall through to $tx->amount (a
 *        foreign-currency value) for cross-currency non-EGP transfers
 *        (e.g. USD cashbox → SAR clearing), corrupting P&L totals.
 *        Now returns 0.0 for the genuine cross-currency non-EGP case,
 *        skipping the row instead of mis-pricing it.
 *
 *   H4 — applyRelevanceFilter() excluded 'writeoff' transactions after
 *        the type enum was extended by migration
 *        2026_07_09_020000_add_writeoff_to_transactions_type_enum.
 *        Approved-loss writeoffs were silently hidden from the Dashboard.
 *        Now 'writeoff' is included in the relevance set.
 *
 *   H6 — moduleAccountMaps() in LedgerClearingAccounts read only the
 *        single-currency `income`/`expense` config keys, missing the
 *        per-currency `income_per_currency`/`expense_per_currency`
 *        buckets introduced by Phase 7 (multi-currency visa/hajj).
 *        USD/SAR visa and hajj bookings posted into the per-currency
 *        clearing accounts (e.g. `إقفال إيرادات التأشيرات (USD)`) were
 *        therefore classified as null and dropped from the P&L.
 *
 * Scope: these are Dashboard-only — the underlying ledger, balances,
 * Account Statement, and ReportController paths are unaffected (they
 * read directly from account_entries / use different classification).
 * The assertions below target the ProfitLossReportService public API
 * only.
 */
class TourismDashboardCalculationFixesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected Account $usdTreasury;

    /** @var array<string, Account> */
    protected array $incomeClearings = [];

    /** @var array<string, Account> */
    protected array $expenseClearings = [];

    /** Per-currency income clearing accounts (Phase 7). */
    protected Account $visaUsdIncomeClearing;

    protected Account $hajjSarIncomeClearing;

    protected Account $officeExpenseAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Tourism Dashboard Fixes Tester',
            'email' => 'tourism-fixes@example.com',
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
            'name' => 'EGP Treasury (Fixes)',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100_000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $this->usdTreasury = Account::create([
            'name' => 'USD Treasury (Fixes)',
            'type' => 'cashbox',
            'currency' => 'USD',
            'balance' => 10_000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $clearing = app(LedgerClearingAccounts::class);
        foreach (['flight', 'hajj_umra', 'visa'] as $mod) {
            $incomeId = $clearing->incomeContraIdForModule($mod);
            $expenseId = $clearing->expenseContraIdForModule($mod);
            $this->assertNotNull($incomeId, "income clearing for $mod must exist");
            $this->assertNotNull($expenseId, "expense clearing for $mod must exist");
            $this->incomeClearings[$mod] = Account::query()->findOrFail($incomeId);
            $this->expenseClearings[$mod] = Account::query()->findOrFail($expenseId);
        }

        // Per-currency clearing accounts (Phase 7). These rows are created
        // lazily on first resolver call. The bug (H6) was that they were
        // never returned from moduleAccountMaps() — so the P&L engine
        // classified any transfer touching them as null and dropped them.
        $this->visaUsdIncomeClearing = Account::query()->findOrFail(
            $clearing->incomeContraIdForModuleAndCurrency('visa', 'USD')
        );
        $this->hajjSarIncomeClearing = Account::query()->findOrFail(
            $clearing->incomeContraIdForModuleAndCurrency('hajj_umra', 'SAR')
        );

        $this->officeExpenseAccount = Account::create([
            'name' => 'مصروف إيجار المكتب (Fixes)',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);
    }

    // ============================================================
    // H1 — moduleBreakdown() must subtract 'عكس ' (space) reversals
    // ============================================================

    /**
     * H1 — A flight booking is created, then cancelled. The cancellation
     * posts a companion reversal row with notes starting with 'عكس '
     * (space, no colon) — created by FlightBookingService::cancelBooking
     * via recordJournalTransfer. Pre-fix, moduleBreakdown() SKIPPED that
     * row (treating it like the 'عكس:' colon variant) while report()
     * correctly RECLASSIFIED it as revenue_reversal. Net effect: the
     * per-module profit card showed the original revenue forever, while
     * the aggregate card correctly subtracted it.
     *
     * Post-fix: both methods classify it identically, so the per-module
     * net is zero (matching report()) for the cancelled booking.
     */
    public function test_h1_module_breakdown_subtracts_space_prefixed_reversal(): void
    {
        // Original revenue: clearing → treasury, +10,000.
        $this->revenue($this->incomeClearings['flight'], 10_000, 'flight', 'بيع تذكرة طيران');

        // Cancellation companion row (same EGP amount, mirror legs).
        // Notes MUST start with 'عكس ' (space) — this is the production
        // pattern from FlightBookingService::cancelBooking line 2380
        // (`عكس مبيعات حجز طيران ملغي ...`).
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'flight', 'amount' => 10_000,
            'from_account_id' => $this->treasury->id,
            'to_account_id' => $this->incomeClearings['flight']->id,
            'created_by' => $this->user->id,
            'notes' => 'عكس مبيعات حجز طيران ملغي — حجز #FIX-H1',
        ]);

        $report = app(ProfitLossReportService::class)->report(['category' => 'tourism']);
        $breakdownRow = $this->byModule('flight');

        // The aggregate (report) MUST net to zero for the cancelled booking.
        $this->assertSame(0.0, $report['totalRevenues'],
            'report() must net the reversal against the original revenue');

        // The per-module breakdown MUST match the aggregate — this is the
        // invariant H1 broke.
        $this->assertSame(0.0, $breakdownRow['income'],
            'moduleBreakdown() must subtract the عكس space-prefixed reversal');
        $this->assertSame(0.0, $breakdownRow['profit'],
            'Per-module flight profit must be zero after cancellation reversal');

        // Sanity: aggregate === per-module. The whole point of the H1 fix.
        $this->assertSame(
            (float) $report['totalRevenues'],
            (float) $breakdownRow['income'],
            'report() and moduleBreakdown() must agree on per-module income'
        );
    }

    /**
     * H1 — Same invariant for COGS: a prepaid → expense_clearing cogs
     * row + a companion `عكس ` (space) reversal must net to zero in BOTH
     * methods. Pre-fix, moduleBreakdown() skipped the reversal, leaving
     * per-module COGS inflated.
     */
    public function test_h1_module_breakdown_subtracts_space_prefixed_cogs_reversal(): void
    {
        // COGS: treasury → expense_clearing, +4,000.
        $this->cogs($this->expenseClearings['visa'], 4_000, 'visa', 'تكلفة تأشيرة');

        // Cancellation companion row (mirror legs of cogs).
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'visa', 'amount' => 4_000,
            'from_account_id' => $this->expenseClearings['visa']->id,
            'to_account_id' => $this->treasury->id,
            'created_by' => $this->user->id,
            'notes' => 'عكس قيد تكلفة تأشيرة — حجز #FIX-H1-COGS',
        ]);

        $report = app(ProfitLossReportService::class)->report(['category' => 'tourism']);
        $breakdownRow = $this->byModule('visa');

        $this->assertSame(0.0, $report['totalCogs']);
        $this->assertSame(0.0, $breakdownRow['cogs'],
            'moduleBreakdown() must subtract the عكس space-prefixed cogs reversal');
        $this->assertSame(
            (float) $report['totalCogs'],
            (float) $breakdownRow['cogs'],
            'report() and moduleBreakdown() must agree on per-module cogs'
        );
    }

    /**
     * H1 — Sanity: 'عكس:' (with colon) rows are STILL skipped in BOTH
     * methods (this is the TransactionService::reverseTransaction() path
     * which modifies the same original row — must not double-count).
     * Pre-fix, moduleBreakdown() already skipped these. Post-fix, still
     * skipped. Guards against an accidental "fix" that over-corrects.
     */
    public function test_h1_colon_prefixed_reversal_remains_skipped(): void
    {
        $this->revenue($this->incomeClearings['hajj_umra'], 8_000, 'hajj_umra', 'باقة عمرة');

        // Colon-prefixed row — this is the reverseTransaction() marker.
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'hajj_umra', 'amount' => 8_000,
            'from_account_id' => $this->treasury->id,
            'to_account_id' => $this->incomeClearings['hajj_umra']->id,
            'created_by' => $this->user->id,
            'notes' => 'عكس: باقة عمرة',
        ]);

        $report = app(ProfitLossReportService::class)->report(['category' => 'tourism']);
        $breakdownRow = $this->byModule('hajj_umra');

        // The colon-prefixed row is dropped (no classification runs on it).
        // Only the original +8,000 should remain.
        $this->assertSame(8_000.0, $report['totalRevenues']);
        $this->assertSame(8_000.0, $breakdownRow['income'],
            'عكس: (colon) rows must still be skipped in moduleBreakdown()');
    }

    // ============================================================
    // H2 — resolveAmountEGP() must not mis-price cross-currency non-EGP
    // ============================================================

    /**
     * H2 — A transfer whose BOTH legs are non-EGP (e.g. USD cashbox →
     * SAR clearing) used to be summed as if it were EGP. Post-fix, the
     * resolver returns 0.0 for the genuine cross-currency non-EGP case,
     * so the outer $amount <= 0 guard skips the row. The P&L MUST NOT
     * surface this row's foreign-currency amount under an EGP label.
     *
     * Note: `from_currency` / `to_currency` / `converted_amount` live on
     * the `transfers` table (joined into the P&L query) — NOT on
     * `transactions`. Setting them on Transaction::create() is silently
     * dropped because the transactions table doesn't have those columns.
     * We insert a sibling transfers row directly so the join finds it.
     */
    public function test_h2_cross_currency_non_egp_transfer_is_not_double_priced(): void
    {
        // 1) Cross-currency non-EGP transfer: USD cashbox → SAR clearing.
        // Pre-fix, this contributed $tx->amount (5,000) under an EGP label.
        // Post-fix, resolveAmountEGP returns 0.0 → outer guard skips it.
        $crossCurrencyTx = Transaction::query()->create([
            'type' => 'transfer', 'module' => 'visa', 'amount' => 5_000,
            'from_account_id' => $this->usdTreasury->id,
            'to_account_id' => $this->incomeClearings['visa']->id,
            'created_by' => $this->user->id,
            'notes' => 'cross-currency USD→SAR test',
        ]);
        \DB::table('transfers')->insert([
            'from_account_id' => $this->usdTreasury->id,
            'to_account_id' => $this->incomeClearings['visa']->id,
            'amount' => 5_000,
            'from_currency' => 'USD',
            'to_currency' => 'SAR',
            'exchange_rate' => 3.75,
            'converted_amount' => 18_750, // 5,000 USD ≈ 18,750 SAR — different from amount
            'transaction_id' => $crossCurrencyTx->id,
            'created_by' => $this->user->id,
            'notes' => 'cross-currency transfer leg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2) Control: a normal EGP revenue must still count.
        $this->revenue($this->incomeClearings['visa'], 2_000, 'visa', 'control EGP revenue');

        $report = app(ProfitLossReportService::class)->report(['category' => 'tourism']);

        // Only the EGP control row should appear (+2,000). The USD→SAR row
        // must NOT contribute its 5,000 USD or 18,750 SAR under EGP.
        // (It also classifies as revenue_reversal due to direction, which
        // would double-bias the result if it weren't skipped by amount=0.)
        $this->assertSame(2_000.0, $report['totalRevenues'],
            'Cross-currency non-EGP row must be skipped, not mis-priced as EGP');
    }

    /**
     * H2 — Defensive same-currency case: when both legs are the same
     * currency AND amount equals converted_amount (within 0.0001), the
     * resolver returns amount. This protects intra-bucket USD movements
     * (e.g. USD visa income clearing → USD visa cashbox) from being
     * dropped by the cross-currency guard.
     *
     * Direction: revenue = clearing → cashbox (the classifier's
     * fromIncome && !toIncome arm labels it 'revenue'). The previous
     * version of this test had cashbox → clearing (which classifies as
     * revenue_reversal, hence the negative result).
     */
    public function test_h2_same_currency_transfer_uses_amount(): void
    {
        // USD visa income clearing → USD cashbox (revenue direction).
        $usdRevenueTx = Transaction::query()->create([
            'type' => 'transfer', 'module' => 'visa', 'amount' => 1_000,
            'from_account_id' => $this->visaUsdIncomeClearing->id, // clearing → cashbox
            'to_account_id' => $this->usdTreasury->id,
            'created_by' => $this->user->id,
            'notes' => 'USD visa sale same-currency',
        ]);
        \DB::table('transfers')->insert([
            'from_account_id' => $this->visaUsdIncomeClearing->id,
            'to_account_id' => $this->usdTreasury->id,
            'amount' => 1_000,
            'from_currency' => 'USD',
            'to_currency' => 'USD',
            'exchange_rate' => 1.0,
            'converted_amount' => 1_000, // same-ccy defensive: amount == converted_amount
            'transaction_id' => $usdRevenueTx->id,
            'created_by' => $this->user->id,
            'notes' => 'same-currency transfer leg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Per-currency clearing (H6) routes this row to visa; same-currency
        // defensive (H2) ensures the resolver doesn't drop it. Both must
        // hold for the breakdown to surface the USD visa revenue.
        $breakdownRow = $this->byModule('visa');

        $this->assertSame(1_000.0, $breakdownRow['income'],
            'USD same-currency visa transfer must surface as visa income');
    }

    // ============================================================
    // H4 — 'writeoff' transactions must be included in the P&L
    // ============================================================

    /**
     * H4 — Approved-loss writeoffs (transactions.type = 'writeoff') are
     * produced by legacy reconciliation scripts and the writeoff flow.
     * Pre-fix, applyRelevanceFilter() dropped them (the whereIn was
     * ['income','expense','refund'] only), so approved losses never
     * showed on the Dashboard. Post-fix, 'writeoff' is in the relevance
     * set. The classifier routes them through the
     * `if ($type === 'expense') return 'operating_expense'` branch in
     * classify(), so they surface as operating_expense.
     */
    public function test_h4_writeoff_transactions_surface_as_operating_expense(): void
    {
        // Pre-fix this row was filtered out entirely → totalExpenses = 0.
        // Post-fix it should be classified as operating_expense.
        Transaction::query()->create([
            'type' => 'writeoff', 'module' => 'flight', 'amount' => 1_500,
            'from_account_id' => $this->treasury->id,
            'to_account_id' => $this->officeExpenseAccount->id,
            'created_by' => $this->user->id,
            'notes' => 'writeoff approved loss',
        ]);

        // Sanity: a normal expense should also be counted, so we can
        // assert that the writeoff adds ON TOP of the existing flow.
        $this->operatingExpense(500, 'flight', 'regular office expense');

        $report = app(ProfitLossReportService::class)->report(['category' => 'tourism']);

        $this->assertSame(2_000.0, $report['totalExpenses'],
            'Writeoff (1500) + regular expense (500) = 2000 operating expenses');
    }

    // ============================================================
    // H6 — per-currency clearing accounts must be recognized in the P&L
    // ============================================================

    /**
     * H6 — A USD visa booking posts into the per-currency income
     * clearing account `إقفل إيرادات التأشيرات (USD)` (created lazily
     * by LedgerClearingAccounts::incomeContraIdForModuleAndCurrency).
     * Pre-fix, moduleAccountMaps() did NOT include per-currency
     * accounts, so this transaction's destination was unknown to the
     * classifier → classify() returned null → row was dropped from P&L.
     *
     * Post-fix, the per-currency clearing is in the income map, the row
     * is classified as 'revenue' (type='transfer', toIncome=true,
     * fromIncome=false), and the visa module's income picks it up.
     */
    public function test_h6_usd_visa_income_clearing_recognised(): void
    {
        // Direct revenue: USD visa clearing → USD cashbox.
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'visa', 'amount' => 3_500,
            'from_account_id' => $this->visaUsdIncomeClearing->id,
            'to_account_id' => $this->usdTreasury->id,
            'from_currency' => 'USD',
            'to_currency' => 'USD',
            'created_by' => $this->user->id,
            'notes' => 'USD visa revenue via per-currency clearing',
        ]);

        $report = app(ProfitLossReportService::class)->report(['category' => 'tourism']);
        $breakdownRow = $this->byModule('visa');

        // Aggregate and per-module must agree (the H1 invariant) AND
        // both must surface the USD revenue (the H6 invariant).
        $this->assertSame(3_500.0, $report['totalRevenues'],
            'USD visa per-currency income must contribute to tourism P&L');
        $this->assertSame(3_500.0, $breakdownRow['income'],
            'USD visa per-currency income must contribute to visa per-module');
        $this->assertSame(
            (float) $report['totalRevenues'],
            (float) $breakdownRow['income'],
            'report() and moduleBreakdown() must agree after H6 fix'
        );
    }

    /**
     * H6 — Mirror for hajj: a SAR hajj booking that posts into the
     * per-currency income clearing `إقفال إيرادات الحج والعمرة (SAR)`
     * must be recognized. Independent of the visa test to ensure both
     * per-currency maps are populated.
     */
    public function test_h6_sar_hajj_income_clearing_recognised(): void
    {
        $sarTreasury = Account::create([
            'name' => 'SAR Treasury (H6)',
            'type' => 'cashbox',
            'currency' => 'SAR',
            'balance' => 50_000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'hajj_umra', 'amount' => 7_500,
            'from_account_id' => $this->hajjSarIncomeClearing->id,
            'to_account_id' => $sarTreasury->id,
            'from_currency' => 'SAR',
            'to_currency' => 'SAR',
            'created_by' => $this->user->id,
            'notes' => 'SAR hajj revenue via per-currency clearing',
        ]);

        $report = app(ProfitLossReportService::class)->report(['category' => 'tourism']);
        $breakdownRow = $this->byModule('hajj_umra');

        $this->assertSame(7_500.0, $report['totalRevenues'],
            'SAR hajj per-currency income must contribute to tourism P&L');
        $this->assertSame(7_500.0, $breakdownRow['income'],
            'SAR hajj per-currency income must contribute to hajj per-module');
    }

    /**
     * H6 — Negative guard: the per-currency fix MUST NOT accidentally
     * pull in office-category rows. A USD revenue posted to the visa
     * per-currency clearing must still be classified as tourism (visa
     * is in TOURISM_MODULES), and the office report must remain clean.
     */
    public function test_h6_per_currency_clearing_does_not_leak_into_office(): void
    {
        Transaction::query()->create([
            'type' => 'transfer', 'module' => 'visa', 'amount' => 4_000,
            'from_account_id' => $this->visaUsdIncomeClearing->id,
            'to_account_id' => $this->usdTreasury->id,
            'created_by' => $this->user->id,
            'notes' => 'USD visa revenue',
        ]);

        $tourismReport = app(ProfitLossReportService::class)->report(['category' => 'tourism']);
        $officeReport = app(ProfitLossReportService::class)->report(['category' => 'office']);

        $this->assertSame(4_000.0, $tourismReport['totalRevenues'],
            'Per-currency visa revenue must count toward tourism');
        $this->assertSame(0.0, $officeReport['totalRevenues'],
            'Per-currency visa revenue must NOT leak into the office report');
    }

    // ============================================================
    // Helpers (mirrors PnlTourismReconciliationTest)
    // ============================================================

    /**
     * Record a "revenue" transfer: income_clearing → treasury.
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