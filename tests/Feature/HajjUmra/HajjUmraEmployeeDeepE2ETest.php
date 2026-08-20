<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmraBooking;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\TourismEmployeeE2E\EmployeeTestCase;

/**
 * Phase 10.3 — Employee Deep E2E (Section 7 of the 30-section prompt, applied
 * independently to the Hajj/Umra module).
 *
 * Audit target: persona-driven behavior on the Hajj/Umra API.
 *
 * Personas:
 *   - Admin: full access (id=1 typically)
 *   - normalEmployee: standard employee without explicit grants (default perms
 *     via `defaultEmployeeModules()` include `manage_hajj`)
 *   - restrictedEmployee: employee with explicit perms=[] (no module grants)
 *   - lockedEmployee: employee with permissions=[] and no module grants
 *
 * Tourism is a SHARED module: any employee can record payments on any
 * booking. Cancellation, deletion, and supplier withdraw/repay are
 * admin-only. Refund is gated by `manage_refunds` permission.
 */
class HajjUmraEmployeeDeepE2ETest extends EmployeeTestCase
{
    /* ============================================================
     *  SETUP HELPERS
     * ============================================================ */

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function makeEmployee(string $name, array $permissions = []): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)) . '-' . uniqid() . '@test.local',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
            'permissions' => $permissions,
        ]);
    }

    protected function createHajjBooking(\App\Models\Program $program, array $overrides = []): HajjUmraBooking
    {
        $vault = Account::query()->create([
            'name' => 'Phase 10.3 Treasury',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 1_000_000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'is_module_vault' => true,
            'created_by' => $this->admin->id,
        ]);

        $payload = array_merge([
            'customer' => [
                'full_name' => 'Phase 10.3 Customer',
                'phone' => '010' . substr((string) mt_rand(10_000_000, 99_999_999), 0, 8),
            ],
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'per_person' => true,
            'account_id' => $vault->id,
        ], $overrides);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();
        return HajjUmraBooking::query()->findOrFail($response->json('data.id'));
    }

    protected function createHajjExecutingCompany(array $overrides = []): HajjUmraExecutingCompany
    {
        return HajjUmraExecutingCompany::query()->create(array_merge([
            'name' => 'Phase 10.3 Executing '.uniqid(),
            'phone' => '+20100000000',
            'is_active' => true,
        ], $overrides));
    }

    /* ============================================================
     *  A. CREATE / READ — employee allowed
     * ============================================================ */

    public function test_employee_can_create_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
        ]));
        $response->assertCreated();
    }

    public function test_employee_can_show_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->getJson("/api/v1/hajj-umra/bookings/{$booking->id}");
        $response->assertOk();
    }

    public function test_employee_can_list_bookings(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->getJson('/api/v1/hajj-umra/bookings');
        $response->assertOk();
    }

    /* ============================================================
     *  B. PAYMENT — employee allowed (cross-employee by design)
     * ============================================================ */

    public function test_employee_can_record_payment_on_any_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 5000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P103_EMP_PAY_'.uniqid(),
        ]);
        $response->assertCreated();
    }

    public function test_other_employee_can_pay_booking_created_by_first_employee(): void
    {
        $program = $this->createHajjProgram();
        $emp1 = $this->makeEmployee('Emp A');
        $emp2 = $this->makeEmployee('Emp B');

        $this->actAs($emp1);
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
        ]));
        $bookingId = $response->json('data.id');

        // Different employee pays — should succeed
        $this->actAs($emp2);
        $pay = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P103_CROSS_PAY_'.uniqid(),
        ]);
        $pay->assertCreated();
    }

    /* ============================================================
     *  C. REFUND — gated by manage_refunds
     * ============================================================ */

    public function test_employee_without_manage_refunds_cannot_refund(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P103_PAY_'.uniqid(),
        ])->assertCreated();

        // Restricted employee: only manage_hajj, no manage_refunds.
        // The permissions column is a JSON array of permission keys (flat list).
        $restricted = $this->makeEmployee('Restricted Emp', ['manage_hajj']);
        $this->actAs($restricted);

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'phase 10.3 restricted refund attempt',
        ]);
        $response->assertStatus(403,
            'employee without manage_refunds must not refund');
    }

    public function test_employee_with_manage_refunds_can_refund(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 15000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P103_PAY_'.uniqid(),
        ])->assertCreated();

        $refunder = $this->makeEmployee('Refunder', ['manage_hajj', 'manage_refunds']);
        $this->actAs($refunder);

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'phase 10.3 with manage_refunds',
        ]);
        $response->assertOk();
    }

    /* ============================================================
     *  D. CANCEL / DELETE — admin-only
     * ============================================================ */

    public function test_employee_cannot_cancel_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'phase 10.3 employee cancel attempt',
        ]);
        $response->assertStatus(403);
    }

    public function test_employee_cannot_delete_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}");
        $response->assertStatus(403);
    }

    /* ============================================================
     *  E. EXECUTING COMPANY FINANCE — admin-only
     * ============================================================ */

    public function test_employee_cannot_withdraw_from_executing_company(): void
    {
        $company = $this->createHajjExecutingCompany();
        $this->actAs($this->normalEmployee);

        $response = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/withdraw", [
            'amount' => 1000,
            'to_account_id' => $this->vaultEgp->id,
        ]);
        $response->assertStatus(403);
    }

    public function test_employee_cannot_repay_to_executing_company(): void
    {
        $company = $this->createHajjExecutingCompany();
        $this->actAs($this->normalEmployee);

        $response = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/repay", [
            'amount' => 1000,
            'from_account_id' => $this->vaultEgp->id,
        ]);
        $response->assertStatus(403);
    }

    /* ============================================================
     *  F. PROGRAMS — admin-only mutations
     * ============================================================ */

    public function test_employee_cannot_create_program(): void
    {
        $this->actAs($this->normalEmployee);
        $response = $this->postJson('/api/v1/hajj-umra/programs', $this->programPayload());
        $response->assertStatus(403);
    }

    public function test_employee_cannot_update_program(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);
        $response = $this->putJson("/api/v1/hajj-umra/programs/{$program->id}", [
            'program_name' => 'Updated by employee',
        ]);
        $response->assertStatus(403);
    }

    public function test_employee_cannot_delete_program(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);
        $response = $this->deleteJson("/api/v1/hajj-umra/programs/{$program->id}");
        $response->assertStatus(403);
    }

    /* ============================================================
     *  G. INACTIVE / UNAUTHENTICATED
     * ============================================================ */

    public function test_inactive_employee_request_rejected(): void
    {
        $this->normalEmployee->update(['is_active' => false]);

        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P103_INACT_'.uniqid(),
        ]);
        $response->assertStatus(401,
            'inactive employee must be rejected');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        // Drop any Sanctum auth set up by parent::setUp()
        app('auth')->forgetGuards();
        $response = $this->getJson('/api/v1/hajj-umra/bookings');
        $this->assertNotSame(200, $response->status(),
            'an unauthenticated request must not return 200');
        $this->assertContains($response->status(), [401, 403]);
    }

    /* ============================================================
     *  H. IDOR — sequential ID enumeration
     * ============================================================ */

    public function test_sequential_id_enumeration_returns_404(): void
    {
        $this->actAs($this->normalEmployee);
        $response = $this->getJson('/api/v1/hajj-umra/bookings/999999999');
        $this->assertNotSame(500, $response->status(), 'missing booking must not 500');
        $this->assertContains($response->status(), [404, 403]);
    }

    public function test_negative_id_rejected(): void
    {
        $this->actAs($this->normalEmployee);
        $response = $this->getJson('/api/v1/hajj-umra/bookings/-1');
        $this->assertNotSame(500, $response->status());
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    protected function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'customer' => [
                'full_name' => 'Emp Test '.uniqid(),
                'phone' => '010' . substr((string) mt_rand(10_000_000, 99_999_999), 0, 8),
            ],
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'per_person' => true,
            'account_id' => $this->vaultEgp->id,
        ], $overrides);
    }

    protected function programPayload(array $overrides = []): array
    {
        return array_merge([
            'program_name' => 'P103 Prog '.uniqid(),
            'program_type' => 'hajj',
            'total_nights' => 14,
            'accommodation_type' => 'QUAD',
            'mecca_hotel_name' => 'H',
            'mecca_nights' => 7,
            'medina_hotel_name' => 'M',
            'medina_nights' => 7,
            'airline' => 'A',
            'departure_point' => 'CAI',
            'default_purchase_price' => 42000,
            'default_selling_price' => 50000,
            'is_active' => true,
        ], $overrides);
    }
}
