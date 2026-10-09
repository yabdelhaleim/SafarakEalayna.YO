<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\TransactionType;
use App\Models\HajjUmraPayment;
use App\Models\Transaction;

/**
 * REGRESSION TEST — latent bug exposed by FC-AUDIT-20260814 (D1).
 *
 * BUG SCENARIO (HIDDEN):
 *   HajjUmraBookingService::addPayment() historically called
 *   TransactionService::recordIncome() for every payment. Pre-FC-AUDIT,
 *   recordIncome() silently defaulted to type=Transfer (bug-mis-categorization),
 *   so the duplicate-income guard never fired and the misuse was invisible.
 *
 *   After the FC-AUDIT D1 fix (recordIncome() now correctly tags type=Income),
 *   the second payment triggered the duplicate-income guard at
 *   TransactionService.php:612–625 and threw InvalidArgumentException.
 *
 * FIX:
 *   addPayment() now uses recordJournalTransfer() with explicit type=Transfer.
 *   - Same debit/credit accounting (customer AR → treasury).
 *   - Bypasses duplicate-income guard (Transfer ≠ Income).
 *   - Bypasses DB-level income_unique_key constraint (Transfer → NULL).
 *   - Matches pre-FC-AUDIT behaviour exactly.
 *
 * WHAT THIS TEST PROVES:
 *   1) Booking creation records exactly ONE sale Income transaction.
 *   2) First addPayment() succeeds (no guard fires).
 *   3) Second addPayment() also succeeds (no guard fires).
 *   4) Each payment transaction has type='transfer' (semantic correctness).
 *   5) paid_amount on the booking equals the sum of payment amounts.
 *   6) Exactly one Income transaction exists for the booking — no duplicates.
 */
class HajjUmraAddPaymentRegressionTest extends HajjUmraTestCase
{
    public function test_booking_sale_records_one_income_then_two_payments_both_transfer_with_no_duplicate_income(): void
    {
        // ─── Setup: master data ────────────────────────────────────────────
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $treasury = $this->makeTreasuryAccount('EGP', 500_000.00);

        // ─── 1) Create booking (records the sale Income) ───────────────────
        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000,
            'selling_price' => 15000,
            'account_id' => $treasury->id,
            'status' => 'confirmed',
        ]);

        $response->assertCreated();
        $bookingId = $response->json('data.id');

        $booking = \App\Models\HajjUmraBooking::query()->findOrFail($bookingId);
        $this->assertNotNull($booking->income_transaction_id,
            'Booking must have a sale Income transaction_id after create.');

        // ─── Assertion 1: exactly ONE Income transaction exists for booking ─
        $incomeCount = Transaction::query()
            ->where('related_type', \App\Models\HajjUmraBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', TransactionType::Income->value)
            ->count();
        $this->assertSame(1, $incomeCount,
            'Booking must have exactly ONE sale Income transaction. Found: '.$incomeCount);

        $saleIncome = Transaction::query()->findOrFail($booking->income_transaction_id);
        $this->assertSame(TransactionType::Income, $saleIncome->type,
            'Sale transaction must be type=Income.');
        $this->assertEquals(15000.0, (float) $saleIncome->amount,
            'Sale Income amount must equal the booking selling_price.');

        // ─── 2) First addPayment() — must succeed (regression for the bug) ─
        $firstPayment = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 5000,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            'paid_by' => 'cashier',
        ]);

        $firstPayment->assertCreated();
        $firstPaymentId = $firstPayment->json('data.payment.id');
        $this->assertNotNull($firstPaymentId, 'First payment must be persisted.');

        // ─── Assertion 2: payment transaction is type=Transfer (semantic fix) ─
        $firstPaymentModel = HajjUmraPayment::query()->findOrFail($firstPaymentId);
        $firstPaymentTx = Transaction::query()->findOrFail($firstPaymentModel->transaction_id);
        $this->assertSame(TransactionType::Transfer, $firstPaymentTx->type,
            'Payment transaction MUST be type=Transfer (not Income). The sale is already recorded.');

        // ─── 3) Second addPayment() — must also succeed (regression) ──────
        $secondPayment = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 4000,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            'paid_by' => 'cashier',
        ]);

        $secondPayment->assertCreated();
        $secondPaymentId = $secondPayment->json('data.payment.id');
        $this->assertNotNull($secondPaymentId, 'Second payment must be persisted.');

        $secondPaymentTx = Transaction::query()->findOrFail(
            HajjUmraPayment::query()->findOrFail($secondPaymentId)->transaction_id
        );
        $this->assertSame(TransactionType::Transfer, $secondPaymentTx->type,
            'Second payment MUST also be type=Transfer.');

        // ─── Assertion 3: paid_amount on booking = sum of payments ────────
        $booking->refresh();
        $expectedPaid = 5000.0 + 4000.0;
        $this->assertEqualsWithDelta(
            $expectedPaid,
            (float) $booking->paid_amount,
            0.01,
            "Booking paid_amount must equal sum of payments (5000 + 4000). Got: {$booking->paid_amount}"
        );

        // ─── Assertion 4: exactly ONE Income transaction exists (no duplicate) ─
        $incomeCountAfter = Transaction::query()
            ->where('related_type', \App\Models\HajjUmraBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', TransactionType::Income->value)
            ->count();
        $this->assertSame(1, $incomeCountAfter,
            'After TWO payments, booking must STILL have exactly ONE Income transaction. '.
            'Found: '.$incomeCountAfter.' (duplicate-income regression).');

        // ─── Assertion 5: TWO Transfer transactions exist (one per payment) ─
        // Count Transfer transactions whose note begins with "دفعة على حجز" (payment note).
        // The booking creation itself may post other Transfers (e.g. the purchase-cost
        // leg is recorded as type=Transfer pre-FC-AUDIT) — those are NOT the payments.
        $paymentTransferCount = Transaction::query()
            ->where('related_type', \App\Models\HajjUmraBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', TransactionType::Transfer->value)
            ->where('notes', 'like', 'دفعة على حجز%')
            ->count();
        $this->assertSame(2, $paymentTransferCount,
            'After TWO payments, booking must have exactly TWO payment-Transfer transactions. '.
            'Found: '.$paymentTransferCount);

        // ─── Assertion 6: each payment's transaction is balanced (ΣD = ΣC) ─
        $paymentTxIds = [
            $firstPaymentModel->transaction_id,
            HajjUmraPayment::query()->findOrFail($secondPaymentId)->transaction_id,
        ];
        foreach ($paymentTxIds as $txId) {
            $entries = \App\Models\AccountEntry::query()
                ->where('transaction_id', $txId)
                ->get();
            $sumDebit = (float) $entries->sum('debit');
            $sumCredit = (float) $entries->sum('credit');
            $this->assertEqualsWithDelta(
                $sumDebit,
                $sumCredit,
                0.01,
                "Payment transaction #{$txId} must be balanced (ΣD={$sumDebit}, ΣC={$sumCredit})."
            );
        }
    }
}
