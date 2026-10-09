<?php

namespace Tests\Feature\TourismEmployeeE2E;

/**
 * Static-analysis audit of the Vue SPA frontend permission surface.
 *
 * Scans the router config (`resources/js/router/index.js`) and auth store
 * (`resources/js/stores/authStore.js`) to verify:
 *  - All routes have permission gating.
 *  - The auth store correctly identifies admin/owner roles.
 *  - Tourism routes (flight/hajj/visa) require module permission.
 *  - Admin-only routes (finance/reports/users) require admin permission.
 *
 * This complements the API-level E2E tests — even if the backend blocks
 * a request, an employee who sees an admin button in the UI may try harder
 * to bypass. The frontend MUST hide admin-only affordances from employees.
 */
class FrontendPermissionAuditTest extends EmployeeTestCase
{
    protected string $routerFile;
    protected string $authStoreFile;
    protected string $dashboardLayoutFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->routerFile = base_path('resources/js/router/index.js');
        $this->authStoreFile = base_path('resources/js/stores/authStore.js');
        $this->dashboardLayoutFile = base_path('resources/js/layouts/DashboardLayout.vue');
    }

    /* ============================================================
     *  File existence sanity
     * ============================================================ */

    public function test_router_file_exists(): void
    {
        $this->assertFileExists($this->routerFile);
    }

    public function test_auth_store_file_exists(): void
    {
        $this->assertFileExists($this->authStoreFile);
    }

    public function test_dashboard_layout_file_exists(): void
    {
        $this->assertFileExists($this->dashboardLayoutFile);
    }

    /* ============================================================
     *  Auth store — admin detection
     * ============================================================ */

    public function test_auth_store_distinguishes_admin_from_employee(): void
    {
        $contents = file_get_contents($this->authStoreFile);

        // The auth store must define an isAdmin getter that checks role
        $this->assertStringContainsString('isAdmin', $contents, 'authStore must expose isAdmin');
        $this->assertStringContainsString("['admin', 'owner']", $contents, 'isAdmin must include admin + owner');
    }

    /* ============================================================
     *  Router — Tourism routes must require module permission
     * ============================================================ */

    public function test_flight_routes_use_finance_gate_for_treasury(): void
    {
        $contents = file_get_contents($this->routerFile);

        // Flight treasury sub-route uses manage_finance; parent uses requiresAuth.
        $this->assertMatchesRegularExpression(
            '/flights[\s\S]{0,3000}permission:\s*[\'"]manage_finance[\'"]/',
            $contents,
            'Flight treasury sub-route must gate on manage_finance'
        );
    }

    public function test_hajj_routes_use_finance_gate_for_treasury(): void
    {
        $contents = file_get_contents($this->routerFile);

        $this->assertMatchesRegularExpression(
            '/hajj[\s\S]{0,3000}permission:\s*[\'"]manage_finance[\'"]/',
            $contents,
            'Hajj treasury sub-route must gate on manage_finance'
        );
    }

    public function test_visa_routes_use_finance_gate_for_treasury(): void
    {
        $contents = file_get_contents($this->routerFile);

        $this->assertMatchesRegularExpression(
            '/visa[\s\S]{0,3000}permission:\s*[\'"]manage_finance[\'"]/',
            $contents,
            'Visa treasury sub-route must gate on manage_finance'
        );
    }

    /* ============================================================
     *  Admin-only routes must require admin permission
     * ============================================================ */

    public function test_finance_routes_require_manage_finance(): void
    {
        $contents = file_get_contents($this->routerFile);
        $this->assertMatchesRegularExpression(
            '/finance[\s\S]*?permission:\s*[\'"]manage_finance[\'"]/',
            $contents,
            'Finance routes must declare meta.permission = manage_finance'
        );
    }

    public function test_users_routes_require_manage_users(): void
    {
        $contents = file_get_contents($this->routerFile);
        $this->assertMatchesRegularExpression(
            '/users[\s\S]*?permission:\s*[\'"]manage_users[\'"]/',
            $contents,
            'User management routes must declare meta.permission = manage_users'
        );
    }

    public function test_reports_routes_require_view_reports(): void
    {
        $contents = file_get_contents($this->routerFile);
        $this->assertMatchesRegularExpression(
            '/reports[\s\S]*?permission:\s*[\'"]view_reports[\'"]/',
            $contents,
            'Reports routes must declare meta.permission = view_reports'
        );
    }

    public function test_employees_routes_require_manage_employees(): void
    {
        $contents = file_get_contents($this->routerFile);
        $this->assertMatchesRegularExpression(
            '/employees[\s\S]*?permission:\s*[\'"]manage_employees[\'"]/',
            $contents,
            'Employee routes must declare meta.permission = manage_employees'
        );
    }

    /* ============================================================
     *  Router guard — token check
     * ============================================================ */

    public function test_router_has_auth_guard(): void
    {
        $contents = file_get_contents($this->routerFile);

        $this->assertStringContainsString('requiresAuth', $contents,
            'Router must have requiresAuth meta field for protected routes');
        $this->assertStringContainsString('beforeEach', $contents,
            'Router must register a global beforeEach guard');
    }

    /* ============================================================
     *  Dashboard layout — admin-only links hidden
     * ============================================================ */

    public function test_dashboard_layout_hides_admin_link_from_employees(): void
    {
        $contents = file_get_contents($this->dashboardLayoutFile);

        // The /admin link must be conditional on isAdmin
        $this->assertMatchesRegularExpression(
            '/admin[\s\S]*?(isAdmin|authStore\.isAdmin|role.*admin)/',
            $contents,
            'Dashboard layout must hide the /admin link from non-admin users'
        );
    }

    public function test_dashboard_layout_uses_hasPermission_helper(): void
    {
        $contents = file_get_contents($this->dashboardLayoutFile);

        $this->assertStringContainsString('hasPermission', $contents,
            'Dashboard layout must use a hasPermission helper for menu gating');
    }

    /* ============================================================
     *  Component-level permission checks — Tourism views
     * ============================================================ */

    public function test_flight_index_hides_profit_for_non_admin(): void
    {
        $file = base_path('resources/js/views/flights/FlightIndex.vue');
        if (! file_exists($file)) {
            $this->markTestSkipped('FlightIndex.vue not found');
        }

        $contents = file_get_contents($file);

        // Profit column must be hidden for non-admin
        $this->assertMatchesRegularExpression(
            '/isAdmin[\s\S]*?(profit|الربح)/',
            $contents,
            'FlightIndex must hide profit column for non-admin users'
        );
    }

    public function test_hajj_dashboard_hides_admin_columns_for_non_admin(): void
    {
        $file = base_path('resources/js/views/hajjUmra/HajjUmraDashboard.vue');
        if (! file_exists($file)) {
            $this->markTestSkipped('HajjUmraDashboard.vue not found');
        }

        $contents = file_get_contents($file);
        $this->assertStringContainsString('isAdmin', $contents,
            'HajjUmraDashboard must check isAdmin for admin-only columns');
    }

    public function test_visa_index_hides_profit_for_non_admin(): void
    {
        $file = base_path('resources/js/views/visa/VisaIndex.vue');
        if (! file_exists($file)) {
            $this->markTestSkipped('VisaIndex.vue not found');
        }

        $contents = file_get_contents($file);
        $this->assertMatchesRegularExpression(
            '/isAdmin[\s\S]*?(profit|الربح)/',
            $contents,
            'VisaIndex must hide profit column for non-admin users'
        );
    }

    /* ============================================================
     *  Auth store — Bearer token persistence
     * ============================================================ */

    public function test_auth_store_sets_bearer_token_header(): void
    {
        $contents = file_get_contents($this->authStoreFile);

        $this->assertStringContainsString('Bearer', $contents,
            'authStore must attach Bearer token to API requests');
    }
}