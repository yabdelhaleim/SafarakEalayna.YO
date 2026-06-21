<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroupTransaction;
use App\Models\Flight\FlightSystemTransaction;
use App\Models\Bus\BusCompany;
use App\Models\HajjUmra\VisaAgent;
use App\Models\Flight\FlightGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Report Tester',
            'email' => 'report-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_debts_report_retrieves_correct_data(): void
    {
        // 1. Create a customer with a positive ledger balance (receivable)
        $account1 = Account::create([
            'name' => 'Customer Receivable Account',
            'type' => 'customer',
            'currency' => 'EGP',
            'balance' => 5000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);
        
        Customer::create([
            'account_id' => $account1->id,
            'full_name' => 'Ahmed Customer',
            'phone' => '01011111111',
            'notes' => 'Receivable customer',
        ]);

        // 2. Create a customer with a negative ledger balance (payable)
        $account2 = Account::create([
            'name' => 'Customer Payable Account',
            'type' => 'customer',
            'currency' => 'EGP',
            'balance' => -1500.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);

        Customer::create([
            'account_id' => $account2->id,
            'full_name' => 'Mohamed Customer',
            'phone' => '01022222222',
            'notes' => 'Payable customer',
        ]);

        // 3. Create a supplier with a negative ledger balance (payable)
        $account3 = Account::create([
            'name' => 'Supplier Payable Account',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => -3000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);

        Supplier::create([
            'account_id' => $account3->id,
            'name' => 'Global Supplier',
            'code' => 'SUPP-GLOBAL',
            'phone' => '01033333333',
            'type' => 'airline', // Maps to office department / flight module
        ]);

        // 4. Create an AirlineAccount with a negative balance (payable)
        AirlineAccount::create([
            'name' => 'EgyptAir',
            'code' => 'MS',
            'system_type' => 'GDS',
            'currency' => 'EGP',
            'balance' => -2000.00,
            'credit_limit' => 10000.00,
            'is_active' => true,
        ]);

        // Call the endpoint
        $response = $this->getJson('/api/v1/reports/debts');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total_receivables',
                    'total_payables',
                    'net_balance',
                    'items',
                ]
            ]);

        $data = $response->json('data');

        // Receivables: Customer 1 (5000.00) = 5000.00
        // Payables: Customer 2 (1500.00) + Supplier (3000.00) + Airline (2000.00) = 6500.00
        // Net: 5000.00 - 6500.00 = -1500.00
        $this->assertEquals(5000.00, $data['total_receivables']);
        $this->assertEquals(6500.00, $data['total_payables']);
        $this->assertEquals(-1500.00, $data['net_balance']);

        // Check if all four items are returned
        $this->assertCount(4, $data['items']);
    }

    public function test_debts_report_can_filter_by_direction(): void
    {
        // Setup a receivable customer and payable supplier
        $account1 = Account::create([
            'name' => 'Rec Account',
            'type' => 'customer',
            'currency' => 'EGP',
            'balance' => 4000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);
        Customer::create([
            'account_id' => $account1->id,
            'full_name' => 'Rec Customer',
            'phone' => '01011111111',
        ]);

        $account2 = Account::create([
            'name' => 'Pay Account',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => -2000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);
        Supplier::create([
            'account_id' => $account2->id,
            'name' => 'Pay Supplier',
            'code' => 'SUPP-PAY',
            'phone' => '01022222222',
            'type' => 'airline',
        ]);

        // Filter by receivables
        $recResponse = $this->getJson('/api/v1/reports/debts?direction=receivables');
        $recResponse->assertOk();
        $this->assertCount(1, $recResponse->json('data.items'));
        $this->assertEquals('Rec Customer', $recResponse->json('data.items.0.name'));

        // Filter by payables
        $payResponse = $this->getJson('/api/v1/reports/debts?direction=payables');
        $payResponse->assertOk();
        $this->assertCount(1, $payResponse->json('data.items'));
        $this->assertEquals('Pay Supplier', $payResponse->json('data.items.0.name'));
    }

    public function test_debts_report_can_filter_by_department(): void
    {
        // 1. Create a Tourism-related account: Visa Agent
        $account = Account::create([
            'name' => 'Tourism Account',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => -5000.00,
            'is_active' => true,
            'owner_type' => 'tourism',
            'module_type' => 'tourism',
        ]);
        
        VisaAgent::create([
            'account_id' => $account->id,
            'company_name' => 'Visa Agent Comp',
            'phone' => '01011111111',
            'email' => 'agent@tourism.com',
            'is_active' => true,
        ]);

        // 2. Create an Office-related account: Bus Company
        $account2 = Account::create([
            'name' => 'Office Account',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => -3000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);

        BusCompany::create([
            'account_id' => $account2->id,
            'name' => 'Bus Co',
            'phone' => '01022222222',
            'address' => 'Cairo',
            'is_active' => true,
        ]);

        // Filter by tourism department
        $tourismResponse = $this->getJson('/api/v1/reports/debts?department=tourism');
        $tourismResponse->assertOk();
        $this->assertCount(1, $tourismResponse->json('data.items'));
        $this->assertEquals('Visa Agent Comp', $tourismResponse->json('data.items.0.name'));

        // Filter by office department
        $officeResponse = $this->getJson('/api/v1/reports/debts?department=office');
        $officeResponse->assertOk();
        $this->assertCount(1, $officeResponse->json('data.items'));
        $this->assertEquals('Bus Co', $officeResponse->json('data.items.0.name'));
    }

    public function test_debts_report_can_filter_by_entity_type(): void
    {
        // 1. Create a customer with a positive ledger balance (receivable)
        $account1 = Account::create([
            'name' => 'Rec Customer Account',
            'type' => 'customer',
            'currency' => 'EGP',
            'balance' => 4000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);
        Customer::create([
            'account_id' => $account1->id,
            'full_name' => 'Test Customer Entity',
            'phone' => '01011111111',
        ]);

        // 2. Create a supplier with a negative ledger balance (payable)
        $account2 = Account::create([
            'name' => 'Pay Supplier Account',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => -2000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);
        Supplier::create([
            'account_id' => $account2->id,
            'name' => 'Test Supplier Entity',
            'code' => 'SUPP-TEST-ENTITY',
            'phone' => '01022222222',
            'type' => 'airline',
        ]);

        // Filter by customer entity_type
        $custResponse = $this->getJson('/api/v1/reports/debts?entity_type=customer');
        $custResponse->assertOk();
        $this->assertCount(1, $custResponse->json('data.items'));
        $this->assertEquals('Test Customer Entity', $custResponse->json('data.items.0.name'));

        // Filter by supplier entity_type
        $suppResponse = $this->getJson('/api/v1/reports/debts?entity_type=supplier');
        $suppResponse->assertOk();
        $this->assertCount(1, $suppResponse->json('data.items'));
        $this->assertEquals('Test Supplier Entity', $suppResponse->json('data.items.0.name'));
    }

    public function test_debts_report_correctly_maps_flight_to_tourism(): void
    {
        // 1. Create a supplier of type airline (should be tourism department, flight module)
        $account = Account::create([
            'name' => 'Airline Acc',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => -10000.00,
            'is_active' => true,
        ]);
        Supplier::create([
            'account_id' => $account->id,
            'name' => 'EgyptAir',
            'code' => 'MS',
            'type' => 'airline',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/reports/debts?department=tourism');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.items'));
        $this->assertEquals('EgyptAir', $response->json('data.items.0.name'));
        $this->assertEquals('tourism', $response->json('data.items.0.department'));
        $this->assertEquals('flight', $response->json('data.items.0.module'));
    }

    public function test_debts_report_retrieves_flight_groups(): void
    {
        $account = Account::create([
            'name' => 'Flight Group Payable Account',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => -4500.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
        ]);

        FlightGroup::create([
            'account_id' => $account->id,
            'name' => 'Discount Flights Group',
            'code' => 'DFG',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/reports/debts?department=tourism&module=flight');
        $response->assertOk();
        $data = $response->json('data.items');
        
        $this->assertCount(1, $data);
        $this->assertEquals('Discount Flights Group', $data[0]['name']);
        $this->assertEquals('flight_group', $data[0]['entity_type']);
        $this->assertEquals('tourism', $data[0]['department']);
        $this->assertEquals('flight', $data[0]['module']);
        $this->assertEquals(-4500.00, $data[0]['balance']);

        // Verify it is returned when filtering by entity_type=customer
        $customerResponse = $this->getJson('/api/v1/reports/debts?department=tourism&module=flight&entity_type=customer');
        $customerResponse->assertOk();
        $this->assertCount(1, $customerResponse->json('data.items'));
        $this->assertEquals('Discount Flights Group', $customerResponse->json('data.items.0.name'));

        // Verify it is returned when filtering by entity_type=supplier
        $supplierResponse = $this->getJson('/api/v1/reports/debts?department=tourism&module=flight&entity_type=supplier');
        $supplierResponse->assertOk();
        $this->assertCount(1, $supplierResponse->json('data.items'));
        $this->assertEquals('Discount Flights Group', $supplierResponse->json('data.items.0.name'));
    }

    public function test_detailed_flight_report_includes_group_booking_transactions(): void
    {
        $customer = Customer::factory()->create();

        $group = FlightGroup::create([
            'name' => 'مجموعة اختبار',
            'code' => 'TST',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $booking = FlightBooking::create([
            'booking_reference' => 'FLT-REF-DET',
            'booking_number' => 'FLT-DET-001',
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'Direct',
            'system_type' => 'manual',
            'status' => 'CONFIRMED',
            'customer_id' => $customer->id,
            'flight_group_id' => $group->id,
            'purchase_balance_source' => 'group',
            'agent_name' => 'Test Agent',
            'airline' => 'Test Air',
            'airline_name' => 'Test Air',
            'origin' => 'CAI',
            'from_airport' => 'CAI',
            'destination' => 'JED',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(5),
            'departure_time' => now()->addDays(5)->setTime(10, 0),
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'purchase_price' => 1000,
            'purchase_price_egp' => 1000,
            'selling_price' => 1500,
            'profit' => 500,
            'currency' => 'EGP',
            'created_by' => $this->user->id,
        ]);

        FlightGroupTransaction::create([
            'flight_group_id' => $group->id,
            'flight_booking_id' => $booking->id,
            'type' => 'debt',
            'amount' => 1000,
            'notes' => 'شراء تذكرة طيران بالأجل — حجز #FLT-DET-001',
            'created_by' => $this->user->id,
        ]);

        FlightGroupTransaction::create([
            'flight_group_id' => $group->id,
            'flight_booking_id' => null,
            'type' => 'payment',
            'amount' => 1000,
            'notes' => 'سند صرف — دفع لمجموعة طيران: مجموعة اختبار',
            'created_by' => $this->user->id,
        ]);

        FlightSystemTransaction::create([
            'flight_system_id' => \App\Models\Flight\FlightSystem::create([
                'name' => 'Other System',
                'code' => 'oth',
                'type' => 'gds',
                'currency' => 'EGP',
                'balance' => 5000,
                'is_active' => true,
                'created_by' => $this->user->id,
            ])->id,
            'flight_booking_id' => null,
            'type' => 'credit',
            'amount' => 500,
            'balance_before' => 4500,
            'balance_after' => 5000,
            'description' => 'شحن رصيد نظام',
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/v1/reports/flights/detailed');

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertGreaterThanOrEqual(1, count($items));

        $groupItem = collect($items)->first(fn (array $row) => ($row['source_type'] ?? '') === 'group' && ($row['status_ar'] ?? '') === 'حجز');
        $this->assertNotNull($groupItem);
        $this->assertEquals('FLT-DET-001', $groupItem['booking_number']);
        $this->assertEquals(1000.0, (float) $groupItem['debit']);
        $this->assertEquals('حجز', $groupItem['status_ar']);
        $this->assertStringContainsString('مجموعة اختبار', $groupItem['system_name']);

        $paymentItem = collect($items)->first(fn (array $row) => ($row['source_type'] ?? '') === 'group' && ($row['status_ar'] ?? '') === 'سداد');
        $this->assertNotNull($paymentItem);
        $this->assertEquals('FLT-DET-001', $paymentItem['booking_number']);
        $this->assertEquals(1000.0, (float) $paymentItem['credit']);

        $groupRows = collect($items)
            ->where('group_key', $groupItem['group_key'])
            ->sortBy(fn($row) => $row['type'] === 'debit' ? 0 : 1)
            ->values();
        $this->assertCount(2, $groupRows);
        $this->assertEquals('حجز', $groupRows[0]['status_ar']);
        $this->assertEquals('سداد', $groupRows[1]['status_ar']);
    }

    public function test_debts_report_converts_multi_currency(): void
    {
        $usdAccount = Account::create([
            'name' => 'USD Customer Account',
            'type' => 'customer',
            'currency' => 'USD',
            'balance' => 100.0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]);

        $customer = Customer::create([
            'account_id' => $usdAccount->id,
            'full_name' => 'USD Customer',
            'phone' => '01299999999',
        ]);

        \App\Models\ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50.0,
            'effective_date' => now(),
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/v1/reports/debts?department=tourism&entity_type=customer');

        $response->assertOk();
        $data = $response->json('data');

        // Check that USD customer is present and correctly converted
        $item = collect($data['items'])->firstWhere('id', $customer->id);
        $this->assertNotNull($item);
        $this->assertEquals(100.0, $item['balance']);
        $this->assertEquals('USD', $item['currency']);
        $this->assertEquals(5000.0, $item['balance_egp']);

        // Check aggregated totals
        $this->assertEquals(5000.0, (float) $data['total_receivables']);
    }

    public function test_debts_report_office_converts_multi_currency(): void
    {
        $usdAccount = Account::create([
            'name' => 'USD Supplier Account',
            'type' => 'supplier',
            'currency' => 'USD',
            'balance' => -100.0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'created_by' => $this->user->id,
        ]);

        $supplier = Supplier::create([
            'account_id' => $usdAccount->id,
            'name' => 'USD Bus Company',
            'code' => 'SUPP-USD-BUS',
            'phone' => '01288888888',
            'type' => 'bus_company',
        ]);

        \App\Models\ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50.0,
            'effective_date' => now(),
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/v1/reports/debts?department=office&entity_type=supplier');

        $response->assertOk();
        $data = $response->json('data');

        // Check that USD supplier is present and correctly converted
        $item = collect($data['items'])->firstWhere('id', $supplier->id);
        $this->assertNotNull($item);
        $this->assertEquals(-100.0, $item['balance']);
        $this->assertEquals('USD', $item['currency']);
        $this->assertEquals(-5000.0, $item['balance_egp']);

        // Check aggregated totals
        $this->assertEquals(5000.0, (float) $data['total_payables']);
    }
}
