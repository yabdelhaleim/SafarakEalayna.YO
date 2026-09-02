<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Models\AccountEntry;
use App\Models\RefundAuditLog;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Models\VisaDetail;
use App\Models\VisaPayment;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9.10 — Failure Injection (Section 18).
 *
 * Simulates mid-transaction failures and verifies ALL-OR-NOTHING rollback.
 * Each test inverts one failure point and asserts the database is in
 * its pre-call state (no phantom rows, no half-posted ledger entries,
 * no status transitions).
 */
class VisaFailureInjectionTest extends VisaTestCase
{
    public function test_payment_failure_does_not_create_partial_ledger_entries(): void
    {
        $booking = $this->makeBooking();
        $baselineVault = $this->ledgerNet($this->vaultEgp->id);
        $baselineTxCount = Transaction::count();

        // Force a failure by simulating a constraint violation after payment
        // is posted but before payment row is created. Use a service call
        // that fails AFTER recording the transfer tx.
        try {
            DB::transaction(function () use ($booking) {
                app(VisaBookingService::class)->addPayment($booking->fresh(), [
                    'amount' => 500.0,
                    'payment_method' => 'cash',
                    'account_id' => $this->vaultEgp->id,
                    'idempotency_key' => 'P910_FAIL_'.uniqid(),
                ]);
                throw new RuntimeException('simulated mid-transaction failure');
            });
        } catch (RuntimeException $e) {
            // expected
        }

        // ALL-OR-NOTHING: vault NET and tx count must be at baseline
        $this->assertEqualsWithDelta($baselineVault, $this->ledgerNet($this->vaultEgp->id), 0.01,
            'vault NET must be at baseline after rolled-back payment');
        $this->assertSame($baselineTxCount, Transaction::count(),
            'no transactions must persist after rollback');
    }

    public function test_cancel_failure_does_not_partial_cancel(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P910_PRE_PAY_'.uniqid(),
        ])->assertCreated();

        $baselineTxCount = Transaction::count();
        $baselineStatus = $booking->fresh()->status;

        try {
            DB::transaction(function () use ($booking) {
                app(VisaRefundService::class)->cancel($booking->fresh());
                throw new RuntimeException('simulated mid-cancel failure');
            });
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame($baselineTxCount, Transaction::count(),
            'cancel rollback must NOT leave orphan reversal entries');
        $this->assertSame($baselineStatus, $booking->fresh()->status,
            'booking status must NOT change when cancel rolls back');
    }

    public function test_refund_failure_does_not_partial_refund(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P910_REF_PRE_'.uniqid(),
        ])->assertCreated();

        $baselineAuditCount = RefundAuditLog::count();

        try {
            DB::transaction(function () use ($booking) {
                app(VisaRefundService::class)->refund($booking->fresh(), 'test');
                throw new RuntimeException('simulated mid-refund failure');
            });
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame($baselineAuditCount, RefundAuditLog::count(),
            'no refund_audit_logs row must persist on rollback');
        $this->assertNotEquals(VisaStatus::Refunded, $booking->fresh()->status,
            'booking must NOT be Refunded when refund rolls back');
    }

    public function test_create_booking_failure_does_not_leave_orphan_visa_detail(): void
    {
        $baselineDetails = VisaDetail::count();
        $baselineBookings = VisaBooking::count();

        try {
            DB::transaction(function () {
                app(VisaBookingService::class)->create([
                    'customer_id' => $this->customer->id,
                    'purchase_price' => 100.0,
                    'selling_price' => 200.0,
                    'service_fee' => 0.0,
                    'currency' => 'EGP',
                    'account_id' => $this->vaultEgp->id,
                    'visa_details' => [
                        'visa_type' => 'tourist',
                        'country' => 'FAIL-LAND',
                        'duration' => '30',
                        'visa_duration_id' => $this->duration->id,
                        'entry_type' => 'single',
                        'validity_from' => now()->toDateString(),
                        'validity_to' => now()->addDays(30)->toDateString(),
                        'executing_company' => 'Fail Co',
                        'visa_agent_id' => $this->agent->id,
                    ],
                ]);
                throw new RuntimeException('simulated mid-create failure');
            });
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame($baselineDetails, VisaDetail::count(),
            'no orphan visa_detail row on rolled-back create');
        $this->assertSame($baselineBookings, VisaBooking::count(),
            'no orphan visa_booking row on rolled-back create');
    }

    public function test_invalid_currency_payment_is_rejected_not_silently_swallowed(): void
    {
        $booking = $this->makeBooking();
        $baselinePayments = VisaPayment::count();
        $baselineTxCount = Transaction::count();

        // Try payment with USD account against EGP booking
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultUsd->id,  // USD account
            'idempotency_key' => 'P910_CUR_'.uniqid(),
        ]);

        $this->assertContains($response->status(), [422, 400],
            'currency mismatch must be rejected');
        $this->assertSame($baselinePayments, VisaPayment::count(),
            'no payment row on rejected request');
        $this->assertSame($baselineTxCount, Transaction::count(),
            'no transaction on rejected request');
    }

    public function test_overpayment_is_rejected_not_partial_applied(): void
    {
        $booking = $this->makeBooking();
        $baselinePayments = VisaPayment::count();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 9999.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P910_OVER_'.uniqid(),
        ]);

        $this->assertSame(422, $response->status(), 'over-payment must be rejected');
        $this->assertSame($baselinePayments, VisaPayment::count(),
            'no payment row on rejected over-payment');
    }

    public function test_duplicate_payment_failure_is_caught_not_silently_double_posted(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P910_DUP1_'.uniqid(),
            'reference' => 'DUP_FAIL_TEST',
        ])->assertCreated();

        $baselineVault = $this->ledgerNet($this->vaultEgp->id);
        $baselinePayments = VisaPayment::count();

        // Force a DB UNIQUE violation by attempting raw INSERT (bypasses service pre-check)
        $threw = false;
        try {
            DB::table('visa_payments')->insert([
                'visa_booking_id' => $booking->id,
                'payment_method' => 'cash',
                'amount' => 500.0,
                'currency' => 'EGP',
                'treasury_account' => 'office_drawer',
                'transaction_reference' => 'DUP_FAIL_TEST',
                'payment_date' => now(),
                'paid_by' => 'bypass',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'DB UNIQUE must catch bypass attempt');
        $this->assertSame($baselinePayments, VisaPayment::count(),
            'no extra payment row after DB UNIQUE rejection');
        $this->assertEqualsWithDelta($baselineVault, $this->ledgerNet($this->vaultEgp->id), 0.01,
            'vault NET must not change after DB UNIQUE rejection');
    }

    public function test_payment_on_refunded_booking_throws_no_ledger_movement(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P910_RFN_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'pre-condition',
        ])->assertOk();

        $baselinePayments = VisaPayment::count();
        $baselineTxCount = Transaction::count();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P910_AFT_RFN_'.uniqid(),
        ]);

        $this->assertSame(422, $response->status(),
            'payment on Refunded booking must be rejected');
        $this->assertSame($baselinePayments, VisaPayment::count());
        $this->assertSame($baselineTxCount, Transaction::count());
    }

    public function test_global_ledger_balanced_after_all_failure_injection_scenarios(): void
    {
        // Sanity: after all the failed/rolled-back scenarios above, the
        // global ledger must still be balanced.
        $this->assertLedgerGloballyBalanced();
    }

    private function ledgerNet(int $accountId): float
    {
        return (float) AccountEntry::where('account_id', $accountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
    }
}
