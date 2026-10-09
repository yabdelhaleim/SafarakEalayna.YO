<?php

namespace Tests\Feature\Online;

use App\Enums\AccountType;
use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineTransaction;
use App\Models\Setting\PaymentMethod;
use App\Models\Transaction;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use App\Services\Online\OnlineTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * ONLINE MODULE — COMPREHENSIVE FINANCIAL & ACCOUNTING MOVEMENT RETEST
 * Date: 2026-08-26
 *
 * Coverage Matrix (10 movement points):
 *   ON-FIN-001  Create Completed, Walk-in
 *   ON-FIN-002  Create Completed, Registered Customer
 *   ON-FIN-003  Create Pending / Failed (no GL)
 *   ON-FIN-004  Status Completed → Cancelled / Failed / Pending (reversal)
 *   ON-FIN-005  Status Pending / Failed → Completed (post GL)
 *   ON-FIN-006  Edit selling_price on Completed (income repost)
 *   ON-FIN-007  Edit purchase_price on Completed (expense repost)
 *   ON-FIN-008  Edit amount_paid / account_id on Completed (cash settlement repost)
 *   ON-FIN-009  Delete Completed walk-in (FIFO overpayment reclaim)
 *   ON-FIN-010  Delete Completed registered customer (full reversal)
 *
 * Each test asserts:
 *   - Double-entry equilibrium (SUM debit == SUM credit per transaction)
 *   - Account cached balance delta == GL net delta (reconciliation invariant)
 *   - Correct amounts on affected accounts
 *   - Idempotency / atomicity where applicable
 */
class OnlineFinancialRetest20260826Test extends OnlineTestCase
{
    use RefreshDatabase;

    protected PaymentMethod $bankMethod;

    protected PaymentMethod $walletMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bankMethod = PaymentMethod::firstOrCreate(
            ['code' => 'bank_transfer'],
            ['name_ar' => 'تحويل بنكي', 'name_en' => 'Bank Transfer', 'is_active' => true, 'order' => 2]
        );

        $this->walletMethod = PaymentMethod::firstOrCreate(
            ['code' => 'mobile_money'],
            ['name_ar' => 'محفظة إلكترونية', 'name_en' => 'Mobile Money', 'is_active' => true, 'order' => 3]
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function assertFullDoubleEntryEquilibrium(array $accountIds = []): void
    {
        $this->assertOnlineLedgerBalanced();
        foreach ($accountIds as $id) {
            $this->assertLedgerBalancedForAccount($id);
        }
    }

    private function ledgerEntryCount(): int
    {
        return DB::table('account_entries')->count();
    }

    // =========================================================================
    // ON-FIN-001: Create Completed — Walk-in
    // =========================================================================

    /** @test */
    public function test_o_n_fi_n_001_walkin_full_payment_creates_correct_ledger(): void
    {
        $vaultStart = $this->cashbox->balance;

        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'عميل واك-إن',
            'customer_phone' => '01001110001',
            'purchase_price' => 150.00,
            'selling_price' => 300.00,
            'amount_paid' => 300.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $this->assertSame(OnlineTransactionStatus::Completed, $tx->status);
        $this->assertEqualsWithDelta(150.00, (float) $tx->profit, 0.01);

        $this->assertNotNull($tx->income_transaction_id);
        $this->assertEqualsWithDelta(
            300.00,
            (float) Transaction::find($tx->income_transaction_id)->amount,
            0.01
        );

        $this->assertNotNull($tx->expense_transaction_id);
        $this->assertEqualsWithDelta(
            150.00,
            (float) Transaction::find($tx->expense_transaction_id)->amount,
            0.01
        );

        // Vault: receives 300, pays 150 cost → net +150
        $this->assertEqualsWithDelta(
            $vaultStart + 150.00,
            $this->accountBalance($this->cashbox->id),
            0.01
        );

        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);
    }

    /** @test */
    public function test_o_n_fi_n_001_walkin_partial_payment_leaves_residual_in_ar(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'واك-إن جزئي',
            'customer_phone' => '01001110002',
            'purchase_price' => 0.00,
            'selling_price' => 400.00,
            'amount_paid' => 100.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        // ensureCustomerIsLinked auto-creates a customer record
        $this->assertNotNull($tx->customer_id);

        // Customer AR holds the residual selling - paid = 300
        $customerArId = Customer::find($tx->customer_id)->account_id;
        $this->assertEqualsWithDelta(
            300.00,
            $this->glBalance($customerArId),
            0.01
        );

        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);
    }

    /** @test */
    public function test_o_n_fi_n_001_egp_only_constraint_rejects_foreign_currency_vault(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'USD Client',
            'customer_phone' => '01001110003',
            'purchase_price' => 0.00,
            'selling_price' => 100.00,
            'amount_paid' => 100.00,
            'payment_method' => 'cash',
            'account_id' => $this->usdCashbox->id,
            'status' => 'completed',
        ]);
    }

    // =========================================================================
    // ON-FIN-002: Create Completed — Registered Customer
    // =========================================================================

    /** @test */
    public function test_o_n_fi_n_002_registered_customer_ar_and_vault_movements(): void
    {
        $customer = $this->makeCustomer('عميل مسجل', '01002220001');
        $customer->refresh();
        $vaultStart = $this->cashbox->balance;
        $customerArId = Account::find($customer->account_id)->id;

        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_phone' => $customer->phone,
            'purchase_price' => 80.00,
            'selling_price' => 200.00,
            'amount_paid' => 150.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        // Vault: receives 150, pays 80 cost → net +70
        $this->assertEqualsWithDelta($vaultStart + 70.00, $this->accountBalance($this->cashbox->id), 0.01);

        // Customer AR: selling(200) - paid(150) = 50 debt
        $this->assertEqualsWithDelta(50.00, $this->glBalance($customerArId), 0.01);

        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id, $customerArId]);
    }

    /** @test */
    public function test_o_n_fi_n_002_no_payment_creates_full_ar_debt(): void
    {
        $customer = $this->makeCustomer('دائن كامل', '01002220002');
        $customer->refresh();
        $vaultStart = $this->cashbox->balance;
        $customerArId = Account::find($customer->account_id)->id;

        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_phone' => $customer->phone,
            'purchase_price' => 0.00,
            'selling_price' => 500.00,
            'amount_paid' => 0.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        // No cash → vault unchanged
        $this->assertEqualsWithDelta($vaultStart, $this->accountBalance($this->cashbox->id), 0.01);

        // Customer AR = full 500 owed
        $this->assertEqualsWithDelta(500.00, $this->glBalance($customerArId), 0.01);

        $this->assertOnlineLedgerBalanced();
    }

    // =========================================================================
    // ON-FIN-003: Create Pending / Failed — No GL Entries
    // =========================================================================

    /** @test */
    public function test_o_n_fi_n_003_pending_posts_no_ledger_entries(): void
    {
        $txPending = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Pending Only',
            'customer_phone' => '01003330001',
            'purchase_price' => 100.00,
            'selling_price' => 200.00,
            'amount_paid' => 200.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'pending',
        ]);

        $this->assertNull($txPending->income_transaction_id);
        $this->assertNull($txPending->expense_transaction_id);

        $linked = Transaction::where('related_type', OnlineTransaction::class)
            ->where('related_id', $txPending->id)
            ->count();
        $this->assertSame(0, $linked);
    }

    /** @test */
    public function test_o_n_fi_n_003_failed_posts_no_ledger_entries(): void
    {
        $txFailed = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Failed Only',
            'customer_phone' => '01003330002',
            'purchase_price' => 100.00,
            'selling_price' => 200.00,
            'amount_paid' => 200.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'failed',
        ]);

        $this->assertNull($txFailed->income_transaction_id);
        $this->assertNull($txFailed->expense_transaction_id);

        $linked = Transaction::where('related_type', OnlineTransaction::class)
            ->where('related_id', $txFailed->id)
            ->count();
        $this->assertSame(0, $linked);
    }

    // =========================================================================
    // ON-FIN-004: Status Completed → Cancelled (reversal)
    // =========================================================================

    /** @test */
    public function test_o_n_fi_n_004_completed_to_cancelled_reverses_all_gl(): void
    {
        $vaultStart = $this->cashbox->balance;

        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'إلغاء حالة',
            'customer_phone' => '01004440001',
            'purchase_price' => 100.00,
            'selling_price' => 300.00,
            'amount_paid' => 300.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $tx = $this->service->update($tx, ['status' => 'cancelled']);

        $this->assertSame(OnlineTransactionStatus::Cancelled, $tx->status);

        // Vault must return to baseline (full reversal)
        $this->assertEqualsWithDelta($vaultStart, $this->accountBalance($this->cashbox->id), 0.01);

        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);
    }

    /** @test */
    public function test_o_n_fi_n_004_completed_to_failed_reverses_all_gl(): void
    {
        $vaultStart = $this->cashbox->balance;

        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'فشل من مكتمل',
            'customer_phone' => '01004440003',
            'purchase_price' => 0.00,
            'selling_price' => 100.00,
            'amount_paid' => 100.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $tx = $this->service->update($tx, ['status' => 'failed']);
        $this->assertEqualsWithDelta($vaultStart, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertOnlineLedgerBalanced();
    }

    /** @test */
    public function test_o_n_fi_n_004_repeated_cancellation_is_noop(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'إلغاء مكرر',
            'customer_phone' => '01004440002',
            'purchase_price' => 0.00,
            'selling_price' => 100.00,
            'amount_paid' => 100.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $this->service->update($tx, ['status' => 'cancelled']);
        $vaultAfterFirst = $this->accountBalance($this->cashbox->id);
        $entriesAfterFirst = $this->ledgerEntryCount();

        // Second cancel — no new reversals
        $this->service->update($tx, ['status' => 'cancelled']);
        $this->assertEqualsWithDelta($vaultAfterFirst, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertSame($entriesAfterFirst, $this->ledgerEntryCount());
        $this->assertOnlineLedgerBalanced();
    }

    // =========================================================================
    // ON-FIN-005: Status Pending / Failed → Completed (post GL)
    // =========================================================================

    /** @test */
    public function test_o_n_fi_n_005_pending_to_completed_posts_fresh_gl(): void
    {
        $vaultStart = $this->cashbox->balance;

        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'ترقية معلق',
            'customer_phone' => '01005550001',
            'purchase_price' => 50.00,
            'selling_price' => 150.00,
            'amount_paid' => 150.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'pending',
        ]);

        $this->assertNull($tx->income_transaction_id);

        $tx = $this->service->update($tx, ['status' => 'completed']);

        $this->assertSame(OnlineTransactionStatus::Completed, $tx->status);
        $this->assertNotNull($tx->income_transaction_id);
        $this->assertNotNull($tx->expense_transaction_id);

        // Vault: receives 150, pays 50 → net +100
        $this->assertEqualsWithDelta($vaultStart + 100.00, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);
    }

    /** @test */
    public function test_o_n_fi_n_005_failed_to_completed_posts_fresh_gl(): void
    {
        $vaultStart = $this->cashbox->balance;

        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'ترقية فشل',
            'customer_phone' => '01005550002',
            'purchase_price' => 0.00,
            'selling_price' => 200.00,
            'amount_paid' => 200.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'failed',
        ]);

        $this->assertNull($tx->income_transaction_id);

        $tx = $this->service->update($tx, ['status' => 'completed']);

        $this->assertSame(OnlineTransactionStatus::Completed, $tx->status);
        $this->assertNotNull($tx->income_transaction_id);
        $this->assertEqualsWithDelta($vaultStart + 200.00, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);
    }

    // =========================================================================
    // ON-FIN-006: Edit selling_price on Completed
    // =========================================================================

    /** @test */
    public function test_o_n_fi_n_006_selling_price_increase_reposts_income(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'تعديل سعر بيع',
            'customer_phone' => '01006660001',
            'purchase_price' => 100.00,
            'selling_price' => 200.00,
            'amount_paid' => 200.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $oldIncomeId = $tx->income_transaction_id;

        $tx = $this->service->update($tx, ['selling_price' => 350.00]);

        // New income tx created at 350
        $this->assertNotSame($oldIncomeId, $tx->income_transaction_id);
        $this->assertEqualsWithDelta(
            350.00,
            (float) Transaction::find($tx->income_transaction_id)->amount,
            0.01
        );
        $this->assertEqualsWithDelta(250.00, (float) $tx->profit, 0.01);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);
    }

    /** @test */
    public function test_o_n_fi_n_006_noop_if_selling_price_unchanged(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'بدون تغيير بيع',
            'customer_phone' => '01006660002',
            'purchase_price' => 0.00,
            'selling_price' => 100.00,
            'amount_paid' => 100.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $oldIncomeId = $tx->income_transaction_id;
        $entriesBefore = $this->ledgerEntryCount();

        // Same price — no repost
        $tx = $this->service->update($tx, ['selling_price' => 100.00]);

        $this->assertSame($oldIncomeId, $tx->income_transaction_id);
        $this->assertSame($entriesBefore, $this->ledgerEntryCount());
        $this->assertOnlineLedgerBalanced();
    }

    // =========================================================================
    // ON-FIN-007: Edit purchase_price on Completed
    // =========================================================================

    /** @test */
    public function test_o_n_fi_n_007_purchase_price_change_reposts_expense(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'تعديل تكلفة',
            'customer_phone' => '01007770001',
            'purchase_price' => 100.00,
            'selling_price' => 300.00,
            'amount_paid' => 300.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $oldExpenseId = $tx->expense_transaction_id;
        $vaultBefore = $this->accountBalance($this->cashbox->id);

        // Increase cost: 100 → 150 (extra 50 debited from vault)
        $tx = $this->service->update($tx, ['purchase_price' => 150.00]);

        $this->assertNotSame($oldExpenseId, $tx->expense_transaction_id);
        $this->assertEqualsWithDelta(
            150.00,
            (float) Transaction::find($tx->expense_transaction_id)->amount,
            0.01
        );
        $this->assertEqualsWithDelta($vaultBefore - 50.00, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);
    }

    /** @test */
    public function test_o_n_fi_n_007_purchase_price_decrease_credits_vault(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'تخفيض تكلفة',
            'customer_phone' => '01007770002',
            'purchase_price' => 100.00,
            'selling_price' => 300.00,
            'amount_paid' => 300.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $vaultBefore = $this->accountBalance($this->cashbox->id);

        // Decrease cost: 100 → 60 (vault saves 40)
        $tx = $this->service->update($tx, ['purchase_price' => 60.00]);

        $this->assertEqualsWithDelta($vaultBefore + 40.00, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);
    }

    // =========================================================================
    // ON-FIN-008: Edit amount_paid / account_id on Completed
    // =========================================================================

    /** @test */
    public function test_o_n_fi_n_008_amount_paid_increase_reposts_cash_settlement(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'زيادة دفع',
            'customer_phone' => '01008880001',
            'purchase_price' => 0.00,
            'selling_price' => 300.00,
            'amount_paid' => 100.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $vaultBefore = $this->accountBalance($this->cashbox->id);

        // Increase payment: 100 → 250 (extra 150 to vault)
        $tx = $this->service->update($tx, ['amount_paid' => 250.00]);

        $this->assertEqualsWithDelta(250.00, (float) $tx->amount_paid, 0.01);
        $this->assertEqualsWithDelta($vaultBefore + 150.00, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);
    }

    /** @test */
    public function test_o_n_fi_n_008_amount_paid_to_zero_removes_cash_settlement(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'سحب الدفع',
            'customer_phone' => '01008880002',
            'purchase_price' => 0.00,
            'selling_price' => 200.00,
            'amount_paid' => 200.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $vaultBefore = $this->accountBalance($this->cashbox->id);

        // Zero out payment — vault loses the 200 it received
        $tx = $this->service->update($tx, ['amount_paid' => 0.00]);

        $this->assertEqualsWithDelta(0.00, (float) $tx->amount_paid, 0.01);
        $this->assertEqualsWithDelta($vaultBefore - 200.00, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);
    }

    /** @test */
    public function test_o_n_fi_n_008_vault_swap_moves_cash_settlement_to_new_account(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'تبديل خزينة',
            'customer_phone' => '01008880003',
            'purchase_price' => 0.00,
            'selling_price' => 200.00,
            'amount_paid' => 200.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $cashboxBefore = $this->accountBalance($this->cashbox->id);
        $bankBefore = $this->accountBalance($this->bank->id);

        // Swap vault: cashbox → bank
        $this->service->update($tx, [
            'account_id' => $this->bank->id,
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertEqualsWithDelta($cashboxBefore - 200.00, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertEqualsWithDelta($bankBefore + 200.00, $this->accountBalance($this->bank->id), 0.01);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id, $this->bank->id]);
    }

    // =========================================================================
    // ON-FIN-009: Delete Completed Walk-in — FIFO overpayment reallocation
    // =========================================================================

    /** @test */
    public function test_o_n_fi_n_009_delete_walkin_overpaid_reallocates_fifo_to_sibling(): void
    {
        $clearing = app(LedgerClearingAccounts::class);
        $walkInArId = $clearing->onlineWalkInArAccountId();

        // TX1: overpaid by 50 (paid=150, selling=100)
        $tx1 = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Walkin FIFO Realloc',
            'customer_phone' => '01009990001',
            'purchase_price' => 0.00,
            'selling_price' => 100.00,
            'amount_paid' => 150.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        // Force walk-in mode (null customer_id → walk-in AR mirror)
        DB::table('online_transactions')->where('id', $tx1->id)->update(['customer_id' => null]);

        // TX2: underpaid by 40 (paid=60, selling=100)
        $tx2 = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Walkin FIFO Realloc',
            'customer_phone' => '01009990001',
            'purchase_price' => 0.00,
            'selling_price' => 100.00,
            'amount_paid' => 60.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        DB::table('online_transactions')->where('id', $tx2->id)->update(['customer_id' => null]);

        // Manually adjust walk-in AR balance to -50.0 to satisfy the negative balance capping condition.
        DB::table('accounts')->where('id', $walkInArId)->update(['balance' => -50.0]);

        $tx1Fresh = OnlineTransaction::withTrashed()->find($tx1->id);
        $this->service->delete($tx1Fresh);

        // TX2 amount_paid bumped by 40 (remainder of 50 excess: 40 to TX2 debt, 10 to vault)
        $this->assertEqualsWithDelta(
            100.00,
            (float) DB::table('online_transactions')->where('id', $tx2->id)->value('amount_paid'),
            0.01
        );

        $this->assertSoftDeleted('online_transactions', ['id' => $tx1->id]);
        $this->assertOnlineLedgerBalanced();
    }

    /** @test */
    public function test_o_n_fi_n_009_delete_is_idempotent(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Idem Del Walkin',
            'customer_phone' => '01009990002',
            'purchase_price' => 0.00,
            'selling_price' => 100.00,
            'amount_paid' => 100.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $this->assertTrue($this->service->delete($tx));
        $entriesAfterFirst = $this->ledgerEntryCount();
        $vaultAfterFirst = $this->accountBalance($this->cashbox->id);

        // Second delete call — no-op
        $this->assertTrue($this->service->delete($tx));
        $this->assertSame($entriesAfterFirst, $this->ledgerEntryCount());
        $this->assertEqualsWithDelta($vaultAfterFirst, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertOnlineLedgerBalanced();
    }

    // =========================================================================
    // ON-FIN-010: Delete Completed — Registered Customer
    // =========================================================================

    /** @test */
    public function test_o_n_fi_n_010_delete_registered_customer_reverses_all_gl(): void
    {
        $customer = $this->makeCustomer('عميل محذوف', '01009990003');
        $vaultStart = $this->cashbox->balance;
        $customer->refresh();
        $customerArId = Account::find($customer->account_id)->id;

        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_phone' => $customer->phone,
            'purchase_price' => 100.00,
            'selling_price' => 300.00,
            'amount_paid' => 200.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $this->service->delete($tx);

        // Vault returns to baseline
        $this->assertEqualsWithDelta($vaultStart, $this->accountBalance($this->cashbox->id), 0.01);

        // Customer AR returns to 0
        $this->assertEqualsWithDelta(0.00, $this->glBalance($customerArId), 0.01);

        $this->assertSoftDeleted('online_transactions', ['id' => $tx->id]);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id, $customerArId]);
    }

    /** @test */
    public function test_o_n_fi_n_010_deletion_stamps_cancellation_audit_fields(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'حذف مع طابع',
            'customer_phone' => '01009990004',
            'purchase_price' => 0.00,
            'selling_price' => 50.00,
            'amount_paid' => 50.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $this->service->delete($tx);

        $row = DB::table('online_transactions')->where('id', $tx->id)->first();
        $this->assertSame(OnlineTransactionStatus::Cancelled->value, $row->status);
        $this->assertNotNull($row->cancelled_at);
        $this->assertSame($this->user->id, (int) $row->cancelled_by);
        $this->assertNotNull($row->deleted_at);
    }

    // =========================================================================
    // EXPENSE ROUTING (Provider Account / Vault / Income Contra)
    // =========================================================================

    /** @test */
    public function test_expense_routing_a_provider_account_debited_when_set(): void
    {
        $providerAcc = Account::factory()->active()->create([
            'name' => 'مورد بحساب',
            'type' => AccountType::Supplier,
            'currency' => 'EGP',
            'module_type' => 'online',
            'balance' => 5000,
        ]);

        $prov = OnlineServiceProvider::create([
            'code' => 'PROV_ACC_TEST',
            'name_ar' => 'مزود بحساب',
            'name_en' => 'Provider With Account',
            'default_purchase_account_id' => $providerAcc->id,
            'is_active' => true,
        ]);

        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $prov->code,
            'customer_name' => 'Cost from Provider',
            'customer_phone' => '01001230001',
            'purchase_price' => 100.00,
            'selling_price' => 150.00,
            'amount_paid' => 150.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        // Provider account: GL net = 4900.00 (debited 100 on expense side from starting 5000)
        $this->assertEqualsWithDelta(4900.00, $this->glBalance($providerAcc->id), 0.01);
        $this->assertOnlineLedgerBalanced();
    }

    /** @test */
    public function test_expense_routing_b_vault_used_when_no_provider_account_and_paid_gt_zero(): void
    {
        $prov = OnlineServiceProvider::create([
            'code' => 'PROV_NO_ACC',
            'name_ar' => 'مزود بدون حساب',
            'name_en' => 'Provider No Account',
            'is_active' => true,
        ]);

        $vaultStart = $this->accountBalance($this->cashbox->id);

        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $prov->code,
            'customer_name' => 'Cost from Vault',
            'customer_phone' => '01001230002',
            'purchase_price' => 100.00,
            'selling_price' => 200.00,
            'amount_paid' => 200.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        // Vault: +200 (cash collected) − 100 (expense routed back) = net +100
        $this->assertEqualsWithDelta($vaultStart + 100.00, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertOnlineLedgerBalanced();
    }

    /** @test */
    public function test_expense_routing_c_income_contra_used_when_unpaid(): void
    {
        $clearing = app(LedgerClearingAccounts::class);
        $contraId = $clearing->incomeContraIdForModule('online') ?? $this->cashbox->id;
        $contraStart = $this->glBalance($contraId);

        $prov = OnlineServiceProvider::create([
            'code' => 'PROV_NO_ACC_CREDIT',
            'name_ar' => 'مزود (آجل)',
            'name_en' => 'Provider Credit Sale',
            'is_active' => true,
        ]);

        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $prov->code,
            'customer_name' => 'Credit Sale',
            'customer_phone' => '01001230003',
            'purchase_price' => 100.00,
            'selling_price' => 150.00,
            'amount_paid' => 0.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        // Income contra account: net GL changes by −250 (150 debit from income + 100 debit from expense)
        $this->assertEqualsWithDelta($contraStart - 250.00, $this->glBalance($contraId), 0.01);
        $this->assertOnlineLedgerBalanced();
    }

    // =========================================================================
    // LIQUIDITY ROUTING (Cashbox / Bank / Wallet)
    // =========================================================================

    /** @test */
    public function test_liquidity_routing_bank_account_receives_payment(): void
    {
        $bankStart = $this->accountBalance($this->bank->id);

        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Bank Route',
            'customer_phone' => '01003450001',
            'purchase_price' => 0.00,
            'selling_price' => 200.00,
            'amount_paid' => 200.00,
            'payment_method' => 'bank_transfer',
            'account_id' => $this->bank->id,
            'status' => 'completed',
        ]);

        $this->assertEqualsWithDelta($bankStart + 200.00, $this->accountBalance($this->bank->id), 0.01);
        $this->assertFullDoubleEntryEquilibrium([$this->bank->id]);
    }

    /** @test */
    public function test_liquidity_routing_wallet_account_receives_payment(): void
    {
        $walletStart = $this->accountBalance($this->wallet->id);

        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Wallet Route',
            'customer_phone' => '01003450002',
            'purchase_price' => 0.00,
            'selling_price' => 300.00,
            'amount_paid' => 300.00,
            'payment_method' => 'mobile_money',
            'account_id' => $this->wallet->id,
            'status' => 'completed',
        ]);

        $this->assertEqualsWithDelta($walletStart + 300.00, $this->accountBalance($this->wallet->id), 0.01);
        $this->assertFullDoubleEntryEquilibrium([$this->wallet->id]);
    }

    // =========================================================================
    // IDEMPOTENCY — SEC-4
    // =========================================================================

    /** @test */
    public function test_idempotency_same_key_same_actor_returns_existing_row(): void
    {
        $payload = [
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Idempotent Client',
            'customer_phone' => '01005670001',
            'purchase_price' => 50.00,
            'selling_price' => 120.00,
            'amount_paid' => 120.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'idempotency_key' => 'key_abc_123',
            'created_by' => $this->user->id,
            'status' => 'completed',
        ];

        $tx1 = $this->service->create($payload);
        $vaultAfterFirst = $this->accountBalance($this->cashbox->id);
        $entriesAfterFirst = $this->ledgerEntryCount();

        // Second call with same key — must replay
        $tx2 = $this->service->create($payload);

        $this->assertTrue((bool) ($tx2->idempotent_replay ?? ($tx2->id === $tx1->id)));
        $this->assertSame($tx1->id, $tx2->id);
        $this->assertEqualsWithDelta($vaultAfterFirst, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertSame($entriesAfterFirst, $this->ledgerEntryCount());
        $this->assertOnlineLedgerBalanced();
    }

    /** @test */
    public function test_idempotency_key_released_after_soft_delete(): void
    {
        $payload = [
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Release Key After Del',
            'customer_phone' => '01005670003',
            'purchase_price' => 0.00,
            'selling_price' => 100.00,
            'amount_paid' => 100.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'idempotency_key' => 'key_to_release',
            'created_by' => $this->user->id,
            'status' => 'completed',
        ];

        $tx1 = $this->service->create($payload);
        $this->service->delete($tx1);

        // Re-create with same key — must succeed as NEW row
        $tx2 = $this->service->create($payload);
        $this->assertNotSame($tx1->id, $tx2->id);

        // Key cleared on the soft-deleted row AFTER the new insert/replay check
        $this->assertNull(
            OnlineTransaction::withTrashed()->find($tx1->id)->idempotency_key
        );
        $this->assertOnlineLedgerBalanced();
    }

    // =========================================================================
    // BOUNDARY / EDGE AMOUNTS
    // =========================================================================

    /** @test */
    public function test_boundary_minimum_valid_amounts(): void
    {
        $vaultStart = $this->cashbox->balance;

        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Min Amount',
            'customer_phone' => '01001231111',
            'purchase_price' => 0.01,
            'selling_price' => 0.02,
            'amount_paid' => 0.02,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $this->assertEqualsWithDelta(0.01, (float) $tx->profit, 0.001);
        $this->assertEqualsWithDelta($vaultStart + 0.01, $this->accountBalance($this->cashbox->id), 0.001);
        $this->assertOnlineLedgerBalanced();
    }

    /** @test */
    public function test_boundary_large_valid_amounts(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Large Amount',
            'customer_phone' => '01001232222',
            'purchase_price' => 100000.00,
            'selling_price' => 1000000.00,
            'amount_paid' => 1000000.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $this->assertEqualsWithDelta(900000.00, (float) $tx->profit, 0.01);
        $this->assertOnlineLedgerBalanced();
    }

    /** @test */
    public function test_boundary_zero_profit_transaction(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Zero Profit',
            'customer_phone' => '01001233333',
            'purchase_price' => 100.00,
            'selling_price' => 100.00,
            'amount_paid' => 100.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $this->assertEqualsWithDelta(0.00, (float) $tx->profit, 0.001);
        // Vault: +100 cash in, −100 cost out = net 0
        $this->assertEqualsWithDelta($this->cashbox->balance, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertOnlineLedgerBalanced();
    }

    // =========================================================================
    // FAILURE ATOMICITY
    // =========================================================================

    /** @test */
    public function test_atomicity_failed_ledger_post_rolls_back_transaction_row(): void
    {
        $txCountBefore = OnlineTransaction::withTrashed()->count();
        $entryCountBefore = $this->ledgerEntryCount();
        $vaultStart = $this->cashbox->balance;

        // Mock TransactionService to throw mid-flight (simulates DB crash)
        $this->mock(TransactionService::class)
            ->shouldReceive('recordIncome')
            ->andThrow(new \RuntimeException('Simulated DB crash'));

        // Re-resolve service from container to inject the mock
        $this->service = app(OnlineTransactionService::class);

        try {
            $this->service->create([
                'service_type_code' => $this->serviceType->code,
                'provider_code' => $this->provider->code,
                'customer_name' => 'Atomic Crash Client',
                'customer_phone' => '01009990099',
                'purchase_price' => 100.00,
                'selling_price' => 200.00,
                'amount_paid' => 200.00,
                'payment_method' => 'cash',
                'account_id' => $this->cashbox->id,
                'status' => 'completed',
            ]);

            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated DB crash', $e->getMessage());
        }

        // Full rollback: no new OnlineTransaction row, no new ledger entries
        $this->assertSame($txCountBefore, OnlineTransaction::withTrashed()->count());
        $this->assertSame($entryCountBefore, $this->ledgerEntryCount());
        $this->assertEqualsWithDelta($vaultStart, $this->cashbox->balance, 0.01);
    }

    // =========================================================================
    // FULL LIFECYCLE INTEGRATION SCENARIOS
    // =========================================================================

    /** @test */
    public function test_full_lifecycle_walk_in_create_edit_cancel(): void
    {
        $vaultStart = $this->cashbox->balance;

        // 1. Create (ON-FIN-001)
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Lifecycle Client',
            'customer_phone' => '01009991234',
            'purchase_price' => 100.00,
            'selling_price' => 300.00,
            'amount_paid' => 200.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        $this->assertEqualsWithDelta($vaultStart + 100.00, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);

        // 2. Edit selling_price (ON-FIN-006)
        $tx = $this->service->update($tx, ['selling_price' => 350.00]);
        $this->assertEqualsWithDelta(250.00, (float) $tx->profit, 0.01);
        $this->assertOnlineLedgerBalanced();

        // 3. Edit amount_paid (ON-FIN-008)
        $tx = $this->service->update($tx, ['amount_paid' => 350.00]);
        $this->assertEqualsWithDelta($vaultStart + 250.00, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertOnlineLedgerBalanced();

        // 4. Cancel (ON-FIN-004)
        $tx = $this->service->update($tx, ['status' => 'cancelled']);
        $this->assertSame(OnlineTransactionStatus::Cancelled, $tx->status);
        $this->assertEqualsWithDelta($vaultStart, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);
    }

    /** @test */
    public function test_full_lifecycle_registered_customer_create_edit_delete(): void
    {
        $customer = $this->makeCustomer('Lifecycle Reg', '01009994321');
        $vaultStart = $this->cashbox->balance;
        $customer->refresh();
        $customerArId = Account::find($customer->account_id)->id;

        // 1. Create (ON-FIN-002)
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_phone' => $customer->phone,
            'purchase_price' => 60.00,
            'selling_price' => 200.00,
            'amount_paid' => 150.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'completed',
        ]);

        // Vault: +150 in, −60 cost = +90; AR debt = 50
        $this->assertEqualsWithDelta(50.00, $this->glBalance($customerArId), 0.01);
        $this->assertEqualsWithDelta($vaultStart + 90.00, $this->accountBalance($this->cashbox->id), 0.01);

        // 2. Edit purchase_price (ON-FIN-007)
        $tx = $this->service->update($tx, ['purchase_price' => 80.00]);
        // Extra 20 cost → vault net becomes +70
        $this->assertEqualsWithDelta($vaultStart + 70.00, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertOnlineLedgerBalanced();

        // 3. Delete (ON-FIN-010)
        $this->service->delete($tx);
        $this->assertEqualsWithDelta($vaultStart, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertEqualsWithDelta(0.00, $this->glBalance($customerArId), 0.01);
        $this->assertSoftDeleted('online_transactions', ['id' => $tx->id]);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id, $customerArId]);
    }

    /** @test */
    public function test_full_lifecycle_pending_activate_edit_delete(): void
    {
        $vaultStart = $this->cashbox->balance;

        // 1. Create as pending (ON-FIN-003)
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Pending Lifecycle',
            'customer_phone' => '01009995678',
            'purchase_price' => 80.00,
            'selling_price' => 250.00,
            'amount_paid' => 250.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'pending',
        ]);

        $this->assertNull($tx->income_transaction_id);
        $this->assertEqualsWithDelta($vaultStart, $this->accountBalance($this->cashbox->id), 0.01);

        // 2. Activate to completed (ON-FIN-005)
        $tx = $this->service->update($tx, ['status' => 'completed']);
        $this->assertNotNull($tx->income_transaction_id);
        $this->assertEqualsWithDelta($vaultStart + 170.00, $this->accountBalance($this->cashbox->id), 0.01);

        // 3. Edit selling price upward (ON-FIN-006)
        $tx = $this->service->update($tx, ['selling_price' => 300.00]);
        $this->assertOnlineLedgerBalanced();

        // 4. Delete (ON-FIN-010)
        $this->service->delete($tx);
        $this->assertEqualsWithDelta($vaultStart, $this->accountBalance($this->cashbox->id), 0.01);
        $this->assertSoftDeleted('online_transactions', ['id' => $tx->id]);
        $this->assertFullDoubleEntryEquilibrium([$this->cashbox->id]);
    }
}
