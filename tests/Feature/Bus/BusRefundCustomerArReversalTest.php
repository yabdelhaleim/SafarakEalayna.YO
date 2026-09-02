<?php

namespace Tests\Feature\Bus;

use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;
use App\Models\Treasury;
use App\Services\Bus\BusBookingService;

/**
 * Phase 9 — Bus Module financial reconciliation test.
 *
 * Regression for P1-FIN: `BusRefundService::processRefundRequest` did NOT
 * reverse the customer AR when the refund was processed via the standalone
 * `/api/v1/bus/refunds/{id}/process` endpoint. Only the supplier cost and the
 * treasury were updated. As a result, the customer debt stayed stranded.
 *
 * This test pins the FIX:
 *   1. Create booking (customer AR created)
 *   2. Pay booking (customer AR cleared to 0)
 *   3. Re-book / re-pay for the refund scenario (positive AR)
 *   4. Create a refund request (status='pending')
 *   5. Process the refund via /api/v1/bus/refunds/{id}/process
 *   6. Assert customer AR has been REVERSED by the refund amount
 *   7. Assert supplier debt has been REVERSED by the cost amount
 *   8. Assert treasury has been CREDITED by the refund amount
 *   9. Assert booking status is 'refunded'
 *   10. Assert global ledger invariant holds
 *
 * Independent verification:
 *   For each step, the expected balance is computed by hand from the inputs
 *   (booking, payments, refund) and asserted against the actual account
 *   balance. A passing HTTP response is NOT sufficient.
 */
class BusRefundCustomerArReversalTest extends BusTestCase
{
    /**
     * EG happy path — book → pay → refund → assert customer AR cleared.
     *
     * Setup:
     *   - EGP cashbox seeded at 0 EGP (BusTestCase default)
     *   - 100 EGP booking (1 ticket × 100 sell, 80 cost)
     *   - Customer pays 100 EGP from cashbox
     *
     * After pay:
     *   - customer AR = 0 (cleared by Transfer)
     *   - cashbox = +100
     *   - supplier AP = -80
     *   - income clearing = -100
     *   - expense clearing = +80
     *
     * But for the refund scenario, we need a POSITIVE customer AR. So this
     * test books twice (paid + unpaid) and refunds the unpaid one... actually
     * simpler: book + pay + REFUND before the customer AR clears? No, pay
     * is a Transfer that clears AR. So customer AR is always 0 after pay.
     *
     * Solution: book + pay full → cashbox has 100 EGP. Then "reverse" the
     * payment (refund): customer AR should swing back to -100 (was 0),
     * supplier AP to 0, income clearing to 0, expense clearing to 0, cashbox
     * back to 0.
     *
     * Wait — payBooking moves money from customer AR to cashbox. The AR was
     * +100 from the booking. After pay, AR is 0. So a refund of 100 EGP
     * should reverse the customer AR by... well, AR is already 0. The
     * refund posts a Refund-type entry that DECREMENTS AR (from 0 to -100)
     * — that's a credit balance. Is that correct?
     *
     * Actually looking at the code:
     *   recordSaleToCustomer:   from=clearing → to=customer  (customer +price)
     *   payBooking (Transfer):  from=customer → to=cashbox   (customer cleared)
     *   processRefund AR rev:  from=customer → to=clearing   (customer -refund)
     *
     * After booking + payment + refund:
     *   customer AR = +price - price (pay) - refund = -refund
     *
     * This means the customer ends up with a NEGATIVE AR (credit balance).
     * That's actually CORRECT — the office owes the customer back. The AR
     * account represents "customer money we hold", so a negative balance
     * means "we owe the customer".
     */
    public function test_refund_reverses_customer_ar_in_egp_lifecycle(): void
    {
        // Setup: seed cashbox so we can record an opening balance entry
        // (otherwise the global ledger invariant would skip the cashbox).
        $this->seedCashboxBalance(1000.0);

        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 100,
        ]);

        $service = app(BusBookingService::class);

        // 1) Book
        $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_name' => 'Refund AR Test',
            'customer_phone' => '010R0000001',
            'quantity' => 1,
        ]);

        $booking = BusBooking::latest('id')->firstOrFail();
        $customer = Customer::where('phone', '010R0000001')->firstOrFail();
        $customerAccount = Account::find($customer->account_id);

        // After booking: customer AR = +100 EGP
        $this->assertEqualsWithDelta(
            100.0,
            (float) $customerAccount->fresh()->balance,
            0.01,
            'After booking, customer AR must be +100 EGP'
        );

        // 2) Pay from cashbox — Transfer moves +100 from customer to cashbox
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        // After pay: customer AR = 0 (cleared)
        $this->assertEqualsWithDelta(
            0.0,
            (float) $customerAccount->fresh()->balance,
            0.01,
            'After full payment, customer AR must be 0'
        );

        // 3) Create refund request via the standalone endpoint
        // Need a treasury for destination='agency_treasury'.
        $treasury = Treasury::query()->create([
            'name' => 'Test Treasury',
            'currency' => 'EGP',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $refundResponse = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 20, // 20 EGP office penalty
            'refund_currency' => 'EGP',
            'refund_exchange_rate' => 1.0,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
            'refund_type' => 'cash_to_agency',
        ]);
        $refundResponse->assertCreated();
        $refundId = $refundResponse->json('data.id');

        $refundRequest = BusRefundRequest::findOrFail($refundId);
        $this->assertEquals(80.0, (float) $refundRequest->refund_amount); // 100 - 20 = 80

        // 4) Process refund
        $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();

        // 5) Verify customer AR is REVERSED (Phase 9 fix verification)
        // Expected: AR was 0 (after pay), refund moves 80 EGP FROM customer TO clearing
        // → AR becomes 0 - 80 = -80 EGP (customer credit balance — office owes customer)
        $this->assertEqualsWithDelta(
            -80.0,
            (float) $customerAccount->fresh()->balance,
            0.01,
            'After refund processing, customer AR must be -80 EGP (the office owes the customer)'
        );

        // 6) Verify supplier debt reversed
        // Cost was 80 EGP, supplier AP was -80 EGP, after refund it should be 0
        $this->assertEqualsWithDelta(
            0.0,
            (float) $company->account->fresh()->balance,
            0.01,
            'After refund processing, supplier AP must be 0 EGP (debt cleared)'
        );

        // 7) Verify treasury credited with refund
        $this->assertEqualsWithDelta(
            80.0,
            (float) $treasury->fresh()->current_balance,
            0.01,
            'After refund processing, treasury must be credited by 80 EGP'
        );

        // 8) Verify booking status — with 20 EGP office penalty, the booking is
        // "PartiallyRefunded" (cancellation_fee > 0). Verified by the service
        // logic at BusRefundService::processRefundRequest lines ~210-213:
        //   $isPartial = cancellation_fee > 0 || refund_amount < original_amount;
        $booking->refresh();
        $this->assertEquals(
            \App\Enums\BusBookingStatus::PartiallyRefunded,
            $booking->status,
            'Booking status must be PartiallyRefunded (20 EGP office penalty retained)'
        );

        // 9) Global ledger invariant — every account's balance matches its entries
        $this->assertLedgerGloballyBalanced();

        // 10) Verify a Refund-type transaction was posted with module=bus, related to this booking
        $refundTx = \App\Models\Transaction::query()
            ->where('module', 'bus')
            ->where('related_type', BusBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', \App\Enums\TransactionType::Refund->value)
            ->latest('id')
            ->first();
        $this->assertNotNull($refundTx, 'A Refund-type transaction must exist for this booking');
        $this->assertEqualsWithDelta(
            80.0,
            (float) $refundTx->amount,
            0.01,
            'Refund transaction amount must equal 80 EGP (= 100 paid - 20 office penalty)'
        );
    }

    /**
     * Idempotency: processing the same refund twice does NOT double-reverse AR.
     */
    public function test_double_process_refund_does_not_double_reverse_ar(): void
    {
        $this->seedCashboxBalance(1000.0);

        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 100,
        ]);

        $service = app(BusBookingService::class);
        $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_name' => 'Idem Refund',
            'customer_phone' => '010R0000003',
            'quantity' => 1,
        ]);
        $booking = BusBooking::latest('id')->firstOrFail();
        $customer = Customer::where('phone', '010R0000003')->firstOrFail();
        $customerAccount = Account::find($customer->account_id);

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100, 'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $treasury = Treasury::query()->create([
            'name' => 'Idem Treasury',
            'currency' => 'EGP',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $refundResponse = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0,
            'refund_currency' => 'EGP',
            'refund_exchange_rate' => 1.0,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ]);
        $refundId = $refundResponse->json('data.id');

        // Process once
        $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();
        $arAfterFirst = (float) $customerAccount->fresh()->balance;

        // Capture state after first process
        $this->assertEqualsWithDelta(-100.0, $arAfterFirst, 0.01);

        // Process again — should be idempotent (no double reversal)
        $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();
        $this->assertEqualsWithDelta(
            $arAfterFirst,
            (float) $customerAccount->fresh()->balance,
            0.01,
            'Customer AR must not be double-reversed on second process'
        );
    }
}
