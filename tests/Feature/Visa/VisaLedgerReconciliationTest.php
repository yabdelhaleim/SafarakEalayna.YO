<?php

namespace Tests\Feature\Visa;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;

/**
 * PHASE 12: GL Reconciliation — verify accounting invariants hold across all operations.
 *
 * Project invariants:
 *   - For every account: balance = SUM(credit) - SUM(debit)
 *   - For every transaction: SUM(debit) = SUM(credit) (balanced)
 *   - For every visa booking: total_paid + outstanding = selling_price + service_fee
 *   - For every reversal: original transaction.amount is preserved (additive)
 *   - All visa transactions have module=TransactionModule::Visa
 *   - All visa transactions have related_type=VisaBooking, related_id=booking_id
 *
 * @group visa
 * @group visa-ledger
 */
class VisaLedgerReconciliationTest extends VisaTestCase
{
    public function test_booking_creation_balances_all_ledger(): void
    {
        $vaultBefore = (float) $this->vaultEgp->fresh()->balance;
        $custBefore = 0.0;

        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 2000.0,
            'service_fee' => 200.0,
        ]);

        $this->assertLedgerGloballyBalanced();

        // Verify transactions tagged correctly
        $expense = Transaction::find($booking->expense_transaction_id);
        $income = Transaction::find($booking->income_transaction_id);

        $this->assertSame(TransactionModule::Visa->value, $expense->module instanceof \BackedEnum ? $expense->module->value : $expense->module);
        $this->assertSame(TransactionModule::Visa->value, $income->module instanceof \BackedEnum ? $income->module->value : $income->module);
        $this->assertSame(\App\Models\VisaBooking::class, $expense->related_type);
        $this->assertSame($booking->id, $expense->related_id);
        $this->assertSame(\App\Models\VisaBooking::class, $income->related_type);
        $this->assertSame($booking->id, $income->related_id);

        // Verify both balanced
        $this->assertTransactionBalanced($expense);
        $this->assertTransactionBalanced($income);
    }

    public function test_payment_creates_balanced_transaction(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 100.0,
            'selling_price' => 1000.0,
        ]);

        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $payment = $booking->payments()->latest('id')->first();
        $tx = Transaction::find($payment->transaction_id);

        $this->assertTransactionBalanced($tx);
        $this->assertSame(TransactionModule::Visa->value, $tx->module instanceof \BackedEnum ? $tx->module->value : $tx->module);
        $this->assertSame(\App\Models\VisaBooking::class, $tx->related_type);
        $this->assertSame($booking->id, $tx->related_id);

        $this->assertLedgerGloballyBalanced();
    }

    public function test_cancel_reverses_balance_to_zero_net(): void
    {
        $vaultBefore = (float) $this->vaultEgp->fresh()->balance;
        $agentBefore = (float) Account::find($this->agent->account_id)->fresh()->balance;

        $booking = $this->makeBooking([
            'purchase_price' => 500.0,
            'selling_price' => 1000.0,
            'service_fee' => 100.0,
        ]);

        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1100.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        // Cancel
        app(VisaRefundService::class)->cancel($booking->fresh(), 'audit');

        // Net balance change must be 0
        $vaultAfter = (float) $this->vaultEgp->fresh()->balance;
        $agentAfter = (float) Account::find($this->agent->account_id)->fresh()->balance;

        $this->assertEqualsWithDelta($vaultBefore, $vaultAfter, 0.01,
            'vault net = 0 after pay+cancel');
        $this->assertEqualsWithDelta($agentBefore, $agentAfter, 0.01,
            'agent net = 0 after expense+cancel');

        $this->assertLedgerGloballyBalanced();

        // Original transaction amounts must be preserved (additive reversal)
        $originalExpense = Transaction::find($booking->expense_transaction_id);
        $originalIncome = Transaction::find($booking->income_transaction_id);

        $this->assertEqualsWithDelta(500.0, (float) $originalExpense->amount, 0.01);
        $this->assertEqualsWithDelta(1100.0, (float) $originalIncome->amount, 0.01);
    }

    public function test_refund_reverses_balance_to_zero_net(): void
    {
        $vaultBefore = (float) $this->vaultEgp->fresh()->balance;

        $booking = $this->makeBooking([
            'purchase_price' => 100.0,
            'selling_price' => 1000.0,
        ]);

        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        app(VisaRefundService::class)->refund($booking->fresh(), 'audit refund');

        $vaultAfter = (float) $this->vaultEgp->fresh()->balance;
        $this->assertEqualsWithDelta($vaultBefore, $vaultAfter, 0.01);

        $this->assertLedgerGloballyBalanced();
    }

    public function test_delete_with_reversal_full_balance_zero(): void
    {
        $vaultBefore = (float) $this->vaultEgp->fresh()->balance;
        $agentBefore = (float) Account::find($this->agent->account_id)->fresh()->balance;

        $booking = $this->makeBooking([
            'purchase_price' => 300.0,
            'selling_price' => 500.0,
        ]);

        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 200.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->user->id);

        $vaultAfter = (float) $this->vaultEgp->fresh()->balance;
        $agentAfter = (float) Account::find($this->agent->account_id)->fresh()->balance;

        $this->assertEqualsWithDelta($vaultBefore, $vaultAfter, 0.01);
        $this->assertEqualsWithDelta($agentBefore, $agentAfter, 0.01);

        $this->assertLedgerGloballyBalanced();
    }

    // test_repost_preserves_original_amount — REMOVED (INCIDENT-2026-08-17)
//   The test asserted additive-reversal behavior of Edit (selling/purchase price repost).
//   Edit is permanently disabled — no Edit path exists at all. Cancellation is the correction path.

public function test_paid_plus_remaining_equals_selling_plus_fee(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 2000.0,
            'service_fee' => 300.0,
        ]);

        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 700.0, 'payment_method' => 'cash', 'account_id' => $this->vaultEgp->id,
        ]);
        app(VisaBookingService::class)->addPayment($booking->fresh(), [
            'amount' => 800.0, 'payment_method' => 'cash', 'account_id' => $this->vaultEgp->id,
        ]);

        $booking->refresh();
        $expectedTotal = (float) $booking->selling_price + (float) $booking->service_fee;
        $actualTotal = (float) $booking->paid_amount + (float) $booking->remaining_amount;

        $this->assertEqualsWithDelta($expectedTotal, $actualTotal, 0.01,
            'paid + remaining = selling + fee');
    }

    public function test_profit_calculation_invariant(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 2000.0,
            'service_fee' => 200.0,
        ]);

        $this->assertEqualsWithDelta(1200.0, (float) $booking->profit, 0.01,
            'profit = selling + service_fee - purchase_price');
    }

    public function test_all_visa_transactions_have_correct_module(): void
    {
        $this->makeBooking(['selling_price' => 1000.0]);
        $this->makeBooking(['selling_price' => 2000.0]);

        $visaTx = Transaction::where('module', TransactionModule::Visa->value)->get();
        $this->assertGreaterThan(0, $visaTx->count());

        foreach ($visaTx as $tx) {
            $this->assertSame(TransactionModule::Visa->value, $tx->module instanceof \BackedEnum ? $tx->module->value : $tx->module);
        }
    }

    public function test_all_visa_transactions_have_correct_related_type(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0]);

        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 100.0, 'payment_method' => 'cash', 'account_id' => $this->vaultEgp->id,
        ]);

        $visaTx = Transaction::where('module', TransactionModule::Visa->value)->get();
        foreach ($visaTx as $tx) {
            $this->assertSame(\App\Models\VisaBooking::class, $tx->related_type);
            $this->assertNotNull($tx->related_id);
        }
    }

    public function test_multi_currency_bookings_independent_balances(): void
    {
        $egpBooking = $this->makeBooking([
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'selling_price' => 1000.0,
        ]);

        $usdPayload = $this->bookingPayload();
        $usdPayload['currency'] = 'USD';
        $usdPayload['account_id'] = $this->vaultUsd->id;
        $usdPayload['visa_details']['country'] = 'AUDIT-USD';
        $usdBooking = \App\Models\VisaBooking::find(
            $this->postJson('/api/v1/visa/bookings', $usdPayload)->json('data.id')
        );

        // Each currency operates independently
        app(VisaBookingService::class)->addPayment($egpBooking, [
            'amount' => 500.0, 'payment_method' => 'cash', 'account_id' => $this->vaultEgp->id,
        ]);

        app(VisaBookingService::class)->addPayment($usdBooking, [
            'amount' => 300.0, 'payment_method' => 'cash', 'account_id' => $this->vaultUsd->id,
        ]);

        // Each vault shows correct currency delta
        $egpDelta = $this->assertAccountDelta($this->vaultEgp, 500.0);
        $usdDelta = $this->assertAccountDelta($this->vaultUsd, 300.0);

        $this->assertLedgerGloballyBalanced();
    }

    private function assertAccountDelta(Account $account, float $expected): float
    {
        $current = (float) $account->fresh()->balance;
        $baseline = $account->id === $this->vaultEgp->id ? 100000.0
            : ($account->id === $this->vaultUsd->id ? 10000.0 : 0.0);
        $delta = round($current - $baseline, 2);

        $this->assertEqualsWithDelta($expected, $delta, 0.01);

        return $delta;
    }
}