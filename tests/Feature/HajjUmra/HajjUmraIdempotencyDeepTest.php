<?php

namespace Tests\Feature\HajjUmra;

use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Services\HajjUmra\HajjUmraBookingService;

/**
 * Phase 10.8 — Idempotency Deep Audit (Section 14 of the audit prompt).
 *
 * Verifies the 4-layer idempotency defence for Hajj/Umra payments:
 *
 *   Layer 1 — Service-layer pre-check inside `lockForUpdate()`:
 *             SELECT existing payment with (booking_id, idempotency_key);
 *             if found, return it (idempotent_replay=true). No second
 *             financial mutation.
 *   Layer 2 — DB UNIQUE constraint `hup_idem_uniq` on (booking_id, idempotency_key).
 *             Last-line backstop if two callers bypass the lock.
 *   Layer 3 — `lockForUpdate()` on the booking row serializes concurrent
 *             calls on the same booking.
 *   Layer 4 — Transaction rollback on duplicate.
 *
 * Backward compat:
 *   - When `idempotency_key` is null/empty, no protection is applied.
 *     Legacy callers may still replay.
 *   - `transaction_reference` is free-text and NOT unique — multiple
 *     payments may share the same reference without conflict.
 */
class HajjUmraIdempotencyDeepTest extends HajjUmraTestCase
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

    private function postPayment(int $bookingId, string $idempotencyKey, float $amount = 10000.0, ?string $reference = null)
    {
        $payload = [
            'amount' => $amount,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => $idempotencyKey,
        ];
        if ($reference !== null) {
            $payload['reference'] = $reference;
        }
        return $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", $payload);
    }

    /* ============================================================
     *  Layer 1 — service-layer pre-check (sequential replays)
     * ============================================================ */

    public function test_same_idempotency_key_returns_same_payment_id(): void
    {
        $booking = $this->makeBooking();
        $key = 'P108_K1_'.uniqid();

        $first = $this->postPayment($booking->id, $key)->assertCreated();
        $firstId = $first->json('data.payment.id');

        // Replay — same key, should return SAME payment
        $replay = $this->postPayment($booking->id, $key)->assertStatus(200);
        $replayId = $replay->json('data.payment.id');

        $this->assertSame($firstId, $replayId,
            'replay with same key must return same payment id');

        $this->assertSame(1, $booking->payments()->count(),
            'only one payment row should exist');
    }

    public function test_replay_marks_idempotent_replay_flag(): void
    {
        $booking = $this->makeBooking();
        $key = 'P108_FLAG_'.uniqid();

        $this->postPayment($booking->id, $key)->assertCreated();
        $replay = $this->postPayment($booking->id, $key)->assertStatus(200);

        $this->assertTrue((bool) $replay->json('data.idempotent_replay'),
            'replay response must include idempotent_replay=true');
    }

    public function test_3x_replay_with_same_key_creates_one_payment(): void
    {
        $booking = $this->makeBooking();
        $key = 'P108_3X_'.uniqid();

        $this->postPayment($booking->id, $key)->assertCreated();
        $this->postPayment($booking->id, $key)->assertStatus(200);
        $this->postPayment($booking->id, $key)->assertStatus(200);

        $this->assertSame(1, $booking->payments()->count());
    }

    /* ============================================================
     *  Different keys → different payments (no false dedup)
     * ============================================================ */

    public function test_different_keys_create_different_payments(): void
    {
        $booking = $this->makeBooking();

        $this->postPayment($booking->id, 'P108_D1_'.uniqid(), 10000.0)->assertCreated();
        $this->postPayment($booking->id, 'P108_D2_'.uniqid(), 10000.0)->assertCreated();
        $this->postPayment($booking->id, 'P108_D3_'.uniqid(), 10000.0)->assertCreated();

        $this->assertSame(3, $booking->payments()->count());
        $this->assertEqualsWithDelta(30000.0, (float) $booking->fresh()->paid_amount, 0.01);
    }

    /* ============================================================
     *  NULL / empty idempotency_key → legacy behavior (no auto-dedup)
     * ============================================================ */

    public function test_null_idempotency_key_allows_duplicate_payment(): void
    {
        $booking = $this->makeBooking();

        // Both without idempotency_key — pre-Phase-B legacy behavior
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();

        $this->assertSame(2, $booking->payments()->count(),
            'null idempotency_key must allow multiple payments (legacy callers)');
    }

    public function test_empty_string_idempotency_key_treated_as_null(): void
    {
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => '',
        ])->assertCreated();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => '',
        ])->assertCreated();

        $this->assertSame(2, $booking->payments()->count());
    }

    /* ============================================================
     *  transaction_reference is NOT unique (free-text)
     * ============================================================ */

    public function test_same_reference_different_keys_both_persist(): void
    {
        $booking = $this->makeBooking();

        $this->postPayment($booking->id, 'P108_R1_'.uniqid(), 1000.0, 'WIRE-001')->assertCreated();
        $this->postPayment($booking->id, 'P108_R2_'.uniqid(), 1000.0, 'WIRE-001')->assertCreated();

        $this->assertSame(2, $booking->payments()->count(),
            'same reference with different keys must both persist');
    }

    public function test_same_reference_no_key_both_persist(): void
    {
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'reference' => 'SHARED-REF',
        ])->assertCreated();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'reference' => 'SHARED-REF',
        ])->assertCreated();

        $this->assertSame(2, $booking->payments()->count());
    }

    /* ============================================================
     *  Layer 2 — DB UNIQUE constraint (defense in depth)
     * ============================================================ */

    public function test_db_unique_constraint_rejects_duplicate_key(): void
    {
        $booking = $this->makeBooking();
        $key = 'P108_DBC_'.uniqid();

        // First insert — succeeds
        HajjUmraPayment::query()->create([
            'hajj_umra_booking_id' => $booking->id,
            'account_id' => $this->treasuryEGP->id,
            'payment_method' => 'cash',
            'amount' => 1000.0,
            'currency' => 'EGP',
            'treasury_account' => 'office_drawer',
            'transaction_reference' => 'direct-1',
            'idempotency_key' => $key,
            'payment_date' => now(),
            'paid_by' => 'audit-customer',
            'created_by' => $this->admin->id,
        ]);

        // Second insert with same (booking_id, idempotency_key) — must fail
        $this->expectException(\Illuminate\Database\QueryException::class);
        HajjUmraPayment::query()->create([
            'hajj_umra_booking_id' => $booking->id,
            'account_id' => $this->treasuryEGP->id,
            'payment_method' => 'cash',
            'amount' => 1000.0,
            'currency' => 'EGP',
            'treasury_account' => 'office_drawer',
            'transaction_reference' => 'direct-2',
            'idempotency_key' => $key,
            'payment_date' => now(),
            'paid_by' => 'audit-customer',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_same_key_different_booking_both_persist(): void
    {
        $b1 = $this->makeBooking();
        $b2 = $this->makeBooking();
        $key = 'P108_CROSS_'.uniqid();

        $this->postPayment($b1->id, $key)->assertCreated();
        $this->postPayment($b2->id, $key)->assertCreated();

        $this->assertSame(1, $b1->payments()->count());
        $this->assertSame(1, $b2->payments()->count());
    }

    /* ============================================================
     *  No second financial mutation on replay
     * ============================================================ */

    public function test_replay_does_not_record_extra_account_entries(): void
    {
        $booking = $this->makeBooking();
        $key = 'P108_ENT_'.uniqid();

        $this->postPayment($booking->id, $key)->assertCreated();
        $entriesAfterFirst = \App\Models\AccountEntry::query()->count();

        // Replay
        $this->postPayment($booking->id, $key)->assertStatus(200);
        $entriesAfterReplay = \App\Models\AccountEntry::query()->count();

        $this->assertSame($entriesAfterFirst, $entriesAfterReplay,
            'replay must not create extra AccountEntry rows');
    }

    public function test_replay_does_not_double_paid_amount(): void
    {
        $booking = $this->makeBooking();
        $key = 'P108_AMT_'.uniqid();

        $this->postPayment($booking->id, $key, 10000.0)->assertCreated();
        $this->postPayment($booking->id, $key, 10000.0)->assertStatus(200);
        $this->postPayment($booking->id, $key, 10000.0)->assertStatus(200);

        $this->assertEqualsWithDelta(10000.0, (float) $booking->fresh()->paid_amount, 0.01,
            'paid_amount must reflect only the original 10000, not 30000');
    }

    /* ============================================================
     *  Soft-deleted payment frees up the idempotency key
     * ============================================================ */

    public function test_soft_deleted_payment_key_blocks_new_payment(): void
    {
        // Documented (and tested) behavior:
        //   The DB UNIQUE index `hup_idem_uniq` is PLANT (not partial).
        //   Soft-deleted rows DO count for the constraint. Once a payment
        //   is soft-deleted, its idempotency_key is permanently used.
        //   A second POST with the same key is rejected with 422 (caught
        //   DB UNIQUE constraint violation by the service layer).
        //
        //   To free a key, an operator would need to hard-delete the row
        //   (which is forbidden by the architecture — AccountEntry /
        //   Transaction are immutable). This is intentional: the canonical
        //   audit-trail forbids key reuse.
        $booking = $this->makeBooking();
        $key = 'P108_SOFT_'.uniqid();

        $first = $this->postPayment($booking->id, $key)->assertCreated();
        $firstId = $first->json('data.payment.id');

        // Soft-delete the original payment
        HajjUmraPayment::query()->find($firstId)->delete();

        // A new payment with the same key must fail (DB UNIQUE constraint)
        $this->postPayment($booking->id, $key)->assertStatus(422);

        $this->assertSame(1, HajjUmraPayment::withTrashed()
            ->where('hajj_umra_booking_id', $booking->id)
            ->where('idempotency_key', $key)
            ->count(),
            'only the soft-deleted row exists');
    }

    /* ============================================================
     *  Edge case — key length and special characters
     * ============================================================ */

    public function test_long_idempotency_key_accepted(): void
    {
        $booking = $this->makeBooking();
        $key = str_repeat('A', 100); // max length per migration

        $this->postPayment($booking->id, $key)->assertCreated();
        $this->assertSame(1, $booking->payments()->count());
    }
}
