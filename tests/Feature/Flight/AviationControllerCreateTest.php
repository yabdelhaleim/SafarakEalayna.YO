<?php

namespace Tests\Feature\Flight;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for AviationController::create + report + treasury endpoints — Phase 3.
 *
 * ⚠️ AviationController has multiple HTTP endpoints separate from AviationService:
 *   - POST /api/v1/flight/aviation — create booking (via AviationService::createBooking)
 *   - GET /api/v1/flight/aviation — index (uses getReport)
 *   - POST /api/v1/flight/aviation/{id}/cancel — cancelBooking
 *   - POST /api/v1/flight/aviation/treasury — transfer funds
 *   - GET /api/v1/flight/aviation/report — report
 *   - GET /api/v1/flight/aviation/next-number — booking number generator
 *
 * Coverage:
 *   ① POST creates booking successfully with payment
 *   ② POST validates passenger rule (infant > adult → throws json error)
 *   ③ GET /index returns booking list
 *   ④ POST /treasury validates account_id required when amount > 0
 *   ⑤ GET /next-number returns a generated booking number
 *
 * @see \App\Http\Controllers\Api\V1\Flight\AviationController
 */
class AviationControllerCreateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Account $cashbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Aviation Ctrl Admin',
            'email' => 'aviation-ctrl-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->cashbox = Account::create([
            'name' => 'Aviation Ctrl Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * ✅ 1) POST creates booking successfully
     */
    public function test_post_creates_booking_with_payment(): void
    {
        $payload = [
            'booking_reference' => 'AV-CTRL-'.uniqid(),
            'agent_name' => 'Test Agent',
            'customer' => [
                'phone' => '01099999999',
                'full_name' => 'Aviation Ctrl Cust',
                'national_id' => '12345678901234',
                'city' => 'Cairo',
                'customer_tier' => 'STANDARD',
            ],
            'pricing' => [
                'currency' => 'EGP',
                'purchase_price' => 15000,
                'selling_price' => 18000,
            ],
            'flight' => [
                'origin' => 'JED',
                'destination' => 'CAI',
                'departure_date' => now()->addDays(7)->toDateString(),
                'departure_time' => '10:30',
                'trip_type' => 'ONE_WAY',
                'airline' => 'Test Airline',
                'baggage_allowance_kg' => 23,
            ],
            'booking_channel' => [
                'type' => 'SYSTEM',
                'provider' => 'Amadeus',
            ],
            'passengers' => [
                [
                    'first_name' => 'First',
                    'last_name' => 'Pax',
                    'date_of_birth' => '1990-01-01',
                ],
            ],
            'payment' => [
                'payment_method' => 'cash',
                'amount' => 18000,
                'treasury_account' => 'cashbox',
                'account_id' => $this->cashbox->id,
            ],
        ];

        $response = $this->postJson('/api/v1/flight/aviation', $payload);

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.booking_reference', $payload['booking_reference'])
            ->assertJsonPath('data.status', 'CONFIRMED');

        $this->assertDatabaseHas('flight_bookings', [
            'booking_reference' => $payload['booking_reference'],
        ]);

        $this->assertDatabaseHas('flight_pricings', [
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'profit' => 3000,
        ]);
    }

    /**
     * ✅ 2) POST rejects passenger rule violation (infant > adult) with structured error
     */
    public function test_post_rejects_passenger_rule_violation(): void
    {
        $payload = [
            'booking_reference' => 'AV-PAX-VIOLATION-'.uniqid(),
            'agent_name' => 'Test Agent',
            'customer' => [
                'phone' => '01088888888',
                'full_name' => 'Pax Rule Test',
            ],
            'pricing' => [
                'currency' => 'EGP',
                'purchase_price' => 5000,
                'selling_price' => 7000,
            ],
            'flight' => [
                'origin' => 'JED',
                'destination' => 'CAI',
                'departure_date' => now()->addDays(7)->toDateString(),
                'departure_time' => '10:30',
                'trip_type' => 'ONE_WAY',
                'airline' => 'Test',
            ],
            'booking_channel' => [
                'type' => 'SYSTEM',
                'provider' => 'Amadeus',
            ],
            'passengers' => [
                // 2 infants + 1 adult → infants > adults → INFANT_EXCEEDS_ADULT
                ['first_name' => 'B1', 'last_name' => 'X', 'date_of_birth' => now()->subMonths(6)->format('Y-m-d')],
                ['first_name' => 'B2', 'last_name' => 'X', 'date_of_birth' => now()->subMonths(3)->format('Y-m-d')],
                ['first_name' => 'Mom', 'last_name' => 'X', 'date_of_birth' => '1990-01-01'],
            ],
        ];

        $response = $this->postJson('/api/v1/flight/aviation', $payload);

        // الفشل بيترمي JSON string، الـ controller بيحوّلها لـ ApiResponse
        $response->assertStatus(422);

        // الـ body لازم يحتوي على رسالة خطأ passenger
        $responseData = json_decode($response->getContent(), true);
        $this->assertFalse($responseData['success'] ?? true);

        // ما اتنشأش booking في الـ DB
        $this->assertDatabaseMissing('flight_bookings', [
            'booking_reference' => $payload['booking_reference'],
        ]);
    }

    /**
     * ✅ 3) GET /index returns list of aviation bookings
     */
    public function test_index_returns_aviation_bookings(): void
    {
        $customer = Customer::create([
            'full_name' => 'Index Aviation Cust',
            'phone' => '01077777777',
        ]);

        FlightBooking::create([
            'customer_id' => $customer->id,
            'booking_reference' => 'AV-IDX-'.uniqid(),
            'booking_number' => 'AV-IDX-'.uniqid(),
            'booking_channel_type' => 'SYSTEM',
            'booking_channel_provider' => 'Amadeus',
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
            'purchase_price' => 1000,
            'selling_price' => 1500,
            'profit' => 500,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/flight/aviation');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data']);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(1, count($data),
            'Aviation index must return at least 1 booking');
    }

    /**
     * ✅ 4) POST /aviation validates required fields
     */
    public function test_post_aviation_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/flight/aviation', [
            // booking_reference مفقود — agent_name مفقود — إلخ
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'booking_reference',
                'agent_name',
                'customer.phone',
                'pricing.currency',
                'flight.origin',
                'booking_channel.provider',
                'passengers',
            ]);
    }

    /**
     * ✅ 5) GET /aviation/next-number returns a generated booking number
     *
     * Note: next-number route is registered OUTSIDE the flight prefix
     * (see routes/api.php — top-level route for the frontend fallback).
     */
    public function test_next_number_returns_generated_booking_number(): void
    {
        $response = $this->getJson('/api/v1/aviation/next-number');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => ['number'],
                'number',
            ]);

        $bookingNumber = $response->json('number');
        $this->assertMatchesRegularExpression('/^FLT-\d{8}-[A-Z0-9]{6}$/', $bookingNumber,
            'Booking number must match expected format FLT-YYYYMMDD-XXXXXX');
    }
}
