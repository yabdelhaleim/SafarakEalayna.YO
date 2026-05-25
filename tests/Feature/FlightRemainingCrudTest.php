<?php

namespace Tests\Feature;

use App\Events\TicketModified;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\FlightBooking;
use App\Models\Treasury;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlightRemainingCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);
        $this->customer = Customer::factory()->create();

        Sanctum::actingAs($this->user);
    }

    public function test_aviation_crud(): void
    {
        $booking = FlightBooking::create([
            'customer_id' => $this->customer->id,
            'booking_reference' => 'AV-'.uniqid(),
            'booking_number' => 'AV-'.uniqid(),
            'booking_channel_type' => 'SYSTEM',
            'booking_channel_provider' => 'Amadeus',
            'system_type' => 'manual',
            'status' => 'CONFIRMED',
            'agent_name' => 'Test Agent',
            'airline' => 'Test Air',
            'airline_name' => 'Test Air',
            'origin' => 'JED',
            'destination' => 'CAI',
            'from_airport' => 'JED',
            'to_airport' => 'CAI',
            'departure_date' => now()->addDays(7),
            'departure_time' => now()->addDays(7)->setTime(10, 0),
            'arrival_time' => now()->addDays(7)->setTime(12, 0),
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'purchase_price' => 500,
            'selling_price' => 800,
            'profit' => 300,
            'currency' => 'EGP',
            'created_by' => $this->user->id,
        ]);
        $id = $booking->id;

        $this->getJson('/api/v1/flight/aviation')
            ->assertJsonPath('success', true);

        $this->deleteJson("/api/v1/flight/aviation/{$id}")
            ->assertJsonPath('success', true);
    }

    public function test_airline_account_create(): void
    {
        $bankAccount = Account::create([
            'name' => 'Test Bank Account',
            'type' => 'bank',
            'currency' => 'SAR',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $payload = [
            'name' => 'Saudi Airlines Account',
            'code' => 'SV-'.uniqid(),
            'system_type' => 'Amadeus',
            'currency' => 'SAR',
        ];

        $create = $this->postJson('/api/v1/flight/airline-accounts', $payload);
        $create->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account.name', $payload['name'])
            ->assertJsonPath('data.account.code', $payload['code']);
    }

    public function test_flight_refunds(): void
    {
        $booking = FlightBooking::create([
            'customer_id' => $this->customer->id,
            'booking_reference' => 'FLT-'.uniqid(),
            'booking_number' => 'FLT-'.uniqid(),
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'Direct',
            'system_type' => 'manual',
            'status' => 'CONFIRMED',
            'agent_name' => 'Test',
            'airline' => 'Test Air',
            'airline_name' => 'Test Air',
            'origin' => 'JED',
            'destination' => 'CAI',
            'from_airport' => 'JED',
            'to_airport' => 'CAI',
            'departure_date' => now()->addDays(7),
            'departure_time' => now()->addDays(7)->setTime(10, 0),
            'arrival_time' => now()->addDays(7)->setTime(12, 0),
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'purchase_price' => 800,
            'selling_price' => 1200,
            'profit' => 400,
            'currency' => 'SAR',
            'created_by' => $this->user->id,
        ]);

        $treasury = Treasury::create([
            'name' => 'Refund Treasury',
            'currency' => 'SAR',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $refundPayload = [
            'flight_booking_id' => $booking->id,
            'cancellation_fee' => 100,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
            'notes' => 'Test refund',
        ];

        $create = $this->postJson('/api/v1/flight/refunds', $refundPayload);
        $create->assertCreated()
            ->assertJsonPath('success', true);

        $refundId = $create->json('data.id');

        $process = $this->postJson("/api/v1/flight/refunds/{$refundId}/process");
        $process->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_flight_modifications(): void
    {
        $airlineAccount = AirlineAccount::create([
            'name' => 'Mod Test Airline',
            'code' => 'MOD-'.uniqid(),
            'system_type' => 'Amadeus',
            'currency' => 'SAR',
            'balance' => 1000,
        ]);

        $booking = FlightBooking::create([
            'customer_id' => $this->customer->id,
            'booking_reference' => 'FLT-'.uniqid(),
            'booking_number' => 'FLT-'.uniqid(),
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'Direct',
            'system_type' => 'manual',
            'status' => 'CONFIRMED',
            'agent_name' => 'Test',
            'airline' => 'Test Air',
            'airline_name' => 'Test Air',
            'origin' => 'JED',
            'destination' => 'CAI',
            'from_airport' => 'JED',
            'to_airport' => 'CAI',
            'departure_date' => now()->addDays(7),
            'departure_time' => now()->addDays(7)->setTime(10, 0),
            'arrival_time' => now()->addDays(7)->setTime(12, 0),
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'purchase_price' => 800,
            'selling_price' => 1200,
            'profit' => 400,
            'currency' => 'SAR',
            'airline_account_id' => $airlineAccount->id,
            'created_by' => $this->user->id,
        ]);

        $modPayload = [
            'booking_id' => $booking->id,
            'modification_type' => 'date_change',
            'new_departure_date' => now()->addDays(14)->toDateString(),
            'airline_change_fee' => 150,
            'reason_for_change' => 'Customer requested date change',
        ];

        $create = $this->postJson('/api/v1/flight/modifications', $modPayload);
        $create->assertCreated()
            ->assertJsonPath('success', true);

        $modId = $create->json('data.id');

        Event::fake([TicketModified::class]);

        $confirm = $this->postJson("/api/v1/flight/modifications/{$modId}/confirm");
        $confirm->assertOk()
            ->assertJsonPath('success', true);

        $reconcile = $this->postJson("/api/v1/flight/modifications/{$modId}/reconcile", [
            'invoice_number' => 'INV-'.uniqid(),
        ]);
        $reconcile->assertOk()
            ->assertJsonPath('success', true);
    }
}
