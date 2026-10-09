<?php

namespace Tests\Feature\HajjUmra;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8 / Option A — regression tests for HajjUmra read-endpoint
 * authorization.
 *
 * B-1 finding fixed (2026-08-19): the HajjUmra customer-balances and
 * customer-statement endpoints (routes/api.php:564-565) were reachable
 * by any authenticated user, allowing cross-customer financial info
 * disclosure. Both are now `admin`-gated.
 */
class HajjUmraRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'HajjUmra Auth Tester Admin',
            'email' => 'hajjumra-auth-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->employee = User::query()->create([
            'name' => 'HajjUmra Auth Tester Employee',
            'email' => 'hajjumra-auth-employee@example.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
            'permissions' => json_encode(['manage_flights', 'manage_hajj']),
        ]);
    }

    public function test_get_hajjumra_customer_balances_employee_forbidden(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $response = $this->getJson('/api/v1/hajj-umra/customer-balances');

        $response->assertForbidden();
    }

    public function test_get_hajjumra_customer_statement_employee_forbidden(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $response = $this->getJson('/api/v1/hajj-umra/customer-statement?client_id=1');

        $response->assertForbidden();
    }
}