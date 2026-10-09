<?php

namespace Tests\Feature\TourismAudit;

use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\HajjUmra\HajjUmraRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Section 8 — Hajj/Umrah Full Audit.
 *
 * Tests:
 *  - Program + booking creation
 *  - Supplier attachment
 *  - Payments (single, partial, multiple, full)
 *  - Cancellation (HajjUmraBookingService::cancel)
 *  - Refund (HajjUmraRefundService::refund)
 *  - Idempotency-Key replay
 *  - Locked fields on update (selling_price, etc.)
 *  - Lifecycle guards (cannot pay/refund on cancelled)
 */
class HajjUmraFullAuditTest extends TourismAuditTestCase
{
    protected HajjUmraBookingService $bookingService;

    protected HajjUmraRefundService $refundService;

    protected Customer $customer;

    protected Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = app(HajjUmraBookingService::class);
        $this->refundService = app(HajjUmraRefundService::class);

        $this->customer = Customer::query()->create([
            'full_name' => 'Hajj Audit Customer',
            'phone' => '01300000001',
            'type' => 'individual',
            'status' => 'active',
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $this->program = Program::query()->create([
            'program_name' => 'Audit Hajj Program',
            'program_type' => 'hajj',
            'total_nights' => 14,
            'mecca_nights' => 8,
            'medina_nights' => 6,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'Audit Mecca Hotel',
            'medina_hotel_name' => 'Audit Medina Hotel',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(74)->toDateString(),
            'airline' => 'Audit Air',
            'executing_company' => 'Audit Executing Co',
            'departure_point' => 'CAI',
            'default_selling_price' => 80000.0,
            'default_purchase_price' => 70000.0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'customer' => [
                'full_name' => $this->customer->full_name,
                'phone' => $this->customer->phone,
            ],
            'customer_id' => $this->customer->id,
            'program_id' => $this->program->id,
            'selling_price' => 80000.0,
            'purchase_price' => 70000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'Audit Agent',
            'notes' => 'Audit 2026-08-17',
        ], $overrides);
    }

    /**
     * Booking creation happy path.
     */
    public function test_create_booking(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());
        $this->assertNotNull($booking);
        $this->assertSame(HajjUmraBooking::class, get_class($booking));
        $this->assertSame('confirmed', $booking->fresh()->status->value ?? (string) $booking->fresh()->status);

        // Verify transaction + account entries were created
        $this->assertNotNull($booking->fresh()->expense_transaction_id);
        $this->assertNotNull($booking->fresh()->income_transaction_id);

        $this->assertLedgerGloballyBalanced();
    }

    // test_update_blocks_locked_selling_price / test_update_blocks_locked_purchase_price
// — REMOVED (INCIDENT-2026-08-17 Tourism no-edit contract)
//   Both tests asserted that updating a locked financial field throws an exception.
//   With Edit permanently disabled at the Service layer (LogicException stub), the
//   premise is moot — no Edit path exists at all. Cancellation is the correction path.

/**
     * Idempotency — payment replay.
     */
    public function test_payment_idempotency_key_replay(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());
        $key = 'audit-hajj-idem-'.uniqid();

        $first = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 10000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => $key,
        ]);

        $this->assertFalse($first->idempotent_replay ?? false);

        $second = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 10000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => $key,
        ]);

        $this->assertTrue($second->idempotent_replay ?? false);
        $this->assertSame($first->id, $second->id);

        $this->assertSame(1, HajjUmraPayment::query()->where('hajj_umra_booking_id', $booking->id)->count());
        $this->assertLedgerGloballyBalanced();
    }

    public function test_payment_different_idempotency_keys_create_distinct_payments(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());

        $first = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 10000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => 'audit-hajj-key-A',
        ]);

        $second = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 20000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => 'audit-hajj-key-B',
        ]);

        $this->assertNotSame($first->id, $second->id);
    }

    /**
     * Multiple payments sum correctly.
     */
    public function test_multiple_payments(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());

        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 30000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 30000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'bank_transfer',
            'currency' => 'EGP',
        ]);

        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 20000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $this->assertEquals(80000.0, round((float) $booking->fresh()->paid_amount, 2));
        $this->assertTrue($booking->fresh()->is_fully_paid);
        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Cancellation — additive reversal.
     */
    public function test_cancellation_additive_reversal(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());

        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 30000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $originalExpenseId = $booking->fresh()->expense_transaction_id;
        $originalIncomeId = $booking->fresh()->income_transaction_id;

        $this->bookingService->cancel($booking->fresh(), 'Audit cancel');

        $fresh = $booking->fresh();
        $this->assertSame('cancelled', $fresh->status->value ?? (string) $fresh->status);

        // Original transactions must still exist (additive reversal)
        $this->assertNotNull(\App\Models\Transaction::query()->find($originalExpenseId));
        $this->assertNotNull(\App\Models\Transaction::query()->find($originalIncomeId));

        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Cancellation blocks double cancel.
     */
    public function test_double_cancel_blocked(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());
        $this->bookingService->cancel($booking->fresh(), 'First cancel');

        $this->expectException(\Exception::class);
        $this->bookingService->cancel($booking->fresh(), 'Second cancel');
    }

    /**
     * Refund — additive reversal with status=refunded.
     */
    public function test_refund_additive_reversal(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());

        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 40000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $originalExpenseId = $booking->fresh()->expense_transaction_id;
        $originalIncomeId = $booking->fresh()->income_transaction_id;

        $this->refundService->refund($booking->fresh(), 'Audit refund');

        $fresh = $booking->fresh();
        $this->assertSame('refunded', $fresh->status->value ?? (string) $fresh->status);

        // Original transactions must still exist
        $this->assertNotNull(\App\Models\Transaction::query()->find($originalExpenseId));
        $this->assertNotNull(\App\Models\Transaction::query()->find($originalIncomeId));

        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Refund on cancelled booking is blocked.
     */
    public function test_refund_on_cancelled_blocked(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());
        $this->bookingService->cancel($booking->fresh(), 'Cancel first');

        $this->expectException(\Exception::class);
        $this->refundService->refund($booking->fresh(), 'Refund attempt');
    }

    /**
     * Payment on cancelled booking is blocked.
     */
    public function test_payment_on_cancelled_blocked(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());
        $this->bookingService->cancel($booking->fresh(), 'Cancel first');

        $this->expectException(\Exception::class);
        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 100.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);
    }

    /**
     * All Hajj/Umrah transactions tagged as Tourism.
     */
    public function test_all_transactions_tagged_as_tourism(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());
        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 10000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $paymentId = HajjUmraPayment::query()->where('hajj_umra_booking_id', $booking->id)->first()?->id ?? 0;

        $transactions = \App\Models\Transaction::query()
            ->where(function ($q) use ($booking, $paymentId) {
                $q->where(function ($q2) use ($booking) {
                    $q2->where('related_type', HajjUmraBooking::class)->where('related_id', $booking->id);
                });
                if ($paymentId) {
                    $q->orWhere(function ($q2) use ($paymentId) {
                        $q2->where('related_type', HajjUmraPayment::class)->where('related_id', $paymentId);
                    });
                }
            })
            ->get();

        $this->assertGreaterThan(0, $transactions->count());
        foreach ($transactions as $tx) {
            $this->assertTransactionIsTourism($tx);
        }
    }

    /**
     * Customer account uses Hajj/Umrah module_type after booking.
     */
    public function test_customer_account_module_type_hajj_umra(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());

        $customerAccount = $this->customer->fresh()->ledgerAccount;
        $this->assertNotNull($customerAccount);
        $this->assertSame('hajj_umra', $customerAccount->fresh()->module_type);
    }
}
