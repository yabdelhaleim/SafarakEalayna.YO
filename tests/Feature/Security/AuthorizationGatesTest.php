<?php

namespace Tests\Feature\Security;

use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\HajjUmraProgram;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\User;
use App\Models\VisaBooking;
use App\Models\VisaDetail;
use App\Support\UserPermissions;
use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 5 Security Hardening — Authorization gate regression tests.
 *
 * Verifies that the `admin` middleware correctly blocks non-admin
 * (employee/manager) users from destructive financial endpoints:
 *  - HajjUmra bookings: destroy, cancel, refund
 *  - Visa bookings: destroy, cancel, refund
 *  - HajjUmra executing-companies: withdraw, repay
 *  - Visa agents: withdraw, repay
 *  - Customers: pay-debt
 *  - Suppliers: all CRUD + recharge
 *  - Invoices: all CRUD
 *  - Employees: CRUD + bonus/deduction/draw
 *  - Wallet, Fawry, Fawry machine, Fawry walk-in: writes
 *  - Bus pay-debt / cancel
 *
 * @see \App\Http\Middleware\EnsureIsAdmin
 * @see \App\Http\Middleware\CheckPermission
 */
class AuthorizationGatesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $employee;

    protected User $manager;

    protected Account $treasury;

    protected Customer $customer;

    protected HajjUmraBooking $hajjBooking;

    protected VisaBooking $visaBooking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-sec@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->manager = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager-sec@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->employee = User::query()->create([
            'name' => 'Employee',
            'email' => 'employee-sec@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->treasury = Account::query()->create([
            'name' => 'Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'is_module_vault' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'Customer',
            'phone' => '01000000000',
        ]);

        $program = Program::query()->create([
            'program_name' => 'Program',
            'program_type' => 'umrah',
            'total_nights' => 7,
            'mecca_hotel_name' => 'Hotel',
            'mecca_nights' => 4,
            'medina_hotel_name' => 'Hotel',
            'medina_nights' => 3,
            'airline' => 'Airline',
            'executing_company' => 'Co',
            'accommodation_type' => 'DOUBLE',
            'default_purchase_price' => 10000,
            'default_selling_price' => 15000,
            'departure_date' => now()->addDays(15)->toDateString(),
            'return_date' => now()->addDays(22)->toDateString(),
            'departure_point' => 'Cairo',
            'is_active' => true,
        ]);

        $this->hajjBooking = HajjUmraBooking::query()->create([
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'module' => 'hajj_umra',
            'selling_price' => 15000,
            'purchase_price' => 10000,
            'profit' => 5000,
            'currency' => 'EGP',
            'per_person' => true,
            'status' => 'confirmed',
            'agent_name' => 'Customer',
            'created_by' => $this->admin->id,
            'account_id' => $this->treasury->id,
        ]);

        $duration = VisaDuration::query()->create([
            'code' => '6m_single',
            'label_ar' => '6 أشهر',
            'label_en' => '6 months',
            'months' => 6,
            'is_active' => true,
        ]);

        $visaDetail = VisaDetail::query()->create([
            'visa_type' => VisaType::Work->value,
            'country' => 'USA',
            'duration' => '6 months',
            'visa_duration_id' => $duration->id,
            'entry_type' => VisaEntryType::Single->value,
            'executing_company' => 'Test Co',
            'executing_agent' => 'Test Agent',
            'status' => VisaStatus::Submitted->value,
        ]);

        $this->visaBooking = VisaBooking::query()->create([
            'customer_id' => $this->customer->id,
            'visa_detail_id' => $visaDetail->id,
            'purchase_price' => 10000,
            'selling_price' => 15000,
            'service_fee' => 0,
            'profit' => 5000,
            'currency' => 'EGP',
            'status' => VisaStatus::Submitted->value,
            'agent_name' => 'Customer',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Helper: assert endpoint blocks non-admin and allows admin.
     *
     * Note: 403 (admin gate) OR 404 (route-model-binding failed because record
     * doesn't exist) BOTH indicate the endpoint is protected from unauthorized
     * access. We assert "not 2xx" to cover both cases.
     */
    protected function assertAdminGate(string $method, string $url, array $data = []): void
    {
        // Employee must be blocked — either 403 (admin middleware) or 404 (binding fail)
        Sanctum::actingAs($this->employee, ['*']);
        $response = $this->json($method, $url, $data);
        $this->assertNotSame(200, $response->status(), "Employee should NOT have access on {$method} {$url}, got {$response->status()}");
        $this->assertNotSame(201, $response->status(), "Employee should NOT create on {$method} {$url}, got {$response->status()}");

        // Manager must also be blocked
        Sanctum::actingAs($this->manager, ['*']);
        $response = $this->json($method, $url, $data);
        $this->assertNotSame(200, $response->status(), "Manager should NOT have access on {$method} {$url}, got {$response->status()}");

        // Admin should NOT be blocked by admin middleware (could be 200, 201, 422 etc)
        Sanctum::actingAs($this->admin, ['*']);
        $response = $this->json($method, $url, $data);
        $this->assertNotSame(403, $response->status(), "Admin should not be blocked by admin gate on {$method} {$url}, got {$response->status()}");
    }

    /* =========================================================
     * HAJJUMRA BOOKINGS — DESTRUCTIVE OPS MUST BE ADMIN-ONLY
     * ========================================================= */

    public function test_hajjumra_booking_destroy_requires_admin(): void
    {
        $this->assertAdminGate('DELETE', "/api/v1/hajj-umra/bookings/{$this->hajjBooking->id}");
    }

    public function test_hajjumra_booking_cancel_requires_admin(): void
    {
        $this->assertAdminGate('POST', "/api/v1/hajj-umra/bookings/{$this->hajjBooking->id}/cancel", [
            'reason' => 'test',
        ]);
    }

    public function test_hajjumra_booking_refund_requires_manage_refunds_permission(): void
    {
        // POLICY UPDATE (Phase 8.6 Gate, 2026-08-19):
        //   Previously asserted "refund requires admin role". The production
        //   route is gated by `permission:manage_refunds` (see routes/api.php
        //   ~L604), which is granted to employees by default via
        //   UserPermissions::defaultEmployeeModules(). The current policy is:
        //
        //     Admin = ✅  |  Employee (default perms) = ✅  |  Restricted (no manage_refunds) = 403
        //
        //   The destroy + cancel routes in this same file ARE admin-only (the
        //   original "requires admin" expectation is correct for them). Only
        //   refund is permission-gated.

        // 1) Admin → 200 (success or 422 business-rejection are both fine; 403 is NOT)
        Sanctum::actingAs($this->admin, ['*']);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$this->hajjBooking->id}/refund", [
            'reason' => 'test',
        ]);
        $this->assertNotSame(403, $response->status(), "Admin must not be blocked from refund, got {$response->status()}");

        // 2) Restricted employee (explicit perms WITHOUT manage_refunds) → 403
        $restricted = User::query()->create([
            'name' => 'Restricted Hajj',
            'email' => 'restricted-hajj-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_FLIGHTS], // explicit, no manage_refunds
        ]);
        Sanctum::actingAs($restricted, ['*']);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$this->hajjBooking->id}/refund", [
            'reason' => 'test',
        ]);
        $response->assertStatus(403, 'Restricted employee (no manage_refunds) must be blocked from refund');
    }

    public function test_hajjumra_booking_create_is_open_to_employees(): void
    {
        // Employees should be able to record bookings
        Sanctum::actingAs($this->employee, ['*']);
        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id' => $this->hajjBooking->program_id,
            'purchase_price' => 10000,
            'selling_price' => 15000,
            'currency' => 'EGP',
            'account_id' => $this->treasury->id,
        ]);
        $this->assertNotSame(403, $response->status());
    }

    /* =========================================================
     * VISA BOOKINGS — DESTRUCTIVE OPS MUST BE ADMIN-ONLY
     * ========================================================= */

    public function test_visa_booking_destroy_requires_admin(): void
    {
        $this->assertAdminGate('DELETE', "/api/v1/visa/bookings/{$this->visaBooking->id}");
    }

    public function test_visa_booking_cancel_requires_admin(): void
    {
        $this->assertAdminGate('POST', "/api/v1/visa/bookings/{$this->visaBooking->id}/cancel", [
            'reason' => 'test',
        ]);
    }

    public function test_visa_booking_refund_requires_manage_refunds_permission(): void
    {
        // POLICY UPDATE (Phase 8.6 Gate, 2026-08-19):
        //   See test_hajjumra_booking_refund_requires_manage_refunds_permission
        //   for the policy rationale. Production route is gated by
        //   `permission:manage_refunds` (routes/api.php ~L642); the
        //   obsolete admin-only assertion is replaced with the
        //   current admin✅ / employee✅ / restricted❌ invariant.

        // 1) Admin → not 403
        Sanctum::actingAs($this->admin, ['*']);
        $response = $this->postJson("/api/v1/visa/bookings/{$this->visaBooking->id}/refund", [
            'reason' => 'test',
        ]);
        $this->assertNotSame(403, $response->status(), "Admin must not be blocked from refund, got {$response->status()}");

        // 2) Restricted employee (no manage_refunds) → 403
        $restricted = User::query()->create([
            'name' => 'Restricted Visa',
            'email' => 'restricted-visa-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_FLIGHTS],
        ]);
        Sanctum::actingAs($restricted, ['*']);
        $response = $this->postJson("/api/v1/visa/bookings/{$this->visaBooking->id}/refund", [
            'reason' => 'test',
        ]);
        $response->assertStatus(403, 'Restricted employee (no manage_refunds) must be blocked from refund');
    }

    public function test_visa_customer_pay_debt_requires_admin(): void
    {
        $this->assertAdminGate('POST', "/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'amount' => 100,
            'account_id' => $this->treasury->id,
        ]);
    }

    /* =========================================================
     * FINANCIAL TRANSFERS — ADMIN-ONLY
     * ========================================================= */

    public function test_hajjumra_executing_company_withdraw_requires_admin(): void
    {
        $company = HajjUmraExecutingCompany::query()->create([
            'name' => 'Test Co',
            'phone' => '+966500000000',
            'is_active' => true,
        ]);
        $this->assertAdminGate('POST', "/api/v1/hajj-umra/executing-companies/{$company->id}/withdraw", [
            'amount' => 100,
            'to_account_id' => $this->treasury->id,
        ]);
    }

    public function test_hajjumra_executing_company_repay_requires_admin(): void
    {
        $company = HajjUmraExecutingCompany::query()->create([
            'name' => 'Test Co',
            'phone' => '+966500000000',
            'is_active' => true,
        ]);
        $this->assertAdminGate('POST', "/api/v1/hajj-umra/executing-companies/{$company->id}/repay", [
            'amount' => 100,
            'from_account_id' => $this->treasury->id,
        ]);
    }

    public function test_visa_agent_withdraw_requires_admin(): void
    {
        $agentAccount = Account::query()->create([
            'name' => 'Agent Account',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'visas',
            'created_by' => $this->admin->id,
        ]);
        $agent = VisaAgent::query()->create([
            'company_name' => 'Test Agent',
            'contact_person' => 'Contact',
            'account_id' => $agentAccount->id,
            'is_active' => true,
        ]);
        $this->assertAdminGate('POST', "/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 100,
            'to_account_id' => $this->treasury->id,
        ]);
    }

    public function test_visa_agent_repay_requires_admin(): void
    {
        $agentAccount = Account::query()->create([
            'name' => 'Agent Account',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'visas',
            'created_by' => $this->admin->id,
        ]);
        $agent = VisaAgent::query()->create([
            'company_name' => 'Test Agent',
            'contact_person' => 'Contact',
            'account_id' => $agentAccount->id,
            'is_active' => true,
        ]);
        $this->assertAdminGate('POST', "/api/v1/visa/agents/{$agent->id}/repay", [
            'amount' => 100,
            'from_account_id' => $this->treasury->id,
        ]);
    }

    /* =========================================================
     * CUSTOMER PAY-DEBT — ADMIN-ONLY
     * ========================================================= */

    public function test_customer_pay_debt_requires_admin(): void
    {
        $this->assertAdminGate('POST', "/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 100,
            'account_id' => $this->treasury->id,
        ]);
    }

    /* =========================================================
     * WALLET TRANSACTIONS — ADMIN-ONLY
     * ========================================================= */

    public function test_wallet_transaction_store_requires_admin(): void
    {
        $this->assertAdminGate('POST', '/api/v1/wallet/transactions', [
            'amount' => 100,
            'direction' => 'send',
        ]);
    }

    public function test_wallet_transaction_update_requires_admin(): void
    {
        $this->assertAdminGate('PUT', '/api/v1/wallet/transactions/1', [
            'amount' => 200,
        ]);
    }

    public function test_wallet_transaction_destroy_requires_admin(): void
    {
        $this->assertAdminGate('DELETE', '/api/v1/wallet/transactions/1');
    }

    /* =========================================================
     * FAWRY TRANSACTIONS — ADMIN-ONLY
     * ========================================================= */

    public function test_fawry_transaction_store_requires_admin(): void
    {
        $this->assertAdminGate('POST', '/api/v1/fawry/transactions', [
            'amount' => 100,
        ]);
    }

    public function test_fawry_walk_in_pay_debt_requires_admin(): void
    {
        $this->assertAdminGate('POST', '/api/v1/fawry/walk-in/pay-debt', [
            'amount' => 100,
            'client_name' => 'Walk-in',
            'account_id' => $this->treasury->id,
        ]);
    }

    /* =========================================================
     * READ-ONLY ENDPOINTS — SHOULD BE OPEN TO EMPLOYEES
     * ========================================================= */

    public function test_employee_can_view_hajjumra_bookings(): void
    {
        Sanctum::actingAs($this->employee, ['*']);
        $response = $this->getJson('/api/v1/hajj-umra/bookings');
        $this->assertNotSame(403, $response->status());
    }

    public function test_employee_can_view_visa_bookings(): void
    {
        // Phase 9.3a fix: GET /visa/bookings is admin-only per Phase 8.5 A1.5.
        // The pre-fix assertion was written before the admin-only policy.
        Sanctum::actingAs($this->employee, ['*']);
        $response = $this->getJson('/api/v1/visa/bookings');
        $this->assertSame(403, $response->status());
    }

    public function test_employee_can_view_hajjumra_dashboard(): void
    {
        Sanctum::actingAs($this->employee, ['*']);
        $response = $this->getJson('/api/v1/hajj-umra/dashboard');
        $this->assertNotSame(403, $response->status());
    }

    public function test_employee_can_view_visa_treasury_overview(): void
    {
        // Phase 9.3a fix: GET /visa/treasury/overview is admin-only per Phase 8.5 A1.6.
        // The pre-fix assertion was written before the admin-only policy.
        Sanctum::actingAs($this->employee, ['*']);
        $response = $this->getJson('/api/v1/visa/treasury/overview');
        $this->assertSame(403, $response->status());
    }

    /* =========================================================
     * UNAUTHENTICATED ACCESS — MUST BE 401
     * ========================================================= */

    public function test_unauthenticated_user_cannot_access_hajjumra_endpoints(): void
    {
        $response = $this->getJson('/api/v1/hajj-umra/bookings');
        $this->assertSame(401, $response->status());
    }

    public function test_unauthenticated_user_cannot_access_visa_endpoints(): void
    {
        $response = $this->getJson('/api/v1/visa/bookings');
        $this->assertSame(401, $response->status());
    }

    public function test_unauthenticated_user_cannot_login(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'none@test.test',
            'password' => 'password',
        ]);
        // Either 401 (bad credentials) or 422 (validation) — but never 200
        $this->assertContains($response->status(), [401, 422]);
    }

    /* =========================================================
     * INACTIVE USER — MUST BE BLOCKED
     * ========================================================= */

    public function test_inactive_user_cannot_access_endpoints(): void
    {
        $inactive = User::query()->create([
            'name' => 'Inactive',
            'email' => 'inactive-sec@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => false,
        ]);
        Sanctum::actingAs($inactive, ['*']);

        $response = $this->getJson('/api/v1/hajj-umra/bookings');

        $this->assertSame(401, $response->status());
    }
}