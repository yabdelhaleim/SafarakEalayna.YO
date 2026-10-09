<?php

namespace Tests\Feature\Flight;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\User;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2 (B-1) — Flight payment IDOR regression test.
 *
 * Defect B-1 (Tourism Full Audit 2026-08-18):
 *   Before the fix, any authenticated user with `manage_flights` permission
 *   could record a payment against ANY flight booking — regardless of which
 *   customer owned the booking or which employee created it. This is a
 *   classic Insecure Direct Object Reference (IDOR) that lets Employee A
 *   move money against Customer B's booking.
 *
 * Fix:
 *   * New `App\Policies\FlightBookingPolicy::pay()` gates the endpoint.
 *   * `FlightController::addPayment` calls `$this->authorize('pay', $flightBooking)`
 *     before touching the service.
 *   * `StoreFlightPaymentRequest::prepareForValidation()` already whitelists
 *     allowed fields (amount, payment_method, account_id, notes, idempotency_key)
 *     — customer_id was never in the FormRequest and remains absent.
 *
 * Test coverage:
 *   ✅ Admin / owner → can pay any booking (oversight path).
 *   ✅ Owning employee (employee_id matches user->employee->id) → can pay.
 *   ✅ Other employee → 403 Forbidden (the B-1 fix itself).
 *   ✅ Random authenticated user → 403 Forbidden.
 *   ✅ 403 response must NOT mutate any account balance / create any payment row.
 *   ✅ FormRequest rejects `customer_id` if smuggled in the payload (already enforced
 *      by prepareForValidation whitelist — we re-verify it explicitly here).
 *
 * @see \App\Policies\FlightBookingPolicy::pay
 * @see \App\Http\Controllers\Api\V1\Flight\FlightController::addPayment
 */
class FlightPaymentOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $owner;

    protected User $cashier1;

    protected User $cashier2;

    protected User $randomUser;

    protected Customer $customer;

    protected FlightBooking $booking;

    /**
     * Cashbox-like account used for the payment. Marked as tourism-division
     * so the new `AccountModuleContract::OFFICE_MODULE_TYPE` /
     * `TOURISM_MODULE_TYPE` validation accepts it (Account.php:253).
     */
    protected $cashbox;

    protected function setUp(): void
    {
        parent::setUp();

        // ── Users (with linked Employees for cashier1/cashier2) ────────────
        $this->admin = User::query()->create([
            'name' => 'Flight Admin',
            'email' => 'flight-admin-b1@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->owner = User::query()->create([
            'name' => 'Flight Owner',
            'email' => 'flight-owner-b1@example.com',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $cashier1Emp = Employee::query()->create([
            'first_name' => 'Cashier',
            'last_name' => 'One',
            'user_id' => null, // set below
            'is_active' => true,
        ]);
        $this->cashier1 = User::query()->create([
            'name' => 'Cashier One',
            'email' => 'flight-cashier-1-b1@example.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'is_active' => true,
        ]);
        $cashier1Emp->update(['user_id' => $this->cashier1->id]);

        $cashier2Emp = Employee::query()->create([
            'first_name' => 'Cashier',
            'last_name' => 'Two',
            'user_id' => null,
            'is_active' => true,
        ]);
        $this->cashier2 = User::query()->create([
            'name' => 'Cashier Two',
            'email' => 'flight-cashier-2-b1@example.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'is_active' => true,
        ]);
        $cashier2Emp->update(['user_id' => $this->cashier2->id]);

        $this->randomUser = User::query()->create([
            'name' => 'Random Authenticated',
            'email' => 'flight-random-b1@example.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'is_active' => true,
        ]);

        // ── Customer + Booking (created by cashier1) ──────────────────────
        $this->customer = Customer::query()->create([
            'full_name' => 'Test Customer B1',
            'phone' => '01000000001',
            'is_active' => true,
            'created_by' => $this->cashier1->id,
        ]);

        // Cashbox account (tourism division — required by the new contract).
        $this->cashbox = \App\Models\Account::query()->create([
            'name' => 'B1 Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => AccountModuleContract::TOURISM_MODULE_TYPE,
            'is_module_vault' => true,
            'balance' => 10000,
            'created_by' => $this->cashier1->id,
        ]);

        // Pre-create a FlightBooking owned by cashier1 → customer.
        // Required NOT NULL fields per database/migrations/2026_04_26_211424_create_flight_bookings_table.php
        $uniq = uniqid();
        $this->booking = FlightBooking::query()->create([
            'booking_number' => 'FLT-B1-'.$uniq,
            'booking_reference' => 'FLT-B1-REF-'.$uniq,
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'office',
            'customer_id' => $this->customer->id,
            'employee_id' => $cashier1Emp->id,
            'created_by' => $this->cashier1->id,
            'agent_name' => 'Cashier One',
            'origin' => 'CAI',
            'destination' => 'DXB',
            'departure_date' => '2026-09-01',
            'departure_time' => '10:00:00',
            'trip_type' => 'one_way',
            'airline' => 'EK',
            'passenger_count' => 1,
            'currency' => 'EGP',
            'selling_price' => 1000,
            'purchase_price' => 900,
            'status' => 'PENDING',
            'account_id' => $this->cashbox->id,
        ]);
    }

    /**
     * ✅ 1) Admin can pay any booking — oversight / correction path.
     */
    public function test_admin_can_pay_any_flight_booking(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson(
            "/api/v1/flight/bookings/{$this->booking->id}/payments",
            [
                'amount' => 500,
                'payment_method' => 'cash',
                'account_id' => $this->cashbox->id,
            ]
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('flight_payments', [
            'flight_booking_id' => $this->booking->id,
            'amount' => 500,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * ✅ 2) Owner role can pay any booking — oversight / correction path.
     */
    public function test_owner_can_pay_any_flight_booking(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson(
            "/api/v1/flight/bookings/{$this->booking->id}/payments",
            [
                'amount' => 500,
                'payment_method' => 'cash',
                'account_id' => $this->cashbox->id,
            ]
        );

        $response->assertStatus(201);
    }

    /**
     * ✅ 3) The booking's owning employee CAN pay their own booking —
     *    the legitimate cashier flow.
     */
    public function test_owning_employee_can_pay_their_own_booking(): void
    {
        Sanctum::actingAs($this->cashier1);

        $response = $this->postJson(
            "/api/v1/flight/bookings/{$this->booking->id}/payments",
            [
                'amount' => 500,
                'payment_method' => 'cash',
                'account_id' => $this->cashbox->id,
            ]
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('flight_payments', [
            'flight_booking_id' => $this->booking->id,
            'created_by' => $this->cashier1->id,
        ]);
    }

    /**
     * ✅ 4) B-1 IDOR FIX — another employee CANNOT pay a booking that
     *    belongs to a different customer/employee. This is the core test:
     *    before the fix, this returned 201; after the fix, it must return 403.
     */
    public function test_other_employee_cannot_pay_someone_elses_flight_booking(): void
    {
        Sanctum::actingAs($this->cashier2);

        $cashboxBalanceBefore = $this->cashbox->balance;
        $booking = $this->booking;

        $response = $this->postJson(
            "/api/v1/flight/bookings/{$booking->id}/payments",
            [
                'amount' => 500,
                'payment_method' => 'cash',
                'account_id' => $this->cashbox->id,
            ]
        );

        // ── B-1 fix: must return 403 Forbidden ────────────────────────────
        $response->assertStatus(403);

        // ── No financial mutation: zero payments must have been recorded ──
        $this->assertDatabaseMissing('flight_payments', [
            'flight_booking_id' => $booking->id,
            'created_by' => $this->cashier2->id,
        ]);

        // ── Cashbox balance unchanged ─────────────────────────────────────
        $this->assertEquals(
            $cashboxBalanceBefore,
            $this->cashbox->fresh()->balance,
            'Cashbox balance must not change when a 403 is returned'
        );
    }

    /**
     * ✅ 5) Random authenticated user (no admin role, no employee link) is
     *    also forbidden from paying. Even with `manage_flights` permission,
     *    the policy gate stops them.
     */
    public function test_random_user_cannot_pay_any_flight_booking(): void
    {
        Sanctum::actingAs($this->randomUser);

        $response = $this->postJson(
            "/api/v1/flight/bookings/{$this->booking->id}/payments",
            [
                'amount' => 500,
                'payment_method' => 'cash',
                'account_id' => $this->cashbox->id,
            ]
        );

        $response->assertStatus(403);

        $this->assertDatabaseMissing('flight_payments', [
            'flight_booking_id' => $this->booking->id,
            'created_by' => $this->randomUser->id,
        ]);
    }

    /**
     * ✅ 6) Unauthenticated request is rejected with 401, NOT 403 — Laravel
     *    distinguishes "not logged in" from "logged in but not allowed".
     */
    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $response = $this->postJson(
            "/api/v1/flight/bookings/{$this->booking->id}/payments",
            [
                'amount' => 500,
                'payment_method' => 'cash',
                'account_id' => $this->cashbox->id,
            ]
        );

        $response->assertStatus(401);
    }

    /**
     * ✅ 7) The customer_id is NEVER accepted from the request payload.
     *    Even admin cannot smuggle in a different customer_id — the
     *    StoreFlightPaymentRequest whitelist rejects unknown fields.
     */
    public function test_customer_id_in_payload_is_rejected_by_form_request_whitelist(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson(
            "/api/v1/flight/bookings/{$this->booking->id}/payments",
            [
                'amount' => 500,
                'payment_method' => 'cash',
                'account_id' => $this->cashbox->id,
                'customer_id' => 99999, // smuggled attempt
            ]
        );

        // FormRequest rejects unknown fields with 422.
        $response->assertStatus(422);

        // No payment was created.
        $this->assertDatabaseMissing('flight_payments', [
            'flight_booking_id' => $this->booking->id,
            'amount' => 500,
        ]);
    }

    /**
     * ✅ 8) The cancel endpoint applies the same authorization gate. A
     *    different employee cannot cancel another employee's booking.
     */
    public function test_other_employee_cannot_cancel_someone_elses_flight_booking(): void
    {
        Sanctum::actingAs($this->cashier2);

        // Mark the booking as PENDING so cancel logic gets past the
        // "Cannot cancel non-PENDING" early-return (sanity check that the
        // 403 comes from the policy, not from a downstream validation).
        $response = $this->postJson(
            "/api/v1/flight/bookings/{$this->booking->id}/cancel",
            [
                'airline_penalty' => 0,
                'office_penalty' => 0,
            ]
        );

        $response->assertStatus(403);

        $this->assertDatabaseHas('flight_bookings', [
            'id' => $this->booking->id,
            'status' => 'PENDING', // not touched
        ]);
    }
}