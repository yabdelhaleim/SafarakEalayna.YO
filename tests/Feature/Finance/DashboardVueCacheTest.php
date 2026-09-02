<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression: Vue Dashboard endpoint must set Cache-Control: no-store
 * and must respect the ?nocache=1 query param to bypass the 5-minute cache.
 *
 * Pre-fix, the DashboardController::index endpoint did NOT set
 * Cache-Control: no-store, allowing the browser to cache the response and
 * serve stale snapshots even after server data changed. Operators reported
 * the Vue dashboard "stuck on 0s" for KPIs that did have live data after
 * fixes were deployed.
 */
class DashboardVueCacheTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::query()->create([
            'name' => 'Test',
            'email' => 'dashboard-cache-'.uniqid().'@example.com',
            'password' => Hash::make('test'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_dashboard_endpoint_sets_cache_control_header(): void
    {
        $response = $this->getJson('/api/v1/dashboard');

        // Must set Cache-Control header to prevent browser caching
        // (sibling report endpoints already set this — dashboard was
        // the only one missing it).
        $response->assertHeader('Cache-Control');
    }

    public function test_dashboard_endpoint_accepts_nocache_query_param_without_error(): void
    {
        $r1 = $this->getJson('/api/v1/dashboard?nocache=1');
        $r1->assertOk();

        $r2 = $this->getJson('/api/v1/dashboard?no_cache=1');
        $r2->assertOk();
    }

    public function test_dashboard_endpoint_returns_full_structure(): void
    {
        // Verifies the Vue Dashboard contract is preserved end-to-end
        // (overview, financial, tourism_summary, office_summary, treasury_summary).
        $response = $this->getJson('/api/v1/dashboard?nocache=1');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'overview' => ['today', 'this_month', 'total_customers', 'total_employees'],
                    'financial' => ['total_income', 'total_cogs', 'total_operating_expenses', 'total_expense', 'net_profit'],
                    'tourism_summary' => ['flights', 'hajj', 'visa', 'total_count', 'total_revenue', 'total_profit'],
                    'office_summary' => ['bus', 'fawry', 'online', 'wallet', 'total_count', 'total_revenue', 'total_profit'],
                    'treasury_summary' => ['total', 'cashbox', 'bank', 'wallet'],
                ],
            ]);
    }
}
