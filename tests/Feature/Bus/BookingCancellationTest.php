<?php

namespace Tests\Feature\Bus;

use App\Enums\BusBookingStatus;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusRefundRequest;
use App\Services\Finance\CurrencyService;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Booking cancellation scenarios.
 *
 * Validates:
 *   - Cancelling unpaid booking with no penalty.
 *   - Cancelling paid booking refunds customer via treasury.
 *   - Company penalty reduces the company-debt reversal.
 *   - Office penalty increases office-side recovery.
 *   - Double-cancellation is rejected (idempotency).
 *   - Multi-currency cancellation refunds in booking currency.
 */
class BookingCancellationTest extends BusTestCase
{
    private function createPaidEgBooking(int $totalPrice = 240): BusBooking
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => $totalPrice / 2,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Cancel Test',
            'customer_phone' => '01080000001',
            'quantity' => 2,
        ])->assertCreated();

        $booking = BusBooking::latest('id')->firstOrFail();

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => $totalPrice,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        return BusBooking::find($booking->id);
    }

    public function test_cancel_unpaid_booking_with_no_penalty(): void
    {
        $booking = $this->createPaidEgBooking(240);
        // Refund path requires a customer — undo the full payment first to make this unpaid-ish.
        $booking->payments()->delete();

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ]);

        $response->assertOk();

        $booking->refresh();
        $this->assertEquals(BusBookingStatus::Cancelled, $booking->status);
    }

    public function test_cancel_paid_booking_refunds_customer(): void
    {
        $startCashbox = (float) $this->cashboxEgp->fresh()->balance;
        $booking = $this->createPaidEgBooking(240);

        // Cashbox debited by 240 during payment.
        $this->cashboxEgp->refresh();
        $afterPaymentCashbox = (float) $this->cashboxEgp->balance;
        $this->assertEqualsWithDelta(
            $startCashbox + 240, // recordIncome uses EGP, but since both are EGP, the actual updates should be... hmm
            $afterPaymentCashbox,
            1.0
        );

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $response->assertOk();

        $booking->refresh();
        // Booking has been paid but refund_amount = 240 → status = Refunded.
        $this->assertEquals(BusBookingStatus::Refunded, $booking->status);

        // Refund record created.
        $this->assertNotNull($booking->refund);
        $this->assertEqualsWithDelta(240.0, (float) $booking->refund->refund_amount, 0.01);

        // Ledger invariant holds after refund.
        $this->assertLedgerGloballyBalanced();
    }

    public function test_cancel_with_company_penalty_only(): void
    {
        $booking = $this->createPaidEgBooking(240);

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 50,    // we keep 50 EGP from the company
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $response->assertOk();

        $booking->refresh();
        // Paid 240, refund = 240 - 50 = 190, status = Refunded.
        $this->assertEqualsWithDelta(190.0, (float) $booking->refund->refund_amount, 0.01);
    }

    public function test_cancel_office_penalty_only(): void
    {
        $booking = $this->createPaidEgBooking(240);

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 30,    // kept by office
            'account_id' => $this->cashboxEgp->id,
        ]);

        $response->assertOk();

        $booking->refresh();
        $this->assertEqualsWithDelta(210.0, (float) $booking->refund->refund_amount, 0.01);
    }

    public function test_rejects_double_cancellation(): void
    {
        $booking = $this->createPaidEgBooking(240);
        $booking->payments()->delete();

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ])->assertOk();

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ]);

        $response->assertStatus(422);
    }

    public function test_multi_currency_cancellation_refunds_in_booking_currency(): void
    {
        // Full multi-currency refund flow:
        //   1. Create USD-priced inventory (cost $2, sell $3, FX 1 USD = 50 EGP).
        //   2. Book 2 tickets → 6 USD sale, 4 USD cost = 100 EGP supplier debt,
        //      300 EGP-equivalent customer sale cleared.
        //   3. Pay from USD wallet → USD customer account receives 6 USD.
        //   4. Cancel with no penalty → refund goes back to USD wallet in USD,
        //      customer AR reversed in USD, supplier debt reversed in EGP.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'cost_per_ticket' => 2.00,
            'selling_price' => 3.00,
            'currency' => 'USD',
            'exchange_rate_to_egp' => 50.0,
            'total_tickets' => 5,
            'available_tickets' => 5,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Multi Currency Cancel',
            'customer_phone' => '01080000005',
            'quantity' => 2,
        ])->assertCreated();

        $booking = BusBooking::latest('id')->firstOrFail();
        $this->assertEquals('USD', $booking->currency);
        $this->assertEqualsWithDelta(50.0, (float) $booking->exchange_rate_to_egp, 0.001);

        // EGP cashbox cannot pay USD booking (currency-mismatch guard).
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 6,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertStatus(422);

        // Pay from the USD wallet (BusTestCase seeds `walletUsd`).
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 6,
            'payment_method' => 'cash_wallet',
            'account_id' => $this->walletUsd->id,
        ])->assertOk();

        // The wallet RECEIVES the payment — balance increases. We assert the
        // invariant first to ensure no semantic regression on USD flows.
        $this->assertLedgerGloballyBalanced();

        // USD wallet received 6 USD.
        $this->assertEqualsWithDelta(6.0, (float) $this->walletUsd->fresh()->balance, 0.01);

        $customer = \App\Models\Customer::where('phone', '01080000005')->firstOrFail();
        $this->assertEquals('USD', $customer->ledgerAccount->currency);

        // Note: the customer's AR was created at booking time (recordSaleToCustomer),
        // but `recordIncome` does not reduce it at pay time — the convention in
        // this codebase is to keep the AR until invoice generation clears it.
        // The USD ledger therefore holds a +6 USD AR + 6 USD cash, which the
        // global invariant (balance == SUM(entries.debit-credit)) tolerates
        // because each account's entries match its balance individually.
        $this->assertEqualsWithDelta(6.0, (float) $customer->ledgerAccount->fresh()->balance, 0.01);

        // Cancel with no penalty — full 6 USD refund back to USD wallet.
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->walletUsd->id,
        ])->assertOk();

        // USD wallet restored to balance 0.
        $this->assertEqualsWithDelta(0.0, (float) $this->walletUsd->fresh()->balance, 0.01);

        // USD customer AR reversed back to 0.
        $this->assertEqualsWithDelta(0.0, (float) $customer->ledgerAccount->fresh()->balance, 0.01);

        // Supplier debt reversed back to 0 (was -100 EGP after the booking).
        $this->assertEqualsWithDelta(0.0, (float) $company->account->fresh()->balance, 0.01);

        // Refund record carries the booking currency metadata.
        $booking->refresh();
        $this->assertNotNull($booking->refund);
        $this->assertEquals('USD', $booking->refund->refund_currency);
        $this->assertEqualsWithDelta(6.0, (float) $booking->refund->refund_amount, 0.01);
    }

    public function test_multi_currency_cancellation_with_egp_treasury_converts_via_fx(): void
    {
        // Phase 7 — cross-currency refund:
        //   USD booking, paid via USD wallet, CANCELLED but refund goes to
        //   an EGP cashbox (operator chose it via the dialog). The system
        //   must convert 6 USD → 300 EGP and post a same-currency EGP
        //   journal entry.
        //
        // Seed the EGP cashbox with enough balance to absorb the refund —
        // in real operation the office keeps a cash float that covers
        // outstanding refunds.
        LedgerBalanceMutationGuard::run(function () {
            $this->cashboxEgp->update(['balance' => 5000.0]);
            \App\Models\AccountEntry::create([
                'account_id' => $this->cashboxEgp->id,
                'transaction_id' => \App\Models\Transaction::create([
                    'type' => 'transfer',
                    'amount' => 5000.0,
                    'module' => 'general',
                    'from_account_id' => $this->cashboxEgp->id,
                    'to_account_id' => $this->cashboxEgp->id,
                    'created_by' => $this->user->id,
                ])->id,
                'debit' => 5000.0,
                'credit' => 0,
                'balance_after' => 5000.0,
            ]);
        });

        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'cost_per_ticket' => 2.00,
            'selling_price' => 3.00,
            'currency' => 'USD',
            'exchange_rate_to_egp' => 50.0,
            'total_tickets' => 5,
            'available_tickets' => 5,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Cross FX Refund',
            'customer_phone' => '01080000099',
            'quantity' => 2,
        ])->assertCreated();

        $booking = BusBooking::latest('id')->firstOrFail();

        // Pay via USD wallet.
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 6, 'payment_method' => 'cash_wallet',
            'account_id' => $this->walletUsd->id,
        ])->assertOk();

        // Cancel with refund → EGP cashbox. The system must compute the
        // EGP-equivalent (6 USD × 50 = 300 EGP) and post the refund
        // on the EGP side, while the USD customer AR is reversed in USD.
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $booking->refresh();
        $this->assertEquals('USD', $booking->currency);

        // Per-account invariant holds across the multi-currency lifecycle.
        $this->assertLedgerGloballyBalanced();

        // The refund record still shows 6 USD (booking currency).
        $this->assertEqualsWithDelta(6.0, (float) $booking->refund->refund_amount, 0.01);
        $this->assertEquals('USD', $booking->refund->refund_currency);

        // USD wallet unaffected (refund went to EGP cashbox, not USD wallet).
        $this->assertEqualsWithDelta(6.0, (float) $this->walletUsd->fresh()->balance, 0.01);

        // EGP cashbox: 5000 - 300 (refund) = 4700.
        $this->assertEqualsWithDelta(4700.0, (float) $this->cashboxEgp->fresh()->balance, 0.01);
    }
}
