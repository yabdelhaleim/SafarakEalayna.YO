<?php

namespace Tests\Feature\Bus;

use App\Models\Bus\BusBooking;
use App\Models\Bus\BusPayment;
use App\Services\Bus\BusBookingService;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Level 2 / Problem 4 — Idempotency on POST /bus/bookings/{id}/pay.
 *
 * Audit finding I-01 / R-09: the bus payment endpoint had no idempotency layer.
 * Two identical POSTs (same amount / account / booking) would both create
 * BusPayment rows and record two financial movements. In production this
 * meant a cashier who double-clicked "Pay" debited the customer twice.
 *
 * Two-layer fix:
 *   (a) Explicit Idempotency-Key header — the canonical mechanism. The
 *       client generates a UUID per logical operation. If the request is
 *       retried (network error, refresh, double-click), the SAME key is
 *       sent and the server replays the original result instead of charging
 *       again. Storage: nullable unique column `bus_payments.idempotency_key`.
 *
 *   (b) Safety-net time window — when no header is provided (legacy client
 *       path or direct curl attack), the service rejects a second payment
 *       with the same (booking_id, amount, account_id, payment_method)
 *       tuple within IDEMPOTENCY_WINDOW_SECONDS (default 5 seconds). After
 *       the window expires, intentional repeat payments are allowed.
 *
 * Coverage:
 *   - Same Idempotency-Key twice → 1 payment row, both responses identical
 *   - Different Idempotency-Keys → both succeed independently
 *   - Same tuple within window (no header) → 2nd rejected with 422
 *   - Same tuple after window (no header) → both succeed
 *   - E2E flow from E2E test still passes (single payment, no header)
 */
class BusPaymentIdempotencyTest extends BusTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCashboxBalance(1000.0);
    }

    private function makeBookingWithCompany(float $sellingPrice = 100, float $costPerTicket = 80): BusBooking
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'selling_price' => $sellingPrice,
            'cost_per_ticket' => $costPerTicket,
        ]);

        $service = app(BusBookingService::class);
        $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_name' => 'عميل اختبار',
            'customer_phone' => '0100IDEMPOTENCY',
            'quantity' => 1,
        ]);

        return BusBooking::latest('id')->firstOrFail();
    }

    // ────────────────────────────────────────────────────────────────────
    // EXPLICIT Idempotency-Key PATH (header present)
    // ────────────────────────────────────────────────────────────────────

    public function test_same_idempotency_key_twice_creates_only_one_payment(): void
    {
        $booking = $this->makeBookingWithCompany(sellingPrice: 200);
        $key = (string) Str::uuid();

        // First request — should succeed
        $r1 = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
                'amount' => 100, 'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ]);
        $r1->assertOk();

        $this->assertEquals(1, BusPayment::where('booking_id', $booking->id)->count(),
            'one payment row after first request');

        // Second request — same key, same payload → must be idempotent
        $r2 = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
                'amount' => 100, 'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ]);
        $r2->assertOk();

        $this->assertEquals(1, BusPayment::where('booking_id', $booking->id)->count(),
            'same Idempotency-Key must NOT create a second payment');

        // Booking state must be exactly the same as after the first request
        $booking->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $booking->paid_amount, 0.01,
            'paid_amount must reflect exactly ONE payment, not two');

        // Both responses carry the same payment id
        $r1PaymentId = $r1->json('data.payments.0.id') ?? null;
        $r2PaymentId = $r2->json('data.payments.0.id') ?? null;
        $this->assertEquals($r1PaymentId, $r2PaymentId,
            'idempotent replay must return the same payment id');
    }

    public function test_different_idempotency_keys_create_separate_payments(): void
    {
        // The whole point of the header is to distinguish INTENTIONAL separate
        // payments (different keys → both succeed) from accidental retries
        // (same key → idempotent replay). If different keys were deduplicated
        // we'd be blocking legitimate cashiers who legitimately pay twice.
        $booking = $this->makeBookingWithCompany(sellingPrice: 200);

        $key1 = (string) Str::uuid();
        $key2 = (string) Str::uuid();
        $this->assertNotSame($key1, $key2);

        $this->withHeaders(['Idempotency-Key' => $key1])
            ->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
                'amount' => 100, 'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ])->assertOk();

        $this->withHeaders(['Idempotency-Key' => $key2])
            ->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
                'amount' => 50, 'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ])->assertOk();

        $this->assertEquals(2, BusPayment::where('booking_id', $booking->id)->count(),
            'two distinct Idempotency-Keys must produce two distinct payments');
    }

    // ────────────────────────────────────────────────────────────────────
    // SAFETY-NET PATH (no header, time-window check)
    // ────────────────────────────────────────────────────────────────────

    public function test_same_payment_tuple_within_5_seconds_rejected_by_safety_net(): void
    {
        // No Idempotency-Key header → the safety-net time window catches the
        // double-submit. The 2nd request within IDEMPOTENCY_WINDOW_SECONDS
        // (default 5s) must be rejected with 422.
        $booking = $this->makeBookingWithCompany(sellingPrice: 200);

        $payload = [
            'amount' => 100, 'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ];

        $r1 = $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", $payload);
        $r1->assertOk();
        $this->assertEquals(1, BusPayment::where('booking_id', $booking->id)->count());

        // Send the EXACT SAME payload immediately, no header.
        $r2 = $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", $payload);
        $r2->assertStatus(422);

        $this->assertEquals(1, BusPayment::where('booking_id', $booking->id)->count(),
            'safety-net must reject the 2nd payment within the window — only 1 row remains');
    }

    public function test_same_payment_tuple_after_5_seconds_allowed(): void
    {
        // After the safety-net window expires, intentional repeat payments
        // (e.g. partial-then-partial) MUST be allowed. We use Carbon's
        // setTestNow to fake time so the test runs instantly.
        $booking = $this->makeBookingWithCompany(sellingPrice: 200);

        $payload = [
            'amount' => 100, 'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ];

        // First payment at T=0
        Carbon::setTestNow('2026-08-20 10:00:00');
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", $payload)->assertOk();
        $this->assertEquals(1, BusPayment::where('booking_id', $booking->id)->count());

        // Same payload at T=10s (window has expired) — must succeed
        Carbon::setTestNow('2026-08-20 10:00:10');
        $r2 = $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", $payload);
        $r2->assertOk();
        $this->assertEquals(2, BusPayment::where('booking_id', $booking->id)->count(),
            'after the safety-net window, intentional repeat payment must succeed');

        Carbon::setTestNow(); // restore
    }

    public function test_safety_net_does_not_block_different_payment_tuples(): void
    {
        // The safety-net must only catch the EXACT same tuple (same booking +
        // amount + account + method). Two different amounts OR different
        // accounts within the window must BOTH succeed.
        $booking = $this->makeBookingWithCompany(sellingPrice: 200);

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100, 'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        // Same booking, DIFFERENT amount (50 vs 100) — must succeed
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 50, 'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $this->assertEquals(2, BusPayment::where('booking_id', $booking->id)->count(),
            'different amount must not be blocked by the safety-net');
    }
}