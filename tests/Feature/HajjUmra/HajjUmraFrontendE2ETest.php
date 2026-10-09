<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hajj/Umrah — Frontend HTTP E2E Test (Vue Pages Black-Box Coverage)
 *
 * Date: 2026-08-29
 * Purpose: Verify that every API endpoint consumed by the HajjUmra Vue
 *          pages (Index, Create, Show, Edit, Dashboard, Treasury,
 *          CustomerBalances, ExecutingCompaniesDue) returns the expected
 *          contract that the frontend expects.
 *
 * No production code modified — purely consumer-side validation.
 */
class HajjUmraFrontendE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $vaultEgp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'FE Admin',
            'email' => 'fe-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->vaultEgp = Account::query()->create(['name' => 'V-EGP', 'type' => AccountType::Cashbox->value, 'currency' => 'EGP', 'balance' => 5_000_000.00, 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism', 'module' => 'hajj_umra', 'is_module_vault' => true, 'created_by' => $this->admin->id]);
        });
    }

    /* =========================================================
     *  HajjUmraIndex.vue — list + filters
     * ========================================================= */

    public function test_FE_01_index_returns_paginated_items(): void
    {
        $program = $this->makeProgram();
        for ($i = 0; $i < 3; $i++) {
            $this->makeBooking($program->id);
        }

        $resp = $this->getJson('/api/v1/hajj-umra/bookings');
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertArrayHasKey('items', $body);
        $this->assertArrayHasKey('pagination', $body);
        $this->assertGreaterThanOrEqual(3, count($body['items']));
    }

    public function test_FE_02_index_filter_by_status(): void
    {
        $program = $this->makeProgram();
        $b1 = $this->makeBooking($program->id);
        $b2 = $this->makeBooking($program->id);
        HajjUmraBooking::find($b2->id)->update(['status' => 'cancelled']);

        $resp = $this->getJson('/api/v1/hajj-umra/bookings?status=cancelled');
        $resp->assertOk();
        $items = $resp->json('data.items');
        foreach ($items as $item) {
            $this->assertSame('cancelled', $item['status']);
        }
    }

    public function test_FE_03_index_filter_by_program_id(): void
    {
        $p1 = $this->makeProgram();
        $p2 = $this->makeProgram();
        $this->makeBooking($p1->id);
        $this->makeBooking($p2->id);

        $resp = $this->getJson("/api/v1/hajj-umra/bookings?program_id={$p1->id}");
        $resp->assertOk();
        foreach ($resp->json('data.items') as $item) {
            $this->assertSame($p1->id, $item['program']['id']);
        }
    }

    public function test_FE_04_index_search_by_customer_phone(): void
    {
        $program = $this->makeProgram();
        $customer = Customer::query()->create(['full_name' => 'X', 'phone' => '01999999999', 'is_active' => true]);
        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $customer->full_name, 'phone' => $customer->phone],
            'program_id' => $program->id,
            'purchase_price' => 42000.0, 'selling_price' => 50000.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
        ])->assertCreated();

        $resp = $this->getJson('/api/v1/hajj-umra/bookings?search=01999999999');
        $resp->assertOk();
        $this->assertGreaterThanOrEqual(1, count($resp->json('data.items')));
    }

    /* =========================================================
     *  HajjUmraShow.vue — booking detail
     * ========================================================= */

    public function test_FE_05_show_returns_full_resource(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id);

        $resp = $this->getJson("/api/v1/hajj-umra/bookings/{$booking->id}");
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertSame($booking->id, $body['id']);
        $this->assertArrayHasKey('pricing', $body);
        $this->assertArrayHasKey('finance', $body);
        $this->assertArrayHasKey('payments', $body);
        $this->assertArrayHasKey('customer', $body);
        $this->assertArrayHasKey('program', $body);
    }

    /* =========================================================
     *  HajjUmraCreate.vue — POST booking
     * ========================================================= */

    public function test_FE_06_create_returns_201_with_resource(): void
    {
        $program = $this->makeProgram();
        $customer = Customer::query()->create(['full_name' => 'C', 'phone' => '01001000001', 'is_active' => true]);

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $customer->full_name, 'phone' => $customer->phone],
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
        ]);

        $resp->assertCreated();
        $body = $resp->json('data');
        $this->assertSame(50000.0, (float) $body['pricing']['selling_price']);
        $this->assertSame('EGP', $body['pricing']['currency']);
        $this->assertSame('confirmed', $body['status']);
    }

    public function test_FE_07_create_with_companion_succeeds(): void
    {
        $program = $this->makeProgram();
        $c1 = Customer::query()->create(['full_name' => 'A', 'phone' => '01001000002', 'is_active' => true]);
        $c2 = Customer::query()->create(['full_name' => 'B', 'phone' => '01001000003', 'is_active' => true]);

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $c1->full_name, 'phone' => $c1->phone],
            'companion_customer_id' => $c2->id,
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'companion_purchase_price' => 30000.0,
            'companion_selling_price' => 35000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
        ]);
        $resp->assertCreated();
        $this->assertSame($c2->id, $resp->json('data.companion.id'));
    }

    /* =========================================================
     *  HajjUmraShow.vue — payment endpoint
     * ========================================================= */

    public function test_FE_08_add_payment_returns_201_with_booking(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id);

        $resp = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 25000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'fe-key-'.uniqid(),
        ]);
        $resp->assertCreated();
        $this->assertArrayHasKey('payment', $resp->json('data'));
        $this->assertArrayHasKey('booking', $resp->json('data'));
        $this->assertSame(25000.0, (float) $resp->json('data.payment.amount'));
    }

    public function test_FE_09_add_payment_idempotent_replay(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id);

        $key = 'fe-idem-'.uniqid();
        $first = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 25000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => $key,
        ]);
        $first->assertCreated();
        $firstId = $first->json('data.payment.id');

        $replay = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 25000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => $key,
        ]);
        $replay->assertOk();
        $this->assertTrue($replay->json('data.idempotent_replay'));
        $this->assertSame($firstId, $replay->json('data.payment.id'));
    }

    /* =========================================================
     *  HajjUmraShow.vue — cancel + refund + delete
     * ========================================================= */

    public function test_FE_10_cancel_returns_200_with_cancelled_status(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id);

        $resp = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'fe-cancel',
        ]);
        $resp->assertOk();
        $this->assertSame('cancelled', $resp->json('data.status'));
    }

    public function test_FE_11_refund_returns_200_with_refunded_status(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 25000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'fe-'.uniqid(),
        ])->assertCreated();

        $resp = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'fe-refund',
        ]);
        $resp->assertOk();
        $this->assertSame('refunded', $resp->json('data.booking.status'));
    }

    public function test_FE_12_delete_returns_200(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id);

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();
        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $booking->id]);
    }

    /* =========================================================
     *  HajjUmraDashboard.vue
     * ========================================================= */

    public function test_FE_13_dashboard_endpoint(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/dashboard');
        $resp->assertOk();
        $this->assertIsArray($resp->json('data'));
    }

    /* =========================================================
     *  HajjUmraTreasury.vue
     * ========================================================= */

    public function test_FE_14_treasury_overview_returns_accounts_and_companies(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/treasury/overview');
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertArrayHasKey('settlement_accounts', $body);
        $this->assertArrayHasKey('executing_companies', $body);
        $this->assertArrayHasKey('recent_hajj_umra_transactions', $body);
    }

    public function test_FE_15_treasury_account_transactions(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'fe-'.uniqid(),
        ])->assertCreated();

        $resp = $this->getJson("/api/v1/hajj-umra/treasury/accounts/{$this->vaultEgp->id}/transactions");
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertNotEmpty($body['data'] ?? $body);
    }

    /* =========================================================
     *  HajjUmraCustomerBalances.vue
     * ========================================================= */

    public function test_FE_16_customer_balances_returns_aggregated_rows(): void
    {
        $program = $this->makeProgram();
        $customer = Customer::query()->create(['full_name' => 'CB', 'phone' => '01001000004', 'is_active' => true]);
        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $customer->full_name, 'phone' => $customer->phone],
            'program_id' => $program->id,
            'purchase_price' => 42000.0, 'selling_price' => 50000.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
        ]);
        $bookingId = $resp->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 20000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id, 'idempotency_key' => 'fe-'.uniqid(),
        ])->assertCreated();

        $resp = $this->getJson('/api/v1/hajj-umra/customer-balances');
        $resp->assertOk();
        $items = $resp->json('data');
        $row = collect($items)->firstWhere('client_id', $customer->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(50000.0, (float) $row['total_sales'], 0.02);
        $this->assertEqualsWithDelta(20000.0, (float) $row['total_paid'], 0.02);
        $this->assertEqualsWithDelta(30000.0, (float) $row['total_debt'], 0.02);
    }

    public function test_FE_17_customer_balances_debtors_filter(): void
    {
        $program = $this->makeProgram();
        $customer = Customer::query()->create(['full_name' => 'D', 'phone' => '01001000005', 'is_active' => true]);
        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $customer->full_name, 'phone' => $customer->phone],
            'program_id' => $program->id,
            'purchase_price' => 42000.0, 'selling_price' => 50000.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
        ]);
        $resp->assertCreated();

        $resp = $this->getJson('/api/v1/hajj-umra/customer-balances?status=debtors');
        $resp->assertOk();
        foreach ($resp->json('data') as $row) {
            $this->assertGreaterThan(0.009, (float) $row['total_debt']);
        }
    }

    /* =========================================================
     *  HajjUmraExecutingCompaniesDue.vue
     * ========================================================= */

    public function test_FE_18_executing_companies_dues(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/executing-companies/dues');
        $resp->assertOk();
        $this->assertArrayHasKey('items', $resp->json('data'));
    }

    /* =========================================================
     *  Customer statement
     * ========================================================= */

    public function test_FE_19_customer_statement_running_balance(): void
    {
        $program = $this->makeProgram();
        $customer = Customer::query()->create(['full_name' => 'S', 'phone' => '01001000006', 'is_active' => true]);
        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $customer->full_name, 'phone' => $customer->phone],
            'program_id' => $program->id,
            'purchase_price' => 42000.0, 'selling_price' => 50000.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
        ]);
        $bookingId = $resp->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 25000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id, 'idempotency_key' => 'fe-'.uniqid(),
        ])->assertCreated();

        $resp = $this->getJson("/api/v1/hajj-umra/customer-statement?client_id={$customer->id}");
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertArrayHasKey('summary', $body);
        $this->assertArrayHasKey('transactions', $body);
        $this->assertSame(25000.0, (float) $body['summary']['total_debt']);
    }

    /* =========================================================
     *  Settings endpoints
     * ========================================================= */

    public function test_FE_20_settings_programs(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/settings/programs');
        $resp->assertOk();
        $this->assertIsArray($resp->json('data'));
    }

    public function test_FE_21_settings_executing_companies(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/settings/executing-companies');
        $resp->assertOk();
        $this->assertIsArray($resp->json('data'));
    }

    public function test_FE_22_settings_statuses_returns_valid_statuses(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/settings/statuses');
        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertArrayHasKey('hajj_umra', $data);
        $this->assertCount(6, $data['hajj_umra'], 'HajjUmraStatus enum has 6 cases');
        $values = array_column($data['hajj_umra'], 'value');
        $this->assertContains('confirmed', $values);
        $this->assertContains('refunded', $values);
        $this->assertContains('cancelled', $values);
    }

    /* =========================================================
     *  Happy-user full flow (5-step)
     * ========================================================= */

    public function test_FE_23_full_user_flow_book_pay_refund(): void
    {
        $program = $this->makeProgram();
        $customer = Customer::query()->create(['full_name' => 'Flow', 'phone' => '01001000007', 'is_active' => true]);

        // step 1: book
        $bookResp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $customer->full_name, 'phone' => $customer->phone],
            'program_id' => $program->id,
            'purchase_price' => 42000.0, 'selling_price' => 50000.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
        ]);
        $bookResp->assertCreated();
        $bookingId = $bookResp->json('data.id');

        // step 2: pay
        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 50000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id, 'idempotency_key' => 'flow-'.uniqid(),
        ])->assertCreated();

        // step 3: list
        $this->getJson('/api/v1/hajj-umra/bookings')->assertOk();

        // step 4: show
        $this->getJson("/api/v1/hajj-umra/bookings/{$bookingId}")->assertOk();

        // step 5: refund
        $refund = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund", ['reason' => 'flow']);
        $refund->assertOk();
        $this->assertSame('refunded', $refund->json('data.booking.status'));

        // step 6: customer balances — refunded booking must NOT contribute to debt
        $cb = $this->getJson('/api/v1/hajj-umra/customer-balances')->json('data');
        $row = collect($cb)->firstWhere('client_id', $customer->id);
        if ($row !== null) {
            $this->assertEqualsWithDelta(0.0, (float) $row['total_debt'], 0.02,
                'after refund, debt must be zero');
        }
    }

    /* =========================================================
     *  Helpers
     * ========================================================= */

    protected function makeProgram(): Program
    {
        return Program::query()->create([
            'program_name' => 'P-'.uniqid(),
            'program_type' => 'hajj',
            'total_nights' => 14, 'mecca_nights' => 8, 'medina_nights' => 6,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'فندق مكة', 'medina_hotel_name' => 'فندق المدينة',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(74)->toDateString(),
            'airline' => 'Test Air', 'executing_company' => 'NONE-'.uniqid(),
            'departure_point' => 'CAI',
            'default_selling_price' => 50000.0, 'default_purchase_price' => 42000.0,
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);
    }

    protected function makeBooking(int $programId): HajjUmraBooking
    {
        $customer = Customer::query()->create([
            'full_name' => 'C-'.uniqid(),
            'phone' => '01'.str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
            'is_active' => true,
        ]);
        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $customer->full_name, 'phone' => $customer->phone],
            'program_id' => $programId,
            'purchase_price' => 42000.0, 'selling_price' => 50000.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
        ]);
        $resp->assertCreated();
        return HajjUmraBooking::findOrFail($resp->json('data.id'));
    }
}
