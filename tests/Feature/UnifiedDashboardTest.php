<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Models\Flight\FlightBooking;
use App\Models\Bus\BusBooking;
use App\Models\HajjUmraBooking;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnifiedDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Dashboard Tester',
            'email' => 'dash-tester@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);
    }

    public function test_dashboard_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/dashboard');
        $response->assertStatus(401);
    }

    public function test_unified_dashboard_returns_correct_data_structure_and_values(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        // 1. Create accounts to verify treasury summary mapping
        $cashbox = Account::query()->create([
            'name' => 'الخزينة الرئيسية',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->user->id,
        ]);

        $bank = Account::query()->create([
            'name' => 'البنك التجاري الدولي',
            'type' => 'bank',
            'currency' => 'EGP',
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->user->id,
        ]);

        $wallet = Account::query()->create([
            'name' => 'محفظة فودافون كاش',
            'type' => 'wallet',
            'currency' => 'EGP',
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->user->id,
        ]);

        LedgerBalanceMutationGuard::run(function () use ($cashbox, $bank, $wallet) {
            $cashbox->update(['balance' => 15000.00]);
            $bank->update(['balance' => 50000.00]);
            $wallet->update(['balance' => 5000.00]);
        });

        // 2. Create customer & flight/bus bookings
        $customer = Customer::query()->create([
            'full_name' => 'عميل تجريبي لوحة البيانات',
            'phone' => '01011112222',
        ]);

        FlightBooking::query()->create([
            'booking_number' => 'FL-8899',
            'booking_reference' => 'FLT-REF-1111',
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'Direct',
            'system_type' => 'manual',
            'customer_id' => $customer->id,
            'agent_name' => 'Direct Agent',
            'airline' => 'MS',
            'airline_name' => 'EgyptAir',
            'origin' => 'CAI',
            'from_airport' => 'CAI',
            'destination' => 'JED',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => now()->addDays(7)->setTime(12, 0),
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'baggage_allowance_kg' => 23,
            'trip_details' => 'One-way test flight booking',
            'purchase_price' => 8000.00,
            'selling_price' => 10000.00,
            'profit' => 2000.00,
            'currency' => 'EGP',
            'status' => 'CONFIRMED',
            'created_by' => $this->user->id,
        ]);

        // 3. Hit the endpoint
        $response = $this->getJson('/api/v1/dashboard');

        // 4. Assert response is successful and contains all expected structural blocks
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'overview',
                    'financial',
                    'bookings',
                    'top_customers',
                    'recent_activities',
                    'alerts',
                    'kpis',
                    'carrier_balance_cards',
                    'bookings_chart',
                    'revenue_chart',
                    'carrier_performance',
                    'top_routes',
                    'recent_activity',
                    'bus_kpis',
                    'bus_bookings_chart',
                    'bus_revenue_chart',
                    'bus_company_performance',
                    'bus_top_routes',
                    'bus_recent_activity',
                    'tourism_summary' => [
                        'flights',
                        'hajj',
                        'total_count',
                        'total_revenue',
                        'total_profit',
                    ],
                    'office_summary' => [
                        'bus',
                        'fawry',
                        'online',
                        'total_count',
                        'total_revenue',
                        'total_profit',
                    ],
                    'treasury_summary' => [
                        'total',
                        'cashbox',
                        'bank',
                        'wallet',
                    ]
                ]
            ]);

        // 5. Assert database-driven values
        $this->assertEquals(70000.00, $response->json('data.treasury_summary.total'));
        $this->assertEquals(15000.00, $response->json('data.treasury_summary.cashbox'));
        $this->assertEquals(50000.00, $response->json('data.treasury_summary.bank'));
        $this->assertEquals(5000.00, $response->json('data.treasury_summary.wallet'));

        // 6. Assert recent activity contains human-friendly 'time'
        $recent = $response->json('data.recent_activities');
        $this->assertNotEmpty($recent);
        $this->assertArrayHasKey('time', $recent[0]);
        $this->assertNotNull($recent[0]['time']);
    }
}
