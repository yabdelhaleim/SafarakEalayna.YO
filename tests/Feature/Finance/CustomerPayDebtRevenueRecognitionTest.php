<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Services\Reports\ProfitLossReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FIN-3 regression — CustomerController::payDebt revenue recognition.
 *
 * User-reported scenario (2026-08-29, staging):
 *
 *   A flight booking on credit produced these transactions in the period:
 *
 *     TX1   group → cost_clearing         500   (purchase cost — recognised as cogs)
 *     TX2   pending_sales → customer AR   600   (credit sale — NO cash yet → skip)
 *     TX3   cash → group                  500   (cash-out to group — neutral in P&L)
 *     TX4   customer AR → cash            600   (cash-in from customer — should be revenue)
 *
 *   Pre-fix behaviour:
 *     CustomerController::payDebt() called `recordJournalTransfer(...)` with
 *     no `type` field, defaulting to `TransactionType::Transfer`. The
 *     ProfitLossReportService::classify() engine only recognizes revenue
 *     when (a) `type === 'income'`, or (b) one leg touches income_clearing.
 *     TX4 satisfied neither rule, so the P&L silently dropped the line and
 *     reported:
 *         totalRevenues = 0
 *         totalCogs     = 500
 *         netProfit     = -500
 *     when the economically correct figures are:
 *         totalRevenues = 600
 *         totalCogs     = 500
 *         netProfit     = +100
 *
 *   Fix:
 *     CustomerController::payDebt() now calls `recordIncome(...)` with
 *     `contra_account_id = customer AR`. Internally that ends up calling
 *     `recordJournalTransfer` with `type = 'income'`, which the classifier
 *     short-circuits to 'revenue' on line 560-561.
 *
 *   These tests pin:
 *     1. The endpoint posts a Transaction with `type='income'` (not 'transfer').
 *     2. The Cashbox gains the payment amount and the customer AR drops.
 *     3. ProfitLossReportService::report() now sees the revenue.
 *     4. Net profit reflects the new revenue (revenue − cogs = profit).
 */
class CustomerPayDebtRevenueRecognitionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Account $cashbox;

    protected Customer $customer;

    protected Account $customerAR;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'FIN-3 Admin',
            'email' => 'fin3-admin-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // The admin-middleware on /customers/{customer}/pay-debt relies on the
        // Employee + role wiring; create an Employee row so the gate is happy.
        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        // Office cashbox (EGP) — destination of the customer's payment.
        // Pre-funded so the full-economic test's TX3 (cash → group, 500)
        // has enough headroom without ordering tricks.
        $this->cashbox = Account::create([
            'name' => 'FIN-3 Treasury',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 1_000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'is_module_vault' => true,
            'created_by' => $this->admin->id,
        ]);

        // Customer AR mirror — starts at 0, will be lifted to a 600 debt
        // to simulate TX2 (credit sale recorded on the books).
        $this->customer = Customer::create([
            'full_name' => 'عميل اختبار FIN-3',
            'phone' => '0111'.random_int(1000000, 9999999),
            'email' => 'fin3-cust-'.uniqid('', true).'@test.local',
            'national_id' => '29'.str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'type' => 'individual',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->customerAR = Account::create([
            'name' => 'FIN-3 Customer AR: '.$this->customer->full_name,
            'type' => AccountType::Customer->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'flights',
            'is_module_vault' => false,
            'created_by' => $this->admin->id,
        ]);

        $this->customer->update(['account_id' => $this->customerAR->id]);
    }

    /**
     * Helper — set the customer's AR balance to the desired amount by
     * issuing a single `recordJournalTransfer` that mirrors TX2 (credit
     * sale). Returns the booking-sale transaction row.
     *
     * Important: this helper uses `type=Transfer` deliberately so it does
     * NOT inflate P&L revenue — only the pay-debt endpoint under test
     * should.
     */
    protected function seedCustomerDebt(float $amount): Transaction
    {
        // Create a simple contra account that is NOT income_clearing — the
        // engine should classify this transfer as null (P&L-neutral).
        // Mirrors the project's own `pending_sales_receivable` account shape
        // (AccountType::Owner — internal system account).
        $placeholder = Account::create([
            'name' => 'FIN-3 Pending Sales Receivable',
            'type' => AccountType::Owner->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'flights',
            'created_by' => $this->admin->id,
        ]);

        return app(TransactionService::class)->recordJournalTransfer([
            'amount' => $amount,
            'from_account_id' => $placeholder->id,
            'to_account_id' => $this->customerAR->id,
            'currency' => 'EGP',
            'module' => 'flight',
            'type' => 'transfer',
            'notes' => 'TX2 (sim) — credit sale on the books (no cash)',
            'created_by' => $this->admin->id,
        ]);
    }

    protected function snapshotPnl(string $category = 'tourism'): array
    {
        $report = app(ProfitLossReportService::class)->report(['category' => $category]);

        return [
            'totalRevenues' => (float) $report['totalRevenues'],
            'totalCogs' => (float) $report['totalCogs'],
            'totalExpenses' => (float) ($report['totalExpenses'] ?? 0.0),
            'netProfit' => (float) $report['netProfit'],
        ];
    }

    /**
     * R1 — Pay-debt posts a Transaction with `type='income'`.
     *
     * Pinning the engine contract: the classifier short-circuits on
     * `type === 'income'` (ProfitLossReportService line 560-561). If a
     * future refactor of CustomerController::payDebt() ever reverts to
     * `recordJournalTransfer` without `type`, this assertion fails.
     */
    public function test_pay_debt_records_income_typed_transaction(): void
    {
        $this->seedCustomerDebt(600.0);

        $beforeCashbox = (float) $this->cashbox->fresh()->balance;
        $beforeAr = (float) $this->customerAR->fresh()->balance;

        $response = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 600,
            'account_id' => $this->cashbox->id,
            'notes' => 'TX4 (sim) — تسديد مديونية العميل',
            'type' => 'receipt',
            'module' => 'flight',
        ]);

        $response->assertOk();

        $txId = $response->json('data.transaction_id');
        $this->assertNotNull($txId, 'pay-debt must return a transaction id.');

        $tx = Transaction::query()->findOrFail($txId);

        $typeValue = $tx->type instanceof \BackedEnum ? $tx->type->value : (string) $tx->type;

        $this->assertSame(
            'income',
            strtolower((string) $typeValue),
            'FIN-3 fix: pay-debt must post type=income so the classifier short-circuits to revenue.'
        );

        $freshType = $tx->fresh()->type;
        $freshTypeValue = $freshType instanceof \BackedEnum ? $freshType->value : (string) $freshType;

        $this->assertSame(
            'income',
            strtolower((string) $freshTypeValue),
            'Transaction::type must persist as income on reload.'
        );

        $afterCashbox = (float) $this->cashbox->fresh()->balance;
        $afterAr = (float) $this->customerAR->fresh()->balance;

        $this->assertSame(600.0, round($afterCashbox - $beforeCashbox, 2),
            'Cashbox must gain the full payment amount.');
        $this->assertSame(-600.0, round($afterAr - $beforeAr, 2),
            'Customer AR must drop by the full payment amount.');
    }

    /**
     * R2 — Pay-debt revenue shows up in ProfitLossReportService totals.
     *
     * End-to-end pinning of the user's reported symptom: a 600 EGP
     * customer debt settlement must surface as 600 EGP revenue in the
     * tourism P&L report (NOT zero, which was the pre-fix bug).
     */
    public function test_pay_debt_revenue_appears_in_pnl_report(): void
    {
        $this->seedCustomerDebt(600.0);

        // Sanity: BEFORE the payment, the credit-sale-only journal is
        // P&L-neutral (neither income_clearing nor fetchable as revenue).
        $pnlBefore = $this->snapshotPnl('tourism');
        $this->assertSame(0.0, $pnlBefore['totalRevenues'],
            'Sanity: a TX2-style transfer alone must NOT inflate revenue (no income_clearing leg).');
        $this->assertSame(0.0, $pnlBefore['netProfit'],
            'Sanity: net profit must be 0 before pay-debt runs.');

        // Now exercise the FIN-3 fix.
        $response = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 600,
            'account_id' => $this->cashbox->id,
            'notes' => 'TX4 (sim) — تسديد مديونية العميل',
            'type' => 'receipt',
            'module' => 'flight',
        ]);

        $response->assertOk();

        $pnlAfter = $this->snapshotPnl('tourism');

        $this->assertSame(600.0, $pnlAfter['totalRevenues'],
            'FIN-3 fix: pay-debt must contribute +600 to totalRevenues (was 0 pre-fix).');
        $this->assertSame(0.0, $pnlAfter['totalCogs'],
            'pay-debt does not move cost_clearing; cogs stays at 0.');
        $this->assertSame(600.0, $pnlAfter['netProfit'],
            'FIN-3 fix: netProfit must equal totalRevenues - totalCogs - totalExpenses = 600.');
    }

    /**
     * R3 — Economic reconciliation over the user's exact 4-TX scenario.
     *
     * Simulates the staging scenario end-to-end:
     *   TX1   group → cost_clearing  500  (cogs)
     *   TX2   pending → customer AR  600  (credit sale, no cash)
     *   TX3   cash → group           500  (cash-out — neutral)
     *   TX4   customer AR → cash     600  (cash-in — revenue)
     *
     * Expected final P&L (cash-basis):
     *   totalRevenues = 600
     *   totalCogs     = 500
     *   netProfit     = +100
     */
    public function test_full_economic_reconciliation_user_scenario(): void
    {
        // Build the same fixture: cost-clearing account + group account for
        // the cogs side, and a placeholder for the credit-sale side. The
        // system also auto-creates income_clearing / expense_clearing on
        // first use, which is what we want the engine to recognise.
        $clearing = app(\App\Services\Finance\LedgerClearingAccounts::class);
        $costClearingId = $clearing->expenseContraIdForModuleAndCurrency('flight', 'EGP');
        $this->assertNotNull($costClearingId, 'cost_clearing for flight/EGP must exist');
        $costClearing = Account::query()->findOrFail($costClearingId);

        $group = Account::create([
            'name' => 'FIN-3 مجموعة طيران (مجموعة اختبار)',
            'type' => AccountType::Supplier->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'flights',
            'created_by' => $this->admin->id,
        ]);

        $pendingPlaceholder = Account::create([
            'name' => 'FIN-3 Pending Sales Receivable',
            'type' => AccountType::Owner->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'flights',
            'created_by' => $this->admin->id,
        ]);

        $txService = app(TransactionService::class);

        // TX1 — group → cost_clearing  (500)  // cogs
        // NOTE: type='transfer' (NOT 'expense') is intentional. The engine's
        // classify() short-circuits type='expense' → 'operating_expense'
        // (line 568-570), but the user-described scenario expects COGS.
        // Going supplier → expense_clearing with type='transfer' triggers the
        // `toExpense && !fromExpense && !fromPrepaid` branch (line 605-607)
        // which returns 'cogs'. This matches how the live system records
        // a 500 EGP carrier purchase against the unpaid cost line.
        $txService->recordJournalTransfer([
            'amount' => 500,
            'from_account_id' => $group->id,
            'to_account_id' => $costClearing->id,
            'currency' => 'EGP',
            'module' => 'flight',
            'type' => 'transfer',
            'notes' => 'TX1 (sim) — تكلفة شراء بالأجل',
            'created_by' => $this->admin->id,
        ]);

        // TX2 — pending_sales → customer AR  (600)  // credit sale, no cash
        $txService->recordJournalTransfer([
            'amount' => 600,
            'from_account_id' => $pendingPlaceholder->id,
            'to_account_id' => $this->customerAR->id,
            'currency' => 'EGP',
            'module' => 'flight',
            'type' => 'transfer',
            'notes' => 'TX2 (sim) — حجز طيران (بيع بالأجل)',
            'created_by' => $this->admin->id,
        ]);

        // TX3 — cash → group  (500)  // cash-out to group; SKIP in P&L
        $txService->recordJournalTransfer([
            'amount' => 500,
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $group->id,
            'currency' => 'EGP',
            'module' => 'flight',
            'type' => 'transfer',
            'notes' => 'TX3 (sim) — سند صرف — دفع لمجموعة طيران',
            'created_by' => $this->admin->id,
        ]);

        // ── Sanity gate (must match the user's TX1-TX3 baseline) ──
        $pnlMid = $this->snapshotPnl('tourism');
        $this->assertSame(500.0, $pnlMid['totalCogs'],
            'After TX1: cogs must be 500 (cost_clearing recognised).');
        $this->assertSame(0.0, $pnlMid['totalRevenues'],
            'After TX1+TX2+TX3 (no pay-debt yet): revenue must still be 0.');

        // TX4 — the actual fix under test:
        //     customer AR → cash  (600)  via /customers/{id}/pay-debt
        $response = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 600,
            'account_id' => $this->cashbox->id,
            'notes' => 'TX4 (sim) — سند قبض — تسديد مديونية',
            'type' => 'receipt',
            'module' => 'flight',
        ]);
        $response->assertOk();

        // ── Final reconciliation (the headline of the user report) ──
        $pnlFinal = $this->snapshotPnl('tourism');

        $this->assertSame(600.0, $pnlFinal['totalRevenues'],
            'FIN-3 headline: totalRevenues must be 600 after pay-debt (was 0 pre-fix).');
        $this->assertSame(500.0, $pnlFinal['totalCogs'],
            'FIN-3 headline: totalCogs must remain 500.');
        $this->assertSame(100.0, $pnlFinal['netProfit'],
            'FIN-3 headline: netProfit must equal +100 EGP (600 revenue − 500 cogs).');
    }

    /**
     * R4 — Multiple payDebt calls on the same customer must succeed.
     *
     * Verify that a customer can pay their debt in multiple payments/installments
     * without triggering the "Duplicate income transaction blocked" guard.
     */
    public function test_multiple_pay_debt_calls_on_same_customer_are_not_blocked(): void
    {
        $this->seedCustomerDebt(1000.0);

        // First payment: 400 EGP
        $response1 = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 400,
            'account_id' => $this->cashbox->id,
            'notes' => 'الدفعة الأولى من تسديد المديونية',
            'type' => 'receipt',
            'module' => 'flight',
        ]);
        $response1->assertOk();

        // Second payment: 600 EGP
        $response2 = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 600,
            'account_id' => $this->cashbox->id,
            'notes' => 'الدفعة الثانية من تسديد المديونية',
            'type' => 'receipt',
            'module' => 'flight',
        ]);
        $response2->assertOk();

        $this->assertSame(
            0.0,
            (float) $this->customerAR->fresh()->balance,
            'Customer AR balance must be fully paid (0.0).'
        );
    }
}
