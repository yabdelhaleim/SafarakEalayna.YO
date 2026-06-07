<?php

namespace Tests\Feature\Bus;

use App\Enums\BusBookingStatus;
use App\Enums\BusInventoryPaymentType;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected BusInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Bus API Tester',
            'email' => 'bus-api-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = Account::query()->create([
            'name' => 'خزينة الباصات',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'bus',
            'module' => 'bus',
            'created_by' => $this->user->id,
        ]);

        LedgerBalanceMutationGuard::run(function (): void {
            $this->treasury->update(['balance' => 50000.00]);
        });

        $company = BusCompany::query()->create([
            'name' => 'شركة اختبار API',
            'phone' => '01055566677',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->inventory = BusInventory::query()->create([
            'company_id' => $company->id,
            'route' => 'القاهرة - الإسكندرية',
            'travel_date' => now()->addDays(3)->toDateString(),
            'total_tickets' => 20,
            'available_tickets' => 20,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred,
            'total_cost' => 1600,
            'amount_paid' => 0,
            'remaining_debt' => 1600,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_dashboard_endpoint_returns_stats(): void
    {
        $this->createBooking();

        $response = $this->getJson('/api/v1/bus/dashboard');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'stats' => [
                        'monthly_revenue',
                        'total_bookings',
                        'cashboxes',
                        'banks',
                        'wallets',
                    ],
                    'recent_bookings',
                    'liquidity',
                    'total_company_debt',
                ],
            ])
            ->assertJsonPath('data.stats.total_bookings', 1)
            ->assertJsonPath('data.stats.monthly_revenue', 120);
    }

    public function test_dashboard_excludes_cancelled_from_monthly_revenue(): void
    {
        $booking = $this->createBooking();

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel")
            ->assertOk();

        $response = $this->getJson('/api/v1/bus/dashboard');
        $response->assertOk()
            ->assertJsonPath('data.stats.monthly_revenue', 0);
    }

    public function test_treasury_overview_lists_bus_accounts(): void
    {
        $response = $this->getJson('/api/v1/bus/treasury/overview');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'settlement_accounts',
                    'companies',
                    'recent_bus_transactions',
                ],
            ]);

        $ids = collect($response->json('data.settlement_accounts'))->pluck('id');
        $this->assertTrue($ids->contains($this->treasury->id));
    }

    public function test_treasury_transactions_rejects_non_bus_account(): void
    {
        $flightTreasury = Account::query()->create([
            'name' => 'خزينة طيران',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 1000,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/bus/treasury/accounts/{$flightTreasury->id}/bus-transactions");
        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_payment_rejects_account_from_other_module(): void
    {
        $booking = $this->createBooking();

        $flightTreasury = Account::query()->create([
            'name' => 'خزينة طيران',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 1000,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 50,
            'payment_method' => 'cash',
            'account_id' => $flightTreasury->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    public function test_pay_debt_rejects_non_bus_treasury_account(): void
    {
        $company = $this->inventory->company;
        app(\App\Services\Bus\BusCompanyService::class)->ensureCompanyAccount($company);
        $this->createBooking();

        $flightTreasury = Account::query()->create([
            'name' => 'خزينة طيران',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 5000,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/bus/companies/{$company->id}/pay-debt", [
            'amount' => 50,
            'from_account_id' => $flightTreasury->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['from_account_id']);
    }

    public function test_customers_index_excludes_cancelled_bookings_from_debt(): void
    {
        $booking = $this->createBooking();
        $customer = Customer::find($booking->customer_id);

        $response = $this->getJson('/api/v1/bus/customers');
        $response->assertOk()
            ->assertJsonPath('success', true);

        $row = collect($response->json('data.customers.data'))->firstWhere('id', $customer->id);
        $this->assertNotNull($row);
        $this->assertEquals(120.0, (float) $row['total_bus_amount']);
        $this->assertEquals(0.0, (float) $row['total_bus_paid']);
        $this->assertEquals(120.0, (float) $row['bus_remaining_debt']);

        $booking->update(['status' => BusBookingStatus::Cancelled->value]);

        $afterCancel = $this->getJson('/api/v1/bus/customers');
        $afterCancel->assertOk();
        $cancelledRow = collect($afterCancel->json('data.customers.data'))->firstWhere('id', $customer->id);
        $this->assertNull($cancelledRow);
    }

    public function test_booking_stats_endpoint(): void
    {
        $this->createBooking();

        $response = $this->getJson('/api/v1/bus/bookings/stats');
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'total_bookings',
                    'paid_bookings',
                    'pending_bookings',
                    'cancelled_bookings',
                    'total_revenue',
                    'pending_payments',
                ],
            ])
            ->assertJsonPath('data.total_bookings', 1);
    }

    protected function createBooking(): BusBooking
    {
        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $this->inventory->id,
            'customer_name' => 'عميل API',
            'customer_phone' => '01012349876',
            'quantity' => 1,
        ]);

        $response->assertCreated();

        return BusBooking::findOrFail($response->json('data.id'));
    }
}
