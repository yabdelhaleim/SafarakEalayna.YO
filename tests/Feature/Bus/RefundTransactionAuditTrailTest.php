<?php

namespace Tests\Feature\Bus;

use App\Enums\BusInventoryPaymentType;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;
use App\Services\Bus\BusBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Tests for Fix #12: BusRefundRequest.transaction_id is nulled out after
 * deleteBookingWithReversal() reverses the associated payment transactions.
 *
 * Background: after reversal, the original transaction still exists in the DB
 * but carries a net-balance effect of zero (the reversal is additive). Any
 * BusRefundRequest still pointing to it would show a misleading audit link
 * ("Refund #X -> Transaction #Y (reversed)"). The fix sets transaction_id=null
 * on all linked refund requests inside the reversal transaction.
 */
class RefundTransactionAuditTrailTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // 1 — After deleteBookingWithReversal, refund request transaction_id = null
    // ─────────────────────────────────────────────────────────────────────────

    public function test_refund_request_transaction_id_nulled_after_reversal(): void
    {
        $service = app(BusBookingService::class);
        $this->seedCashboxBalance(50000);

        $company   = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id'        => $company->id,
            'total_tickets'     => 10,
            'available_tickets' => 10,
            'cost_per_ticket'   => 50,
            'selling_price'     => 100,
            'payment_type'      => BusInventoryPaymentType::Deferred->value,
            'currency'          => 'EGP',
        ]);

        $customer = Customer::factory()->create(['phone' => '01002000001']);
        $booking  = $service->createBooking([
            'inventory_id'   => $inventory->id,
            'customer_id'    => $customer->id,
            'customer_name'  => 'Audit Trail Test',
            'customer_phone' => '01002000001',
            'quantity'       => 1,
        ]);

        // Pay the booking so a payment transaction exists
        $service->payBooking($booking, [
            'amount'         => 100.0,
            'payment_method' => 'cash',
            'account_id'     => $this->cashboxEgp->id,
        ]);

        $booking->refresh();
        $payment = $booking->payments()->first();
        $this->assertNotNull($payment->transaction_id, 'Payment should have a transaction_id after payBooking');

        // Create a BusRefundRequest that points to the payment transaction
        $refundRequest = LedgerBalanceMutationGuard::run(fn () => BusRefundRequest::create([
            'bus_booking_id'      => $booking->id,
            'company_id'          => $company->id,
            'refund_type'         => 'cash_to_agency',
            'original_currency'   => 'EGP',
            'original_amount'     => 100.0,
            'cancellation_fee'    => 0,
            'refund_amount'       => 100.0,
            'refund_currency'     => 'EGP',
            'refund_exchange_rate'=> 1.0,
            'base_currency_refund'=> 100.0,
            'destination'         => 'agency_treasury',
            'status'              => 'pending',
            'transaction_id'      => $payment->transaction_id, // points to the payment tx
            'created_by'          => $this->user->id,
        ]));

        $this->assertNotNull($refundRequest->transaction_id,
            'Pre-condition: refund request transaction_id should be set');

        // Delete the booking with full reversal
        $service->deleteBookingWithReversal($booking->id, $this->user->id);

        // POSTCONDITION: the refund request transaction_id must be null
        $refundRequest->refresh();
        $this->assertNull($refundRequest->transaction_id,
            'Fix #12: deleteBookingWithReversal must null-out stale BusRefundRequest.transaction_id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2 — Refund request itself is NOT deleted (only transaction_id is cleared)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_refund_request_row_preserved_after_reversal(): void
    {
        $service = app(BusBookingService::class);
        $this->seedCashboxBalance(50000);

        $company   = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id'        => $company->id,
            'total_tickets'     => 10,
            'available_tickets' => 10,
            'cost_per_ticket'   => 40,
            'selling_price'     => 80,
            'payment_type'      => BusInventoryPaymentType::Deferred->value,
            'currency'          => 'EGP',
        ]);

        $customer = Customer::factory()->create(['phone' => '01002000002']);
        $booking  = $service->createBooking([
            'inventory_id'   => $inventory->id,
            'customer_id'    => $customer->id,
            'customer_name'  => 'Row Preserved Test',
            'customer_phone' => '01002000002',
            'quantity'       => 1,
        ]);

        $service->payBooking($booking, [
            'amount'         => 80.0,
            'payment_method' => 'cash',
            'account_id'     => $this->cashboxEgp->id,
        ]);

        $booking->refresh();
        $payment = $booking->payments()->first();

        $refundRequest = LedgerBalanceMutationGuard::run(fn () => BusRefundRequest::create([
            'bus_booking_id'      => $booking->id,
            'company_id'          => $company->id,
            'refund_type'         => 'cash_to_agency',
            'original_currency'   => 'EGP',
            'original_amount'     => 80.0,
            'cancellation_fee'    => 0,
            'refund_amount'       => 80.0,
            'refund_currency'     => 'EGP',
            'refund_exchange_rate'=> 1.0,
            'base_currency_refund'=> 80.0,
            'destination'         => 'agency_treasury',
            'status'              => 'pending',
            'transaction_id'      => $payment->transaction_id,
            'created_by'          => $this->user->id,
        ]));

        $refundRequestId = $refundRequest->id;

        $service->deleteBookingWithReversal($booking->id, $this->user->id);

        // The refund request row must still exist (only transaction_id was cleared)
        $found = BusRefundRequest::withTrashed()->find($refundRequestId);
        $this->assertNotNull($found,
            'The BusRefundRequest row must still exist after deleteBookingWithReversal');
        $this->assertNull($found->transaction_id,
            'transaction_id must be null after reversal');
        $this->assertEquals('pending', $found->status,
            'Status must remain unchanged — only transaction_id is cleared');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3 — Booking with no linked refund requests: reversal succeeds silently
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reversal_without_refund_requests_succeeds(): void
    {
        $service = app(BusBookingService::class);
        $this->seedCashboxBalance(50000);

        $company   = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id'        => $company->id,
            'total_tickets'     => 10,
            'available_tickets' => 10,
            'cost_per_ticket'   => 60,
            'selling_price'     => 120,
            'payment_type'      => BusInventoryPaymentType::Deferred->value,
            'currency'          => 'EGP',
        ]);

        $customer = Customer::factory()->create(['phone' => '01002000003']);
        $booking  = $service->createBooking([
            'inventory_id'   => $inventory->id,
            'customer_id'    => $customer->id,
            'customer_name'  => 'No Refund Requests',
            'customer_phone' => '01002000003',
            'quantity'       => 1,
        ]);

        $service->payBooking($booking, [
            'amount'         => 120.0,
            'payment_method' => 'cash',
            'account_id'     => $this->cashboxEgp->id,
        ]);

        // No BusRefundRequest created deliberately — just make sure reversal is fine
        $result = $service->deleteBookingWithReversal($booking->id, $this->user->id);
        $this->assertTrue($result, 'Reversal must succeed even with no linked refund requests');

        $booking->refresh();
        $this->assertSoftDeleted($booking);
    }
}
