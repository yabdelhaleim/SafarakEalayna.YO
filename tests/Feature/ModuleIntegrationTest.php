<?php

namespace Tests\Feature;

use App\Models\Flight\FlightBooking;
use App\Models\Bus\BusCompany;
use App\Models\Service\Service;
use App\Models\Online\OnlineServiceType;
use App\Models\Employee;
use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a default user for foreign key constraints
        User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_flight_module_model_creation(): void
    {
        // Flight module now uses consistent structure matching the model
        $booking = FlightBooking::create([
            'booking_reference' => 'FLT-REF-001',
            'booking_number' => 'FLT-TEST-001',
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'Direct',
            'system_type' => 'manual',
            'status' => 'PENDING',
            'customer_id' => Customer::factory()->create()->id,
            'employee_id' => null,
            'agent_name' => 'Test Agent',
            'airline' => 'Test Airlines',
            'airline_name' => 'Test Airlines',
            'origin' => 'JED',
            'from_airport' => 'JED',
            'destination' => 'CAI',
            'to_airport' => 'CAI',
            'departure_date' => now()->addDays(7),
            'departure_time' => now()->addDays(7)->setTime(10, 0),
            'return_date' => now()->addDays(14),
            'return_time' => now()->addDays(14)->setTime(14, 0),
            'arrival_time' => now()->addDays(7)->setTime(12, 0),
            'trip_type' => 'round_trip',
            'passenger_count' => 1,
            'baggage_allowance_kg' => 23,
            'trip_details' => 'Test round trip booking',
            'purchase_price' => 1000.00,
            'selling_price' => 1500.00,
            'profit' => 500.00,
            'currency' => 'SAR',
            'notes' => 'Test flight booking',
            'created_by' => 1,
        ]);

        $this->assertDatabaseHas('flight_bookings', [
            'booking_number' => 'FLT-TEST-001',
            'airline_name' => 'Test Airlines',
            'from_airport' => 'JED',
            'to_airport' => 'CAI',
        ]);
    }

    public function test_bus_module_model_creation(): void
    {
        $company = BusCompany::create([
            'name' => 'Test Bus Company',
            'phone' => '+966501234567',
            'address' => 'Riyadh, Saudi Arabia',
            'is_active' => true,
            'notes' => 'Test bus company',
            'created_by' => 1,
        ]);

        $this->assertDatabaseHas('bus_companies', [
            'name' => 'Test Bus Company',
            'phone' => '+966501234567',
        ]);
    }

    public function test_service_module_model_creation(): void
    {
        $service = Service::create([
            'name' => 'Hajj Package 2025',
            'category' => 'hajj',
            'description' => 'Premium Hajj Package',
            'cost_price' => 5000.00,
            'selling_price' => 7000.00,
            'is_active' => true,
            'created_by' => 1,
        ]);

        $this->assertDatabaseHas('services', [
            'name' => 'Hajj Package 2025',
            'category' => 'hajj',
        ]);
    }

    public function test_online_module_model_creation(): void
    {
        $serviceType = OnlineServiceType::create([
            'name' => 'Visa Extension',
            'fee_type' => 'fixed',
            'fee_value' => 500.00,
            'is_active' => true,
            'notes' => 'Online visa extension service',
            'created_by' => 1,
        ]);

        $this->assertDatabaseHas('online_service_types', [
            'name' => 'Visa Extension',
            'fee_type' => 'fixed',
            'fee_value' => 500.00,
        ]);
    }

    public function test_employee_module_model_creation(): void
    {
        $employee = Employee::create([
            'user_id' => null,
            'full_name' => 'Test Employee',
            'phone' => '+966501234567',
            'national_id' => '1234567890',
            'position' => 'Travel Agent',
            'department' => 'Sales',
            'salary' => 5000.00,
            'hire_date' => now(),
            'employment_status' => 'active',
        ]);

        $this->assertDatabaseHas('employees', [
            'full_name' => 'Test Employee',
            'position' => 'Travel Agent',
        ]);
    }

    public function test_finance_module_model_creation(): void
    {
        $account = Account::create([
            'name' => 'Test Bank Account',
            'type' => 'bank',
            'balance' => 10000.00,
            'currency' => 'SAR',
            'is_active' => true,
            'owner_type' => 'owner',
            'notes' => 'Primary bank account',
        ]);

        $this->assertDatabaseHas('accounts', [
            'name' => 'Test Bank Account',
            'type' => 'bank',
            'balance' => 10000.00,
        ]);
    }

    public function test_customer_module_model_creation(): void
    {
        $customer = Customer::create([
            'full_name' => 'Ahmed Mohammed',
            'phone' => '+966501234567',
            'national_id' => '1234567890',
            'passport_number' => 'A12345678',
            'passport_expiry' => '2025-12-31',
            'date_of_birth' => '1990-01-01',
            'city' => 'Riyadh',
            'affiliation' => 'Test Company',
            'customer_tier' => 'STANDARD',
            'notes' => 'Test customer',
        ]);

        $this->assertDatabaseHas('customers', [
            'full_name' => 'Ahmed Mohammed',
            'phone' => '+966501234567',
        ]);
    }

    public function test_flight_enum_consistency(): void
    {
        // Test that the enum values match what's stored in database
        $booking = FlightBooking::create([
            'booking_reference' => 'FLT-REF-002',
            'booking_number' => 'FLT-TEST-002',
            'booking_channel_type' => 'online',
            'booking_channel_provider' => 'Test Provider',
            'system_type' => 'online',
            'status' => 'PENDING',
            'customer_id' => Customer::factory()->create()->id,
            'agent_name' => 'Online Agent',
            'airline' => 'Test Airlines',
            'airline_name' => 'Test Airlines',
            'origin' => 'JED',
            'from_airport' => 'JED',
            'destination' => 'CAI',
            'to_airport' => 'CAI',
            'departure_date' => now()->addDays(7),
            'departure_time' => now()->addDays(7)->setTime(10, 0),
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'baggage_allowance_kg' => 20,
            'trip_details' => 'Test one-way flight',
            'purchase_price' => 800.00,
            'selling_price' => 1200.00,
            'profit' => 400.00,
            'currency' => 'SAR',
            'created_by' => 1,
        ]);

        $this->assertEquals(\App\Enums\FlightBookingStatus::PENDING, $booking->status);
        $this->assertEquals(\App\Enums\FlightSystemType::Online, $booking->system_type);
        $this->assertDatabaseHas('flight_bookings', [
            'status' => 'PENDING',
            'system_type' => 'online',
        ]);
    }

    public function test_employee_without_user_creation(): void
    {
        // Test that employee can be created without user_id (nullable field)
        $employee = Employee::create([
            'user_id' => null, // This should work now
            'full_name' => 'Employee Without User',
            'phone' => '+966501234568',
            'position' => 'Consultant',
            'salary' => 4000.00,
            'hire_date' => now(),
            'employment_status' => 'active',
        ]);

        $this->assertNull($employee->user_id);
        $this->assertDatabaseHas('employees', [
            'full_name' => 'Employee Without User',
            'user_id' => null,
        ]);
    }

    public function test_online_service_type_enum_handling(): void
    {
        // Test that fee_type enum works correctly
        $serviceType = OnlineServiceType::create([
            'name' => 'Test Service',
            'fee_type' => 'fixed', // lowercase string as stored in DB
            'fee_value' => 250.00,
            'is_active' => true,
            'created_by' => 1,
        ]);

        $this->assertEquals(\App\Enums\OnlineFeeType::Fixed, $serviceType->fee_type);
        $this->assertEquals(250.00, $serviceType->fee_value);
    }
}
