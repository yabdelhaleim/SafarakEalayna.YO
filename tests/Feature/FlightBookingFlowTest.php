<?php

namespace Tests\Feature;

use App\Enums\CustomerTier;
use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Mail\FlightBookingTicketMailable;
use App\Services\Flight\FlightBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlightBookingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected FlightBookingService $bookingService;

    protected User $admin;

    protected Customer $customer;

    protected FlightSystem $flightSystem;

    protected FlightCarrier $carrier;

    protected Account $treasuryAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingService = app(FlightBookingService::class);

        // Create admin user
        $this->admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);

        // Create customer
        $this->customer = Customer::create([
            'full_name' => 'Test Customer',
            'phone' => '0123456789',
            'email' => 'customer@example.test',
            'national_id' => '12345678901234',
            'city' => 'Cairo',
            'customer_tier' => CustomerTier::STANDARD->value,
            'notes' => 'Test customer',
        ]);

        $this->flightSystem = FlightSystem::create([
            'name' => 'Test flight system',
            'code' => 'TFS'.substr(md5((string) microtime(true)), 0, 6),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        // Create flight carrier with balance
        $this->carrier = FlightCarrier::create([
            'name' => 'Test Airline',
            'code' => 'TA',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'EGP',
            'balance' => 100000,
            'credit_limit' => 50000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Create treasury account
        $this->treasuryAccount = Account::create([
            'name' => 'Main Treasury',
            'type' => 'treasury',
            'balance' => 50000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        Log::info('Test setup completed', [
            'admin_id' => $this->admin->id,
            'customer_id' => $this->customer->id,
            'carrier_id' => $this->carrier->id,
            'treasury_id' => $this->treasuryAccount->id,
        ]);
    }

    public function test_creates_booking_with_double_entry_accounting(): void
    {
        Log::info('Starting test: it_creates_booking_with_double_entry_accounting');

        $bookingData = [
            'customer_id' => $this->customer->id,
            'employee_id' => null,
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'return_date' => now()->addDays(14)->toDateString(),
            'trip_type' => 'round_trip',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                [
                    'name' => 'Ahmed Mohamed',
                    'type' => 'adult',
                ],
            ],
            'segments' => [
                [
                    'airline_name' => 'Test Airline',
                    'flight_number' => 'TA123',
                    'from_airport' => 'CAI',
                    'to_airport' => 'JED',
                    'departure_date' => now()->addDays(7)->toDateString(),
                    'departure_time' => '10:00',
                    'arrival_time' => '13:00',
                    'flight_class' => 'economy',
                ],
            ],
        ];

        // Execute booking creation
        $booking = $this->bookingService->createBooking($bookingData);

        // Assertions
        $this->assertDatabaseHas('flight_bookings', [
            'id' => $booking->id,
            'customer_id' => $this->customer->id,
            'booking_number' => $booking->booking_number,
            'purchase_price' => 15000.0,
            'selling_price' => 18000.0,
            'profit' => 3000.0,
            'status' => FlightBookingStatus::PENDING,
        ]);

        // Check carrier balance was debited
        $this->carrier->refresh();
        $this->assertEquals(85000, $this->carrier->balance); // 100000 - 15000
        $this->assertEquals(135000, $this->carrier->available_balance); // (85000 balance) + (50000 credit)

        // Check treasury account was credited
        $this->treasuryAccount->refresh();
        $this->assertEquals(50000, $this->treasuryAccount->balance);

        // Check passenger was created
        $this->assertDatabaseHas('passengers', [
            'flight_booking_id' => $booking->id,
            'first_name' => 'Ahmed Mohamed',
            'last_name' => '',
            'type' => 'adult',
        ]);

        // Check segment was created
        $this->assertDatabaseHas('flight_segments', [
            'flight_booking_id' => $booking->id,
            'flight_number' => 'TA123',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
        ]);

        // Check accounting entries exist
        $this->assertDatabaseHas('transactions', [
            'module' => TransactionModule::Flight->value,
            'related_type' => FlightBooking::class,
            'related_id' => $booking->id,
        ]);

        Log::info('Test passed: booking created with double entry accounting', [
            'booking_id' => $booking->id,
            'carrier_balance' => $this->carrier->balance,
            'treasury_balance' => $this->treasuryAccount->balance,
        ]);
    }

    public function test_handles_foreign_currency_booking(): void
    {
        Log::info('Starting test: it_handles_foreign_currency_booking');

        // Create carrier with USD
        $usdCarrier = FlightCarrier::create([
            'name' => 'US Airline',
            'code' => 'USA',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'USD',
            'balance' => 10000,
            'credit_limit' => 5000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $bookingData = [
            'customer_id' => $this->customer->id,
            'airline_name' => 'US Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JFK',
            'departure_date' => now()->addDays(10)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'USD',
            'purchase_price_foreign' => 500,
            'exchange_rate' => 50,
            'selling_price' => 30000,
            'flight_carrier_id' => $usdCarrier->id,
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                ['name' => 'Test Passenger', 'type' => 'adult'],
            ],
        ];

        $booking = $this->bookingService->createBooking($bookingData);

        // Assertions
        $this->assertDatabaseHas('flight_bookings', [
            'id' => $booking->id,
            'currency' => 'USD',
            'purchase_price_foreign' => 500,
            'exchange_rate' => 50,
            'purchase_price_egp' => 25000, // 500 * 50
            'selling_price' => 30000,
            'profit' => 5000, // 30000 - 25000
        ]);

        // Check carrier debited in USD
        $usdCarrier->refresh();
        $this->assertEquals(9500, $usdCarrier->balance); // 10000 - 500

        // Check treasury credited in EGP
        $this->treasuryAccount->refresh();
        $this->assertEquals(50000, $this->treasuryAccount->balance);

        Log::info('Test passed: foreign currency booking handled correctly', [
            'booking_id' => $booking->id,
            'usd_carrier_balance' => $usdCarrier->balance,
            'treasury_balance' => $this->treasuryAccount->balance,
        ]);
    }

    public function test_prevents_booking_when_insufficient_carrier_balance(): void
    {
        Log::info('Starting test: it_prevents_booking_when_insufficient_carrier_balance');

        // Create carrier with low balance
        $lowBalanceCarrier = FlightCarrier::create([
            'name' => 'Low Balance Airline',
            'code' => 'LB',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'EGP',
            'balance' => 5000,
            'credit_limit' => 10000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $bookingData = [
            'customer_id' => $this->customer->id,
            'airline_name' => 'Low Balance Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(5)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 20000, // More than available
            'selling_price' => 25000,
            'flight_carrier_id' => $lowBalanceCarrier->id,
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                ['name' => 'Test', 'type' => 'adult'],
            ],
        ];

        try {
            $this->bookingService->createBooking($bookingData);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('رصيد شركة الطيران غير كافٍ', $e->getMessage());
        }

        $this->assertDatabaseCount('flight_bookings', 0);

        $lowBalanceCarrier->refresh();
        $this->assertEquals(5000, $lowBalanceCarrier->balance);

        Log::info('Test passed: booking prevented with insufficient balance');
    }

    public function test_cancels_booking_with_complete_accounting_rollback(): void
    {
        Log::info('Starting test: it_cancels_booking_with_complete_accounting_rollback');

        // First create a booking
        $bookingData = [
            'customer_id' => $this->customer->id,
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                ['name' => 'Test Passenger', 'type' => 'adult'],
            ],
        ];

        $booking = $this->bookingService->createBooking($bookingData);

        // Add a payment
        $paymentData = [
            'amount' => 18000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryAccount->id,
            'notes' => 'Full payment',
        ];

        $this->bookingService->addPayment($booking, $paymentData);

        $this->carrier->refresh();
        $this->treasuryAccount->refresh();
        $carrierBalanceAfterBooking = $this->carrier->balance;
        $treasuryBalanceAfterBooking = $this->treasuryAccount->balance;

        Log::info('Balances after booking', [
            'carrier' => $carrierBalanceAfterBooking,
            'treasury' => $treasuryBalanceAfterBooking,
        ]);

        // Now cancel the booking
        $cancelData = [
            'airline_penalty' => 500,
            'office_penalty' => 200,
            'account_id' => $this->treasuryAccount->id,
            'notes' => 'Customer cancellation',
        ];

        $refund = $this->bookingService->cancelBooking($booking, $cancelData);

        // Assertions
        $this->assertDatabaseHas('flight_bookings', [
            'id' => $booking->id,
            'status' => FlightBookingStatus::REFUNDED,
        ]);

        $this->assertDatabaseHas('flight_refunds', [
            'id' => $refund->id,
            'flight_booking_id' => $booking->id,
            'airline_penalty' => 500,
            'office_penalty' => 200,
            'total_paid' => 18000,
            'refund_amount' => 17300, // 18000 - 500 - 200
        ]);

        // Check carrier was credited back (purchase - penalty)
        $this->carrier->refresh();
        $expectedCarrierBalance = $carrierBalanceAfterBooking + (15000 - 500); // 85000 + 14500
        $this->assertEquals($expectedCarrierBalance, $this->carrier->balance);

        // Check treasury was debited (refund amount)
        $this->treasuryAccount->refresh();
        $expectedTreasuryBalance = $treasuryBalanceAfterBooking - 17300;
        $this->assertEquals($expectedTreasuryBalance, $this->treasuryAccount->balance);

        Log::info('Test passed: cancellation with complete accounting rollback', [
            'booking_id' => $booking->id,
            'refund_id' => $refund->id,
            'carrier_balance' => $this->carrier->balance,
            'treasury_balance' => $this->treasuryAccount->balance,
        ]);
    }

    public function test_rolls_back_on_error_during_booking_creation(): void
    {
        Log::info('Starting test: it_rolls_back_on_error_during_booking_creation');

        $initialCarrierBalance = $this->carrier->balance;
        $initialTreasuryBalance = $this->treasuryAccount->balance;

        // Try to create booking with invalid data (will fail)
        $bookingData = [
            'customer_id' => $this->customer->id,
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => 'invalid-date', // This will cause validation error
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                ['name' => 'Test', 'type' => 'adult'],
            ],
        ];

        try {
            $this->bookingService->createBooking($bookingData);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            // Expected exception
        }

        // Verify balances unchanged (rollback successful)
        $this->carrier->refresh();
        $this->treasuryAccount->refresh();

        $this->assertEquals($initialCarrierBalance, $this->carrier->balance, 'Carrier balance should be unchanged');
        $this->assertEquals($initialTreasuryBalance, $this->treasuryAccount->balance, 'Treasury balance should be unchanged');

        // Verify no booking was created
        $this->assertDatabaseCount('flight_bookings', 0);

        Log::info('Test passed: rollback on error successful');
    }

    public function test_update_pending_booking_sets_pnr_and_notes(): void
    {
        $bookingData = [
            'customer_id' => $this->customer->id,
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                ['name' => 'Solo Pax', 'type' => 'adult'],
            ],
        ];

        $booking = $this->bookingService->createBooking($bookingData);

        $updated = $this->bookingService->updateBooking($booking, [
            'pnr' => 'PNR-XYZ',
            'notes' => 'Desk note',
        ]);

        $this->assertSame('PNR-XYZ', $updated->fresh()->pnr);
        $this->assertSame('Desk note', $updated->fresh()->notes);
    }

    public function test_send_ticket_email_queues_mailable(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin, ['*']);

        $bookingData = [
            'customer_id' => $this->customer->id,
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 10000,
            'selling_price' => 12000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                ['name' => 'Mail Pax', 'type' => 'adult'],
            ],
        ];

        $booking = $this->bookingService->createBooking($bookingData);

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/send-ticket-email", [
            'to_email' => 'recipient@example.test',
        ]);

        $response->assertOk();
        Mail::assertQueued(FlightBookingTicketMailable::class, function (FlightBookingTicketMailable $mail) use ($booking) {
            return (int) $mail->booking->id === (int) $booking->id;
        });
    }

    public function test_kwd_booking_cancel_restores_carrier_sign_and_refunds_customer_in_egp(): void
    {
        $kwdCarrier = FlightCarrier::create([
            'name' => 'Kuwait Airways Sign',
            'code' => 'KUW',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'KWD',
            'balance' => 1000,
            'credit_limit' => 0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $rate = 157.5;
        $purchaseForeign = 50.0;
        $purchaseEgp = $purchaseForeign * $rate;
        $sellingEgp = 9000.0;

        $booking = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'from_airport' => 'CAI',
            'to_airport' => 'KWI',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'KWD',
            'purchase_price_foreign' => $purchaseForeign,
            'exchange_rate' => $rate,
            'selling_price' => $sellingEgp,
            'flight_carrier_id' => $kwdCarrier->id,
            'purchase_balance_source' => 'carrier',
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                ['first_name' => 'KWD', 'last_name' => 'Pax', 'type' => 'adult'],
            ],
        ]);

        $kwdCarrier->refresh();
        $this->assertEquals(950.0, (float) $kwdCarrier->balance);
        $this->assertEquals('KWD', $booking->currency);
        $this->assertEquals($rate, (float) $booking->exchange_rate_used);

        $this->bookingService->addPayment($booking, [
            'amount' => $sellingEgp,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryAccount->id,
        ]);

        $treasuryAfterPayment = (float) $this->treasuryAccount->fresh()->balance;

        $airlinePenaltyEgp = 1575.0; // 10 KWD عند نفس سعر الحجز
        $officePenaltyEgp = 200.0;
        $expectedRefundEgp = $sellingEgp - $airlinePenaltyEgp - $officePenaltyEgp;

        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => $airlinePenaltyEgp,
            'office_penalty' => $officePenaltyEgp,
            'account_id' => $this->treasuryAccount->id,
        ]);

        $kwdCarrier->refresh();
        $this->treasuryAccount->refresh();

        $expectedCarrierBalance = 1000.0 - 10.0; // 50 KWD خصم ثم 40 KWD إرجاع
        $this->assertEquals($expectedCarrierBalance, (float) $kwdCarrier->balance);
        $this->assertEquals($treasuryAfterPayment - $expectedRefundEgp, (float) $this->treasuryAccount->balance);
    }

    public function test_system_booking_debits_system_not_carrier_when_carrier_is_informational(): void
    {
        $this->flightSystem->update(['balance' => 50000]);

        $carrierBefore = (float) $this->carrier->balance;
        $systemBefore = (float) $this->flightSystem->fresh()->balance;

        $booking = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 12000,
            'selling_price' => 15000,
            'purchase_balance_source' => 'system',
            'flight_system_id' => $this->flightSystem->id,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                ['first_name' => 'System', 'last_name' => 'Pax', 'type' => 'adult'],
            ],
        ]);

        $this->assertEquals('system', $booking->purchase_balance_source);
        $this->assertEquals($this->flightSystem->id, $booking->flight_system_id);
        $this->assertEquals($this->carrier->id, $booking->flight_carrier_id);

        $this->carrier->refresh();
        $this->flightSystem->refresh();

        $this->assertEquals($carrierBefore, (float) $this->carrier->balance, 'Carrier balance must not change on system booking');
        $this->assertEquals($systemBefore - 12000, (float) $this->flightSystem->balance);
    }

    public function test_creates_booking_via_group_and_records_debt_correctly(): void
    {
        // 1. Create a flight group
        $group = \App\Models\Flight\FlightGroup::create([
            'name' => 'Test Group Partner',
            'code' => 'TGP',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // 2. Set up booking data using this group
        $bookingData = [
            'customer_id' => $this->customer->id,
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'purchase_balance_source' => 'group',
            'flight_group_id' => $group->id,
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                ['name' => 'Group Pax', 'type' => 'adult'],
            ],
        ];

        // Execute booking creation
        $booking = $this->bookingService->createBooking($bookingData);

        // Assert booking details
        $this->assertEquals('group', $booking->purchase_balance_source);
        $this->assertEquals($group->id, $booking->flight_group_id);

        // Assert customer was debited (selling price)
        $this->customer->refresh();
        $this->assertEquals(18000.0, $this->customer->ledgerAccount->balance);

        // Assert group transaction was recorded (purchase price)
        $this->assertDatabaseHas('flight_group_transactions', [
            'flight_group_id' => $group->id,
            'flight_booking_id' => $booking->id,
            'type' => 'debt',
            'amount' => 15000.0,
        ]);

        $totalDebt = $group->groupTransactions()->where('type', 'debt')->sum('amount');
        $totalPayment = $group->groupTransactions()->where('type', 'payment')->sum('amount');
        $this->assertEquals(15000.0, $totalDebt - $totalPayment);

        $this->carrier->refresh();
        $this->assertEquals(100000.0, (float) $this->carrier->balance, 'Carrier balance must not change on group booking');
    }

    public function test_cancels_group_booking_and_reverses_debt_correctly(): void
    {
        // 1. Create a flight group
        $group = \App\Models\Flight\FlightGroup::create([
            'name' => 'Cancel Group Partner',
            'code' => 'CGP',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // 2. Set up booking data using this group
        $bookingData = [
            'customer_id' => $this->customer->id,
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'purchase_balance_source' => 'group',
            'flight_group_id' => $group->id,
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                ['name' => 'Group Pax', 'type' => 'adult'],
            ],
        ];

        // Create booking
        $booking = $this->bookingService->createBooking($bookingData);

        // Group balance should be 15000
        $totalDebt = $group->groupTransactions()->where('type', 'debt')->sum('amount');
        $totalPayment = $group->groupTransactions()->where('type', 'payment')->sum('amount');
        $this->assertEquals(15000.0, $totalDebt - $totalPayment);

        // 3. Cancel the booking with some penalties
        $cancelData = [
            'airline_penalty' => 1000, // We still owe the group 1000 EGP for penalty
            'office_penalty' => 500,
            'account_id' => $this->treasuryAccount->id,
            'notes' => 'Customer cancellation',
        ];

        $refund = $this->bookingService->cancelBooking($booking, $cancelData);

        // Assert booking status is cancelled
        $booking->refresh();
        $this->assertEquals(FlightBookingStatus::CANCELLED, $booking->status);

        // Group balance should be reversed by (purchase - airline_penalty) = (15000 - 1000) = 14000
        // So balance should become 15000 - 14000 = 1000
        $totalDebt = $group->groupTransactions()->where('type', 'debt')->sum('amount');
        $totalPayment = $group->groupTransactions()->where('type', 'payment')->sum('amount');
        $this->assertEquals(1000.0, $totalDebt - $totalPayment);
    }
}
