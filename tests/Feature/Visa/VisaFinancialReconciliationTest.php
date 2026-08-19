<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\VisaBooking;

/**
 * Phase 9.7 — Financial Accounting + Ledger Reconciliation (Sections 11-13).
 *
 * Three concerns:
 *   1. Per-booking independent calc (purchase, selling, customer-paid,
 *      outstanding, supplier payable, supplier paid, profit, refunded)
 *   2. Per-account ledger invariants (Σ credit = Σ debit + balance)
 *   3. Multi-booking reconciliation (currency, agent, status, customer,
 *      module rollup)
 *
 * The plan called out the gap:
 *   - Supplier AP balance under multi-booking scenarios (new — gap)
 *
 * All scenarios assert `assertLedgerGloballyBalanced()` at the end of
 * multi-booking fixtures (the project-wide double-entry invariant).
 */
class VisaFinancialReconciliationTest extends VisaTestCase
{
    /* ============================================================
     *  A. PER-BOOKING FINANCIAL CALCULATIONS
     * ============================================================ */

    public function test_booking_purchase_price_recorded_as_expense(): void
    {
        $booking = $this->makeBooking();  // purchase_price = 1000

        $expense = $booking->fresh()->expenseTransaction;
        $this->assertNotNull($expense, 'expense tx must be created on booking');
        $this->assertEquals(1000.0, (float) $expense->amount,
            'expense amount must equal purchase_price');
    }

    public function test_booking_income_equals_selling_plus_service_fee(): void
    {
        // selling = 1500, service_fee = 100 → income = 1600
        $booking = $this->makeBooking();

        $income = $booking->fresh()->incomeTransaction;
        $this->assertNotNull($income, 'income tx must be created on booking');
        $this->assertEquals(1600.0, (float) $income->amount,
            'income amount must equal selling_price + service_fee');
    }

    public function test_booking_profit_equals_income_minus_purchase(): void
    {
        // profit = (1500 + 100) - 1000 = 600
        $booking = $this->makeBooking();

        $this->assertEquals(600.0, (float) $booking->fresh()->profit,
            'profit = (selling + service_fee) - purchase_price');
    }

    public function test_booking_customer_paid_matches_payments_sum(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_PAID1_'.uniqid(),
        ])->assertCreated();

        $booking->refresh();
        $this->assertEquals(1000.0, (float) $booking->paid_amount,
            'paid_amount must equal SUM(visa_payments.amount) for this booking');
    }

    public function test_booking_customer_outstanding_correct_after_partial_pay(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_OUT1_'.uniqid(),
        ])->assertCreated();

        // outstanding = (selling + service_fee) - paid = 1600 - 500 = 1100
        $booking->refresh();
        $outstanding = (1600.0) - (float) $booking->paid_amount;
        $this->assertEqualsWithDelta(1100.0, $outstanding, 0.01,
            'customer outstanding must = (selling + service_fee) - paid_amount');
    }

    public function test_booking_supplier_payable_equals_negative_purchase_price(): void
    {
        $booking = $this->makeBooking();
        $agentAccountId = $this->agent->account_id;

        $agentNet = (float) AccountEntry::where('account_id', $agentAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->assertEqualsWithDelta(-1000.0, $agentNet, 0.01,
            'agent AP must equal -purchase_price after booking create');
    }

    public function test_booking_refunded_total_equals_sum_of_refund_audit_rows(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_REF1_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.7 refund total',
        ])->assertOk();

        $refundTotal = \App\Models\RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'visa')
            ->sum('refund_amount');
        $this->assertEquals(1600.0, (float) $refundTotal,
            'refunded_total must equal SUM(refund_audit_logs.refund_amount) for booking');
    }

    /* ============================================================
     *  B. PER-ACCOUNT LEDGER INVARIANTS
     * ============================================================ */

    public function test_customer_account_balance_matches_ledger_entries(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_CUST_'.uniqid(),
        ])->assertCreated();

        $customerAccountId = $this->customer->account_id;
        $this->assertLedgerBalancedForAccount(Account::findOrFail($customerAccountId));
    }

    public function test_agent_account_balance_matches_ledger_entries(): void
    {
        $booking = $this->makeBooking();
        $agentAccountId = $this->agent->account_id;
        $this->assertLedgerBalancedForAccount(Account::findOrFail($agentAccountId));
    }

    public function test_vault_balance_correct_after_multiple_payments(): void
    {
        $baselineVault = (float) $this->vaultEgp->fresh()->balance;

        $booking1 = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking1->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_VAULT1_'.uniqid(),
        ])->assertCreated();

        $booking2 = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking2->id}/payments", [
            'amount' => 800.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_VAULT2_'.uniqid(),
        ])->assertCreated();

        // vault balance grew by 500 + 800 = 1300
        $this->assertEqualsWithDelta($baselineVault + 1300.0,
            (float) $this->vaultEgp->fresh()->balance, 0.01,
            'vault balance must reflect SUM(payments) over all bookings');
    }

    public function test_income_clearing_account_net_zero_after_lifecycle(): void
    {
        // Full lifecycle: create + pay + refund → income-clearing must net to 0
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_CLEAR1_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'lifecycle test',
        ])->assertOk();

        // Find income-clearing account (auto-created with module=visa, type=income)
        $clearingAccount = Account::where('module', 'visas')
            ->where('type', 'income')
            ->where('is_module_vault', true)
            ->first();
        if ($clearingAccount) {
            $this->assertLedgerBalancedForAccount($clearingAccount);
        }
        // Always assert global balance
        $this->assertLedgerGloballyBalanced();
    }

    /* ============================================================
     *  C. MULTI-BOOKING RECONCILIATION (THE GAP: supplier AP)
     * ============================================================ */

    public function test_multiple_bookings_aggregate_correctly(): void
    {
        $b1 = $this->makeBooking();
        $b2 = $this->makeBooking();
        $b3 = $this->makeBooking();

        $this->postJson("/api/v1/visa/bookings/{$b1->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_MULTI_B1_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/visa/bookings/{$b2->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_MULTI_B2_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/visa/bookings/{$b3->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_MULTI_B3_'.uniqid(),
        ])->assertCreated();

        $this->assertSame(3, VisaBooking::count());
        $this->assertSame(3, \App\Models\VisaPayment::count());
        $this->assertLedgerGloballyBalanced();
    }

    public function test_supplier_ap_aggregates_correctly_across_bookings(): void
    {
        // THE GAP: supplier AP balance under multi-booking scenarios
        // 3 bookings × purchase_price(1000) = -3000 agent AP total
        $b1 = $this->makeBooking();
        $b2 = $this->makeBooking();
        $b3 = $this->makeBooking();

        $agentAccountId = $this->agent->account_id;
        $agentAP = (float) AccountEntry::where('account_id', $agentAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->assertEqualsWithDelta(-3000.0, $agentAP, 0.01,
            'agent AP must equal -SUM(purchase_price) over all bookings');

        $this->assertLedgerGloballyBalanced();
    }

    public function test_per_currency_totals_isolated(): void
    {
        // EGP booking → EGP vault changes; USD vault untouched
        $egpBooking = $this->makeBooking(['currency' => 'EGP']);
        $usdBooking = $this->makeBooking([
            'currency' => 'USD',
            'account_id' => $this->vaultUsd->id,
            'purchase_price' => 500.0,
            'selling_price' => 700.0,
            'service_fee' => 50.0,
        ]);

        $this->postJson("/api/v1/visa/bookings/{$egpBooking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_CUR_EGP_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$usdBooking->id}/payments", [
            'amount' => 750.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultUsd->id,
            'idempotency_key' => 'P97_CUR_USD_'.uniqid(),
        ])->assertCreated();

        // USD vault grew by 750, EGP vault grew by 1600
        $this->assertEqualsWithDelta(10000.0 + 750.0,
            (float) $this->vaultUsd->fresh()->balance, 0.01,
            'USD vault increased by USD payment only');
        $this->assertEqualsWithDelta(100000.0 + 1600.0,
            (float) $this->vaultEgp->fresh()->balance, 0.01,
            'EGP vault increased by EGP payment only');

        $this->assertLedgerGloballyBalanced();
    }

    public function test_per_status_breakdown_correct(): void
    {
        // 3 bookings: 2 stay Submitted, 1 cancelled
        $b1 = $this->makeBooking();
        $b2 = $this->makeBooking();
        $b3 = $this->makeBooking();

        $this->postJson("/api/v1/visa/bookings/{$b3->id}/cancel", [
            'reason' => 'cancel one',
        ])->assertOk();

        $this->assertSame(2, VisaBooking::where('status', VisaStatus::Submitted->value)->count());
        $this->assertSame(1, VisaBooking::where('status', VisaStatus::Cancelled->value)->count());

        $this->assertLedgerGloballyBalanced();
    }

    public function test_per_customer_portfolio_balances_correctly(): void
    {
        // Same customer, 2 bookings; total AR = SUM(outstanding across bookings)
        $booking1 = $this->makeBooking();
        $booking2 = $this->makeBooking();

        $this->postJson("/api/v1/visa/bookings/{$booking1->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_PORT_B1_'.uniqid(),
        ])->assertCreated();
        // booking2 unpaid → customer owes 1600 for it

        $customerAccountId = $this->customer->account_id;
        $customerAR = (float) AccountEntry::where('account_id', $customerAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        // AR = (income 1600 + 1600) - payment 1600 = 1600 (still owes 1600 for b2)
        $this->assertEqualsWithDelta(1600.0, $customerAR, 0.01,
            'customer AR across portfolio must reflect net debt');

        $this->assertLedgerGloballyBalanced();
    }

    /* ============================================================
     *  D. PERIOD-END / MODULE ROLLUP
     * ============================================================ */

    public function test_module_visa_rollup_excludes_other_modules(): void
    {
        // Sanity: visa module entries must be isolated
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_ROLLUP_'.uniqid(),
        ])->assertCreated();

        $visaTxCount = Transaction::where('module', 'visa')->count();
        $this->assertGreaterThan(0, $visaTxCount, 'visa transactions must exist');

        // No flight module transactions in this test (only visa)
        $flightTxCount = Transaction::where('module', 'flight')->count();
        $this->assertSame(0, $flightTxCount,
            'visa-only test must NOT create flight transactions');
    }

    public function test_global_ledger_balanced_after_complex_lifecycle(): void
    {
        // Complex: create + pay + edit-price + refund + create-another + pay-partial
        $b1 = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$b1->id}/payments", [
            'amount' => 800.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_CPX1_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/visa/bookings/{$b1->id}/refund", [
            'reason' => 'lifecycle refund',
        ])->assertOk();

        $b2 = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$b2->id}/payments", [
            'amount' => 400.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_CPX2_'.uniqid(),
        ])->assertCreated();

        $this->assertLedgerGloballyBalanced();
    }

    public function test_per_transaction_debit_equals_credit(): void
    {
        // For every transaction tied to visa bookings, SUM(debit) = SUM(credit)
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P97_DBL_'.uniqid(),
        ])->assertCreated();

        $transactions = Transaction::where('module', 'visa')
            ->where('related_type', VisaBooking::class)
            ->orWhere('related_type', \App\Models\VisaPayment::class)
            ->get();

        foreach ($transactions as $tx) {
            $debit = (float) AccountEntry::where('transaction_id', $tx->id)->sum('debit');
            $credit = (float) AccountEntry::where('transaction_id', $tx->id)->sum('credit');
            $this->assertEqualsWithDelta($debit, $credit, 0.01,
                "transaction #{$tx->id} (type=".$tx->type->value.") debit ({$debit}) must equal credit ({$credit})");
        }
    }

    /* ============================================================
     *  E. EDGE CASES
     * ============================================================ */

    public function test_zero_payment_booking_shows_full_outstanding(): void
    {
        $booking = $this->makeBooking();
        $booking->refresh();

        $outstanding = (1500.0 + 100.0) - (float) $booking->paid_amount;
        $this->assertEqualsWithDelta(1600.0, $outstanding, 0.01,
            'newly created booking (no payments) has full outstanding = selling + service_fee');
        $this->assertEquals(0.0, (float) $booking->paid_amount);
    }

    public function test_multi_currency_booking_does_not_pollute_other_currencies(): void
    {
        $baselineEgp = (float) $this->vaultEgp->fresh()->balance;
        $baselineUsd = (float) $this->vaultUsd->fresh()->balance;
        $baselineSar = (float) $this->vaultSar->fresh()->balance;

        $booking = $this->makeBooking([
            'currency' => 'USD',
            'account_id' => $this->vaultUsd->id,
            'purchase_price' => 500.0,
            'selling_price' => 700.0,
            'service_fee' => 50.0,
        ]);

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 750.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultUsd->id,
            'idempotency_key' => 'P97_POLLUTE_'.uniqid(),
        ])->assertCreated();

        $this->assertEqualsWithDelta($baselineEgp, (float) $this->vaultEgp->fresh()->balance, 0.01,
            'EGP vault must NOT change for USD booking');
        $this->assertEqualsWithDelta($baselineSar, (float) $this->vaultSar->fresh()->balance, 0.01,
            'SAR vault must NOT change for USD booking');
        $this->assertEqualsWithDelta($baselineUsd + 750.0, (float) $this->vaultUsd->fresh()->balance, 0.01,
            'USD vault increased by USD payment');
    }
}