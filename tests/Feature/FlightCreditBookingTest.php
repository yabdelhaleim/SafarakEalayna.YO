<?php

namespace Tests\Feature;

use App\Enums\CustomerTier;
use App\Enums\FlightPaymentMethod;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightCreditBookingTest extends TestCase
{
    use RefreshDatabase;

    protected FlightBookingService $bookingService;

    protected User $admin;

    protected Customer $customer;

    protected FlightCarrier $carrier;

    protected Account $treasuryAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingService = app(FlightBookingService::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'full_name' => 'عميل آجل',
            'phone' => '01099998888',
            'email' => 'credit-customer@example.test',
            'customer_tier' => CustomerTier::STANDARD->value,
        ]);

        $system = FlightSystem::create([
            'name' => 'NDC Test',
            'code' => 'NDC'.substr(md5((string) microtime(true)), 0, 4),
            'type' => 'ndc',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 500000,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'name' => 'Test Carrier',
            'code' => 'TC',
            'flight_system_id' => $system->id,
            'currency' => 'EGP',
            'balance' => 200000,
            'credit_limit' => 0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->treasuryAccount = Account::create([
            'name' => 'خزينة نقدي طيران',
            'type' => 'cashbox',
            'balance' => 10000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_partial_payment_records_correct_customer_debt(): void
    {
        $booking = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'KWI',
            'departure_date' => now()->addDays(5)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 9500,
            'selling_price' => 9800,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                ['name' => 'Passenger One', 'type' => 'adult'],
            ],
            'payment' => [
                'amount' => 4500,
                'account_id' => $this->treasuryAccount->id,
                'payment_method' => FlightPaymentMethod::Cash->value,
            ],
        ]);

        $this->assertNotNull($booking->sale_gl_transaction_id);

        $this->customer->refresh();
        $customerAccount = Account::query()->findOrFail($this->customer->account_id);

        $this->assertEquals(5300.0, (float) $customerAccount->balance);
    }

    public function test_backfill_fixes_missing_sale_journal_after_partial_payment(): void
    {
        $booking = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'KWI',
            'departure_date' => now()->addDays(5)->toDateString(),
            'departure_time' => '10:00',
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 9500,
            'selling_price' => 9800,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->treasuryAccount->id,
            'passengers' => [
                ['name' => 'Passenger One', 'type' => 'adult'],
            ],
            'payment' => [
                'amount' => 4500,
                'account_id' => $this->treasuryAccount->id,
                'payment_method' => FlightPaymentMethod::Cash->value,
            ],
        ]);

        $customerAccount = Account::query()->findOrFail($this->customer->fresh()->account_id);
        $this->assertEquals(5300.0, (float) $customerAccount->balance);

        // محاكاة الخلل القديم: حذف قيد البيع مع الإبقاء على الدفعة فقط
        $booking->forceFill(['sale_gl_transaction_id' => null])->save();
        LedgerBalanceMutationGuard::run(fn () => $customerAccount->update(['balance' => -4500]));

        $result = $this->bookingService->backfillMissingCustomerSaleLedgers();

        $this->assertSame(1, $result['repaired']);
        $this->assertSame([], $result['errors']);

        $booking->refresh();
        $customerAccount->refresh();

        $this->assertNotNull($booking->sale_gl_transaction_id);
        $this->assertEquals(5300.0, (float) $customerAccount->balance);
    }
}
