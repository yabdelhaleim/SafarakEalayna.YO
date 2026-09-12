<?php

namespace Tests\Feature\HajjUmra;

use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decomposed per-action tests for Api\V1\HajjUmraController (bookings + customer endpoints).
 *
 * Each test exercises a single controller action against the real HTTP routes
 * and asserts the core contract (status code, response shape, side effects).
 *
 * The full E2E accounting invariants are still covered by the existing
 * HajjUmraProductionE2ETest — this file gives fast per-action feedback.
 *
 * @see \App\Http\Controllers\Api\V1\HajjUmraController
 */
class HajjUmraControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasuryEGP;

    protected Program $program;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'HajjUmra Controller Tester',
            'email' => 'hajj-controller@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->user, ['*']);

        $this->treasuryEGP = Account::query()->create([
            'name' => 'خزينة الحج - EGP',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 500000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $this->program = Program::query()->create([
            'program_name' => 'برنامج تجريبي',
            'program_type' => 'umrah',
            'total_nights' => 7,
            'mecca_hotel_name' => 'فندق مكة',
            'mecca_nights' => 4,
            'medina_hotel_name' => 'فندق المدينة',
            'medina_nights' => 3,
            'airline' => 'مصر للطيران',
            'executing_company' => 'شركة تنفيذ',
            'accommodation_type' => 'DOUBLE',
            'default_purchase_price' => 10000,
            'default_selling_price' => 15000,
            'departure_date' => now()->addDays(15)->toDateString(),
            'return_date' => now()->addDays(22)->toDateString(),
            'departure_point' => 'Cairo',
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'عميل تجريبي',
            'phone' => '01000000001',
        ]);
    }

    protected function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'program_id' => $this->program->id,
            'purchase_price' => 10000,
            'selling_price' => 15000,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
            'status' => 'confirmed',
        ], $overrides);
    }

    /* =========================================================
     * INDEX
     * ========================================================= */

    public function test_index_returns_paginated_list(): void
    {
        $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());
        $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload(['agent_name' => 'second']));

        $response = $this->getJson('/api/v1/hajj-umra/bookings');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items',
                    'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
                ],
            ]);

        $this->assertGreaterThanOrEqual(2, $response->json('data.pagination.total'));
    }

    public function test_index_filters_by_status(): void
    {
        $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());

        $response = $this->getJson('/api/v1/hajj-umra/bookings?status=confirmed');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('data.pagination.total'));
    }

    /* =========================================================
     * STORE
     * ========================================================= */

    public function test_store_creates_booking_and_returns_201(): void
    {
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('hajj_umra_bookings', [
            'id' => $response->json('data.id'),
            'customer_id' => $this->customer->id,
            'program_id' => $this->program->id,
            'currency' => 'EGP',
        ]);
    }

    public function test_store_validates_required_program_id(): void
    {
        $payload = $this->bookingPayload();
        unset($payload['program_id']);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['program_id']);
    }

    public function test_store_validates_required_account_id(): void
    {
        $payload = $this->bookingPayload();
        unset($payload['account_id']);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    public function test_store_rejects_office_division_account(): void
    {
        // Office-division treasury is REJECTED by HajjUmraLiquidityAccount —
        // only tourism-division unified vaults (or legacy hajj_umra/hajj/umrah) are accepted.
        $officeTreasury = Account::query()->create([
            'name' => 'Office Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'module' => null,
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'account_id' => $officeTreasury->id,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    /* =========================================================
     * SHOW
     * ========================================================= */

    public function test_show_returns_booking_details(): void
    {
        $created = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());
        $bookingId = $created->json('data.id');

        $response = $this->getJson("/api/v1/hajj-umra/bookings/{$bookingId}");

        $response->assertOk()
            ->assertJsonPath('data.id', $bookingId);
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson('/api/v1/hajj-umra/bookings/999999');

        $response->assertNotFound();
    }

    /* =========================================================
     * Conflict resolution note (Phase 12 forensic audit, 2026-08-20):
     *   The pre-Phase-8.5 `test_update_modifies_selling_price` test
     *   from the WIP branch asserted 422 from PUT /api/v1/hajj-umra/
     *   bookings/{id}. The Tourism no-edit contract (INCIDENT-2026-08-17)
     *   removed PUT/PATCH on bookings — those routes now return 405.
     *   The Phase 10.1 D5 test-harness flip verified `assertSame(405, …)`.
     *   This WIP test was discarded during the Phase 12 forensic merge.
     *   See docs/MERGE_CONFLICT_FORENSIC_AUDIT.md §3 + §8 TEST-C2.
     * ========================================================= */

    /* =========================================================
     * ADD PAYMENT
     * ========================================================= */

    public function test_add_payment_creates_payment_record(): void
    {
        $created = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());
        $bookingId = $created->json('data.id');

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 5000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'payment' => ['id', 'amount', 'payment_method'],
                    'booking' => ['id'],
                ],
            ]);

        $this->assertEquals(5000.0, (float) $response->json('data.payment.amount'));
    }

    public function test_add_payment_validates_amount_gt_zero(): void
    {
        $created = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());
        $bookingId = $created->json('data.id');

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    /* =========================================================
     * CANCEL
     * ========================================================= */

    public function test_cancel_flips_status_to_cancelled(): void
    {
        $created = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());
        $bookingId = $created->json('data.id');

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", [
            'reason' => 'test cancel',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    /* =========================================================
     * REFUND
     * ========================================================= */

    public function test_refund_flips_status_to_refunded(): void
    {
        $created = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());
        $bookingId = $created->json('data.id');

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund", [
            'reason' => 'test refund',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('hajj_umra_bookings', [
            'id' => $bookingId,
            'status' => 'refunded',
        ]);
    }

    /* =========================================================
     * DESTROY (soft delete with reversal)
     * ========================================================= */

    public function test_destroy_soft_deletes_booking(): void
    {
        $created = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());
        $bookingId = $created->json('data.id');

        $response = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");

        $response->assertOk();
        $booking = HajjUmraBooking::withTrashed()->findOrFail($bookingId);
        $this->assertNotNull($booking->deleted_at);
    }

    public function test_destroy_returns_404_for_unknown_id(): void
    {
        $response = $this->deleteJson('/api/v1/hajj-umra/bookings/999999');

        $response->assertStatus(404);
    }

    public function test_destroy_returns_422_when_already_trashed(): void
    {
        $created = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());
        $bookingId = $created->json('data.id');

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");

        $response = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");

        $response->assertStatus(422);
    }

    /* =========================================================
     * CUSTOMER BALANCES
     * ========================================================= */

    public function test_customer_balances_returns_debtors(): void
    {
        $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());

        $response = $this->getJson('/api/v1/hajj-umra/customer-balances');

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
    }

    public function test_customer_balances_filters_by_search(): void
    {
        $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());

        $response = $this->getJson('/api/v1/hajj-umra/customer-balances?search=تجريبي');

        $response->assertOk();
        $items = $response->json('data');
        $this->assertGreaterThanOrEqual(1, count($items));
    }

    public function test_customer_balances_filters_by_status_debtors(): void
    {
        // Booking with no payment → debt > 0
        $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());

        $response = $this->getJson('/api/v1/hajj-umra/customer-balances?status=debtors');

        $response->assertOk();
        foreach ($response->json('data') as $row) {
            $this->assertGreaterThan(0, $row['total_debt']);
        }
    }

    /* =========================================================
     * CUSTOMER STATEMENT
     * ========================================================= */

    public function test_customer_statement_requires_client_id(): void
    {
        $response = $this->getJson('/api/v1/hajj-umra/customer-statement');

        $response->assertStatus(400);
    }

    public function test_customer_statement_returns_summary_for_known_customer(): void
    {
        $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload());

        $response = $this->getJson("/api/v1/hajj-umra/customer-statement?client_id={$this->customer->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'customer' => ['id', 'name'],
                    'summary' => ['total_sales', 'total_paid', 'total_debt'],
                    'transactions',
                ],
            ]);

        $this->assertGreaterThan(0, $response->json('data.summary.total_sales'));
    }

    public function test_customer_statement_returns_404_for_unknown_customer(): void
    {
        $response = $this->getJson('/api/v1/hajj-umra/customer-statement?client_id=999999');

        $response->assertStatus(422);
    }
}