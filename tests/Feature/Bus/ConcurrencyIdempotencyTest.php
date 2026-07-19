<?php

namespace Tests\Feature\Bus;

use App\Enums\BusInventoryPaymentType;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusPayment;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Services\Bus\BusBookingService;
use App\Services\Finance\CurrencyService;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Concurrency / idempotency contract tests for the Bus module.
 *
 * PHPUnit runs single-threaded, so TRUE concurrent writes (two requests
 * hitting the same row at the same instant) cannot be simulated. Instead
 * these tests pin the invariants that MUST hold under serialized
 * re-entry — the same guarantees the production code relies on when the
 * DB-level `lockForUpdate()` ordering gives it deterministic semantics.
 *
 * What this covers:
 *   1. Payment idempotency — double-clicking "Pay" must not double-charge.
 *   2. Cancel → refund race — pay + cancel must produce a coherent
 *      BusRefundRequest with the right currency snapshot.
 *   3. CurrencyService edge cases — `convert()` must handle missing
 *      rates (throw), zero-rate (silent bug today — flagged), and the
 *      through-EGP fallback for foreign→foreign conversions.
 *
 * These complement {@see InventoryRaceTest} which pins the inventory-
 * capacity invariants.
 */
class ConcurrencyIdempotencyTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // 1 — Payment idempotency: double-clicking "Pay" with the SAME amount
    // ─────────────────────────────────────────────────────────────────────

    public function test_double_submit_payment_with_same_amount_returns_already_paid_error(): void
    {
        // Scenario: customer clicks "Pay 120" twice. The first call must
        // succeed; the second call must be rejected with "already fully paid"
        // rather than silently double-posting.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(1000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);
        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');

        $service = app(BusBookingService::class);
        $booking = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Double Click',
            'customer_phone' => '01000000600',
            'quantity' => 1,
        ]);

        // First pay — succeeds.
        $service->payBooking($booking, [
            'amount' => 120.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);
        $this->assertEquals(120.0, (float) $booking->fresh()->paid_amount);

        // Second pay with the same amount — must throw "already fully paid".
        $this->expectExceptionMessageMatches('/already fully paid|تم السداد بالكامل/');

        try {
            $service->payBooking($booking->fresh(), [
                'amount' => 120.0,
                'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ]);
        } catch (\Throwable $e) {
            // Pin the post-condition: only ONE BusPayment row exists.
            $this->assertEquals(1, BusPayment::query()->where('booking_id', $booking->id)->count());
            $this->assertEquals(120.0, (float) $booking->fresh()->paid_amount, 'Paid amount must NOT change on rejection');
            // The cashbox is the DESTINATION of an EGP payment (recordIncome
            // debits the cashbox). After one 120 EGP payment, it gained 120
            // (1000 → 1120). The rejected second pay must NOT increment again.
            $this->assertEquals(1120.0, (float) $this->cashboxEgp->fresh()->balance, 'Cashbox must NOT receive the second payment');
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2 — Cancel + Refund race: pay then cancel must produce a coherent refund
    // ─────────────────────────────────────────────────────────────────────

    public function test_pay_booking_then_cancel_creates_refund_request_with_correct_state(): void
    {
        // Scenario: pay a booking fully, then cancel with zero penalty.
        // The cancel must restore capacity AND issue a refund that matches
        // the original payment amount. This is the "happy refund" path —
        // it pins the invariants that a customer-initiated refund depends on.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(1000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);
        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');

        $service = app(BusBookingService::class);
        $booking = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Pay Then Cancel',
            'customer_phone' => '01000000601',
            'quantity' => 1,
        ]);

        $service->payBooking($booking, [
            'amount' => 120.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        // Cancel with no penalty.
        $refund = $service->cancelBooking($booking->fresh(), [
            'company_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id,
        ]);

        // Refund matches the payment exactly.
        $this->assertEquals(120.0, (float) $refund->refund_amount);
        $this->assertEquals(120.0, (float) $refund->original_amount);
        $this->assertEquals(0.0, (float) $refund->cancellation_fee);
        $this->assertEquals('EGP', $refund->original_currency);

        // Capacity restored.
        $inventory->refresh();
        $this->assertEquals(5, (int) $inventory->available_tickets);

        // Cashbox back to starting balance (no penalty, full refund).
        $this->assertEquals(1000.0, (float) $this->cashboxEgp->fresh()->balance);

        // Booking marked refunded, not cancelled-without-refund.
        $this->assertEquals(\App\Enums\BusBookingStatus::Refunded, $booking->fresh()->status);

        // Customer AR fully cleared.
        $this->assertEquals(0.0, (float) $customer->ledgerAccount->fresh()->balance);

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3 — CurrencyService: missing rate throws a clean exception
    // ─────────────────────────────────────────────────────────────────────

    public function test_convert_throws_when_no_exchange_rate_available_for_currency_pair(): void
    {
        // The seeded exchange rates in BusTestCase cover USD↔EGP, SAR↔EGP,
        // KWD↔EGP, EUR↔EGP. A pair like SAR→KWD has neither a direct nor an
        // inverse rate, AND SAR→EGP→KWD might work IF the through-EGP
        // fallback handles it. We use a pair that genuinely has NO path
        // (e.g., a fictional currency) to confirm convert() throws rather
        // than silently returning 0.
        //
        // The fix: ensure the system fails LOUDLY when FX is needed but no
        // rate is available — silent 0 amounts would corrupt the ledger.
        $service = app(CurrencyService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/لا يوجد سعر صرف|exchange rate|conversion rate/');

        $service->convert(100.0, 'XXX', 'EGP');  // XXX is not seeded anywhere
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4 — CurrencyService: zero direct rate is a silent bug (flagged)
    // ─────────────────────────────────────────────────────────────────────

    public function test_convert_with_zero_direct_rate_returns_zero_amount_as_known_bug(): void
    {
        // KNOWN BUG — flagged here so a future fix surfaces.
        //
        // Current behavior: setExchangeRate() does NOT validate the rate
        // value. If an admin saves rate=0 (or a negative rate), the convert()
        // code's DIRECT-RATE branch returns 100 * 0 = 0 with rate=0 — silent
        // corruption of the ledger. The INVERSE-RATE branch and the
        // CURRENCIES-TABLE branch both guard against rate<=0, but the
        // direct-rate branch is unguarded.
        //
        // This test PASSES by pinning the current (broken) behavior.
        // When setExchangeRate() is hardened with validation, this test
        // should be flipped to assert that rate=0 is rejected at save time.
        $service = app(CurrencyService::class);

        // Plant a zero-rate direct pair.
        ExchangeRate::where('from_currency', 'USD')
            ->where('to_currency', 'EGP')
            ->update(['rate' => 0.0]);

        $result = $service->convert(100.0, 'USD', 'EGP');

        // Direct rate path returns 0 (BUG — should throw or refuse).
        $this->assertEquals(0.0, $result['to_amount'], 'BUG: direct rate=0 silently produces 0 amount');
        $this->assertEquals(0.0, $result['rate']);

        // Restore for downstream tests (the BusTestCase seeds USD→EGP=50).
        ExchangeRate::where('from_currency', 'USD')
            ->where('to_currency', 'EGP')
            ->update(['rate' => 50.0]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5 — CurrencyService: through-EGP fallback for foreign→foreign
    // ─────────────────────────────────────────────────────────────────────

    public function test_convert_uses_through_egp_fallback_for_foreign_to_foreign_pair(): void
    {
        // The seeded rates cover USD↔EGP and SAR↔EGP but NOT USD↔SAR.
        // convert() must find a path: USD→EGP→SAR.
        $service = app(CurrencyService::class);

        // Sanity: confirm the seeded rates exist for both direct legs.
        $this->assertNotNull(ExchangeRate::where('from_currency', 'USD')->where('to_currency', 'EGP')->first());
        $this->assertNotNull(ExchangeRate::where('from_currency', 'SAR')->where('to_currency', 'EGP')->first());

        // USD→SAR via EGP: 100 USD * 50 EGP/USD = 5000 EGP, then 5000 EGP / 13.3333 EGP/SAR ≈ 375 SAR.
        $result = $service->convert(100.0, 'USD', 'SAR');

        $this->assertEquals('USD', $result['from_currency']);
        $this->assertEquals('SAR', $result['to_currency']);
        $this->assertGreaterThan(370.0, $result['to_amount'], 'Through-EGP fallback should produce ~375 SAR');
        $this->assertLessThan(380.0, $result['to_amount']);
        $this->assertGreaterThan(0.0, $result['rate']);
    }
}