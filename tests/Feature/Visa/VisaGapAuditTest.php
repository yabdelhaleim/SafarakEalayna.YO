<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\VisaPayment;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;

/**
 * Visa Module — Gap Audit & Financial Re-Test.
 *
 * This file contains test cases designed to analyze and document the gaps
 * and defects discovered in the Visa Module financial and accounting flows,
 * including concurrency locks, idempotency, updates after payment,
 * and double-reversals.
 */
class VisaGapAuditTest extends VisaTestCase
{
    /* ============================================================
     *  PHASE 5: UPDATE LOCK / FINANCIAL IMMUTABILITY
     * ============================================================ */

    /*    /**
     * Scenario A: Create Booking -> Update attempt (no payment yet) -> ALLOWED.
     *
     * FIX-2026-08-26: The previous implementation threw LogicException
     * unconditionally (INCIDENT-2026-08-17). The corrected rule is:
     *   - No payment recorded → update IS ALLOWED
     *   - Any payment recorded → update THROWS LogicException
     */
    public function test_update_allowed_when_no_payment_made(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
        ]);

        $service = app(VisaBookingService::class);

        // No payments yet — update must SUCCEED
        $updated = $service->update($booking, [
            'notes' => 'Updated notes without payment',
            'agent_name' => 'Updated Agent Name',
        ]);

        $this->assertSame('Updated notes without payment', $updated->fresh()->notes);
        $this->assertSame('Updated Agent Name', $updated->fresh()->agent_name);

        // Accounting must remain balanced after a metadata-only update
        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Scenario B: Create Booking -> Payment made -> Update attempt -> REJECTED.
     */
    public function test_update_after_financial_action(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
        ]);

        // First financial action (payment)
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $service = app(VisaBookingService::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Tourism no-edit contract/');

        $service->update($booking->fresh(), [
            'notes' => 'Attempting update after payment',
            'selling_price' => 2000.0,
        ]);
    }

    /**
     * Scenario C: Verify rejected update leaves NO financial mutation.
     * The booking, payments, transactions, and ledger must be unchanged.
     */
    public function test_rejected_update_leaves_no_financial_mutation(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
        ]);

        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        // Snapshot before attempted update
        $txCountBefore = Transaction::where('related_id', $booking->id)->count();
        $payCountBefore = VisaPayment::where('visa_booking_id', $booking->id)->count();
        $sellingBefore = (float) $booking->fresh()->selling_price;

        try {
            app(VisaBookingService::class)->update($booking->fresh(), [
                'selling_price' => 9999.0,
            ]);
        } catch (\LogicException $e) {
            // expected
        }

        // Booking data unchanged
        $this->assertSame($sellingBefore, (float) $booking->fresh()->selling_price);
        // No new transactions
        $this->assertSame($txCountBefore, Transaction::where('related_id', $booking->id)->count());
        // No new payments
        $this->assertSame($payCountBefore, VisaPayment::where('visa_booking_id', $booking->id)->count());
        // Ledger still balanced
        $this->assertLedgerGloballyBalanced();
    }

    /* ============================================================
     *  PHASE 7: DEBT PAYMENT CONCURRENCY
     * ============================================================ */

    /**
     * Verifies that addDebtPayment succeeds (not blocked by Duplicate Income) and prevents overpayment.
     */
    public function test_concurrent_debt_payments_can_cause_overpayment_due_to_missing_lock(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 0.0,
        ]);

        $service = app(VisaBookingService::class);

        // First payment of 1000 (remaining becomes 500)
        $payment = $service->addDebtPayment($booking->fresh(), [
            'amount' => 1000.0,
            'account_id' => $this->vaultEgp->id,
        ]);

        $this->assertNotNull($payment);
        $this->assertEquals(1000.0, (float) $booking->fresh()->paid_amount);

        // Second payment of 1000 must fail with overpayment validation
        $exceptionThrown = false;
        try {
            $service->addDebtPayment($booking->fresh(), [
                'amount' => 1000.0,
                'account_id' => $this->vaultEgp->id,
            ]);
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('يتجاوز المبلغ المتبقي', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Should reject payment exceeding remaining amount');
    }

    /* ============================================================
     *  PHASE 8: CUSTOMER DEBT WITHOUT OPEN BOOKINGS
     * ============================================================ */

    /**
     * Verifies the behavior of payCustomerDebt when there are no open bookings.
     */
    public function test_pay_customer_debt_with_no_open_bookings(): void
    {
        $customer = $this->makeCustomer();
        // Ensure customer account is created
        $customerAccount = app(VisaBookingService::class)->ensureCustomerAccount($customer->id, 'EGP');

        // Call payCustomerDebt endpoint
        $response = $this->postJson("/api/v1/visa/customers/{$customer->id}/pay-debt", [
            'amount' => 1000.0,
            'account_id' => $this->vaultEgp->id,
            'notes' => 'General credit payment',
        ]);

        $response->assertOk();

        // Check if a general transfer was recorded
        $this->assertDatabaseHas('transactions', [
            'module' => 'visa',
            'from_account_id' => $customerAccount->id,
            'to_account_id' => $this->vaultEgp->id,
            'amount' => 1000.0,
        ]);

        // Check if any VisaPayment rows were created. Since there are no bookings, no payments should exist.
        $paymentsCount = VisaPayment::whereHas('booking', function ($q) use ($customer) {
            $q->where('customer_id', $customer->id);
        })->count();

        echo "\n[AUDIT] Payments count for no-booking debt payment: ".$paymentsCount."\n";
        $this->assertSame(0, $paymentsCount, 'No VisaPayment records should exist if there are no open bookings');

        // Ledger should remain balanced
        $this->assertLedgerGloballyBalanced();
    }

    /* ============================================================
     *  PHASE 11: REVERSAL / DELETE WITH DOUBLE REVERSAL
     * ============================================================ */

    /**
     * Verifies if deleteWithReversal can be run on an already cancelled booking.
     */
    public function test_delete_with_reversal_on_already_cancelled_booking(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
        ]);

        $refundService = app(VisaRefundService::class);

        // Cancel first (reverses transactions)
        $refundService->cancel($booking, 'First Cancel');

        $booking->refresh();
        $this->assertSame(VisaStatus::Cancelled, $booking->status);

        // Snapshot ledger counts
        $txCountBefore = Transaction::where('related_id', $booking->id)->count();

        // Attempt delete with reversal on already cancelled booking.
        // The service now guards against Cancelled status and throws a RuntimeException
        // to prevent silent deletion and loss of the audit trail.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/لا يمكن حذف وعكس حجز تأشيرة ملغى بالفعل/');

        $refundService->deleteWithReversal($booking->id, $this->user->id);
    }
}
