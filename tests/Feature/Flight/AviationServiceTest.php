<?php

namespace Tests\Feature\Flight;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Finance\CurrencyService;
use App\Services\Flight\AviationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for AviationService — Phase 2 coverage.
 *
 * ⚠️ AviationService had ZERO direct tests — only indirect ones via FlightRemainingCrudTest.
 *    This is critical because:
 *    - AviationService.createBooking is a SEPARATE API path from FlightController
 *    - It writes to FlightBooking + FlightPricing + FlightPayment + FlightPassenger via UPSERT-style flows
 *    - BL-04: Auto Profit Calculation (EGP vs foreign currency)
 *    - BL-06: Passenger Rules (infant > adult validation)
 *    - OP-07: Treasury movement via TransactionService
 *
 * @see \App\Services\Flight\AviationService
 * @see \App\Http\Controllers\Api\V1\Flight\AviationController
 */
class AviationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FlightSystem $flightSystem;

    protected AviationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Aviation Test Admin',
            'email' => 'aviation-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->service = app(AviationService::class);
    }

    /**
     * ✅ 1) BL-04 — Auto profit for EGP booking: selling - purchase = profit
     */
    public function test_calculate_profit_for_egp_booking(): void
    {
        $result = $this->service->calculateProfit([
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
        ]);

        $this->assertEquals(15000.0, (float) $result['purchase_price']);
        $this->assertEquals(18000.0, (float) $result['selling_price']);
        $this->assertEquals(3000.0, (float) $result['profit'],
            'EGP profit must equal selling - purchase');
    }

    /**
     * ✅ 2) BL-04 — Foreign currency uses exchange_rate × amount
     */
    public function test_calculate_profit_for_foreign_currency_with_exchange_rate(): void
    {
        $result = $this->service->calculateProfit([
            'currency' => 'USD',
            'amount_in_foreign_currency' => 500,  // 500 USD
            'exchange_rate_used' => 50.0,         // 50 EGP/USD
            'selling_price_egp' => 30000,
        ]);

        $this->assertEquals('USD', $result['booking_currency']);
        $this->assertEquals(500.0, (float) $result['amount_in_foreign_currency']);
        $this->assertEquals(50.0, (float) $result['exchange_rate_used']);

        // purchase_price_egp = 500 × 50 = 25000
        $this->assertEquals(25000.0, (float) $result['purchase_price_egp']);

        // profit_egp = 30000 - 25000 = 5000
        $this->assertEquals(5000.0, (float) $result['profit_egp']);
    }

    /**
     * ✅ 3) BL-04 — Negative margin warning (selling < purchase)
     */
    public function test_calculate_profit_warns_on_negative_margin(): void
    {
        $result = $this->service->calculateProfit([
            'currency' => 'EGP',
            'purchase_price' => 18000,  // أعلى
            'selling_price' => 15000,  // أقل
        ]);

        $this->assertEquals(-3000.0, (float) $result['profit']);

        $warningCodes = collect($result['warnings'])->pluck('code')->toArray();
        $this->assertContains('NEGATIVE_MARGIN_ERROR', $warningCodes,
            'Negative profit must trigger NEGATIVE_MARGIN_ERROR warning');
    }

    /**
     * ✅ 4) BL-04 — Low margin warning (< 50 EGP)
     */
    public function test_calculate_profit_warns_on_low_margin_below_50_egp(): void
    {
        $result = $this->service->calculateProfit([
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 15040,  // هامش 40 EGP < 50
        ]);

        $this->assertEquals(40.0, (float) $result['profit']);

        $warningCodes = collect($result['warnings'])->pluck('code')->toArray();
        $this->assertContains('MARGIN_WARNING', $warningCodes,
            'Margin below 50 EGP must trigger MARGIN_WARNING');
    }

    /**
     * ✅ 5) BL-06 — Passenger validation: infant > adult is invalid
     */
    public function test_validate_passengers_rejects_infant_count_exceeding_adults(): void
    {
        $travelDate = Carbon::parse(now()->addDays(30));
        $passengers = [
            ['first_name' => 'Mom', 'last_name' => 'A', 'date_of_birth' => '1990-01-01'],
            // infant #1
            ['first_name' => 'Baby1', 'last_name' => 'A', 'date_of_birth' => now()->subMonths(6)->format('Y-m-d')],
            // infant #2
            ['first_name' => 'Baby2', 'last_name' => 'A', 'date_of_birth' => now()->subMonths(3)->format('Y-m-d')],
        ];

        $result = $this->service->validatePassengers($passengers, $travelDate);

        $this->assertEquals(2, $result['counts']['infants']);
        $this->assertEquals(1, $result['counts']['adults']);

        $errorCodes = collect($result['errors'])->pluck('code')->toArray();
        $this->assertContains('INFANT_EXCEEDS_ADULT', $errorCodes,
            'Must reject when infants (2) > adults (1)');
    }

    /**
     * ✅ 6) BL-06 — Passenger without date_of_birth defaults to adult
     */
    public function test_validate_passengers_treats_missing_dob_as_adult(): void
    {
        $travelDate = Carbon::parse(now()->addDays(30));
        $passengers = [
            ['first_name' => 'No', 'last_name' => 'DOB', 'date_of_birth' => null],
        ];

        $result = $this->service->validatePassengers($passengers, $travelDate);

        $this->assertEmpty($result['errors']);
        $this->assertEquals(1, $result['counts']['adults'],
            'Passengers without DOB must be classified as adult');
        $this->assertEquals(0, $result['counts']['infants']);
        $this->assertEquals(0, $result['counts']['children']);
    }

    /**
     * ✅ 7) OP-02 — getBooking finds by ID, booking_reference, or customer phone
     */
    public function test_get_booking_finds_by_id_reference_or_phone(): void
    {
        $customer = Customer::create([
            'full_name' => 'Aviation Test Cust',
            'phone' => '01555555555',
        ]);

        $flightSystem = FlightSystem::create([
            'name' => 'Aviation Test System',
            'code' => 'AVS'.substr(md5(microtime(true)), 0, 5),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $booking = FlightBooking::create([
            'customer_id' => $customer->id,
            'booking_reference' => 'AV-TEST-REF-001',
            'booking_number' => 'AV-TEST-REF-001',
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
            'flight_system_id' => $flightSystem->id,
            'created_by' => $this->admin->id,
        ]);

        // Search by ID
        $byId = $this->service->getBooking((string) $booking->id);
        $this->assertNotNull($byId, 'getBooking must find by ID');
        $this->assertEquals($booking->id, $byId->id);

        // Search by booking_reference
        $byRef = $this->service->getBooking('AV-TEST-REF-001');
        $this->assertNotNull($byRef, 'getBooking must find by booking_reference');
        $this->assertEquals($booking->id, $byRef->id);

        // Search by customer phone (different — uses orWhereHas)
        $byPhone = $this->service->getBooking('01555555555');
        $this->assertNotNull($byPhone, 'getBooking must find by customer phone');
        $this->assertEquals($booking->id, $byPhone->id);

        // Search by non-existing value returns null
        $notFound = $this->service->getBooking('NON_EXISTING_REF');
        $this->assertNull($notFound);
    }

    /**
     * ✅ 8) OP-03 — updateBooking sets status + notes
     */
    public function test_update_booking_via_aviation_service(): void
    {
        $customer = Customer::create([
            'full_name' => 'Aviation Update Cust',
            'phone' => '01666666666',
        ]);

        $booking = FlightBooking::create([
            'customer_id' => $customer->id,
            'booking_reference' => 'AV-UPD-'.uniqid(),
            'booking_number' => 'AV-UPD-'.uniqid(),
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
            'purchase_price' => 500,
            'selling_price' => 800,
            'profit' => 300,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $result = $this->service->updateBooking($booking->id, [
            'status' => 'PENDING',
            'notes' => 'Aviation update test',
        ]);

        $freshStatus = $result->fresh()->status;
        $this->assertEquals('PENDING', $freshStatus instanceof \BackedEnum ? $freshStatus->value : $freshStatus);
        $this->assertEquals('Aviation update test', $result->fresh()->notes);
    }

    /**
     * ✅ 9) OP-04 — cancelBooking sets status to CANCELLED and appends reason
     */
    public function test_cancel_booking_via_aviation_service(): void
    {
        $customer = Customer::create([
            'full_name' => 'Aviation Cancel Cust',
            'phone' => '01777777777',
        ]);

        $booking = FlightBooking::create([
            'customer_id' => $customer->id,
            'booking_reference' => 'AV-CXL-'.uniqid(),
            'booking_number' => 'AV-CXL-'.uniqid(),
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
            'purchase_price' => 500,
            'selling_price' => 800,
            'profit' => 300,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
            'notes' => 'Original notes',
        ]);

        $result = $this->service->cancelBooking($booking->id, 'Customer changed mind', 'Agent Smith');

        $freshStatus = $result->fresh()->status;
        $this->assertEquals('CANCELLED', $freshStatus instanceof \BackedEnum ? $freshStatus->value : $freshStatus);

        $notes = $result->fresh()->notes;
        $this->assertStringContainsString('Original notes', $notes,
            'Existing notes must be preserved');
        $this->assertStringContainsString('سبب الإلغاء: Customer changed mind', $notes,
            'Cancellation reason must be appended');
    }

    /**
     * ✅ 10) OP-05 — getReport returns summary with total_revenue and total_profit
     */
    public function test_get_report_returns_summary_with_totals(): void
    {
        $customer = Customer::create([
            'full_name' => 'Aviation Report Cust',
            'phone' => '01888888888',
        ]);

        // حجز 1
        $b1 = FlightBooking::create([
            'customer_id' => $customer->id,
            'booking_reference' => 'AV-RP1-'.uniqid(),
            'booking_number' => 'AV-RP1-'.uniqid(),
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
            'purchase_price' => 500,
            'selling_price' => 800,
            'profit' => 300,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);
        \App\Models\FlightPricing::create([
            'flight_booking_id' => $b1->id,
            'currency' => 'EGP',
            'purchase_price' => 500,
            'selling_price' => 800,
            'profit' => 300,
        ]);

        // حجز 2 (refunded — لازم يتم استثناؤه من الإجمالي)
        $b2 = FlightBooking::create([
            'customer_id' => $customer->id,
            'booking_reference' => 'AV-RP2-'.uniqid(),
            'booking_number' => 'AV-RP2-'.uniqid(),
            'booking_channel_type' => 'SYSTEM',
            'booking_channel_provider' => 'Amadeus',
            'system_type' => 'manual',
            'status' => 'REFUNDED',
            'agent_name' => 'Test',
            'airline' => 'Test Air',
            'airline_name' => 'Test Air',
            'origin' => 'JED',
            'destination' => 'CAI',
            'from_airport' => 'JED',
            'to_airport' => 'CAI',
            'departure_date' => now()->addDays(14),
            'departure_time' => now()->addDays(14)->setTime(10, 0),
            'arrival_time' => now()->addDays(14)->setTime(12, 0),
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'purchase_price' => 1000,
            'selling_price' => 1500,
            'profit' => 500,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);
        \App\Models\FlightPricing::create([
            'flight_booking_id' => $b2->id,
            'currency' => 'EGP',
            'purchase_price' => 1000,
            'selling_price' => 1500,
            'profit' => 500,
        ]);

        // حجز 3 — نظام غير aviation (مش في scope التقرير)
        // FlightBooking ببحث whereIn BookingChannelType فالـstatus filter ما له — تأكد من count
        $report = $this->service->getReport([]);

        // ⚠️ Aviation bookings فقط اللي اتسجلت — ببحث whereNotNull('booking_channel_type')
        // مع whereIn في validation values
        $count = collect($report['bookings'])->count();
        $this->assertGreaterThanOrEqual(2, $count,
            'Report must include bookings with valid booking_channel_type (SYSTEM/SIGN/GROUP)');

        // لازم يكون عندي summary
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('total_bookings', $report['summary']);
        $this->assertArrayHasKey('total_revenue', $report['summary']);
        $this->assertArrayHasKey('total_profit', $report['summary']);

        // إجمالي الربح لازم يكون موجب
        $totalProfit = (float) $report['summary']['total_profit'];
        $this->assertGreaterThan(0, $totalProfit,
            'total_profit must include profits from confirmed aviation bookings');
    }
}
