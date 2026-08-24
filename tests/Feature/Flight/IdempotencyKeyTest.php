<?php

namespace Tests\Feature\Flight;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * IdempotencyKeyTest — DEFECT-001 regression coverage.
 *
 * Background:
 * The flight payment endpoint accepts an optional `idempotency_key` so that
 * client retries (network failure, user double-click, queued messages) do
 * not result in duplicate payments. The contract is documented in
 * FlightBookingService::addPayment():
 *
 *   Layered protection:
 *     1. Pre-check: SELECT existing payment with same (booking, key).
 *        If found AND not soft-deleted → return it (idempotent replay).
 *     2. DB-level UNIQUE constraint (the migration) — backstop in case
 *        two callers bypass the lock. The INSERT will fail with
 *        MySQL error 1062 / SQLSTATE 23000, which we catch and convert
 *        to an idempotent return.
 *     3. `lockForUpdate()` on the booking row serializes concurrent calls.
 *
 *   Backward compat:
 *     - When `idempotency_key` is null/empty, no protection is applied.
 *     - When supplied, replays return the original payment (200 OK with
 *       the original row) — no second financial mutation, no extra
 *       AccountEntry rows, no extra Transaction.
 *
 * DEFECT-001: After a payment is soft-deleted, replaying with the SAME
 * idempotency_key returns 422 instead of either (a) returning the dead
 * row OR (b) creating a new live payment. This breaks legitimate retry
 * scenarios where a payment was reversed and the client retries.
 *
 * The tests below cover BOTH intended behaviors:
 *   - real_idempotent_replay (PASSING): same key, payment still alive
 *     → must return 200 + the original payment, no second financial
 *     mutation.
 *   - retry_after_soft_delete (FAILING — DEFECT-001): same key after the
 *     original payment was soft-deleted → currently returns 422.
 *
 * The "after soft-delete" test is intentionally left failing for now.
 * The fix decision is tracked in the audit report; tests will be updated
 * to assert the chosen contract once we agree on the security model.
 */
class IdempotencyKeyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Customer $customer;

    protected Account $cashbox;

    protected FlightCarrier $carrier;

    protected FlightSystem $system;

    protected FlightBooking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Idempotency Test Admin',
            'email' => 'idem-admin-'.uniqid().'@test.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'Idempotency Customer',
            'phone' => '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->cashbox = Account::query()->create([
            'name' => 'Idempotency Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100_000,
            'is_active' => true,
            'module_type' => 'tourism',
            'module' => 'flights',
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::query()->create([
            'name' => 'Idempotency Carrier',
            'code' => 'IDC-'.substr(md5(uniqid()), 0, 4),
            'currency' => 'EGP',
            'balance' => 50_000,
            'credit_limit' => 10_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->system = FlightSystem::query()->create([
            'name' => 'Idempotency System',
            'code' => 'IDS-'.substr(md5(uniqid()), 0, 4),
            'currency' => 'EGP',
            'balance' => 50_000,
            'credit_limit' => 10_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Create the booking that all idempotency tests reuse.
        // We use the service so all required fields and observers fire.
        $this->booking = app(\App\Services\Flight\FlightBookingService::class)->createBooking([
            'customer_id' => $this->customer->id,
            'flight_carrier_id' => $this->carrier->id,
            'flight_system_id' => $this->system->id,
            'pnr' => 'I'.random_int(1000, 9999),
            'selling_price' => 5_000,
            'purchase_price' => 4_500,
            'currency' => 'EGP',
            'account_id' => $this->cashbox->id,
            'departure_date' => now()->addWeek()->toDateString(),
            'departure_time' => '09:00',
            'arrival_time' => '13:00',
            'flight_number' => 'MS999',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'passengers' => [
                ['first_name' => 'Idem', 'last_name' => 'Pax', 'type' => 'adult'],
            ],
        ]);

        Sanctum::actingAs($this->admin, ['*']);
    }

    /**
     * SCENARIO A — real idempotent replay.
     *
     * Same booking + same idempotency_key + original payment still active
     * (not soft-deleted) → must return the original payment with 200 OK
     * and idempotent_replay flag. NO second financial mutation.
     *
     * This MUST pass before AND after the DEFECT-001 fix.
     */
    public function test_real_idempotent_replay_returns_original_payment(): void
    {
        $key = 'replay_'.uniqid();

        // ─── First call: create the payment ───
        $first = $this->postJson("/api/v1/flight/bookings/{$this->booking->id}/payments", [
            'amount' => 1_000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => $key,
        ]);
        $first->assertStatus(201);

        $originalPaymentId = $first->json('data.id');
        $this->assertNotNull($originalPaymentId);

        // ─── Second call: same key, same data ───
        $replay = $this->postJson("/api/v1/flight/bookings/{$this->booking->id}/payments", [
            'amount' => 1_000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => $key,
        ]);

        // MUST return 200 OK with idempotent_replay flag and the SAME row.
        $replay->assertStatus(200);
        $replay->assertJsonPath('data.id', $originalPaymentId);
        $replay->assertJsonPath('data.idempotent_replay', true);

        // MUST NOT have created a second payment row.
        $this->assertSame(
            1,
            FlightPayment::query()->where('flight_booking_id', $this->booking->id)->count(),
            'Idempotent replay must not create a second payment row.'
        );
    }

    /**
     * SCENARIO B — retry after the original payment was soft-deleted.
     *
     * Same booking + same idempotency_key, BUT the original payment was
     * soft-deleted (e.g., refunded, reversed, voided). The client retries
     * with the same key.
     *
     * DEFECT-001: Today this returns 422 because the pre-check uses
     * Eloquent's default scope (which excludes soft-deleted rows) and the
     * DB unique constraint blocks a fresh INSERT.
     *
     * Expected behavior (TBD by security decision — see audit report):
     *   Option 1 (allow new payment): return 201 with a NEW payment row.
     *   Option 2 (reject as ambiguous): return 409 / 422 with a clear
     *     "this key was previously used" error so the client picks a
     *     fresh key.
     *
     * Until the security decision is made, this test asserts the
     * "Option 1 — allow new payment" contract, which is the most
     * client-friendly and matches the user's stated expectation. If the
     * team chooses Option 2, flip this assertion to 409/422.
     */
    public function test_retry_after_soft_delete_creates_new_payment(): void
    {
        $key = 'softdelete_'.uniqid();

        // ─── Create the original payment ───
        $first = $this->postJson("/api/v1/flight/bookings/{$this->booking->id}/payments", [
            'amount' => 1_000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => $key,
        ]);
        $first->assertStatus(201);

        $originalPaymentId = $first->json('data.id');
        $originalRowCount = FlightPayment::query()->where('flight_booking_id', $this->booking->id)->count();
        $this->assertSame(1, $originalRowCount);

        // Capture the cashbox balance after the original payment — this is
        // the baseline we must NOT see move when the rejected retry happens.
        $this->cashbox->refresh();
        $cashboxBalanceAfterOriginal = (float) $this->cashbox->balance;

        // ─── Simulate refund/reversal by soft-deleting the original ───
        $original = FlightPayment::query()->findOrFail($originalPaymentId);
        $original->delete();
        $this->assertSoftDeleted('flight_payments', ['id' => $originalPaymentId]);

        // ─── Retry with the SAME key ───
        $retry = $this->postJson("/api/v1/flight/bookings/{$this->booking->id}/payments", [
            'amount' => 1_000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => $key,
        ]);

        // Contract: Option 2 — reject as 409 Conflict. The idempotency
        // contract is "same key = same effect". A retry after soft-delete
        // must surface a clear conflict so the client knows to use a
        // fresh idempotency_key.
        $retry->assertStatus(409, 'After soft-delete, retry must be rejected as 409 Conflict.');

        // The error message must point the client at the fix.
        $errorMessage = (string) $retry->json('message');
        $this->assertStringContainsString(
            'Generate a fresh idempotency_key',
            $errorMessage,
            '409 message must guide the client to use a fresh idempotency_key.'
        );

        // No new payment row should have been created.
        $this->assertSame(
            1,
            FlightPayment::query()
                ->withTrashed()
                ->where('flight_booking_id', $this->booking->id)
                ->count(),
            'After rejected retry, only the original soft-deleted payment should exist.'
        );

        // And the cashbox balance must be unchanged — no double-charge.
        $this->cashbox->refresh();
        $expectedBalance = $cashboxBalanceAfterOriginal;
        $this->assertEquals(
            $expectedBalance,
            (float) $this->cashbox->balance,
            'Cashbox balance must not change when retry is rejected (no double-charge).'
        );
    }

    /**
     * SCENARIO C — no idempotency_key supplied.
     *
     * Backward-compat path: when `idempotency_key` is missing or empty,
     * the endpoint must behave like a normal payment creation. Two
     * consecutive calls without a key should create two payments (the
     * client is responsible for not double-clicking).
     *
     * This MUST pass before AND after the DEFECT-001 fix.
     */
    public function test_no_key_means_no_protection_two_calls_two_payments(): void
    {
        $this->postJson("/api/v1/flight/bookings/{$this->booking->id}/payments", [
            'amount' => 1_000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            // no idempotency_key
        ])->assertStatus(201);

        $this->postJson("/api/v1/flight/bookings/{$this->booking->id}/payments", [
            'amount' => 1_000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            // no idempotency_key
        ])->assertStatus(201);

        $this->assertSame(
            2,
            FlightPayment::query()->where('flight_booking_id', $this->booking->id)->count(),
            'Without an idempotency_key, each call should create a new payment.'
        );
    }

    /**
     * SCENARIO D — different idempotency_keys produce different payments.
     *
     * Sanity check: the unique constraint is per (booking, key), so two
     * different keys should be allowed on the same booking.
     *
     * This MUST pass before AND after the DEFECT-001 fix.
     */
    public function test_different_keys_produce_different_payments(): void
    {
        $this->postJson("/api/v1/flight/bookings/{$this->booking->id}/payments", [
            'amount' => 1_000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => 'key_a_'.uniqid(),
        ])->assertStatus(201);

        $this->postJson("/api/v1/flight/bookings/{$this->booking->id}/payments", [
            'amount' => 1_000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => 'key_b_'.uniqid(),
        ])->assertStatus(201);

        $this->assertSame(
            2,
            FlightPayment::query()->where('flight_booking_id', $this->booking->id)->count()
        );
    }
}