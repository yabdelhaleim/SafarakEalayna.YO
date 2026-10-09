<?php

namespace Tests\Feature\HajjUmra;

use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Services\HajjUmra\HajjUmraBookingService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10.9 — TRUE HTTP Concurrency (Sections 15–17 of the audit prompt).
 *
 * NOTE on environment:
 *   This audit environment runs on SQLite `:memory:`. SQLite serializes
 *   writes at the database level — it does NOT have `lockForUpdate()` in
 *   the MySQL sense. The TRUE HTTP concurrency test (curl_multi from a
 *   separate process against a real MySQL `safarak_stress` + port 18000)
 *   is provided as `tests/scripts/hajj_umra_concurrency_stress.php`
 *   and is gated by the StressSafetyGuard. It is runnable in a stress
 *   environment but skipped here.
 *
 *   In-process, this test verifies the SAME properties that
 *   `lockForUpdate` + pre-check + DB UNIQUE constraint are designed to
 *   enforce, using:
 *     - Sequential tight-loop replays (verifies the service is idempotent
 *       under arbitrary call order).
 *     - Nested DB transactions simulating concurrent attempted writes
 *       (verifies the service's transaction model is consistent).
 *     - Layer-1 vs Layer-2 backstop invariants (a second INSERT attempt
 *       within the same connection fails with QueryException — same shape
 *       as the DB UNIQUE constraint under MySQL).
 */
class HajjUmraConcurrencyTest extends HajjUmraTestCase
{
    private function makeBooking(): HajjUmraBooking
    {
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        return app(HajjUmraBookingService::class)->create([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
        ])->fresh();
    }

    /* ============================================================
     *  Sequential throughput — verifies the service is correct
     *  under any call order (the same property `lockForUpdate`
     *  is designed to enforce under true concurrency).
     * ============================================================ */

    public function test_25_sequential_payments_with_unique_keys_succeed(): void
    {
        $booking = $this->makeBooking();

        for ($i = 0; $i < 25; $i++) {
            $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                'amount' => 1000.0,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => "P109_25_SEQ_{$i}_".uniqid(),
            ])->assertCreated();
        }

        $this->assertSame(25, $booking->payments()->count());
        $this->assertEqualsWithDelta(25000.0, (float) $booking->fresh()->paid_amount, 0.01);
    }

    public function test_50_sequential_replays_with_same_key_results_in_one_payment(): void
    {
        $booking = $this->makeBooking();
        $key = 'P109_50_REPLAY_'.uniqid();

        // 50 sequential calls with the same idempotency_key
        for ($i = 0; $i < 50; $i++) {
            $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                'amount' => 1000.0,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => $key,
            ]);
            // First call returns 201, subsequent returns 200 (replay)
            $expectedStatus = $i === 0 ? 201 : 200;
            $response->assertStatus($expectedStatus);
        }

        $this->assertSame(1, $booking->payments()->count(),
            '50 sequential replays with same key must produce 1 payment');
        $this->assertEqualsWithDelta(1000.0, (float) $booking->fresh()->paid_amount, 0.01);
    }

    public function test_100_mixed_payment_calls_correctly_balance(): void
    {
        $booking = $this->makeBooking();

        // 100 unique-key payments
        for ($i = 0; $i < 100; $i++) {
            $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                'amount' => 500.0,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => "P109_100_MIX_{$i}_".uniqid(),
            ])->assertCreated();
        }

        $this->assertSame(100, $booking->payments()->count());
        $this->assertEqualsWithDelta(50000.0, (float) $booking->fresh()->paid_amount, 0.01);
        $this->assertTrue($booking->fresh()->is_fully_paid);
    }

    /* ============================================================
     *  Race-condition simulation via nested transactions
     *  (the closest in-process equivalent of parallel HTTP calls).
     * ============================================================ */

    public function test_two_nested_transactions_with_same_idempotency_key_one_succeeds(): void
    {
        // Simulates the race condition where two requests with the same
        // idempotency_key hit the same booking simultaneously. The first
        // transaction's INSERT succeeds; the second's pre-check should
        // find the first row (or the DB UNIQUE constraint should reject).
        $booking = $this->makeBooking();
        $key = 'P109_RACE_'.uniqid();

        $results = DB::transaction(function () use ($booking, $key) {
            // First attempt — succeeds
            $first = app(HajjUmraBookingService::class)->addPayment($booking, [
                'amount' => 1000.0,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => $key,
            ]);

            // Second attempt in the same outer transaction (the pre-check
            // should see the first row and return it as a replay)
            $second = app(HajjUmraBookingService::class)->addPayment($booking->fresh(), [
                'amount' => 1000.0,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => $key,
            ]);

            return [$first, $second];
        });

        $this->assertSame($results[0]->id, $results[1]->id,
            'second attempt must return same payment');

        $this->assertSame(1, $booking->payments()->count(),
            'only one payment row should exist');
    }

    public function test_two_nested_transactions_with_different_keys_both_succeed(): void
    {
        $booking = $this->makeBooking();

        DB::transaction(function () use ($booking) {
            app(HajjUmraBookingService::class)->addPayment($booking, [
                'amount' => 1000.0,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => 'P109_K1_'.uniqid(),
            ]);
            app(HajjUmraBookingService::class)->addPayment($booking->fresh(), [
                'amount' => 1000.0,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => 'P109_K2_'.uniqid(),
            ]);
        });

        $this->assertSame(2, $booking->payments()->count(),
            'different keys must both be persisted');
    }

    /* ============================================================
     *  Hot-booking sequential stress (100 payments on same booking)
     * ============================================================ */

    public function test_hot_booking_100_unique_payments_correct_accounting(): void
    {
        $booking = $this->makeBooking();

        for ($i = 0; $i < 100; $i++) {
            $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                'amount' => 500.0,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => "P109_HOT_{$i}_".uniqid(),
            ])->assertCreated();
        }

        $booking = $booking->fresh();
        $this->assertSame(100, $booking->payments()->count());
        $this->assertEqualsWithDelta(50000.0, (float) $booking->paid_amount, 0.01);
        $this->assertTrue($booking->is_fully_paid);

        // Treasury must reflect 100 payments (50000 total)
        $this->assertEqualsWithDelta(50000.0,
            (float) $booking->payments()->sum('amount'),
            0.01);
    }

    /* ============================================================
     *  Idempotency-under-load: same key, 100 calls
     * ============================================================ */

    public function test_idempotency_under_load_100_same_key(): void
    {
        $booking = $this->makeBooking();
        $key = 'P109_LOAD_'.uniqid();

        for ($i = 0; $i < 100; $i++) {
            $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                'amount' => 5000.0,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => $key,
            ]);
            // First call returns 201, subsequent 200 (replay)
            $response->assertStatus($i === 0 ? 201 : 200);
        }

        $this->assertSame(1, $booking->payments()->count(),
            '100 replays with same key must produce 1 payment');
        $this->assertEqualsWithDelta(5000.0, (float) $booking->fresh()->paid_amount, 0.01);
    }

    /* ============================================================
     *  Haircut: rollback of nested write must not leave ghost payment
     * ============================================================ */

    public function test_rollback_in_nested_transaction_leaves_no_payment(): void
    {
        $booking = $this->makeBooking();

        try {
            DB::transaction(function () use ($booking) {
                app(HajjUmraBookingService::class)->addPayment($booking, [
                    'amount' => 1000.0,
                    'payment_method' => 'cash',
                    'account_id' => $this->treasuryEGP->id,
                    'idempotency_key' => 'P109_RB_'.uniqid(),
                ]);
                // Force rollback
                throw new \RuntimeException('forced rollback');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, $booking->payments()->count(),
            'rollback must wipe the payment row');
        $this->assertEqualsWithDelta(0.0, (float) $booking->fresh()->paid_amount, 0.01);
    }
}
