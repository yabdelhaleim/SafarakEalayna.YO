<?php

namespace Tests\Feature\Reports;

use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Enums\VisaStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightSystem;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\HajjUmra\VisaDuration;
use App\Models\Program;
use App\Models\User;
use App\Models\VisaBooking;
use App\Models\VisaDetail;
use App\Models\VisaPayment;
use App\Services\Reports\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression test for audit finding 1.1 (Medium, fixed in 8a881bf).
 *
 * The original getCustomerDebtsReport() only summed customer->flightBookings
 * regardless of the requested 'module' filter. When callers passed
 * module='hajj_umra' or module='visa', the report silently dropped those
 * pending balances — leading to underreported customer debt for Hajj/Umra
 * and Visa modules.
 *
 * The fix introduces resolveDebtBookingRelations() which maps the module
 * filter to the correct relation. This test locks in:
 *   - module='flight'    → only flightBookings counted
 *   - module='hajj_umra' → only hajjUmraBookings counted
 *   - module='visa'      → only visaBookings counted
 *   - no filter          → all three counted (union)
 *   - pending status filter still applied per-relation
 *   - the search filter is scoped to customer name/phone only
 *
 * @see \App\Services\Reports\FinancialReportService::getCustomerDebtsReport
 */
class CustomerDebtsReportModuleCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Customer $customer;

    protected Account $treasuryEGP;

    protected FinancialReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Debts Coverage Tester',
            'email' => 'debts-coverage@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin);

        // Treasury account required by Phase 6 NOT NULL on hajj_umra_bookings.account_id
        $this->treasuryEGP = Account::query()->create([
            'name' => 'Hajj Treasury EGP',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 500000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'is_module_vault' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'أحمد العميل',
            'phone' => '01000000001',
            'national_id' => '12345678901234',
            'created_by' => $this->admin->id,
        ]);

        $this->reports = app(FinancialReportService::class);
    }

    /**
     * Helper: create a pending Flight booking with partial payment so
     * the formula (selling_price - sum(payments)) returns a positive debt.
     */
    protected function createPendingFlightBooking(Customer $customer, float $selling, float $paid): FlightBooking
    {
        $system = FlightSystem::query()->create([
            'name' => 'Test System',
            'code' => 'TS'.substr(md5((string) microtime(true)), 0, 5),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $booking = FlightBooking::query()->create([
            'customer_id' => $customer->id,
            'flight_system_id' => $system->id,
            'booking_reference' => 'FL-'.uniqid(),
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'test',
            'agent_name' => $customer->full_name,
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => '10:00',
            'trip_type' => 'one_way',
            'airline' => 'Test Air',
            'passenger_count' => 1,
            'system_type' => 'manual',
            'selling_price' => $selling,
            'purchase_price' => $selling * 0.9,
            'profit' => $selling * 0.1,
            'currency' => 'EGP',
            'status' => 'PENDING',
            'module' => TransactionModule::Flight->value,
            'employee_id' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        if ($paid > 0) {
            FlightPayment::query()->create([
                'flight_booking_id' => $booking->id,
                'amount' => $paid,
                'currency' => 'EGP',
                'payment_method' => 'cash',
                'treasury_account' => 'office_drawer',
                'paid_by' => $customer->full_name,
                'payment_date' => now(),
                'created_by' => $this->admin->id,
            ]);
        }

        return $booking;
    }

    /**
     * Helper: create a pending HajjUmra booking with partial payment.
     */
    protected function createPendingHajjUmraBooking(Customer $customer, float $selling, float $paid): HajjUmraBooking
    {
        $program = Program::query()->create([
            'program_name' => 'Test Hajj Program',
            'program_type' => 'HAJJ',
            'total_nights' => 14,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'Test Mecca Hotel',
            'mecca_nights' => 7,
            'departure_date' => now()->addMonths(3)->toDateString(),
            'return_date' => now()->addMonths(3)->addDays(14)->toDateString(),
            'airline' => 'Test Air',
            'executing_company' => 'Test Co',
            'departure_point' => 'CAI',
            'selling_price' => $selling,
            'purchase_price' => $selling * 0.85,
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $booking = HajjUmraBooking::query()->create([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'module' => TransactionModule::HajjUmra->value,
            'selling_price' => $selling,
            'purchase_price' => $selling * 0.85,
            'profit' => $selling * 0.15,
            'currency' => 'EGP',
            'per_person' => true,
            'status' => HajjUmraStatus::Pending->value,
            'agent_name' => $customer->full_name,
            'created_by' => $this->admin->id,
            'account_id' => $this->treasuryEGP->id,
        ]);

        if ($paid > 0) {
            HajjUmraPayment::query()->create([
                'hajj_umra_booking_id' => $booking->id,
                'amount' => $paid,
                'currency' => 'EGP',
                'payment_method' => 'cash',
                'treasury_account' => 'office_drawer',
                'paid_by' => $customer->full_name,
                'payment_date' => now(),
                'created_by' => $this->admin->id,
            ]);
        }

        return $booking;
    }

    /**
     * Helper: create a pending Visa booking with partial payment.
     */
    protected function createPendingVisaBooking(Customer $customer, float $selling, float $paid): VisaBooking
    {
        $duration = VisaDuration::query()->create([
            'name' => '30 يوم',
            'code' => 'VD-30-'.uniqid(),
            'label_ar' => '30 يوم',
            'days' => 30,
            'is_active' => true,
        ]);

        $detail = VisaDetail::query()->create([
            'visa_type' => 'tourist',
            'country' => 'SA',
            'duration' => 30,
            'visa_duration_id' => $duration->id,
            'status' => 'submitted',
        ]);

        $booking = VisaBooking::query()->create([
            'customer_id' => $customer->id,
            'visa_detail_id' => $detail->id,
            'module' => TransactionModule::Visa->value,
            'purchase_price' => $selling * 0.8,
            'selling_price' => $selling,
            'service_fee' => 0,
            'profit' => $selling * 0.2,
            'currency' => 'EGP',
            'status' => 'submitted',
            'agent_name' => $customer->full_name,
            'created_by' => $this->admin->id,
        ]);

        if ($paid > 0) {
            VisaPayment::query()->create([
                'visa_booking_id' => $booking->id,
                'amount' => $paid,
                'currency' => 'EGP',
                'payment_method' => 'cash',
                'treasury_account' => 'office_drawer',
                'paid_by' => $customer->full_name,
                'payment_date' => now(),
                'created_by' => $this->admin->id,
            ]);
        }

        return $booking;
    }

    /**
     * Module=flight filter must return ONLY flight pending balances —
     * not hajjUmra or visa bookings — so a Hajj/Umra customer with no
     * flight bookings is excluded entirely.
     */
    public function test_module_flight_filter_returns_only_flight_debts(): void
    {
        // Customer has a flight booking (3000 - 500 = 2500 debt)
        $this->createPendingFlightBooking($this->customer, 3000.00, 500.00);

        // And a hajj booking (5000 - 1000 = 4000 debt) — must NOT be counted
        $this->createPendingHajjUmraBooking($this->customer, 5000.00, 1000.00);

        // And a visa booking (2000 - 0 = 2000 debt) — must NOT be counted
        $this->createPendingVisaBooking($this->customer, 2000.00, 0.00);

        $result = $this->reports->getCustomerDebtsReport(['module' => 'flight']);

        $this->assertSame(2500.0, (float) $result['total_debts']);
        $this->assertSame(1, (int) $result['customers_with_debts']);
    }

    /**
     * Module=hajj_umra filter must return ONLY hajjUmra pending balances.
     * Critical test — this is the exact gap that the audit found.
     */
    public function test_module_hajj_umra_filter_returns_only_hajj_debts(): void
    {
        // Flight booking — must NOT be counted
        $this->createPendingFlightBooking($this->customer, 3000.00, 500.00);

        // HajjUmra booking (5000 - 1000 = 4000 debt)
        $this->createPendingHajjUmraBooking($this->customer, 5000.00, 1000.00);

        // Visa booking — must NOT be counted
        $this->createPendingVisaBooking($this->customer, 2000.00, 0.00);

        $result = $this->reports->getCustomerDebtsReport(['module' => 'hajj_umra']);

        $this->assertSame(4000.0, (float) $result['total_debts']);
        $this->assertSame(1, (int) $result['customers_with_debts']);
    }

    /**
     * Module=visa filter must return ONLY visa pending balances.
     */
    public function test_module_visa_filter_returns_only_visa_debts(): void
    {
        $this->createPendingFlightBooking($this->customer, 3000.00, 500.00);
        $this->createPendingHajjUmraBooking($this->customer, 5000.00, 1000.00);
        $this->createPendingVisaBooking($this->customer, 2000.00, 0.00);

        $result = $this->reports->getCustomerDebtsReport(['module' => 'visa']);

        $this->assertSame(2000.0, (float) $result['total_debts']);
        $this->assertSame(1, (int) $result['customers_with_debts']);
    }

    /**
     * With no module filter, all three relations must be unioned.
     */
    public function test_no_filter_unions_all_three_modules(): void
    {
        $this->createPendingFlightBooking($this->customer, 3000.00, 500.00);
        $this->createPendingHajjUmraBooking($this->customer, 5000.00, 1000.00);
        $this->createPendingVisaBooking($this->customer, 2000.00, 0.00);

        $result = $this->reports->getCustomerDebtsReport([]);

        $expectedTotal = 2500.0 + 4000.0 + 2000.0;
        $this->assertSame($expectedTotal, (float) $result['total_debts']);
    }

    /**
     * Customers with zero pending debt are excluded from the report.
     */
    public function test_customers_with_zero_debt_are_excluded(): void
    {
        // Fully-paid booking → debt = 0
        $this->createPendingFlightBooking($this->customer, 1000.00, 1000.00);

        // Other customer with debt
        $otherCustomer = Customer::query()->create([
            'full_name' => 'عميل آخر',
            'phone' => '01000000002',
            'national_id' => '12345678901235',
            'created_by' => $this->admin->id,
        ]);
        $this->createPendingFlightBooking($otherCustomer, 800.00, 100.00);

        $result = $this->reports->getCustomerDebtsReport(['module' => 'flight']);

        $this->assertSame(700.0, (float) $result['total_debts']);
        $this->assertSame(1, (int) $result['customers_with_debts']);
        $this->assertSame($otherCustomer->id, (int) $result['debts'][0]['customer_id']);
    }

    /**
     * Search filter must be scoped to customer name/phone only (the audit
     * fix also closed a search-OR-unrelated-records gap).
     */
    public function test_search_filter_scopes_to_customer_name_or_phone(): void
    {
        $this->createPendingFlightBooking($this->customer, 3000.00, 500.00);

        // Search by name substring
        $resultByName = $this->reports->getCustomerDebtsReport([
            'search' => 'أحمد',
            'module' => 'flight',
        ]);
        $this->assertSame(2500.0, (float) $resultByName['total_debts']);

        // Search by phone substring
        $resultByPhone = $this->reports->getCustomerDebtsReport([
            'search' => '01000000001',
            'module' => 'flight',
        ]);
        $this->assertSame(2500.0, (float) $resultByPhone['total_debts']);

        // Search with no match returns zero
        $resultNoMatch = $this->reports->getCustomerDebtsReport([
            'search' => 'NO_MATCH_XYZ',
            'module' => 'flight',
        ]);
        $this->assertSame(0.0, (float) $resultNoMatch['total_debts']);
        $this->assertSame(0, (int) $resultNoMatch['customers_with_debts']);
    }

    /**
     * Cancelled/confirmed/completed bookings (anything NOT pending) must
     * NOT be counted as debt.
     */
    public function test_non_pending_status_bookings_excluded(): void
    {
        // Pending booking → debt 2500
        $this->createPendingFlightBooking($this->customer, 3000.00, 500.00);

        // Confirmed booking → must NOT be counted
        $system = FlightSystem::query()->firstOrCreate(
            ['code' => 'CONF'.uniqid()],
            [
                'name' => 'Confirmed System',
                'type' => 'gds',
                'is_active' => true,
                'currency' => 'EGP',
                'balance' => 0,
                'credit_limit' => 0,
                'created_by' => $this->admin->id,
            ]
        );

        FlightBooking::query()->create([
            'customer_id' => $this->customer->id,
            'flight_system_id' => $system->id,
            'booking_reference' => 'FL-CONF-'.uniqid(),
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'test',
            'agent_name' => $this->customer->full_name,
            'origin' => 'CAI',
            'destination' => 'RUH',
            'departure_date' => now()->addDays(14)->toDateString(),
            'departure_time' => '12:00',
            'trip_type' => 'one_way',
            'airline' => 'Test Air',
            'passenger_count' => 1,
            'system_type' => 'manual',
            'selling_price' => 9999.00,
            'purchase_price' => 8000.00,
            'profit' => 1999.00,
            'currency' => 'EGP',
            'status' => 'CONFIRMED',
            'module' => TransactionModule::Flight->value,
            'employee_id' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        $result = $this->reports->getCustomerDebtsReport(['module' => 'flight']);

        $this->assertSame(2500.0, (float) $result['total_debts']);
    }
}
