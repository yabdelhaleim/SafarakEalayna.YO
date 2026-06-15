<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Models\Account;
use App\Enums\AccountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlightDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_flight_dashboard_returns_correct_kpis_and_converted_liquidity(): void
    {
        // 1. Insert active exchange rates
        DB::table('exchange_rates')->insert([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50.0,
            'is_active' => true,
            'effective_date' => now()->toDateString(),
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Create standard EGP accounts
        Account::query()->create([
            'name' => 'خزينة طيران EGP',
            'type' => AccountType::Cashbox,
            'balance' => 10000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        // 3. Create USD accounts
        Account::query()->create([
            'name' => 'خزينة دولار',
            'type' => AccountType::Cashbox,
            'balance' => 200.0, // USD
            'currency' => 'USD',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        // 4. Create Flight Systems & Carriers
        DB::table('flight_systems')->insert([
            'name' => 'System EGP',
            'code' => 'SYS-EGP',
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 5000.0,
            'credit_limit' => 0.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('flight_systems')->insert([
            'name' => 'System USD',
            'code' => 'SYS-USD',
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'USD',
            'balance' => 100.0, // USD
            'credit_limit' => 50.0, // USD
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Expected EGP Total Liquidity Calculation:
        // EGP components:
        // - account balance: 10000.0
        // - system balance: 5000.0
        // -> EGP sum = 15000.0 EGP
        //
        // USD components:
        // - account balance: 200.0
        // - system balance: 100.0
        // -> USD sum = 300.0 USD
        // -> Converted to EGP = 300 * 50 = 15000.0 EGP
        //
        // Grand Total in EGP = 15000.0 (EGP) + 15000.0 (USD converted) = 30000.0 EGP

        $response = $this->getJson('/api/v1/flight/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'stats',
                'recent_bookings',
                'liquidity' => [
                    'total',
                    'by_currency',
                ]
            ]
        ]);

        $data = $response->json('data');
        $this->assertEquals(30000.0, $data['liquidity']['total']);

        // Check currency breakdown
        $byCurrency = collect($data['liquidity']['by_currency']);
        $egpRow = $byCurrency->firstWhere('currency', 'EGP');
        $usdRow = $byCurrency->firstWhere('currency', 'USD');

        $this->assertEquals(15000.0, $egpRow['total_actual']);
        $this->assertEquals(300.0, $usdRow['total_actual']);
        $this->assertEquals(350.0, $usdRow['total_available']); // 300 actual + 50 credit
    }
}
