<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Enums\FlightPaymentMethod;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightTicket;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightBookingPhase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_create_booking_records_gl_journal_tickets_and_profit_on_row(): void
    {
        $user = User::create([
            'name' => 'Phase2 Test',
            'email' => 'phase2-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($user);

        $customer = Customer::factory()->create();

        $treasury = Account::create([
            'name' => 'Test treasury',
            'type' => 'treasury',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $user->id,
        ]);

        $service = app(FlightBookingService::class);

        $booking = $service->createBooking([
            'customer_id' => $customer->id,
            'selling_price' => 750,
            'purchase_price' => 500,
            'currency' => 'EGP',
            'account_id' => $treasury->id,
            'departure_date' => now()->addWeek()->toDateString(),
            'departure_time' => '10:00',
            'arrival_time' => '14:00',
            'flight_number' => 'MS100',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'passengers' => [
                ['name' => 'John Doe', 'type' => 'adult'],
                ['name' => 'Jane Doe', 'type' => 'adult'],
            ],
        ]);

        $this->assertSame(250.0, (float) $booking->profit);
        $this->assertNotNull($booking->sale_gl_transaction_id);
        $this->assertSame(2, $booking->tickets->count());
        $this->assertDatabaseCount('flight_tickets', 2);

        $entryCount = AccountEntry::query()
            ->where('transaction_id', $booking->sale_gl_transaction_id)
            ->count();
        $this->assertSame(2, $entryCount);

        $treasury->refresh();
        $this->assertSame(750.0, (float) $treasury->balance);
    }

    public function test_create_booking_with_vodafone_cash_payment_persists_payment_method(): void
    {
        $user = User::create([
            'name' => 'Vodafone Pay Test',
            'email' => 'vcash-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($user);

        $customer = Customer::factory()->create();

        $treasury = Account::create([
            'name' => 'Treasury VC',
            'type' => 'treasury',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $user->id,
        ]);

        $service = app(FlightBookingService::class);

        $booking = $service->createBooking([
            'customer_id' => $customer->id,
            'selling_price' => 600,
            'purchase_price' => 400,
            'currency' => 'EGP',
            'account_id' => $treasury->id,
            'departure_date' => now()->addWeek()->toDateString(),
            'departure_time' => '10:00',
            'arrival_time' => '14:00',
            'flight_number' => 'MS101',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'passengers' => [
                ['name' => 'Pay Test', 'type' => 'adult'],
            ],
            'payment' => [
                'amount' => 600,
                'account_id' => $treasury->id,
                'payment_method' => FlightPaymentMethod::VodafoneCash->value,
            ],
        ]);

        $payment = FlightPayment::query()->where('flight_booking_id', $booking->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame(FlightPaymentMethod::VodafoneCash, $payment->payment_method);
        $this->assertSame(600.0, (float) $payment->amount);
    }

    public function test_create_booking_rolls_back_when_carrier_debit_fails(): void
    {
        $user = User::create([
            'name' => 'Phase2 Test',
            'email' => 'phase2-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($user);

        $customer = Customer::factory()->create();

        $treasury = Account::create([
            'name' => 'Test treasury 2',
            'type' => 'treasury',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $user->id,
        ]);

        $system = FlightSystem::create([
            'name' => 'Test system',
            'code' => 'TSYS'.substr(md5((string) microtime(true)), 0, 6),
            'type' => 'gds',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $carrier = FlightCarrier::create([
            'flight_system_id' => $system->id,
            'name' => 'Low balance carrier',
            'code' => 'LBC'.substr(md5((string) microtime(true)), 0, 5),
            'currency' => 'EGP',
            'balance' => 40,
            'credit_limit' => 0,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $before = FlightBooking::count();

        $service = app(FlightBookingService::class);
        $thrown = false;
        try {
            $service->createBooking([
                'customer_id' => $customer->id,
                'flight_carrier_id' => $carrier->id,
                'selling_price' => 200,
                'purchase_price' => 100,
                'currency' => 'EGP',
                'account_id' => $treasury->id,
                'departure_date' => now()->addWeek()->toDateString(),
                'departure_time' => '10:00',
                'arrival_time' => '14:00',
                'flight_number' => 'MS200',
                'from_airport' => 'CAI',
                'to_airport' => 'JED',
                'passengers' => [
                    ['name' => 'Solo Pax', 'type' => 'adult'],
                ],
            ]);
        } catch (\Exception) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertSame($before, FlightBooking::count());
        $this->assertSame(0, FlightTicket::count());
        $treasury->refresh();
        $this->assertSame(0.0, (float) $treasury->balance);
    }
}
