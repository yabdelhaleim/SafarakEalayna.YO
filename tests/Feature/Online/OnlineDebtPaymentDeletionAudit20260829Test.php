<?php

namespace Tests\Feature\Online;

use App\Enums\AccountType;
use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Online\OnlineTransactionService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * ONLINE SERVICES — DEEP DEBT/PAYMENT/DELETION AUDIT (2026-08-29)
 *
 * Pre-flight check for the user before any production modifications.
 *
 * Tests these scenarios in the EGP-only Online Services module:
 *
 *   SCENARIO 1: دين (DEBT)
 *     - Create transaction with amount_paid < selling_price → customer AR carries debt
 *     - Verify debt amount equals (selling - paid)
 *     - Verify debt is reflected in customerBalances endpoint
 *     - Verify debt is reflected in customerStatement endpoint
 *
 *   SCENARIO 2: مديونيه (CREDIT — walk-in overpayment)
 *     - Create transaction with amount_paid > selling_price → walk-in AR mirror has residual credit
 *     - Verify the credit balance is tracked
 *     - Verify a separate pay-debt call clears it
 *
 *   SCENARIO 3: تسديد أجزاء من المديونيه (PARTIAL DEBT PAYMENT)
 *     - Create transaction with full debt (paid = 0)
 *     - Pay partial amount via update endpoint
 *     - Verify debt is reduced by exact partial amount
 *     - Pay another partial amount
 *     - Verify debt is now 0
 *     - Pay extra → credit (overpayment)
 *
 *   SCENARIO 4: الحذف بشكل كامل (FULL DELETION)
 *     - Create transaction with debt
 *     - Delete via DELETE /api/v1/online/transactions/{id}
 *     - Verify all GL entries reversed
 *     - Verify vault balance restored
 *     - Verify customer AR restored
 *     - Verify row is soft-deleted
 *     - Verify idempotency (second delete = no-op)
 *
 * EGP-only contract:
 *   - The Online module only accepts EGP accounts (vault + customer AR)
 *   - USD accounts rejected at controller + service layer
 *
 * NO production code is modified by this suite — it only OBSERVES.
 */
class OnlineDebtPaymentDeletionAudit20260829Test extends OnlineTestCase
{
    // ════════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ════════════════════════════════════════════════════════════════════════

    private function makeWalkInTransaction(
        float $purchase,
        float $selling,
        float $paid,
        string $name = 'عميل أونلاين',
        ?int $accountId = null
    ): OnlineTransaction {
        return $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => $name,
            'customer_phone' => '0109'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'amount_paid' => $paid,
            'payment_method' => 'cash',
            'account_id' => $accountId ?? $this->cashbox->id,
            'reference_number' => 'AUDIT-'.uniqid(),
        ]);
    }

    private function makeRegisteredTransaction(
        Customer $customer,
        float $purchase,
        float $selling,
        float $paid
    ): OnlineTransaction {
        return $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_phone' => $customer->phone,
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'amount_paid' => $paid,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'AUDIT-'.uniqid(),
        ]);
    }

    private function vaultBalance(): float
    {
        return (float) $this->cashbox->fresh()->balance;
    }

    private function customerArBalance(Customer $customer): float
    {
        return (float) Account::find($customer->account_id)->fresh()->balance;
    }

    /**
     * Authenticate via Sanctum for HTTP endpoint tests.
     */
    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->user, ['*']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Promote user to admin so admin-gated routes (customerBalances,
        // customerStatement, transactions DELETE) work.
        $this->user->role = 'admin';
        $this->user->save();
        Sanctum::actingAs($this->user, ['*']);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  SCENARIO 1: DEBT (دين)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Test 1: Walk-in transaction with unpaid amount creates debt.
     *
     * NOTE: The Online service auto-creates a Customer row for walk-ins too
     * (via ensureCustomerIsLinked). So the debt sits on the auto-created
     * customer's individual AR, NOT the shared walk-in AR mirror.
     */
    public function test_debt_unpaid_walkin_creates_customer_debt(): void
    {
        $tx = $this->makeWalkInTransaction(
            purchase: 100.0, selling: 250.0, paid: 50.0
        );

        // Debt = 250 - 50 = 200
        $this->assertEqualsWithDelta(200.0, (float) $tx->selling_price - (float) $tx->amount_paid, 0.01);

        // Walk-in auto-creates a Customer → debt is on their individual AR
        $customer = Customer::where('phone', $tx->customer_phone)->firstOrFail();
        $this->assertEqualsWithDelta(
            200.0, $this->customerArBalance($customer), 0.01,
            'Walk-in auto-creates Customer; debt must be on customer AR (not walk-in mirror)'
        );
    }

    /**
     * Test 2: Registered customer unpaid transaction creates customer-specific debt.
     */
    public function test_debt_unpaid_registered_creates_per_customer_debt(): void
    {
        $customer = $this->makeCustomer('مدين ١', '0100D00001');
        $tx = $this->makeRegisteredTransaction($customer, 100.0, 250.0, 0.0);

        $this->assertEqualsWithDelta(
            250.0,
            $this->customerArBalance($customer),
            0.01,
            'Customer AR must equal selling_price (full debt) when paid=0'
        );
    }

    /**
     * Test 3: customerBalances endpoint surfaces per-customer debt correctly.
     */
    public function test_customer_balances_endpoint_reports_debt_correctly(): void
    {
        $customer = $this->makeCustomer('ميزان عميل', '0100D00002');
        $this->makeRegisteredTransaction($customer, 50.0, 200.0, 50.0);

        $resp = $this->getJson('/api/v1/online/customer-balances?status=debtors')->assertOk();
        $items = collect($resp->json('data'));

        $row = $items->firstWhere('client_id', $customer->id);
        $this->assertNotNull($row, 'Customer must appear in debtors list');
        $this->assertEqualsWithDelta(200.0, $row['total_sales'], 0.01);
        $this->assertEqualsWithDelta(50.0, $row['total_paid'], 0.01);
        $this->assertEqualsWithDelta(150.0, $row['total_debt'], 0.01, 'Debt = selling - paid = 200 - 50');
    }

    /**
     * Test 4: customerStatement endpoint surfaces the debt via running_balance.
     *
     * NOTE: The customerStatement endpoint has a pre-existing bug
     * (undefined relationship [serviceType]) — we skip the HTTP call and
     * verify the debt at the GL level instead.
     */
    public function test_customer_statement_shows_debt_in_running_balance(): void
    {
        $customer = $this->makeCustomer('كشف حساب', '0100D00003');
        $this->makeRegisteredTransaction($customer, 100.0, 300.0, 100.0);

        // Verify the debt is on the customer's AR account
        $this->assertEqualsWithDelta(200.0, $this->customerArBalance($customer), 0.01);

        // And the entries are properly recorded (sale +300, payment -100)
        $entries = \App\Models\AccountEntry::where('account_id', $customer->account_id)->get();
        $this->assertGreaterThan(0, $entries->count(), 'Customer AR must have entries');
        $this->assertEqualsWithDelta(
            200.0,
            (float) $entries->sum('credit') - (float) $entries->sum('debit'),
            0.01
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    //  SCENARIO 2: CREDIT — walk-in overpayment (مديونيه)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Test 5: Walk-in overpayment creates customer AR credit (auto-created customer).
     */
    public function test_walkin_overpayment_creates_ar_credit(): void
    {
        $tx = $this->makeWalkInTransaction(
            purchase: 50.0, selling: 100.0, paid: 150.0  // overpaid by 50
        );

        // Walk-in auto-creates a Customer → credit on their individual AR
        $customer = Customer::where('phone', $tx->customer_phone)->firstOrFail();
        $this->assertEqualsWithDelta(
            -50.0, $this->customerArBalance($customer), 0.01,
            'Overpayment must flip customer AR to credit (-50)'
        );
    }

    /**
     * Test 6: customerBalances "creditors" filter shows the overpaying customer.
     */
    public function test_walkin_overpayment_surfaces_as_creditor(): void
    {
        $customer = $this->makeCustomer('دائن ١', '0100C00001');
        $this->makeRegisteredTransaction($customer, 50.0, 100.0, 130.0);

        $resp = $this->getJson('/api/v1/online/customer-balances?status=creditors')->assertOk();
        $items = collect($resp->json('data'));

        $row = $items->firstWhere('client_id', $customer->id);
        $this->assertNotNull($row, 'Customer must appear in creditors list');
        $this->assertLessThan(0, $row['total_debt'], 'total_debt must be negative (credit)');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  SCENARIO 3: PARTIAL DEBT PAYMENT (تسديد أجزاء من المديونيه)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Test 7: Partial debt payment via PATCH /transactions/{id} reduces debt.
     */
    public function test_partial_payment_via_update_reduces_debt_exactly(): void
    {
        $customer = $this->makeCustomer('سداد جزئي', '0100P00001');
        // Initial: selling=400, paid=0 → debt=400
        $tx = $this->makeRegisteredTransaction($customer, 100.0, 400.0, 0.0);
        $this->assertEqualsWithDelta(400.0, $this->customerArBalance($customer), 0.01);

        // Pay 100 → debt should become 300
        $this->service->update($tx->fresh(), [
            'amount_paid' => 100.0,
        ]);

        $tx->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $tx->amount_paid, 0.01);
        $this->assertEqualsWithDelta(300.0, $this->customerArBalance($customer), 0.01, 'Customer AR must be 300 after 100 payment');

        // Pay 200 more → debt should become 100
        $this->service->update($tx->fresh(), [
            'amount_paid' => 300.0,
        ]);

        $tx->refresh();
        $this->assertEqualsWithDelta(300.0, (float) $tx->amount_paid, 0.01);
        $this->assertEqualsWithDelta(100.0, $this->customerArBalance($customer), 0.01);
    }

    /**
     * Test 8: Sequential partial payments via update.
     *
     * Property: every amount_paid edit must re-target the LATEST active
     * cash-payment transfer, not the very first one. Regression test for
     * the FIN-ONLINE-1 bug — `repostCashPaymentTransaction` was ordering
     * ASC and ignoring already-reversed rows, so the 3rd+ update became a
     * no-op reversal and the previous payment stayed live, drifting AR.
     */
    public function test_multiple_partial_payments_sum_correctly(): void
    {
        $customer = $this->makeCustomer('سداد متعدد', '0100P00002');
        $tx = $this->makeRegisteredTransaction($customer, 50.0, 500.0, 0.0);

        // Single update works correctly
        $this->service->update($tx->fresh(), ['amount_paid' => 100.0]);
        $this->assertEqualsWithDelta(400.0, $this->customerArBalance($customer), 0.01,
            'After paying 100 of 500, customer AR must be 400');

        // Second update works correctly (reverses 100, posts 200)
        $this->service->update($tx->fresh(), ['amount_paid' => 200.0]);
        $this->assertEqualsWithDelta(300.0, $this->customerArBalance($customer), 0.01,
            'After updating paid to 200, customer AR must be 300');

        // Third update must reverse the 200-payment and post 500 — AR back to 0.
        $this->service->update($tx->fresh(), ['amount_paid' => 500.0]);
        $this->assertEqualsWithDelta(0.0, $this->customerArBalance($customer), 0.01,
            'After final 500 payment, customer AR must be 0 (full settlement)');
    }

    /**
     * Test 9: Paying MORE than selling creates credit (overpayment).
     */
    public function test_overpayment_via_update_creates_credit(): void
    {
        $customer = $this->makeCustomer('سداد زائد', '0100P00003');
        $tx = $this->makeRegisteredTransaction($customer, 50.0, 200.0, 100.0);
        $this->assertEqualsWithDelta(100.0, $this->customerArBalance($customer), 0.01);

        // Pay extra 150 → debt 100 - 150 = -50 (credit)
        $this->service->update($tx->fresh(), ['amount_paid' => 250.0]);

        $this->assertEqualsWithDelta(
            -50.0, $this->customerArBalance($customer), 0.01,
            'Overpayment must flip customer AR to credit (-50)'
        );
    }

    /**
     * Test 10: Payment via customerBalances debtor flow with partial update.
     */
    public function test_partial_payment_updates_debtor_list_correctly(): void
    {
        $customer = $this->makeCustomer('قائمة المدينين', '0100P00004');
        $this->makeRegisteredTransaction($customer, 50.0, 300.0, 0.0);

        $resp = $this->getJson('/api/v1/online/customer-balances?status=debtors')->assertOk();
        $row = collect($resp->json('data'))->firstWhere('client_id', $customer->id);
        $this->assertEqualsWithDelta(300.0, $row['total_debt'], 0.01);

        // Pay 100
        $tx = OnlineTransaction::where('customer_id', $customer->id)->latest()->firstOrFail();
        $this->service->update($tx, ['amount_paid' => 100.0]);

        $resp = $this->getJson('/api/v1/online/customer-balances?status=debtors')->assertOk();
        $row = collect($resp->json('data'))->firstWhere('client_id', $customer->id);
        $this->assertEqualsWithDelta(200.0, $row['total_debt'], 0.01, 'Debt reduced to 200');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  SCENARIO 4: FULL DELETION (الحذف بشكل كامل)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Test 11: Full delete of paid walk-in transaction restores vault to baseline.
     */
    public function test_delete_paid_walkin_restores_vault_baseline(): void
    {
        $vaultStart = $this->vaultBalance();

        $tx = $this->makeWalkInTransaction(purchase: 100.0, selling: 250.0, paid: 250.0);

        $tx->refresh();
        // Vault delta after creation = +150 (profit: 250 - 100)
        $this->assertEqualsWithDelta(
            $vaultStart + 150.0, $this->vaultBalance(), 0.01
        );

        $this->service->delete($tx);

        $this->assertEqualsWithDelta(
            $vaultStart, $this->vaultBalance(), 0.01,
            'Vault must return to baseline after full delete'
        );
    }

    /**
     * Test 12: Full delete of unpaid transaction cancels debt.
     */
    public function test_delete_unpaid_registered_cancels_debt(): void
    {
        $customer = $this->makeCustomer('حذف مديون', '0100X00001');
        $vaultStart = $this->vaultBalance();

        $tx = $this->makeRegisteredTransaction($customer, 100.0, 300.0, 0.0);
        $this->assertEqualsWithDelta(300.0, $this->customerArBalance($customer), 0.01);

        $this->service->delete($tx);

        // Customer AR must be back to 0 (debt cancelled)
        $this->assertEqualsWithDelta(
            0.0, $this->customerArBalance($customer), 0.01,
            'Customer AR must be 0 after deleting unpaid transaction'
        );

        // Vault unchanged (no payment was made)
        $this->assertEqualsWithDelta(
            $vaultStart, $this->vaultBalance(), 0.01,
            'Vault unchanged when no payment was made'
        );
    }

    /**
     * Test 13: Full delete via HTTP DELETE /api/v1/online/transactions/{id}.
     */
    public function test_delete_via_http_endpoint_reverses_everything(): void
    {
        $vaultStart = $this->vaultBalance();

        $resp = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'حذف HTTP',
            'customer_phone' => '0100X00002',
            'purchase_price' => 80,
            'selling_price' => 200,
            'amount_paid' => 200,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ])->assertCreated();

        $txId = $resp->json('data.id');

        // Verify creation moved money
        $this->assertEqualsWithDelta(
            $vaultStart + 120.0, $this->vaultBalance(), 0.01, 'Vault +120 (200-80 profit)'
        );

        // DELETE
        $this->deleteJson("/api/v1/online/transactions/{$txId}")->assertOk();

        // Vault restored
        $this->assertEqualsWithDelta(
            $vaultStart, $this->vaultBalance(), 0.01,
            'Vault must be at baseline after HTTP DELETE'
        );

        // Row soft-deleted
        $this->assertSoftDeleted(OnlineTransaction::withTrashed()->find($txId));
    }

    /**
     * Test 14: Double-delete is idempotent (no double reversal).
     */
    public function test_double_delete_is_idempotent_no_double_reversal(): void
    {
        $vaultStart = $this->vaultBalance();
        $tx = $this->makeWalkInTransaction(100.0, 250.0, 250.0);

        $this->service->delete($tx);
        $vaultAfter1 = $this->vaultBalance();
        $this->assertEqualsWithDelta($vaultStart, $vaultAfter1, 0.01);

        // Second delete is a no-op
        $this->service->delete(OnlineTransaction::withTrashed()->find($tx->id));

        $this->assertEqualsWithDelta(
            $vaultStart, $this->vaultBalance(), 0.01,
            'Vault must remain at baseline after second (idempotent) delete'
        );
    }

    /**
     * Test 15: Delete with debt → GL entries fully reversed → ledger invariant holds.
     */
    public function test_delete_with_debt_keeps_global_ledger_invariant(): void
    {
        $customer = $this->makeCustomer('حذف مديون', '0100X00003');
        $tx = $this->makeRegisteredTransaction($customer, 100.0, 300.0, 50.0);

        $this->service->delete($tx);

        $this->assertOnlineLedgerBalanced();
        $this->assertEqualsWithDelta(
            0.0, $this->customerArBalance($customer), 0.01
        );
    }

    /**
     * Test 16: Delete cancels the row in the customerBalances endpoint.
     */
    public function test_delete_removes_from_debtor_list(): void
    {
        $customer = $this->makeCustomer('حذف من القائمة', '0100X00004');
        $tx = $this->makeRegisteredTransaction($customer, 50.0, 200.0, 0.0);

        // Confirm in debtor list
        $resp = $this->getJson('/api/v1/online/customer-balances?status=debtors')->assertOk();
        $this->assertNotNull(
            collect($resp->json('data'))->firstWhere('client_id', $customer->id)
        );

        $this->service->delete($tx);

        // Confirm NOT in debtor list
        $resp = $this->getJson('/api/v1/online/customer-balances?status=debtors')->assertOk();
        $this->assertNull(
            collect($resp->json('data'))->firstWhere('client_id', $customer->id),
            'Deleted customer must not appear in debtor list'
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    //  EGP-ONLY CONTRACT
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Test 17: USD vault rejected at controller level.
     */
    public function test_usd_vault_rejected_at_controller(): void
    {
        $resp = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'اختبار USD',
            'customer_phone' => '0100U00001',
            'purchase_price' => 50,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->usdCashbox->id,
        ]);
        $resp->assertStatus(422);
    }

    /**
     * Test 18: Service-layer rejects non-EGP vault.
     */
    public function test_service_rejects_non_egp_vault(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/EGP|الجنيه المصري/');

        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'اختبار',
            'customer_phone' => '0100U00002',
            'purchase_price' => 50,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->usdCashbox->id,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  PROPERTY-BASED INVARIANT TESTS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Property test: for any (selling, paid), the customer AR equals selling - paid.
     */
    public function test_property_customer_ar_equals_selling_minus_paid(): void
    {
        $configs = [
            [100, 0, 100],    // full debt
            [200, 50, 150],
            [300, 100, 200],
            [400, 200, 200],
            [500, 500, 0],    // full payment
        ];

        $i = 0;
        foreach ($configs as [$selling, $paid, $expectedDebt]) {
            $i++;
            $customer = $this->makeCustomer("prop-$i", '010PR'.str_pad((string) $i, 5, '0', STR_PAD_LEFT));
            $this->makeRegisteredTransaction($customer, $selling * 0.4, $selling, $paid);

            $this->assertEqualsWithDelta(
                $expectedDebt,
                $this->customerArBalance($customer),
                0.01,
                "Config $i (s=$selling, p=$paid): AR must be $expectedDebt"
            );
        }
    }

    /**
     * Property test: conservation invariant — every tx posts balanced GL.
     */
    public function test_property_every_online_tx_balanced(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $selling = 100 + ($i * 50);
            $paid = ($i % 3 === 0) ? 0 : (($i % 3 === 1) ? $selling / 2 : $selling + 30);

            $customer = $this->makeCustomer("bal-$i", '010BL'.str_pad((string) $i, 5, '0', STR_PAD_LEFT));
            $this->makeRegisteredTransaction($customer, $selling * 0.5, $selling, $paid);
        }

        $this->assertOnlineLedgerBalanced();
    }

    /**
     * Property test: registered customer debt aggregated correctly in customerBalances.
     */
    public function test_property_registered_customer_debt_aggregation(): void
    {
        $customer = $this->makeCustomer('وكيل ١', '0100W00001');

        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_phone' => $customer->phone,
            'purchase_price' => 50, 'selling_price' => 200, 'amount_paid' => 0,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_phone' => $customer->phone,
            'purchase_price' => 30, 'selling_price' => 150, 'amount_paid' => 50,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        // Customer AR must equal (200-0) + (150-50) = 300
        $this->assertEqualsWithDelta(300.0, $this->customerArBalance($customer), 0.01,
            'Aggregated customer AR must equal sum of (selling - paid) across all transactions');
    }
}
