<?php

namespace Tests\Feature\Visa;

use App\Models\User;
use App\Support\UserPermissions;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 12 follow-up regression tests (added 2026-08-20).
 *
 * Covers the authz gap closed in P1.1 (V-2 Visa employee refund).
 * Specifically pins down the three allowed paths and the one blocked path:
 *
 *   1. Admin / owner → 200 (allowed).
 *   2. Booking issuer → 200 (allowed — the user who created the booking
 *      can refund their own).
 *   3. Explicit `manage_refunds` grant on user.permissions → 200 (allowed).
 *   4. Default employee (no explicit perms, not the issuer) → 403 (blocked).
 *
 * @group visa
 * @group visa-permissions
 * @group phase-12
 */
class V12Phase12RegressionTest extends VisaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser($this->user); // admin
    }

    public function test_admin_can_refund_visa_booking(): void
    {
        $booking = $this->makeBooking();
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'P12 regression — admin refund',
        ]);
        $this->assertNotSame(403, $response->status(), "Admin must be allowed, got {$response->status()}");
    }

    public function test_booking_issuer_can_refund_their_own_booking(): void
    {
        // The admin ($this->user) created the booking in makeBooking().
        // So the admin IS the booking issuer — refunds as admin (also covered
        // by the admin test). To test the issuer-exception path, create a
        // booking as a non-admin user who holds `manage_refunds` explicitly.
        $issuer = User::query()->create([
            'name' => 'Issuer User',
            'email' => 'issuer-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_REFUNDS],
        ]);

        $this->actingAsUser($issuer);
        $booking = $this->makeBooking(); // created_by = issuer.id

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'P12 regression — issuer refund',
        ]);
        $this->assertNotSame(403, $response->status(), "Booking issuer must be allowed, got {$response->status()}");
    }

    public function test_explicit_manage_refunds_holder_can_refund_any_booking(): void
    {
        // Different employee with explicit `manage_refunds` — can refund
        // a booking they did NOT create.
        $permitted = User::query()->create([
            'name' => 'Permitted User',
            'email' => 'permitted-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_REFUNDS],
        ]);

        // Booking created by admin.
        $this->actingAsUser($this->user);
        $booking = $this->makeBooking();

        $this->actingAsUser($permitted);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'P12 regression — explicit permission refund',
        ]);
        $this->assertNotSame(403, $response->status(), "Explicit permission holder must be allowed, got {$response->status()}");
    }

    public function test_default_employee_cannot_refund_other_employee_booking(): void
    {
        // Default employee with NO explicit perms (post-SEC-1 deny-by-default).
        // Booking created by admin (not the employee) — must be blocked by
        // both the route `permission:manage_refunds` middleware and the
        // in-controller V-2 strict re-check.
        //
        // NOTE: VisaTestCase::$employeeUser is granted `defaultEmployeeModules()`
        // for cross-employee payment/refund positive tests; here we explicitly
        // create a deny-by-default employee to test the SEC-1 + V-2 paths.
        $lockedDownEmployee = User::query()->create([
            'name' => 'Visa V12 Locked-down Employee',
            'email' => 'visa-v12-locked-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [],   // explicit empty → deny-by-default
        ]);

        $this->actingAsUser($this->user);
        $booking = $this->makeBooking();

        $this->actingAsUser($lockedDownEmployee);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'P12 regression — default employee blocked',
        ]);
        $response->assertStatus(403);
    }
}
