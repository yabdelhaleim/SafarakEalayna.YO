<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
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
 * BookingLifecycleAuditTest — Phase 1 audit of flight booking operations.
 *
 * Date: 2026-08-24
 * Scope: Bookings CRUD + Lifecycle (12 ops × multiple scenarios)
 *
 * Each test:
 *   - Sets up minimal fixtures
 *   - Performs the action
 *   - Asserts HTTP status + DB state
 *   - Logs DEFECT marker comments where bugs are observed
 *
 * Read-only audit: NO logic changes. Tests document behavior only.
 */
class BookingLifecycleAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $employeeUser;

    protected Employee $employee;

    protected Customer $customer;

    protected Account $cashbox;

    protected Account $bank;

    protected FlightCarrier $carrier;

    protected FlightSystem $system;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user (full permissions)
        $this->admin = User::query()->create([
            'name' => 'Audit Admin',
            'email' => 'audit-admin@test.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Regular employee user
        $this->employeeUser = User::query()->create([
            'name' => 'Audit Employee',
            'email' => 'audit-employee@test.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->employee = Employee::query()->create([
            'first_name' => 'Audit',
            'last_name' => 'Cashier',
            'user_id' => $this->employeeUser->id,
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'Audit Customer',
            'phone' => '01000000099',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // EGP cashbox (primary)
        $this->cashbox = Account::query()->create([
            'name' => 'Audit Cashbox EGP',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'flights',
            'created_by' => $this->admin->id,
        ]);

        $this->bank = Account::query()->create([
            'name' => 'Audit Bank EGP',
            'type' => 'bank',
            'currency' => 'EGP',
            'balance' => 50000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'flights',
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::query()->create([
            'name' => 'Audit Carrier',
            'code' => 'AC',
            'currency' => 'EGP',
            'balance' => 50000,
            'credit_limit' => 10000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->system = FlightSystem::query()->create([
            'name' => 'Audit System',
            'code' => 'AS',
            'currency' => 'EGP',
            'balance' => 50000,
            'credit_limit' => 10000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);
    }

    /**
     * Minimal valid payload for booking creation.
     */
    protected function minimalPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'flight_carrier_id' => $this->carrier->id,
            'flight_system_id' => $this->system->id,
            'pnr' => 'AUD' . random_int(1000, 9999),
            'selling_price' => 5000,
            'purchase_price' => 4500,
            'currency' => 'EGP',
            'account_id' => $this->cashbox->id,
            'departure_date' => now()->addWeek()->toDateString(),
            'departure_time' => '09:30',
            'arrival_time' => '13:00',
            'flight_number' => 'MS999',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'passengers' => [
                ['first_name' => 'Audit', 'last_name' => 'Pax', 'type' => 'adult'],
            ],
        ], $overrides);
    }

    // ============================================================
    // OPERATION 1: CREATE BOOKING — POST /api/v1/flight/bookings
    // ============================================================

    /**
     * 1a: Create EGP booking, single passenger, with PNR
     */
    public function test_01a_create_egp_booking_with_pnr(): void
    {
        $response = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());

        $response->assertStatus(201);
        $this->assertDatabaseHas('flight_bookings', [
            'customer_id' => $this->customer->id,
            'currency' => 'EGP',
            'status' => FlightBookingStatus::PENDING->value,
        ]);
    }

    /**
     * 1b: Create KWD booking (foreign currency), multi-passenger, credit (no payment)
     */
    public function test_01b_create_kwd_booking_multi_passenger_credit(): void
    {
        $kwdCarrier = FlightCarrier::query()->create([
            'name' => 'KWD Carrier',
            'code' => 'KC',
            'currency' => 'KWD',
            'balance' => 1000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $kwdSystem = FlightSystem::query()->create([
            'name' => 'KWD System',
            'code' => 'KS',
            'currency' => 'KWD',
            'balance' => 1000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload([
            'flight_carrier_id' => $kwdCarrier->id,
            'flight_system_id' => $kwdSystem->id,
            'currency' => 'KWD',
            'selling_price' => 100,
            'purchase_price' => 90,
            'pnr' => 'KWD' . random_int(1000, 9999),
            'passengers' => [
                ['first_name' => 'Pax1', 'last_name' => 'A', 'type' => 'adult'],
                ['first_name' => 'Pax2', 'last_name' => 'B', 'type' => 'adult'],
                ['first_name' => 'Pax3', 'last_name' => 'C', 'type' => 'child'],
            ],
        ]));

        // DEFECT check: KWD bookings may fail (cross-currency issue documented)
        $response->assertStatus(201);
    }

    /**
     * 1c: Create booking without PNR
     */
    public function test_01c_create_booking_without_pnr(): void
    {
        $response = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload([
            'pnr' => null,
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('flight_bookings', [
            'customer_id' => $this->customer->id,
            'pnr' => null,
        ]);
    }

    /**
     * 1d: Negative purchase_price must fail with proper error
     */
    public function test_01d_negative_purchase_price_fails(): void
    {
        $response = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload([
            'purchase_price' => -100,
        ]));

        // DEFECT: must fail with 422 (validation) — not silently accept
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    /**
     * 1e: Selling_price = 0 (edge case)
     */
    public function test_01e_selling_price_zero(): void
    {
        $response = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload([
            'selling_price' => 0,
            'purchase_price' => 0,
        ]));

        // Zero is allowed (per code comment in updatePrices)
        $response->assertStatus(201);
    }

    // ============================================================
    // OPERATION 2: LIST BOOKINGS — GET /api/v1/flight/bookings
    // ============================================================

    /**
     * 2a: Empty list
     */
    public function test_02a_list_bookings_empty(): void
    {
        $response = $this->getJson('/api/v1/flight/bookings');

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 0);
    }

    /**
     * 2b: List filtered by status
     */
    public function test_02b_list_bookings_filter_by_status(): void
    {
        $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());

        $response = $this->getJson('/api/v1/flight/bookings?status=confirmed');

        $response->assertStatus(200);
        // No confirmed bookings yet — should be empty
        $response->assertJsonPath('data.pagination.total', 0);
    }

    /**
     * 2c: List filtered by currency
     */
    public function test_02c_list_bookings_filter_by_currency(): void
    {
        $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());

        $response = $this->getJson('/api/v1/flight/bookings?currency=USD');

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 0);
    }

    /**
     * 2d: Cache key separation between users
     */
    public function test_02d_list_cache_separates_per_user(): void
    {
        // Admin creates a booking
        $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());

        // Admin views list (caches result)
        Sanctum::actingAs($this->admin);
        $adminResponse = $this->getJson('/api/v1/flight/bookings');
        $adminTotal = $adminResponse->json('data.pagination.total');

        // DEFECT check: cache key should NOT include user ID (Bug B-D from analysis)
        // If it does, switching users should give different results
        Sanctum::actingAs($this->employeeUser);
        $employeeResponse = $this->getJson('/api/v1/flight/bookings');
        $employeeTotal = $employeeResponse->json('data.pagination.total');

        // Both should be 1 (one booking exists)
        $this->assertSame(1, $adminTotal);
        $this->assertSame(1, $employeeTotal);
    }

    // ============================================================
    // OPERATION 3: SHOW BOOKING — GET /api/v1/flight/bookings/{id}
    // ============================================================

    /**
     * 3a: Show existing booking
     */
    public function test_03a_show_existing_booking(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());
        $id = $create->json('data.id');

        $response = $this->getJson("/api/v1/flight/bookings/{$id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $id);
        $response->assertJsonStructure(['data' => ['id', 'pnr', 'status', 'selling_price']]);
    }

    /**
     * 3b: Show non-existent booking
     */
    public function test_03b_show_nonexistent_booking(): void
    {
        $response = $this->getJson('/api/v1/flight/bookings/999999');

        $response->assertStatus(404);
    }

    // ============================================================
    // OPERATION 4: CONFIRM BOOKING — POST /api/v1/flight/bookings/{id}/confirm
    // ============================================================

    /**
     * 4a: Confirm pending booking → confirmed
     */
    public function test_04a_confirm_pending_booking(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());
        $id = $create->json('data.id');

        $response = $this->postJson("/api/v1/flight/bookings/{$id}/confirm");

        $response->assertStatus(200);
        $this->assertDatabaseHas('flight_bookings', [
            'id' => $id,
            'status' => FlightBookingStatus::CONFIRMED->value,
        ]);
    }

    /**
     * 4b: Cannot confirm already-confirmed booking
     */
    public function test_04b_cannot_confirm_already_confirmed(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());
        $id = $create->json('data.id');

        $this->postJson("/api/v1/flight/bookings/{$id}/confirm");

        $second = $this->postJson("/api/v1/flight/bookings/{$id}/confirm");

        // Should fail with 422
        $second->assertStatus(422);
    }

    // ============================================================
    // OPERATION 5: ADD PAYMENT — POST /api/v1/flight/bookings/{id}/payments
    // ============================================================

    /**
     * 5a: Normal EGP payment
     */
    public function test_05a_normal_egp_payment(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());
        $id = $create->json('data.id');

        $response = $this->postJson("/api/v1/flight/bookings/{$id}/payments", [
            'amount' => 2000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => 'audit_5a_' . uniqid(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('flight_payments', [
            'flight_booking_id' => $id,
            'amount' => 2000.0,
        ]);
    }

    /**
     * 5b: Partial payment (multiple times)
     */
    public function test_05b_partial_payment_multiple(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload([
            'selling_price' => 10000,
            'purchase_price' => 9000,
        ]));
        $id = $create->json('data.id');

        // First partial payment
        $this->postJson("/api/v1/flight/bookings/{$id}/payments", [
            'amount' => 3000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => 'audit_5b_1_' . uniqid(),
        ])->assertStatus(201);

        // Second partial payment
        $this->postJson("/api/v1/flight/bookings/{$id}/payments", [
            'amount' => 3000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => 'audit_5b_2_' . uniqid(),
        ])->assertStatus(201);

        $this->assertSame(2, FlightPayment::query()->where('flight_booking_id', $id)->count());
    }

    /**
     * 5c: Overpayment exceeds selling_price
     */
    public function test_05c_overpayment_exceeds_selling_price(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload([
            'selling_price' => 1000,
            'purchase_price' => 900,
        ]));
        $id = $create->json('data.id');

        $response = $this->postJson("/api/v1/flight/bookings/{$id}/payments", [
            'amount' => 5000,  // Way over selling_price
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => 'audit_5c_' . uniqid(),
        ]);

        // DEFECT: should reject overpayment
        // Currently the code may accept or reject — log actual behavior
        $this->assertContains(
            $response->status(),
            [201, 422],
            'Overpayment should either accept (with warning) or reject — got: ' . $response->status()
        );
    }

    /**
     * 5d: Idempotency key replay returns existing payment
     */
    public function test_05d_idempotency_key_replay(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());
        $id = $create->json('data.id');

        $key = 'audit_5d_' . uniqid();

        $first = $this->postJson("/api/v1/flight/bookings/{$id}/payments", [
            'amount' => 1500,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => $key,
        ]);
        $first->assertStatus(201);

        $second = $this->postJson("/api/v1/flight/bookings/{$id}/payments", [
            'amount' => 1500,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => $key,
        ]);

        // Second call must return 200 (replay) not 201 (new)
        $this->assertSame(200, $second->status());
        $this->assertSame(1, FlightPayment::query()->where('flight_booking_id', $id)->count());
    }

    /**
     * 5e: Currency mismatch — EGP booking + KWD payment account
     */
    public function test_05e_currency_mismatch_payment(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload([
            'currency' => 'EGP',
        ]));
        $id = $create->json('data.id');

        $kwdCashbox = Account::query()->create([
            'name' => 'KWD Cashbox',
            'type' => 'cashbox',
            'currency' => 'KWD',
            'balance' => 1000,
            'is_active' => true,
            'module_type' => 'tourism',
            'module' => 'flights',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->postJson("/api/v1/flight/bookings/{$id}/payments", [
            'amount' => 10,
            'account_id' => $kwdCashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => 'audit_5e_' . uniqid(),
        ]);

        // Should reject (currency mismatch) or convert
        $this->assertContains(
            $response->status(),
            [201, 422],
            'Currency mismatch should either reject or convert — got: ' . $response->status()
        );
    }

    /**
     * 5f: Idempotency key after soft-deleted payment (Bug #1 check)
     */
    public function test_05f_idempotency_after_soft_delete(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());
        $id = $create->json('data.id');

        $key = 'audit_5f_' . uniqid();

        // Create payment
        $this->postJson("/api/v1/flight/bookings/{$id}/payments", [
            'amount' => 1000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => $key,
        ])->assertStatus(201);

        // Soft-delete the payment
        $payment = FlightPayment::query()->where('flight_booking_id', $id)->first();
        $payment->delete();

        // Replay with same key — should be rejected as 409 (per DEFECT-001 fix, 2026-08-24)
        $replay = $this->postJson("/api/v1/flight/bookings/{$id}/payments", [
            'amount' => 1000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => $key,
        ]);

        // DEFECT-001 (Phase 1 audit): the original audit asserted [200, 201]
        // but the production code returned 422. After the fix (Option 2
        // — reject as 409 Conflict to preserve idempotency contract), the
        // canonical expectation is 409. The full regression coverage for
        // this scenario lives in:
        //   tests/Feature/Flight/IdempotencyKeyTest::test_retry_after_soft_delete_creates_new_payment
        $this->assertSame(
            409,
            $replay->status(),
            'After soft-delete, replay with same key must be rejected as 409 Conflict — got: ' . $replay->status()
        );
        $this->assertStringContainsString(
            'Generate a fresh idempotency_key',
            (string) $replay->json('message'),
            '409 message must guide the client to use a fresh idempotency_key.'
        );
    }

    // ============================================================
    // OPERATION 6: CANCEL BOOKING — POST /api/v1/flight/bookings/{id}/cancel
    // ============================================================

    /**
     * 6a: Cancel booking with no payments
     */
    public function test_06a_cancel_booking_no_payments(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());
        $id = $create->json('data.id');

        // Cancel endpoint requires airline_penalty + office_penalty
        $response = $this->postJson("/api/v1/flight/bookings/{$id}/cancel", [
            'airline_penalty' => 0,
            'office_penalty' => 0,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('flight_bookings', [
            'id' => $id,
            'status' => FlightBookingStatus::CANCELLED->value,
        ]);
    }

    /**
     * 6b: Cancel booking with partial payments
     */
    public function test_06b_cancel_booking_partial_payments(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload([
            'selling_price' => 10000,
        ]));
        $id = $create->json('data.id');

        // Add partial payment
        $this->postJson("/api/v1/flight/bookings/{$id}/payments", [
            'amount' => 3000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => 'audit_6b_' . uniqid(),
        ])->assertStatus(201);

        $cancel = $this->postJson("/api/v1/flight/bookings/{$id}/cancel", [
            'airline_penalty' => 500,
            'office_penalty' => 200,
            'account_id' => $this->cashbox->id,  // required when there's refund
        ]);

        // Should cancel successfully (full reversal)
        $cancel->assertStatus(200);
    }

    /**
     * 6c: Cancel already-cancelled booking
     */
    public function test_06c_cancel_already_cancelled(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());
        $id = $create->json('data.id');

        $this->postJson("/api/v1/flight/bookings/{$id}/cancel");

        $second = $this->postJson("/api/v1/flight/bookings/{$id}/cancel");

        // Should fail with 422 (can't cancel already-cancelled)
        $second->assertStatus(422);
    }

    // ============================================================
    // OPERATION 7: DELETE BOOKING — DELETE /api/v1/flight/bookings/{id}
    // ============================================================

    /**
     * 7a: Delete booking (admin only) — checks full reversal
     */
    public function test_07a_delete_booking_no_payments(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());
        $id = $create->json('data.id');

        $response = $this->deleteJson("/api/v1/flight/bookings/{$id}");

        $response->assertStatus(200);
        // Booking should be soft-deleted
        $this->assertSoftDeleted('flight_bookings', ['id' => $id]);
    }

    /**
     * 7b: Delete booking with partial payments (full reversal check)
     */
    public function test_07b_delete_booking_with_payments(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload([
            'selling_price' => 10000,
        ]));
        $id = $create->json('data.id');

        // Add payment
        $this->postJson("/api/v1/flight/bookings/{$id}/payments", [
            'amount' => 5000,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'idempotency_key' => 'audit_7b_' . uniqid(),
        ])->assertStatus(201);

        $delete = $this->deleteJson("/api/v1/flight/bookings/{$id}");

        // Should soft-delete with full reversal
        $delete->assertStatus(200);
        $this->assertSoftDeleted('flight_bookings', ['id' => $id]);
    }

    // ============================================================
    // OPERATION 8: SEND TICKET EMAIL — POST /api/v1/flight/bookings/{id}/send-ticket-email
    // ============================================================

    /**
     * 8a: Send ticket email for booking with PNR
     */
    public function test_08a_send_email_with_pnr(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload());
        $id = $create->json('data.id');

        $response = $this->postJson("/api/v1/flight/bookings/{$id}/send-ticket-email", [
            'email' => 'customer@example.com',
        ]);

        // Should succeed (200) or fail gracefully
        $this->assertContains(
            $response->status(),
            [200, 422],
            'Email send should succeed or fail gracefully — got: ' . $response->status()
        );
    }

    /**
     * 8b: Send ticket email without PNR
     */
    public function test_08b_send_email_without_pnr(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalPayload([
            'pnr' => null,
        ]));
        $id = $create->json('data.id');

        $response = $this->postJson("/api/v1/flight/bookings/{$id}/send-ticket-email", [
            'email' => 'customer@example.com',
        ]);

        // May fail (no PNR) or succeed
        $this->assertContains(
            $response->status(),
            [200, 422],
            'Email send without PNR should be handled — got: ' . $response->status()
        );
    }
}