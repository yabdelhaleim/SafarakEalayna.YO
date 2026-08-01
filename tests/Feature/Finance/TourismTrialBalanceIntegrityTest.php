<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightGroup;
use App\Models\User;
use App\Services\Finance\TreasuryService;
use App\Services\Reports\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for the tourism division of
 * `TreasuryService::calculateReceivablesAndPayables('tourism')` after the
 * `'general'` module_type was added to the office AND tourism divisions
 * (commit `f389cb3 fixfawry`).
 *
 * Goal: prove that expanding the tourism list to include `'general'` did
 * NOT leak office balances into the tourism division, and did NOT change
 * the existing aggregation of flight / hajj_umra / visa / flight_group /
 * supplier / prepaid balances.
 */
class TourismTrialBalanceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected TreasuryService $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Tourism Auditor',
            'email' => 'tourism-auditor-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = app(TreasuryService::class);
    }

    /**
     * Baseline: empty DB → due_to_us = 0.
     */
    public function test_empty_database_yields_zero_tourism_due_to_us(): void
    {
        $result = $this->treasury->calculateReceivablesAndPayables('tourism');

        $this->assertEqualsWithDelta(0.0, $result['due_to_us'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['due_from_us'], 0.01);
    }

    /**
     * Cross-division isolation: an office walk-in Fawry debt MUST NOT leak
     * into the tourism division's `due_to_us`. The walk-in Fawry column
     * fallback added in commit `f389cb3` is gated behind `if ($division
     * === 'office')`; this test verifies the gate holds.
     */
    public function test_office_walkin_fawry_does_not_leak_into_tourism_division(): void
    {
        \App\Models\Fawry\FawryTransaction::query()->create([
            'client_id' => null,
            'client_name' => 'عميل فوري ماشي — مكتب',
            'operation_type' => 'payment',
            'client_amount' => 1670.0,
            'fawry_price' => 1650.0,
            'selling_price' => 1670.0,
            'profit' => 20.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('tourism');

        $this->assertEqualsWithDelta(0.0, $result['due_to_us'], 0.01);
    }

    /**
     * Cross-division isolation: an office customer account (Fawry module)
     * MUST NOT leak into tourism.
     */
    public function test_office_customer_account_does_not_leak_into_tourism_division(): void
    {
        $officeCustomerAccount = Account::query()->create([
            'name' => 'ذممة عميل فوري — مكتب',
            'type' => AccountType::Customer,
            'balance' => 1500.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'fawry',
            'created_by' => $this->user->id,
        ]);

        Customer::query()->create([
            'account_id' => $officeCustomerAccount->id,
            'full_name' => 'عميل فوري مسجّل — مكتب',
            'phone' => '01000000001',
            'created_by' => $this->user->id,
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('tourism');

        $this->assertEqualsWithDelta(0.0, $result['due_to_us'], 0.01);
    }

    /**
     * Tourism customer (flight) with `module_type='flights'` must enter the
     * tourism `due_to_us`.
     */
    public function test_flight_customer_debt_appears_in_tourism_due_to_us(): void
    {
        $flightCustomerAccount = Account::query()->create([
            'name' => 'ذممة عميل طيران',
            'type' => AccountType::Customer,
            'balance' => 2000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        Customer::query()->create([
            'account_id' => $flightCustomerAccount->id,
            'full_name' => 'عميل طيران',
            'phone' => '01000000010',
            'created_by' => $this->user->id,
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('tourism');

        $this->assertEqualsWithDelta(2000.0, $result['due_to_us'], 0.01);
    }

    /**
     * Tourism customer (hajj_umra) with `module_type='hajj_umra'` must enter
     * the tourism `due_to_us`.
     */
    public function test_hajj_umra_customer_debt_appears_in_tourism_due_to_us(): void
    {
        $hajjAccount = Account::query()->create([
            'name' => 'ذممة عميل حج',
            'type' => AccountType::Customer,
            'balance' => 1500.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'hajj_umra',
            'created_by' => $this->user->id,
        ]);

        Customer::query()->create([
            'account_id' => $hajjAccount->id,
            'full_name' => 'عميل حج',
            'phone' => '01000000020',
            'created_by' => $this->user->id,
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('tourism');

        $this->assertEqualsWithDelta(1500.0, $result['due_to_us'], 0.01);
    }

    /**
     * Tourism customer (visa) with `module_type='visas'` must enter the
     * tourism `due_to_us`.
     */
    public function test_visa_customer_debt_appears_in_tourism_due_to_us(): void
    {
        $visaAccount = Account::query()->create([
            'name' => 'ذممة عميل تأشيرات',
            'type' => AccountType::Customer,
            'balance' => 800.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'visas',
            'created_by' => $this->user->id,
        ]);

        Customer::query()->create([
            'account_id' => $visaAccount->id,
            'full_name' => 'عميل تأشيرات',
            'phone' => '01000000030',
            'created_by' => $this->user->id,
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('tourism');

        $this->assertEqualsWithDelta(800.0, $result['due_to_us'], 0.01);
    }

    /**
     * The most important tourism-side assertion: an office customer (even
     * with `module_type='fawry'` which is a valid specific module) MUST
     * NOT bleed into tourism. The `'general'` widening did NOT cause the
     * office filter to leak into the tourism filter.
     */
    public function test_office_customer_with_fawry_module_type_does_not_leak_into_tourism(): void
    {
        $fawryAccount = Account::query()->create([
            'name' => 'ذممة عميل — فوري',
            'type' => AccountType::Customer,
            'balance' => 1200.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'fawry',
            'created_by' => $this->user->id,
        ]);

        Customer::query()->create([
            'account_id' => $fawryAccount->id,
            'full_name' => 'عميل فوري',
            'phone' => '01000000040',
            'created_by' => $this->user->id,
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('tourism');

        $this->assertEqualsWithDelta(0.0, $result['due_to_us'], 0.01);
    }

    /**
     * Flight group debt (positive balance = receivable) MUST still enter
     * tourism `due_to_us`. flight_group is in
     * `TRIAL_BALANCE_RECEIVABLE_ENTITY_TYPES`.
     *
     * Note: the FlightGroup ledger account uses `type='customer'` (one of
     * the SUBJECT_TYPES); the `flight_group` value lives in the API entity
     * taxonomy, not in `accounts.type`.
     */
    public function test_flight_group_receivable_appears_in_tourism_due_to_us(): void
    {
        $groupAccount = Account::query()->create([
            'name' => 'مجموعة طيران إيجبت إير',
            'type' => AccountType::Customer,
            'balance' => 3500.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        FlightGroup::query()->create([
            'name' => 'مجموعة إيجبت إير',
            'code' => 'EAS-'.uniqid(),
            'account_id' => $groupAccount->id,
            'contact_phone' => '01000000050',
            'is_active' => true,
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('tourism');

        $this->assertEqualsWithDelta(3500.0, $result['due_to_us'], 0.01);
    }

    /**
     * Supplier positive balance (prepaid to us) MUST NOT count as
     * `due_to_us` — it appears under `total_balances` as a prepaid asset
     * (existing contract enforced by `TRIAL_BALANCE_RECEIVABLE_ENTITY_TYPES`).
     */
    public function test_supplier_prepaid_does_not_count_as_tourism_receivable(): void
    {
        Account::query()->create([
            'name' => 'فندق مكة — رصيد مسبق',
            'type' => AccountType::Supplier,
            'balance' => 4000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'hajj_umra',
            'created_by' => $this->user->id,
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('tourism');

        $this->assertEqualsWithDelta(0.0, $result['due_to_us'], 0.01);
    }

    /**
     * Combined tourism scenario: flight (2,000) + hajj (1,500) + visa (800)
     * + flight_group (3,500) = 7,800. Office artifacts (1,670 walk-in
     * Fawry + 1,200 office customer) MUST NOT bleed in.
     */
    public function test_combined_tourism_scenario_with_office_pollution(): void
    {
        // ── Tourism artifacts ──────────────────────────────────────
        $flightAcct = Account::query()->create([
            'name' => 'ذممة عميل طيران', 'type' => AccountType::Customer,
            'balance' => 2000.0, 'currency' => 'EGP', 'is_active' => true,
            'owner_type' => 'office', 'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);
        Customer::query()->create([
            'account_id' => $flightAcct->id, 'full_name' => 'عميل طيران',
            'phone' => '01000000100', 'created_by' => $this->user->id,
        ]);

        $hajjAcct = Account::query()->create([
            'name' => 'ذممة عميل حج', 'type' => AccountType::Customer,
            'balance' => 1500.0, 'currency' => 'EGP', 'is_active' => true,
            'owner_type' => 'office', 'module_type' => 'hajj_umra',
            'created_by' => $this->user->id,
        ]);
        Customer::query()->create([
            'account_id' => $hajjAcct->id, 'full_name' => 'عميل حج',
            'phone' => '01000000101', 'created_by' => $this->user->id,
        ]);

        $visaAcct = Account::query()->create([
            'name' => 'ذممة عميل تأشيرات', 'type' => AccountType::Customer,
            'balance' => 800.0, 'currency' => 'EGP', 'is_active' => true,
            'owner_type' => 'office', 'module_type' => 'visas',
            'created_by' => $this->user->id,
        ]);
        Customer::query()->create([
            'account_id' => $visaAcct->id, 'full_name' => 'عميل تأشيرات',
            'phone' => '01000000102', 'created_by' => $this->user->id,
        ]);

        $groupAcct = Account::query()->create([
            'name' => 'مجموعة طيران إيجبت إير', 'type' => AccountType::Customer,
            'balance' => 3500.0, 'currency' => 'EGP', 'is_active' => true,
            'owner_type' => 'office', 'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);
        FlightGroup::query()->create([
            'name' => 'مجموعة إيجبت إير',
            'code' => 'EAS-'.uniqid(),
            'account_id' => $groupAcct->id,
            'contact_phone' => '01000000103', 'is_active' => true,
        ]);

        // ── Office pollution that MUST NOT bleed in ────────────────
        $officeAcct = Account::query()->create([
            'name' => 'ذممة عميل فوري — مكتب', 'type' => AccountType::Customer,
            'balance' => 1200.0, 'currency' => 'EGP', 'is_active' => true,
            'owner_type' => 'office', 'module_type' => 'fawry',
            'created_by' => $this->user->id,
        ]);
        Customer::query()->create([
            'account_id' => $officeAcct->id, 'full_name' => 'عميل فوري مسجّل — مكتب',
            'phone' => '01000000200', 'created_by' => $this->user->id,
        ]);

        \App\Models\Fawry\FawryTransaction::query()->create([
            'client_id' => null,
            'client_name' => 'عميل فوري ماشي — مكتب',
            'operation_type' => 'payment',
            'client_amount' => 1670.0,
            'fawry_price' => 1650.0,
            'selling_price' => 1670.0,
            'profit' => 20.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── Office bus company supplier MUST NOT bleed in ─────────
        Account::query()->create([
            'name' => 'شركة باص — رصيد مسبق', 'type' => AccountType::Supplier,
            'balance' => 5000.0, 'currency' => 'EGP', 'is_active' => true,
            'owner_type' => 'office', 'module_type' => 'bus',
            'created_by' => $this->user->id,
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('tourism');

        // Expected: 2000 + 1500 + 800 + 3500 = 7800 (office pollution = 0)
        $this->assertEqualsWithDelta(7800.0, $result['due_to_us'], 0.01);
    }

    /**
     * The tourism trial balance `due_to_us` must equal the unified debts
     * report `total_receivables` for the same tourism department — same
     * invariant as the office side, mirrored.
     *
     * Both consumers should agree on the same number for tourism customers
     * who have bookings. The fallback path (b) in
     * `calculateReceivablesAndPayables('tourism')` includes customers whose
     * `account_id` is linked AND whose `module_type` falls within the
     * tourism division — so this test verifies the two surfaces stay in
     * sync for the common case.
     */
    public function test_tourism_trial_balance_due_to_us_matches_reports_debts_total_receivables(): void
    {
        $flightAcct = Account::query()->create([
            'name' => 'ذممة عميل طيران', 'type' => AccountType::Customer,
            'balance' => 2000.0, 'currency' => 'EGP', 'is_active' => true,
            'owner_type' => 'office', 'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);
        $flightCustomer = Customer::query()->create([
            'account_id' => $flightAcct->id, 'full_name' => 'عميل طيران',
            'phone' => '01000000300', 'created_by' => $this->user->id,
        ]);

        // Provide a real flight booking so the customer surfaces in the
        // `getDebtsReport` customer branch (which gates on booking
        // existence). Without this, the customer only enters via the
        // fallback path and the two surfaces legitimately differ.
        DB::table('flight_bookings')->insert([
            'booking_reference' => 'FB-'.uniqid(),
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'manual',
            'customer_id' => $flightCustomer->id,
            'employee_id' => Employee::query()->value('id'),
            'agent_name' => '',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->addDays(5)->toDateString(),
            'departure_time' => '10:00',
            'trip_type' => 'one_way',
            'airline' => 'EG',
            'passenger_count' => 1,
            'status' => 'CONFIRMED',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $hajjAcct = Account::query()->create([
            'name' => 'ذممة عميل حج', 'type' => AccountType::Customer,
            'balance' => 1500.0, 'currency' => 'EGP', 'is_active' => true,
            'owner_type' => 'office', 'module_type' => 'hajj_umra',
            'created_by' => $this->user->id,
        ]);
        $hajjCustomer = Customer::query()->create([
            'account_id' => $hajjAcct->id, 'full_name' => 'عميل حج',
            'phone' => '01000000301', 'created_by' => $this->user->id,
        ]);

        // Provide a real hajj_umra booking.
        $program = \App\Models\Program::withoutEvents(function () {
            return \App\Models\Program::query()->create([
                'program_name' => 'برنامج اختبار '.uniqid(),
                'program_type' => 'umrah',
                'executing_company' => '',
                'total_nights' => 10,
                'mecca_hotel_name' => 'فندق مكة',
                'mecca_nights' => 5,
                'medina_hotel_name' => 'فندق المدينة',
                'medina_nights' => 5,
                'airline' => 'مصر للطيران',
                'trip_supervisor' => 'مشرف',
                'accommodation_type' => 'QUAD',
                'default_purchase_price' => 10000,
                'default_selling_price' => 12000,
                'departure_date' => now()->addDays(10)->toDateString(),
                'return_date' => now()->addDays(20)->toDateString(),
                'departure_point' => 'Cairo',
                'is_active' => true,
            ]);
        });

        DB::table('hajj_umra_bookings')->insert([
            'customer_id' => $hajjCustomer->id,
            'program_id' => $program->id,
            'account_id' => $hajjAcct->id,
            'employee_id' => Employee::query()->value('id'),
            'module' => 'hajj_umra',
            'purchase_price' => 10000.0,
            'selling_price' => 1500.0,
            'companion_purchase_price' => 0.0,
            'companion_selling_price' => 0.0,
            'profit' => 1500.0,
            'currency' => 'EGP',
            'per_person' => 0,
            'status' => 'confirmed',
            'agent_name' => '',
            'notes' => '',
            'baggage' => 0,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $debtsReport = app(FinancialReportService::class)
            ->getDebtsReport(['department' => 'tourism']);

        $result = $this->treasury->calculateReceivablesAndPayables('tourism');

        $this->assertEqualsWithDelta(
            (float) $debtsReport['total_receivables'],
            (float) $result['due_to_us'],
            0.01,
            'Tourism: DepartmentManagement ($total_receivables) and TreasuryOverview ($due_to_us) must agree.'
        );
    }

    /**
     * `due_from_us` for tourism must still include the negative prepaid
     * balances from flight_systems / flight_carriers / airline_accounts.
     */
    public function test_tourism_due_from_us_includes_negative_prepaid_flight_system(): void
    {
        DB::table('flight_systems')->insert([
            'name' => 'نظام حجز طيران اختبار',
            'code' => 'FST-'.uniqid(),
            'balance' => -3000.0,
            'currency' => 'EGP',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('tourism');

        $this->assertEqualsWithDelta(3000.0, $result['due_from_us'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['due_to_us'], 0.01);
    }
}
