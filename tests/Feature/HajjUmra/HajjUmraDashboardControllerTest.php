<?php

namespace Tests\Feature\HajjUmra;

use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decomposed per-action tests for Api\V1\HajjUmra\HajjUmraDashboardController.
 *
 * @see \App\Http\Controllers\Api\V1\HajjUmra\HajjUmraDashboardController
 */
class HajjUmraDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected Program $program;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Dashboard Tester',
            'email' => 'hajj-dashboard@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = Account::query()->create([
            'name' => 'Hajj Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $this->program = Program::query()->create([
            'program_name' => 'برنامج داشبورد',
            'program_type' => 'umrah',
            'total_nights' => 7,
            'mecca_hotel_name' => 'فندق',
            'mecca_nights' => 4,
            'medina_hotel_name' => 'فندق',
            'medina_nights' => 3,
            'airline' => 'مصر للطيران',
            'executing_company' => 'شركة',
            'accommodation_type' => 'DOUBLE',
            'default_purchase_price' => 10000,
            'default_selling_price' => 15000,
            'departure_date' => now()->addDays(15)->toDateString(),
            'return_date' => now()->addDays(22)->toDateString(),
            'departure_point' => 'Cairo',
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'عميل داشبورد',
            'phone' => '01000000002',
        ]);
    }

    protected function createBooking(string $status = 'confirmed', float $sellingPrice = 15000): HajjUmraBooking
    {
        $booking = HajjUmraBooking::query()->create([
            'customer_id' => $this->customer->id,
            'program_id' => $this->program->id,
            'module' => 'hajj_umra',
            'selling_price' => $sellingPrice,
            'purchase_price' => 10000,
            'profit' => $sellingPrice - 10000,
            'currency' => 'EGP',
            'per_person' => true,
            'status' => $status,
            'agent_name' => $this->customer->full_name,
            'created_by' => $this->user->id,
            'account_id' => $this->treasury->id,
        ]);

        return $booking;
    }

    /* =========================================================
     * INDEX
     * ========================================================= */

    public function test_index_returns_dashboard_payload_shape(): void
    {
        $response = $this->getJson('/api/v1/hajj-umra/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'stats' => [
                        'monthly_revenue',
                        'total_bookings',
                        'cashboxes' => ['count', 'balance'],
                        'banks' => ['count', 'balance'],
                        'wallets' => ['count', 'balance'],
                    ],
                    'recent_bookings',
                    'liquidity' => ['total'],
                ],
            ]);
    }

    public function test_index_includes_only_active_accounts(): void
    {
        Account::query()->create([
            'name' => 'Inactive Account',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50000.00,
            'is_active' => false,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/v1/hajj-umra/dashboard');

        $response->assertOk();
        // The active treasury should be counted; inactive should not
        $this->assertSame(1, $response->json('data.stats.cashboxes.count'));
    }

    public function test_index_counts_total_bookings(): void
    {
        $this->createBooking();
        $this->createBooking();
        $this->createBooking();

        $response = $this->getJson('/api/v1/hajj-umra/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.stats.total_bookings', 3);
    }

    public function test_index_excludes_cancelled_bookings_from_revenue(): void
    {
        $this->createBooking('confirmed', 15000);
        $this->createBooking('cancelled', 99999); // should NOT count

        $response = $this->getJson('/api/v1/hajj-umra/dashboard');

        $response->assertOk();
        // Only the confirmed one counts toward monthly revenue
        $this->assertEqualsWithDelta(15000.0, (float) $response->json('data.stats.monthly_revenue'), 0.01);
    }

    public function test_index_returns_recent_bookings_limited_to_ten(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->createBooking();
        }

        $response = $this->getJson('/api/v1/hajj-umra/dashboard');

        $response->assertOk();
        $this->assertLessThanOrEqual(10, count($response->json('data.recent_bookings')));
    }

    public function test_index_aggregates_cashbox_balance(): void
    {
        // Create another cashbox
        Account::query()->create([
            'name' => 'Second Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/v1/hajj-umra/dashboard');

        $response->assertOk();
        // 100000 + 50000 = 150000
        $this->assertEqualsWithDelta(150000.0, (float) $response->json('data.stats.cashboxes.balance'), 0.01);
        $this->assertSame(2, $response->json('data.stats.cashboxes.count'));
    }
}