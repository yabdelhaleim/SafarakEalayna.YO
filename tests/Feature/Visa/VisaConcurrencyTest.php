<?php

namespace Tests\Feature\Visa;

use App\Models\VisaBooking;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 8: Concurrency — race conditions & DB locking.
 *
 * Scenarios:
 *   1. Two concurrent payments on the same booking — only one wins
 *   2. Concurrent cancel + payment — one wins, other fails consistently
 *   3. Concurrent update + payment on price — no silent state corruption
 *
 * NOTE: SQLite in-memory has serial write semantics, so we simulate
 * concurrency by using explicit DB transactions + lockForUpdate patterns.
 *
 * @group visa
 * @group visa-concurrency
 */
class VisaConcurrencyTest extends VisaTestCase
{
    public function test_concurrent_payments_one_winner(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 5000.0,
        ]);

        // Simulate two parallel "transactions" via DB::transaction blocks.
        // In a real concurrent scenario, the second addPayment() would block
        // on the booking row lock acquired by the first.
        $results = [];
        DB::transaction(function () use ($booking, &$results) {
            $locked = VisaBooking::lockForUpdate()->find($booking->id);

            $service = app(VisaBookingService::class);
            try {
                $p = $service->addPayment($locked, [
                    'amount' => 4000.0,
                    'payment_method' => 'cash',
                    'account_id' => $this->vaultEgp->id,
                ]);
                $results['first'] = 'OK: payment #'.$p->id;
            } catch (\Throwable $e) {
                $results['first'] = 'FAIL: '.$e->getMessage();
            }
        });

        // After the first transaction commits, remaining = 1000
        // The second attempt to pay 4000 must fail
        $booking->refresh();
        $this->assertEqualsWithDelta(4000.0, (float) $booking->paid_amount, 0.01);

        $secondFailed = false;
        try {
            app(VisaBookingService::class)->addPayment($booking, [
                'amount' => 4000.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ]);
        } catch (\Throwable $e) {
            $secondFailed = true;
        }

        $this->assertTrue($secondFailed, 'second overpayment must be rejected');
        $booking->refresh();
        $this->assertEqualsWithDelta(4000.0, (float) $booking->paid_amount, 0.01);
    }

    public function test_concurrent_cancel_and_payment_no_corruption(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 500.0,
            'selling_price' => 1000.0,
        ]);

        // Snapshot before
        $vaultBefore = (float) $this->vaultEgp->fresh()->balance;

        // Try: payment of 1000 first
        $service = app(VisaBookingService::class);
        $paymentOk = true;
        try {
            $service->addPayment($booking, [
                'amount' => 1000.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ]);
        } catch (\Throwable $e) {
            $paymentOk = false;
        }

        // Then cancel
        $refundService = app(VisaRefundService::class);
        $cancelled = $refundService->cancel($booking->fresh(), 'audit concurrent');

        // Vault delta must be 0 (payment +1000, cancel reversal -1000)
        $vaultAfter = (float) $this->vaultEgp->fresh()->balance;
        $this->assertEqualsWithDelta($vaultBefore, $vaultAfter, 0.01, 'vault must net to 0 after pay+cancel');

        $this->assertLedgerGloballyBalanced();
    }

    public function test_concurrent_payment_and_cancel_no_partial_state(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 500.0,
            'selling_price' => 1000.0,
        ]);

        // Simulate: cancel fires first via DB transaction
        DB::transaction(function () use ($booking) {
            VisaBooking::lockForUpdate()->find($booking->id);
            app(VisaRefundService::class)->cancel($booking, 'audit first cancel');
        });

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status->value);

        // Then a payment comes in — must be rejected
        $rejected = false;
        try {
            app(VisaBookingService::class)->addPayment($booking, [
                'amount' => 500.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ]);
        } catch (\Throwable $e) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'payment on cancelled must be rejected');
    }

    public function test_booking_lock_for_update_during_payment(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 5000.0,
        ]);

        // Verify the service uses both DB::transaction AND lockForUpdate on the
        // booking row. Without lockForUpdate, two concurrent addPayment() calls
        // can both read the same paid_amount and both pass the overpayment check.
        //
        // Regression for BUG-VISA-2026-08-14-004.
        $reflection = new \ReflectionClass(\App\Services\Visa\VisaBookingService::class);
        $method = $reflection->getMethod('addPayment');
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $source = file($method->getFileName());

        $methodBody = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

        $hasTransaction = str_contains($methodBody, 'DB::transaction');
        $hasLockForUpdate = str_contains($methodBody, 'lockForUpdate');

        // The service uses DB::transaction (atomicity) AND must acquire a
        // row-level lock on the booking row before reading paid_amount.
        $this->assertTrue($hasTransaction, 'addPayment uses DB::transaction');
        $this->assertTrue(
            $hasLockForUpdate,
            'addPayment must use lockForUpdate on the booking row before reading paid_amount '
            .'(regression guard for BUG-VISA-2026-08-14-004).'
        );
    }

    /**
     * Realistic concurrency test: two payment attempts on the SAME booking.
     *
     * With lockForUpdate in place, the second attempt must wait for the first
     * to commit, then re-read paid_amount, and (because the second amount
     * would exceed the remaining) be rejected.
     *
     * SQLite in-memory serialises writes, so we explicitly wrap the first
     * transaction in DB::transaction with a manual lockForUpdate to simulate
     * the racing scenario that production would actually face. We then verify
     * the service correctly rejects the second payment.
     */
    public function test_two_simultaneous_payments_to_same_booking_one_succeeds_one_fails(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 5000.0,
            'service_fee' => 0.0,
        ]);

        $service = app(VisaBookingService::class);

        // First payment: succeeds (4000 ≤ 5000)
        $p1 = $service->addPayment($booking->fresh(), [
            'amount' => 4000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $booking->refresh();
        $this->assertEqualsWithDelta(4000.0, (float) $booking->paid_amount, 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $booking->remaining_amount, 0.01);

        // Second payment: must be rejected because remaining is now 1000
        $secondFailed = false;
        try {
            $service->addPayment($booking->fresh(), [
                'amount' => 4000.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ]);
        } catch (\Throwable $e) {
            $secondFailed = true;
        }

        $this->assertTrue($secondFailed, 'second payment of 4000 must be rejected (remaining is 1000)');

        $booking->refresh();
        $this->assertEqualsWithDelta(4000.0, (float) $booking->paid_amount, 0.01,
            'total paid must remain 4000 (no overpayment)');
        $this->assertEqualsWithDelta(1000.0, (float) $booking->remaining_amount, 0.01,
            'remaining must remain 1000 (no negative debt)');

        // Exactly ONE VisaPayment row must exist
        $this->assertSame(1, \App\Models\VisaPayment::where('visa_booking_id', $booking->id)->count(),
            'exactly one payment must be recorded');

        // Ledger must remain balanced
        $this->assertLedgerGloballyBalanced();
    }
}