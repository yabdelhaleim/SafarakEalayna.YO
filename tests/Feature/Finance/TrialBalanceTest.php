<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Models\Account;
use App\Models\Customer;
use App\Enums\AccountType;
use App\Enums\CustomerTier;
use App\Services\Finance\TreasuryService;
use App\Services\Finance\TrialBalanceExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrialBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Customer $customer;
    protected TreasuryService $treasuryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Finance Manager',
            'email' => 'finance-manager@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);
        $this->treasuryService = app(TreasuryService::class);

        $this->customer = Customer::create([
            'full_name' => 'John Doe',
            'phone' => '0123456789',
            'email' => 'john@example.com',
            'national_id' => '12345678901234',
            'city' => 'Cairo',
            'customer_tier' => CustomerTier::STANDARD->value,
        ]);
    }

    public function test_average_purchase_rate_calculation_from_bookings(): void
    {
        // Insert sample flight bookings
        DB::table('flight_bookings')->insert([
            [
                'booking_reference' => 'REF-1',
                'booking_channel_type' => 'GDS',
                'booking_channel_provider' => 'Amadeus',
                'customer_id' => $this->customer->id,
                'agent_name' => 'Self',
                'origin' => 'CAI',
                'destination' => 'DXB',
                'departure_date' => now()->toDateString(),
                'departure_time' => '12:00',
                'trip_type' => 'one_way',
                'airline' => 'MS',
                'passenger_count' => 1,
                'purchase_price' => 1000.0,
                'selling_price' => 1200.0,
                'profit' => 200.0,
                'foreign_currency' => 'USD',
                'currency' => 'USD',
                'purchase_price_foreign' => 100.0,
                'purchase_price_egp' => 5000.0, // 50 EGP per USD
                'status' => 'CONFIRMED',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'booking_reference' => 'REF-2',
                'booking_channel_type' => 'GDS',
                'booking_channel_provider' => 'Amadeus',
                'customer_id' => $this->customer->id,
                'agent_name' => 'Self',
                'origin' => 'CAI',
                'destination' => 'DXB',
                'departure_date' => now()->toDateString(),
                'departure_time' => '12:00',
                'trip_type' => 'one_way',
                'airline' => 'MS',
                'passenger_count' => 1,
                'purchase_price' => 2000.0,
                'selling_price' => 2400.0,
                'profit' => 400.0,
                'foreign_currency' => 'USD',
                'currency' => 'USD',
                'purchase_price_foreign' => 200.0,
                'purchase_price_egp' => 11000.0, // 55 EGP per USD
                'status' => 'CONFIRMED',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // Average purchase rate should be: (5000 + 11000) / (100 + 200) = 16000 / 300 = 53.3333
        $rate = $this->treasuryService->getAveragePurchaseRate('USD');
        $this->assertEqualsWithDelta(53.3333, $rate, 0.0001);
    }

    public function test_average_purchase_rate_fallback_to_exchange_rates(): void
    {
        // Insert fallback rate in exchange_rates
        DB::table('exchange_rates')->insert([
            'from_currency' => 'EUR',
            'to_currency' => 'EGP',
            'rate' => 60.0,
            'is_active' => true,
            'effective_date' => now()->toDateString(),
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rate = $this->treasuryService->getAveragePurchaseRate('EUR');
        $this->assertEquals(60.0, $rate);
    }

    public function test_get_trial_balance_equation(): void
    {
        // Set Base Capital in settings
        $printSettingService = app(\App\Services\Setting\PrintSettingService::class);
        $printSettingService->update(['base_capital' => 500000.0]);

        // 1. Total Balances (إجمالي أرصدة الموديولات)
        DB::table('flight_systems')->insert([
            'name' => 'System A',
            'code' => 'SYS-A',
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'USD',
            'balance' => 1000.0, // USD
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Stub USD rate to 50 EGP
        DB::table('flight_bookings')->insert([
            'booking_reference' => 'REF-3',
            'booking_channel_type' => 'GDS',
            'booking_channel_provider' => 'Amadeus',
            'customer_id' => $this->customer->id,
            'agent_name' => 'Self',
            'origin' => 'CAI',
            'destination' => 'DXB',
            'departure_date' => now()->toDateString(),
            'departure_time' => '12:00',
            'trip_type' => 'one_way',
            'airline' => 'MS',
            'passenger_count' => 1,
            'purchase_price' => 100.0,
            'selling_price' => 120.0,
            'profit' => 100.0,
            'foreign_currency' => 'USD',
            'currency' => 'USD',
            'purchase_price_foreign' => 10.0,
            'purchase_price_egp' => 500.0,
            'status' => 'CONFIRMED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Flight Systems Total in EGP = 1000 * 50 = 50000 EGP

        // 2. Liquidity (Tourism Cashbox, Bank, etc.)
        Account::query()->create([
            'name' => 'الخزينة الرئيسية للسياحة',
            'type' => AccountType::Cashbox,
            'balance' => 150000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]);

        // 3. Receivables (Customer positive balances)
        Account::query()->create([
            'name' => 'عميل مدين',
            'type' => AccountType::Customer,
            'balance' => 20000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]);

        // 4. Payables (Supplier negative balances)
        Account::query()->create([
            'name' => 'مورد دائن',
            'type' => AccountType::Supplier,
            'balance' => -10000.0, // due from us is 10000
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]);

        // Let's check:
        // total_balances = 50000 (flight_systems)
        // total_liquidity = 150000
        // due_to_us = 20000
        // due_from_us = 10000
        // current_capital = (50000 + 150000 + 20000) - 10000 = 210000 EGP.

        // Expected Capital:
        // base_capital = 500000 EGP
        // profits = flight bookings profit * USD rate = 100 * 50 = 5000 EGP
        // expected_capital = 500000 + 5000 = 505000 EGP.
        // variance = currentCapital - expectedCapital = 210000 - 505000 = -295000 EGP.
        // Status should be 'يوجد عجز'.

        $trialBalance = $this->treasuryService->getTrialBalance();

        $this->assertEquals(50000.0, $trialBalance['total_balances']);
        $this->assertEquals(150000.0, $trialBalance['total_liquidity']);
        $this->assertEquals(20000.0, $trialBalance['due_to_us']);
        $this->assertEquals(10000.0, $trialBalance['due_from_us']);
        $this->assertEquals(210000.0, $trialBalance['current_capital']);
        $this->assertEquals(500000.0, $trialBalance['base_capital']);
        $this->assertEquals(5000.0, $trialBalance['profits']);
        $this->assertEquals(505000.0, $trialBalance['expected_capital']);
        $this->assertEquals(-295000.0, $trialBalance['variance']);
        $this->assertSame('يوجد عجز', $trialBalance['status']);
    }

    public function test_api_trial_balance_endpoint(): void
    {
        $response = $this->getJson('/api/v1/reports/trial-balance');
        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'rates',
                'details' => [
                    'flight_balances',
                    'hajj_umra_balances',
                    'visa_balances',
                ],
                'total_balances',
                'total_liquidity',
                'due_to_us',
                'due_from_us',
                'current_capital',
                'base_capital',
                'profits',
                'expected_capital',
                'variance',
                'status',
            ]
        ]);
    }

    public function test_api_export_trial_balance_endpoint(): void
    {
        $response = $this->get('/api/v1/finance/treasuries/export-trial-balance');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_online_and_fawry_profits_included_in_trial_balance(): void
    {
        // 0. Create a sample account for online transaction
        $account = Account::query()->create([
            'name' => 'حساب تجريبي أونلاين',
            'type' => AccountType::Cashbox,
            'balance' => 0.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]);

        // 0. Create a sample service type
        $typeId = DB::table('online_service_types')->insertGetId([
            'code' => 'test_service',
            'name_ar' => 'خدمة تجريبية',
            'name_en' => 'Test Service',
            'is_active' => true,
            'order' => 1,
        ]);

        // 1. Insert sample completed online transaction
        DB::table('online_transactions')->insert([
            'service_type_id' => $typeId,
            'selling_price' => 1500.00,
            'purchase_price' => 1200.00,
            'profit' => 300.00,
            'status' => 'completed',
            'customer_name' => 'Online Customer',
            'payment_method' => 'cash',
            'account_id' => $account->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Insert sample Fawry transaction
        DB::table('fawry_transactions')->insert([
            'client_name' => 'Fawry Customer',
            'operation_type' => 'payment',
            'client_amount' => 1000.00,
            'fawry_price' => 950.00,
            'selling_price' => 1050.00,
            'profit' => 100.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 1000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $profits = $this->treasuryService->calculateDynamicProfits();

        // 300 (online) + 100 (fawry) = 400.00
        $this->assertEquals(400.00, $profits);
    }
}
