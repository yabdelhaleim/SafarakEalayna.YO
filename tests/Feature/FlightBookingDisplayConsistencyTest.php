<?php

namespace Tests\Feature;

use App\Enums\CustomerTier;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlightBookingDisplayConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_purchase_currency_does_not_change_selling_currency_in_api(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($user);

        $customer = Customer::create([
            'full_name' => 'ياسر محمود',
            'phone' => '01011112222',
            'email' => 'display-'.uniqid().'@example.test',
            'customer_tier' => CustomerTier::STANDARD->value,
        ]);

        $system = FlightSystem::create([
            'name' => 'SAR System',
            'code' => 'SAR'.substr(md5((string) microtime(true)), 0, 4),
            'type' => 'ndc',
            'is_active' => true,
            'currency' => 'SAR',
            'balance' => 100000,
            'credit_limit' => 0,
            'created_by' => $user->id,
        ]);

        $carrier = FlightCarrier::create([
            'name' => 'SAR Carrier',
            'code' => 'SC',
            'flight_system_id' => $system->id,
            'currency' => 'SAR',
            'balance' => 50000,
            'credit_limit' => 0,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $treasury = Account::create([
            'name' => 'Treasury',
            'type' => 'cashbox',
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $user->id,
        ]);

        $booking = app(FlightBookingService::class)->createBooking([
            'customer_id' => $customer->id,
            'pnr' => 'ABC123',
            'trip_type' => 'one_way',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addWeek()->toDateString(),
            'currency' => 'SAR',
            'purchase_price_foreign' => 1000,
            'exchange_rate' => 13.5,
            'purchase_price_egp' => 13500,
            'selling_price' => 9800,
            'flight_carrier_id' => $carrier->id,
            'account_id' => $treasury->id,
            'passengers' => [
                ['first_name' => 'YASSER', 'last_name' => 'MOH', 'type' => 'adult', 'baggage_allowance_kg' => 30],
            ],
            'segments' => [[
                'from_airport' => 'CAI',
                'to_airport' => 'JED',
                'departure_date' => now()->addWeek()->toDateString(),
            ]],
            'initial_payment' => 4500,
            'payment_method' => 'cash',
        ]);

        $this->assertSame(132300.0, (float) $booking->selling_price);
        $this->assertSame('SAR', $booking->currency);

        $response = $this->getJson("/api/v1/flight/bookings/{$booking->id}");

        $response->assertOk()
            ->assertJsonPath('data.selling_price', 132300)
            ->assertJsonPath('data.original_amount', 9800)
            ->assertJsonPath('data.purchase_currency', 'SAR')
            ->assertJsonPath('data.selling_currency', 'EGP')
            ->assertJsonPath('data.from_airport', 'CAI')
            ->assertJsonPath('data.to_airport', 'JED')
            ->assertJsonPath('data.customer.name', 'ياسر محمود')
            ->assertJsonPath('data.passengers.0.first_name', 'YASSER')
            ->assertJsonPath('data.total_paid', 4500);
    }
}
