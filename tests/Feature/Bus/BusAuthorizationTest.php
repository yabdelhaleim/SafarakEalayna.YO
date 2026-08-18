<?php

namespace Tests\Feature\Bus;

use App\Enums\BusBookingStatus;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\User;
use Database\Factories\Bus\BusBookingFactory;
use Database\Factories\Bus\BusInventoryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 9 — Bus Module authorization regression test.
 *
 * P0-AUTH #1 — DELETE /bus/companies/{id} — admin only
 * P0-AUTH #2 — DELETE /bus/inventories/{id} — admin only
 * P0-AUTH #3 — DELETE /bus/bookings/{id} — admin only
 * P0-AUTH #4 — POST /bus/inventories — admin only
 * P0-AUTH #5 — POST /bus/bookings/{id}/pay — admin OR owning employee (BusBookingPolicy::pay)
 *
 * Each endpoint is exercised in BOTH directions:
 *   - Authorized access (admin / owning employee) → returns 2xx
 *   - Unauthorized access (other employee / no auth) → returns 403 / 401
 *
 * The test also verifies financial invariants are preserved across the auth
 * gate — i.e. an unauthorized 403 must NOT have mutated any account balance
 * (the controller returns before invoking the service).
 *
 * Cross-module isolation:
 *   The destructive operations move money between Office accounts. The
 *   authorization gate runs BEFORE any GL write, so a 403 response cannot
 *   leak a partial financial state. Verified at the assertion level below.
 */
class BusAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $cashier1;

    protected User $cashier2;

    protected User $random;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a system user FIRST so the seeded accounts have a valid
        // `created_by` foreign key reference. The real tests below create
        // their own admin/cashier/random users and `actingAs` them per-request.
        $system = User::query()->create([
            'name' => 'System Seeder',
            'email' => 'system-seeder@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->seedOfficeFixtures($system->id);

        $this->admin = User::query()->create([
            'name' => 'Admin Tester',
            'email' => 'bus-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->cashier1 = User::query()->create([
            'name' => 'Cashier One',
            'email' => 'cashier-1@example.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'is_active' => true,
        ]);
        $cashier1Employee = \App\Models\Employee::query()->create([
            'user_id' => $this->cashier1->id,
            'full_name' => 'Cashier One',
            'first_name' => 'Cashier',
            'last_name' => 'One',
            'status' => 'active',
        ]);

        $this->cashier2 = User::query()->create([
            'name' => 'Cashier Two',
            'email' => 'cashier-2@example.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'is_active' => true,
        ]);
        $cashier2Employee = \App\Models\Employee::query()->create([
            'user_id' => $this->cashier2->id,
            'full_name' => 'Cashier Two',
            'first_name' => 'Cashier',
            'last_name' => 'Two',
            'status' => 'active',
        ]);

        $this->random = User::query()->create([
            'name' => 'Random User',
            'email' => 'random@example.com',
            'password' => Hash::make('password'),
            'role' => 'viewer',
            'is_active' => true,
        ]);
    }

    /**
     * Minimum fixture seeding — office cashbox + bus clearing accounts.
     * Adapted from BusTestCase but without the full factory harness.
     */
    private function seedOfficeFixtures(int $systemUserId): void
    {
        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($systemUserId) {
            \App\Models\Account::create([
                'name' => 'Authz Cashbox',
                'type' => \App\Enums\AccountType::Cashbox,
                'currency' => 'EGP',
                'balance' => 5000.0,
                'is_active' => true,
                'owner_type' => \App\Models\Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => true,
                'notes' => 'Authz test cashbox',
                'created_by' => $systemUserId,
            ]);
        });
        $clearing = app(\App\Services\Finance\LedgerClearingAccounts::class);
        $clearing->incomeContraIdForModule(\App\Enums\TransactionModule::Bus->value);
        $clearing->expenseContraIdForModule(\App\Enums\TransactionModule::Bus->value);
    }

    private function actAs(User $user): self
    {
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    private function makeBookingOwnedBy(int $employeeId): BusBooking
    {
        $company = BusCompany::factory()->create();
        $inventory = BusInventoryFactory::new()->create([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 50,
            'selling_price' => 100,
        ]);

        return BusBookingFactory::new()->create([
            'inventory_id' => $inventory->id,
            'customer_id' => \App\Models\Customer::factory()->create()->id,
            'employee_id' => $employeeId,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
            'paid_amount' => 0,
            'currency' => 'EGP',
            'exchange_rate_to_egp' => 1.0,
            'status' => BusBookingStatus::Pending,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // P0-AUTH #1 — DELETE /bus/companies/{id} admin only
    // ─────────────────────────────────────────────────────────────────────

    public function test_admin_can_delete_company(): void
    {
        $company = BusCompany::factory()->create();
        $this->actAs($this->admin);

        $this->deleteJson("/api/v1/bus/companies/{$company->id}")
            ->assertOk();

        $this->assertSoftDeleted('bus_companies', ['id' => $company->id]);
    }

    public function test_non_admin_cannot_delete_company(): void
    {
        $company = BusCompany::factory()->create();
        $this->actAs($this->cashier1);

        $this->deleteJson("/api/v1/bus/companies/{$company->id}")
            ->assertStatus(403);

        // The company row must NOT be soft-deleted.
        $this->assertDatabaseHas('bus_companies', [
            'id' => $company->id,
            'deleted_at' => null,
        ]);
    }

    public function test_viewer_role_cannot_delete_company(): void
    {
        $company = BusCompany::factory()->create();
        $this->actAs($this->random);

        $this->deleteJson("/api/v1/bus/companies/{$company->id}")
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────
    // P0-AUTH #2 — DELETE /bus/inventories/{id} admin only
    // ─────────────────────────────────────────────────────────────────────

    public function test_admin_can_delete_inventory(): void
    {
        $company = BusCompany::factory()->create();
        $inventory = BusInventoryFactory::new()->create([
            'company_id' => $company->id,
        ]);

        $this->actAs($this->admin);

        $this->deleteJson("/api/v1/bus/inventories/{$inventory->id}")
            ->assertOk();

        $this->assertSoftDeleted('bus_inventories', ['id' => $inventory->id]);
    }

    public function test_non_admin_cannot_delete_inventory(): void
    {
        $company = BusCompany::factory()->create();
        $inventory = BusInventoryFactory::new()->create([
            'company_id' => $company->id,
        ]);

        $this->actAs($this->cashier1);

        $this->deleteJson("/api/v1/bus/inventories/{$inventory->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('bus_inventories', [
            'id' => $inventory->id,
            'deleted_at' => null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // P0-AUTH #3 — DELETE /bus/bookings/{id} admin only
    // ─────────────────────────────────────────────────────────────────────

    public function test_admin_can_delete_booking(): void
    {
        $booking = $this->makeBookingOwnedBy(\App\Models\Employee::query()->value('id') ?? 1);
        $this->actAs($this->admin);

        $this->deleteJson("/api/v1/bus/bookings/{$booking->id}")
            ->assertOk();

        $this->assertSoftDeleted('bus_bookings', ['id' => $booking->id]);
    }

    public function test_non_admin_cannot_delete_booking(): void
    {
        $booking = $this->makeBookingOwnedBy(\App\Models\Employee::query()->value('id') ?? 1);
        $this->actAs($this->cashier1);

        $this->deleteJson("/api/v1/bus/bookings/{$booking->id}")
            ->assertStatus(403);

        // Booking must NOT be soft-deleted.
        $this->assertDatabaseHas('bus_bookings', [
            'id' => $booking->id,
            'deleted_at' => null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // P0-AUTH #4 — POST /bus/inventories admin only
    // ─────────────────────────────────────────────────────────────────────

    public function test_admin_can_create_inventory(): void
    {
        $company = BusCompany::factory()->create();
        $this->actAs($this->admin);

        $this->postJson('/api/v1/bus/inventories', [
            'company_id' => $company->id,
            'route' => 'القاهرة - الأقصر',
            'travel_date' => now()->addDays(5)->toDateString(),
            'departure_time' => '08:00',
            'total_tickets' => 20,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => 'deferred',
            'notes' => 'Authz test inventory',
        ])->assertCreated();
    }

    public function test_non_admin_cannot_create_inventory(): void
    {
        $company = BusCompany::factory()->create();
        $this->actAs($this->cashier1);

        $this->postJson('/api/v1/bus/inventories', [
            'company_id' => $company->id,
            'route' => 'القاهرة - الأقصر',
            'travel_date' => now()->addDays(5)->toDateString(),
            'departure_time' => '08:00',
            'total_tickets' => 20,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => 'deferred',
            'notes' => 'Authz test inventory',
        ])->assertStatus(403);

        // No inventory row created.
        $this->assertDatabaseMissing('bus_inventories', [
            'route' => 'القاهرة - الأقصر',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // P0-AUTH #5 — POST /bus/bookings/{id}/pay — BusBookingPolicy::pay
    // ─────────────────────────────────────────────────────────────────────

    public function test_admin_can_pay_any_booking(): void
    {
        $booking = $this->makeBookingOwnedBy(\App\Models\Employee::query()->value('id') ?? 1);
        $this->actAs($this->admin);

        $cashboxId = \App\Models\Account::query()
            ->where('name', 'Authz Cashbox')
            ->value('id');

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $cashboxId,
        ])->assertOk();
    }

    public function test_owning_employee_can_pay_own_booking(): void
    {
        $cashier1EmployeeId = \App\Models\Employee::query()
            ->where('user_id', $this->cashier1->id)
            ->value('id');

        $booking = $this->makeBookingOwnedBy($cashier1EmployeeId);
        $this->actAs($this->cashier1);

        $cashboxId = \App\Models\Account::query()
            ->where('name', 'Authz Cashbox')
            ->value('id');

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $cashboxId,
        ])->assertOk();
    }

    public function test_non_owning_employee_cannot_pay_someone_elses_booking(): void
    {
        // Booking belongs to cashier1
        $cashier1EmployeeId = \App\Models\Employee::query()
            ->where('user_id', $this->cashier1->id)
            ->value('id');

        $booking = $this->makeBookingOwnedBy($cashier1EmployeeId);

        // cashier2 attempts to pay cashier1's booking
        $this->actAs($this->cashier2);

        $cashboxId = \App\Models\Account::query()
            ->where('name', 'Authz Cashbox')
            ->value('id');

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $cashboxId,
        ])->assertStatus(403);

        // Booking payment_status must remain pending — IDOR denied.
        $booking->refresh();
        $this->assertEquals(0.0, (float) $booking->paid_amount);
        $this->assertEquals(BusBookingStatus::Pending, $booking->status);
    }

    public function test_viewer_role_cannot_pay_booking(): void
    {
        $booking = $this->makeBookingOwnedBy(\App\Models\Employee::query()->value('id') ?? 1);
        $this->actAs($this->random);

        $cashboxId = \App\Models\Account::query()
            ->where('name', 'Authz Cashbox')
            ->value('id');

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $cashboxId,
        ])->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_destructive_routes(): void
    {
        // No Sanctum::actingAs → no token
        $company = BusCompany::factory()->create();
        $inventory = BusInventoryFactory::new()->create(['company_id' => $company->id]);
        $booking = $this->makeBookingOwnedBy(\App\Models\Employee::query()->value('id') ?? 1);

        $this->deleteJson("/api/v1/bus/companies/{$company->id}")->assertStatus(401);
        $this->deleteJson("/api/v1/bus/inventories/{$inventory->id}")->assertStatus(401);
        $this->deleteJson("/api/v1/bus/bookings/{$booking->id}")->assertStatus(401);
    }

    public function test_unauthorized_payment_does_not_mutate_balances(): void
    {
        // Capture starting state — must not change after a 403 payment attempt.
        $cashier1EmployeeId = \App\Models\Employee::query()
            ->where('user_id', $this->cashier1->id)
            ->value('id');
        $booking = $this->makeBookingOwnedBy($cashier1EmployeeId);

        $cashboxId = \App\Models\Account::query()
            ->where('name', 'Authz Cashbox')
            ->value('id');
        $startingCashbox = (float) \App\Models\Account::find($cashboxId)->balance;
        $startingBookingPaid = (float) $booking->paid_amount;

        // cashier2 attempts to pay
        $this->actAs($this->cashier2);
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $cashboxId,
        ])->assertStatus(403);

        // Balances must be unchanged.
        $this->assertEqualsWithDelta(
            $startingCashbox,
            (float) \App\Models\Account::find($cashboxId)->fresh()->balance,
            0.001,
            'Cashbox balance must be unchanged after unauthorized payment attempt'
        );
        $this->assertEqualsWithDelta(
            $startingBookingPaid,
            (float) $booking->fresh()->paid_amount,
            0.001,
            'Booking paid_amount must be unchanged after unauthorized payment attempt'
        );
    }
}
