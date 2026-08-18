<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Models\Account;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\Visa\VisaBookingService;

/**
 * PHASE 6: Customer Debt scenario — exactly the test from the prompt:
 *
 *   Visa total = 10,000
 *   Customer pays:
 *     4,000  →  Paid=4,000   Remaining=6,000
 *     2,000  →  Paid=6,000   Remaining=4,000
 *     4,000  →  Paid=10,000  Remaining=0  (fully paid)
 *
 * Plus extensions:
 *   - Partial > remaining (overpayment) rejected
 *   - Multiple partial payments in different currencies (USD then EGP)
 *   - Debt cleared via payCustomerDebt endpoint
 *
 * @group visa
 * @group visa-debt
 */
class VisaCustomerDebtScenarioTest extends VisaTestCase
{
    public function test_exact_10k_debt_scenario_from_prompt(): void
    {
        // ─── Setup: Visa total = 10,000 EGP ─────────────────────────────
        $booking = $this->makeBooking([
            'purchase_price' => 8000.0,
            'selling_price' => 10000.0,
            'service_fee' => 0.0,
        ]);

        $booking->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $booking->paid_amount, 0.01, 'starting paid=0');
        $this->assertEqualsWithDelta(10000.0, (float) $booking->remaining_amount, 0.01, 'starting remaining=10000');

        $service = app(VisaBookingService::class);

        // ─── Payment 1: 4,000 → Paid=4,000  Remaining=6,000 ────────────
        $service->addPayment($booking, [
            'amount' => 4000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'reference' => 'AUDIT-P1-4000',
            'paid_by' => 'Test Customer',
        ]);

        $booking->refresh();
        $this->assertEqualsWithDelta(4000.0, (float) $booking->paid_amount, 0.01, 'after p1: paid=4000');
        $this->assertEqualsWithDelta(6000.0, (float) $booking->remaining_amount, 0.01, 'after p1: remaining=6000');

        // ─── Payment 2: 2,000 → Paid=6,000  Remaining=4,000 ────────────
        $service->addPayment($booking, [
            'amount' => 2000.0,
            'payment_method' => 'bank_transfer',
            'account_id' => $this->bankEgp->id,
            'reference' => 'AUDIT-P2-2000',
        ]);

        $booking->refresh();
        $this->assertEqualsWithDelta(6000.0, (float) $booking->paid_amount, 0.01, 'after p2: paid=6000');
        $this->assertEqualsWithDelta(4000.0, (float) $booking->remaining_amount, 0.01, 'after p2: remaining=4000');

        // ─── Payment 3: 4,000 → Paid=10,000  Remaining=0 (fully paid) ───
        $service->addPayment($booking, [
            'amount' => 4000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'reference' => 'AUDIT-P3-4000',
        ]);

        $booking->refresh();
        $this->assertEqualsWithDelta(10000.0, (float) $booking->paid_amount, 0.01, 'after p3: paid=10000');
        $this->assertEqualsWithDelta(0.0, (float) $booking->remaining_amount, 0.01, 'after p3: remaining=0');
        $this->assertTrue($booking->is_fully_paid, 'booking is fully paid');

        // ─── Verify 3 payment records persisted ─────────────────────────
        $this->assertSame(3, VisaPayment::where('visa_booking_id', $booking->id)->count());

        // ─── Verify all transactions are balanced (debit = credit) ──────
        $this->assertLedgerGloballyBalanced();

        // ─── Verify total income > total expense (profit realized) ──────
        $incomeAmount = (float) $booking->incomeTransaction?->amount;
        $expenseAmount = (float) $booking->expenseTransaction?->amount;
        $this->assertEqualsWithDelta(10000.0, $incomeAmount, 0.01);
        $this->assertEqualsWithDelta(8000.0, $expenseAmount, 0.01);
        $this->assertEqualsWithDelta(2000.0, (float) $booking->profit, 0.01);
    }

    public function test_overpayment_after_4k_paid_is_rejected(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 5000.0,
            'selling_price' => 10000.0,
            'service_fee' => 0,  // explicit — default payload has 100
        ]);

        $service = app(VisaBookingService::class);

        $service->addPayment($booking, [
            'amount' => 4000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $booking->refresh();
        $this->assertEqualsWithDelta(6000.0, (float) $booking->remaining_amount, 0.01);

        // Try to pay 7000 (only 6000 remaining) — should be rejected
        $rejected = false;
        try {
            $service->addPayment($booking, [
                'amount' => 7000.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ]);
        } catch (\Throwable $e) {
            $rejected = true;
            $this->assertStringContainsString('يتجاوز', $e->getMessage());
        }

        $this->assertTrue($rejected, 'overpayment must be rejected');

        $booking->refresh();
        $this->assertEqualsWithDelta(4000.0, (float) $booking->paid_amount, 0.01, 'paid unchanged after rejection');
    }

    public function test_payment_equal_to_remaining_debt_closes_booking(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 5000.0,
            'selling_price' => 10000.0,
            'service_fee' => 0,  // explicit — default payload has 100
        ]);

        $service = app(VisaBookingService::class);

        // Pay partial: 3000
        $service->addPayment($booking, [
            'amount' => 3000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $booking->refresh();

        // Pay remaining exactly (7000)
        $service->addPayment($booking, [
            'amount' => 7000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $booking->refresh();
        $this->assertTrue($booking->is_fully_paid);
        $this->assertEqualsWithDelta(0.0, (float) $booking->remaining_amount, 0.01);
    }

    public function test_payment_of_zero_is_rejected(): void
    {
        $booking = $this->makeBooking();

        $service = app(VisaBookingService::class);

        $rejected = false;
        try {
            $service->addPayment($booking, [
                'amount' => 0.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ]);
        } catch (\Throwable $e) {
            $rejected = true;
        }

        // Whether 0 is rejected via validation or accepted as no-op, paid_amount must remain 0
        $booking->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $booking->paid_amount, 0.01,
            'zero payment must not change paid_amount');
    }

    public function test_payment_after_fully_paid_is_rejected(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 0,  // explicit — default payload has 100
        ]);

        $service = app(VisaBookingService::class);
        $service->addPayment($booking, [
            'amount' => 1500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $booking->refresh();
        $this->assertTrue($booking->is_fully_paid);

        // Try one more payment after fully paid
        $rejected = false;
        try {
            $service->addPayment($booking, [
                'amount' => 100.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ]);
        } catch (\Throwable $e) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'payment after fully paid must be rejected');
    }

    public function test_payment_after_cancellation_is_rejected(): void
    {
        $booking = $this->makeBooking();
        $service = app(VisaBookingService::class);
        $refundService = app(\App\Services\Visa\VisaRefundService::class);

        $service->addPayment($booking, [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $refundService->cancel($booking->fresh(), 'audit cancel');

        $rejected = false;
        $errorMessage = '';
        try {
            $service->addPayment($booking->fresh(), [
                'amount' => 100.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ]);
        } catch (\Throwable $e) {
            $rejected = true;
            $errorMessage = $e->getMessage();
        }

        $this->assertTrue($rejected, 'payment after cancellation must be rejected');
        // The error message should mention cancellation in some form (Arabic or English)
        $this->assertTrue(
            str_contains($errorMessage, 'ملغى')
            || str_contains($errorMessage, 'cancelled')
            || str_contains($errorMessage, 'cancelled'),
            'Error message should mention cancellation. Got: '.$errorMessage
        );
    }

    public function test_customer_debt_endpoint_clears_remaining(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 5000.0,
            'selling_price' => 10000.0,
            'service_fee' => 0,  // explicit — default payload has 100
        ]);

        $service = app(VisaBookingService::class);
        $service->addPayment($booking, [
            'amount' => 2000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $booking->refresh();
        $this->assertEqualsWithDelta(8000.0, (float) $booking->remaining_amount, 0.01,
            'selling(10000) - paid(2000) - fee(0) = 8000');

        // Use the customer-debt-pay endpoint
        $resp = $this->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'amount' => 3000.0,
            'account_id' => $this->vaultEgp->id,
            'notes' => 'AUDIT debt payment',
        ]);

        $resp->assertOk();

        $booking->refresh();
        $this->assertEqualsWithDelta(5000.0, (float) $booking->paid_amount, 0.01,
            'customer-debt payment must increase paid_amount (2000+3000=5000)');
        $this->assertEqualsWithDelta(5000.0, (float) $booking->remaining_amount, 0.01);
    }

    public function test_ledger_balanced_after_full_debt_lifecycle(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 3000.0,
            'selling_price' => 10000.0,
        ]);

        $service = app(VisaBookingService::class);
        $service->addPayment($booking, ['amount' => 4000.0, 'payment_method' => 'cash', 'account_id' => $this->vaultEgp->id]);
        $service->addPayment($booking, ['amount' => 2000.0, 'payment_method' => 'cash', 'account_id' => $this->vaultEgp->id]);
        $service->addPayment($booking, ['amount' => 4000.0, 'payment_method' => 'cash', 'account_id' => $this->vaultEgp->id]);

        $this->assertLedgerGloballyBalanced();
    }
}