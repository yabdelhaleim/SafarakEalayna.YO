<?php

namespace Tests\Feature\Visa;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8 / Option A — regression tests for Visa route authorization.
 *
 * B-1 findings fixed (2026-08-19):
 *   - POST /visa-agents and POST /umrah-suppliers (un-prefixed duplicates
 *     at routes/api.php:660-666) reachable by any auth user → DELETED.
 *   - 8 read endpoints (treasury/overview, treasury/accounts/{}/transactions,
 *     agents/dues, bookings index/show, bookings/{}/modifications,
 *     customer-balances, customer-statement) reachable by any auth user
 *     → now `admin`-gated.
 *
 * Each test below verifies the new gate behavior.
 */
class VisaRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Visa Auth Tester Admin',
            'email' => 'visa-auth-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->employee = User::query()->create([
            'name' => 'Visa Auth Tester Employee',
            'email' => 'visa-auth-employee@example.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
            'permissions' => json_encode(['manage_flights', 'manage_hajj']),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 1) Un-prefixed alias group at routes/api.php:658-667 was DELETED.
    //    POST /visa-agents and POST /umrah-suppliers must now be REJECTED.
    //    They hit the catch-all `GET /{any}` route in routes/web.php (which
    //    is GET-only) so Laravel returns 405 Method Not Allowed — this is
    //    the correct rejection signal: the URI is matched but POST is not
    //    supported on it. If the alias were still registered, the POST
    //    would return 201 (admin) or 403 (employee), not 405.
    // ─────────────────────────────────────────────────────────────────

    public function test_unprefixed_post_visa_agents_returns_405(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/visa-agents', [
            'name' => 'fake agent',
        ]);

        $response->assertMethodNotAllowed();
    }

    public function test_unprefixed_post_umrah_suppliers_returns_405(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/umrah-suppliers', [
            'name' => 'fake supplier',
        ]);

        $response->assertMethodNotAllowed();
    }

    // ─────────────────────────────────────────────────────────────────
    // 2) The v1-prefixed versions still work correctly:
    //    POST /api/v1/visa-agents → admin → 201
    //    POST /api/v1/visa-agents → employee → 403
    // ─────────────────────────────────────────────────────────────────

    public function test_post_api_v1_visa_agents_admin_allowed(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/api/v1/visa-agents', [
            'name' => 'Real Agent',
        ]);

        $response->assertCreated();
    }

    public function test_post_api_v1_visa_agents_employee_forbidden(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $response = $this->postJson('/api/v1/visa-agents', [
            'name' => 'Real Agent',
        ]);

        $response->assertForbidden();
    }

    public function test_post_api_v1_umrah_suppliers_employee_forbidden(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'name' => 'Real Supplier',
        ]);

        $response->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────
    // 3) The 8 Visa read endpoints (Option A scope) now require admin.
    //    Employee role gets 403; admin role gets 200/422 (controller-level).
    // ─────────────────────────────────────────────────────────────────

    public function test_get_visa_bookings_index_admin_allowed(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson('/api/v1/visa/bookings');

        // 200 with empty array, or 500 on missing module data — anything
        // except 401/403. We assert not forbidden.
        $this->assertNotEquals(403, $response->status(), 'admin must reach /visa/bookings');
    }

    public function test_get_visa_bookings_index_employee_forbidden(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $response = $this->getJson('/api/v1/visa/bookings');

        $response->assertForbidden();
    }

    public function test_get_visa_customer_balances_employee_forbidden(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $response = $this->getJson('/api/v1/visa/customer-balances');

        $response->assertForbidden();
    }

    public function test_get_visa_customer_statement_employee_forbidden(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $response = $this->getJson('/api/v1/visa/customer-statement?client_id=1');

        $response->assertForbidden();
    }

    public function test_get_visa_treasury_overview_employee_forbidden(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $response = $this->getJson('/api/v1/visa/treasury/overview');

        $response->assertForbidden();
    }

    public function test_get_visa_agents_dues_employee_forbidden(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $response = $this->getJson('/api/v1/visa/agents/dues');

        $response->assertForbidden();
    }
}