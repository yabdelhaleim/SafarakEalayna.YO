<?php

namespace Tests\Feature\Visa;

use App\Models\HajjUmra\VisaAgent;
use App\Models\User;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * PHASE 15: Permissions — verify each role's access to each endpoint.
 *
 * Roles (project convention):
 *   - admin / owner  → full access (bypass permission checks)
 *   - employee       → must hold required permission
 *   - readonly       → view only
 *   - unauthenticated → 401
 *
 * Admin-only endpoints (from routes/api.php):
 *   - DELETE /visa/bookings/{id}
 *   - POST /visa/bookings/{id}/cancel
 *   - POST /visa/bookings/{id}/refund
 *   - POST /visa/agents/{id}/withdraw
 *   - POST /visa/agents/{id}/repay
 *   - POST /visa/customers/{id}/pay-debt
 *
 * @group visa
 * @group visa-permissions
 */
class VisaPermissionTest extends VisaTestCase
{
    protected function makeUser(string $role, array $permissions = []): User
    {
        return User::query()->create([
            'name' => "Test {$role}",
            'email' => "{$role}-".uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
            'permissions' => $permissions,
        ]);
    }

    // ─── Admin can do everything ──────────────────────────────────────────

    public function test_admin_can_list_bookings(): void
    {
        $this->actingAsUser($this->user);  // already admin
        $response = $this->getJson('/api/v1/visa/bookings');
        $response->assertOk();
    }

    public function test_admin_can_create_booking(): void
    {
        $this->actingAsUser($this->user);
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());
        $response->assertCreated();
    }

    public function test_admin_can_cancel_booking(): void
    {
        $booking = $this->makeBooking();
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'admin cancel',
        ]);
        $response->assertOk();
    }

    public function test_admin_can_refund_booking(): void
    {
        $booking = $this->makeBooking();
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'admin refund',
        ]);
        $response->assertOk();
    }

    public function test_admin_can_delete_booking(): void
    {
        $booking = $this->makeBooking();
        $response = $this->deleteJson("/api/v1/visa/bookings/{$booking->id}");
        $response->assertOk();
    }

    // ─── Employee cannot perform admin-only ops ───────────────────────────

    public function test_employee_cannot_cancel_booking(): void
    {
        $booking = $this->makeBooking();
        $this->actingAsUser($this->employeeUser);

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'employee cancel attempt',
        ]);

        $this->assertContains($response->status(), [403, 401],
            'employee must not cancel — got: '.$response->status());
    }

    public function test_employee_cannot_refund_booking(): void
    {
        $booking = $this->makeBooking();
        $this->actingAsUser($this->employeeUser);

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'employee refund attempt',
        ]);

        $this->assertContains($response->status(), [403, 401]);
    }

    public function test_employee_cannot_delete_booking(): void
    {
        $booking = $this->makeBooking();
        $this->actingAsUser($this->employeeUser);

        $response = $this->deleteJson("/api/v1/visa/bookings/{$booking->id}");

        $this->assertContains($response->status(), [403, 401]);
    }

    public function test_employee_cannot_pay_customer_debt(): void
    {
        $this->actingAsUser($this->employeeUser);

        $response = $this->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'amount' => 100.0,
            'account_id' => $this->vaultEgp->id,
        ]);

        $this->assertContains($response->status(), [403, 401]);
    }

    public function test_employee_cannot_withdraw_from_agent(): void
    {
        $response = $this->postJson("/api/v1/visa/agents/{$this->agent->id}/withdraw", [
            'amount' => 100.0,
            'to_account_id' => $this->vaultEgp->id,
        ]);

        $this->actingAsUser($this->employeeUser);
        $response = $this->postJson("/api/v1/visa/agents/{$this->agent->id}/withdraw", [
            'amount' => 100.0,
            'to_account_id' => $this->vaultEgp->id,
        ]);

        $this->assertContains($response->status(), [403, 401]);
    }

    // ─── Employee CAN read ────────────────────────────────────────────────

    public function test_employee_can_list_bookings(): void
    {
        $this->makeBooking();
        $this->actingAsUser($this->employeeUser);

        $response = $this->getJson('/api/v1/visa/bookings');
        $response->assertOk();
    }

    public function test_employee_can_show_booking(): void
    {
        $booking = $this->makeBooking();
        $this->actingAsUser($this->employeeUser);

        $response = $this->getJson("/api/v1/visa/bookings/{$booking->id}");
        $response->assertOk();
    }

    public function test_employee_can_add_payment(): void
    {
        $booking = $this->makeBooking();
        $this->actingAsUser($this->employeeUser);

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        // Payment is NOT marked admin-only in routes
        $response->assertCreated();
    }

    // ─── Inactive user ────────────────────────────────────────────────────

    public function test_inactive_user_returns_401_or_403(): void
    {
        $inactive = $this->makeUser('admin');
        $inactive->update(['is_active' => false]);

        Sanctum::actingAs($inactive, ['*']);

        $response = $this->getJson('/api/v1/visa/bookings');
        $this->assertContains($response->status(), [401, 403]);
    }

    // ─── Unauthenticated ──────────────────────────────────────────────────

    public function test_unauthenticated_returns_401(): void
    {
        // Reset auth entirely
        auth()->forgetGuards();

        $response = $this->getJson('/api/v1/visa/bookings');
        $this->assertSame(401, $response->status(),
            'unauthenticated requests must return 401');
    }

    public function test_unauthenticated_create_returns_401(): void
    {
        auth()->forgetGuards();

        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());
        $this->assertSame(401, $response->status());
    }

    public function test_unauthenticated_admin_endpoint_returns_401(): void
    {
        auth()->forgetGuards();

        $response = $this->deleteJson('/api/v1/visa/bookings/1');
        $this->assertContains($response->status(), [401, 403]);
    }
}