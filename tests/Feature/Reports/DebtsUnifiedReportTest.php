<?php

namespace Tests\Feature\Reports;

use App\Enums\AccountType;
use App\Enums\SupplierType;
use App\Models\Account;
use App\Models\Bus\BusCompany;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\Hotel;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmra\VisaAgent;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reports\FinancialReportService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage tests for the unified "reports/debts" endpoint powered by
 * FinancialReportService::getDebtsReport().
 *
 * The endpoint aggregates balances from nine different entity types:
 *   - customers
 *   - suppliers (with type-based module routing)
 *   - visa agents
 *   - hajj/umra executing companies
 *   - bus companies
 *   - airline accounts
 *   - hotels
 *   - umrah suppliers
 *   - flight groups
 *   - flight carriers
 *
 * Each test below targets a slice of that aggregation logic so a future
 * refactor cannot silently drop an entity type from the report.
 *
 * @see \App\Services\Reports\FinancialReportService::getDebtsReport
 * @see \App\Http\Controllers\Api\V1\Reports\FinancialReportController::debtsReport
 */
class DebtsUnifiedReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FinancialReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Unified Debts Tester',
            'email' => 'unified-debts@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->reports = app(FinancialReportService::class);
    }

    /**
     * Helper: build a customer with an AR Account row carrying a non-zero balance.
     *
     * Subject accounts (customer/supplier) require a SPECIFIC module_type per
     * the AccountModuleContract — `'wallet_transfer'` is used here because
     * the customer is treated as a generic office AR (no module activity).
     */
    protected function makeCustomerWithBalance(string $name, string $phone, float $balance, string $currency = 'EGP'): array
    {
        $customerAccount = Account::query()->create([
            'name' => 'AR '.$name,
            'type' => AccountType::Customer,
            'currency' => $currency,
            'balance' => $balance,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'wallet_transfer',
            'module' => 'wallet_transfer',
            'created_by' => $this->admin->id,
        ]);

        $customer = Customer::query()->create([
            'full_name' => $name,
            'phone' => $phone,
            'national_id' => substr(md5($name.'-'.$phone), 0, 14),
            'account_id' => $customerAccount->id,
            'created_by' => $this->admin->id,
        ]);

        return [$customer, $customerAccount];
    }

    /**
     * Helper: build an AP/AR subject account for a supplier-style entity.
     *
     * Subject accounts require a SPECIFIC module_type per AccountModuleContract.
     */
    protected function makeSubjectAccount(string $label, AccountType $type, float $balance, string $moduleType, string $currency = 'EGP'): Account
    {
        return Account::query()->create([
            'name' => $label,
            'type' => $type,
            'currency' => $currency,
            'balance' => $balance,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => $moduleType,
            'module' => $moduleType,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Helper: set a FlightCarrier's balance safely through the mutation guard.
     */
    protected function setFlightCarrierBalance(FlightCarrier $carrier, float $balance): void
    {
        if ($balance === 0.0) {
            return;
        }
        LedgerBalanceMutationGuard::run(function () use ($carrier, $balance) {
            $carrier->balance = $balance;
            $carrier->save();
        });
    }

    /**
     * Helper: set an AirlineAccount's balance safely through the mutation guard.
     */
    protected function setAirlineAccountBalance(AirlineAccount $account, float $balance): void
    {
        if ($balance === 0.0) {
            return;
        }
        LedgerBalanceMutationGuard::run(function () use ($account, $balance) {
            $account->balance = $balance;
            $account->save();
        });
    }

    public function test_endpoint_requires_authentication(): void
    {
        // Wipe Sanctum auth context — fresh test container without a guard user.
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/reports/debts')
            ->assertStatus(401);
    }

    public function test_endpoint_returns_well_formed_payload_with_zero_state(): void
    {
        $response = $this->getJson('/api/v1/reports/debts');

        $response->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_receivables', 0)
            ->assertJsonPath('data.total_payables', 0)
            ->assertJsonPath('data.net_balance', 0)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total_receivables',
                    'total_payables',
                    'net_balance',
                    'items' => [
                        '*' => [
                            'id',
                            'name',
                            'phone',
                            'entity_type',
                            'entity_type_label',
                            'department',
                            'department_label',
                            'module',
                            'module_label',
                            'balance',
                            'currency',
                            'account_id',
                            'statement_url',
                            'balance_egp',
                        ],
                    ],
                ],
            ]);
    }

    public function test_customer_receivable_appears_under_receivables_filter(): void
    {
        [$customer] = $this->makeCustomerWithBalance('أحمد العميل', '01000000001', 1500.00);

        $response = $this->getJson('/api/v1/reports/debts?direction=receivables');

        $response->assertOk()
            ->assertJsonPath('data.total_receivables', 1500)
            ->assertJsonPath('data.total_payables', 0)
            ->assertJsonPath('data.net_balance', 1500)
            ->assertJsonFragment([
                'id' => $customer->id,
                'entity_type' => 'customer',
                'entity_type_label' => 'عميل',
                'balance' => 1500,
                'currency' => 'EGP',
            ]);
    }

    public function test_customer_payable_appears_under_payables_filter(): void
    {
        // Negative customer balance → company owes customer (payable).
        [$customer] = $this->makeCustomerWithBalance('سعيد العميل', '01000000002', -750.50);

        $response = $this->getJson('/api/v1/reports/debts?direction=payables');

        $response->assertOk()
            ->assertJsonPath('data.total_payables', 750.5)
            ->assertJsonPath('data.total_receivables', 0)
            ->assertJsonPath('data.net_balance', -750.5)
            ->assertJsonFragment([
                'id' => $customer->id,
                'entity_type' => 'customer',
                'balance' => -750.5,
            ]);
    }

    public function test_customers_with_zero_balance_are_excluded(): void
    {
        $this->makeCustomerWithBalance('صفر الرصيد', '01000000003', 0.0);

        $response = $this->getJson('/api/v1/reports/debts');

        $response->assertOk()
            ->assertJsonPath('data.total_receivables', 0)
            ->assertJsonPath('data.total_payables', 0)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_search_filter_scopes_to_name_and_phone(): void
    {
        [$matching] = $this->makeCustomerWithBalance('محمد البحث', '01112223334', 500.00);
        $this->makeCustomerWithBalance('اسم مختلف', '01999888777', 750.00);

        $byName = $this->getJson('/api/v1/reports/debts?search='.urlencode('البحث'));
        $byName->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonFragment(['id' => $matching->id]);

        $byPhone = $this->getJson('/api/v1/reports/debts?search=01112223334');
        $byPhone->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonFragment(['id' => $matching->id]);

        $miss = $this->getJson('/api/v1/reports/debts?search=ZZZ_NO_MATCH_ZZZ');
        $miss->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.total_receivables', 0);
    }

    public function test_direction_all_returns_both_positive_and_negative_balances(): void
    {
        $this->makeCustomerWithBalance('مدين إيجابي', '01000000010', 1200.00);
        $this->makeCustomerWithBalance('دائن سالب', '01000000011', -300.00);

        $response = $this->getJson('/api/v1/reports/debts');

        $response->assertOk()
            ->assertJsonPath('data.total_receivables', 1200)
            ->assertJsonPath('data.total_payables', 300)
            ->assertJsonPath('data.net_balance', 900)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_entity_type_filter_customer_excludes_suppliers(): void
    {
        $this->makeCustomerWithBalance('عميل فقط', '01000000020', 1000.00);

        // Add an airline supplier to ensure it is excluded.
        $airlineAccount = $this->makeSubjectAccount('AP شركة طيران', AccountType::Supplier, -2000.00, 'flights');
        Supplier::query()->create([
            'name' => 'شركة طيران المورد',
            'code' => 'SUP-AIR-001',
            'type' => SupplierType::Airline,
            'account_id' => $airlineAccount->id,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/reports/debts?entity_type=customer');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonFragment(['entity_type' => 'customer'])
            ->assertJsonPath('data.total_receivables', 1000)
            ->assertJsonPath('data.total_payables', 0);
    }

    public function test_entity_type_filter_supplier_excludes_customers(): void
    {
        $this->makeCustomerWithBalance('عميل مستبعد', '01000000030', 900.00);

        $busAccount = $this->makeSubjectAccount('AP شركة باصات', AccountType::Supplier, -400.00, 'bus', 'EGP');
        BusCompany::query()->create([
            'name' => 'باصات النيل',
            'phone' => '01111000200',
            'account_id' => $busAccount->id,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/reports/debts?entity_type=supplier&department=office&module=bus');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonFragment(['entity_type' => 'bus_company'])
            ->assertJsonPath('data.total_payables', 400)
            ->assertJsonPath('data.total_receivables', 0);
    }

    public function test_airline_accounts_appear_under_flight_module(): void
    {
        $airline = AirlineAccount::query()->create([
            'name' => 'خط طيران XYZ',
            'code' => 'XYZ',
            'system_type' => 'gds',
            'currency' => 'EGP',
            'credit_limit' => 10000.00,
            'is_active' => true,
            'notes' => null,
        ]);
        $this->setAirlineAccountBalance($airline, 2500.00);

        $response = $this->getJson('/api/v1/reports/debts?module=flight');

        $response->assertOk()
            ->assertJsonPath('data.total_receivables', 2500)
            ->assertJsonFragment([
                'entity_type' => 'airline_account',
                'entity_type_label' => 'حساب خط طيران',
                'module' => 'flight',
                'department' => 'tourism',
            ]);
    }

    public function test_hotel_debt_appears_under_hajj_umra_module(): void
    {
        $hotelAccount = $this->makeSubjectAccount('AP فندق مكة', AccountType::Supplier, -1800.00, 'hajj_umra');
        $hotel = Hotel::query()->create([
            'name' => 'فندق مكة المكرمة',
            'city' => 'Makkah',
            'phone' => '01200000001',
            'account_id' => $hotelAccount->id,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/reports/debts?module=hajj_umra');

        $response->assertOk()
            ->assertJsonPath('data.total_payables', 1800)
            ->assertJsonFragment([
                'id' => $hotel->id,
                'entity_type' => 'hotel',
                'entity_type_label' => 'فندق',
                'module' => 'hajj_umra',
                'department' => 'tourism',
            ]);
    }

    public function test_visa_agent_appears_under_visa_module(): void
    {
        $agentAccount = $this->makeSubjectAccount('AP وكيل تأشيرات', AccountType::Supplier, -550.00, 'visas');
        $agent = VisaAgent::query()->create([
            'company_name' => 'وكيل التأشيرات الدولي',
            'phone' => '01300000001',
            'account_id' => $agentAccount->id,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/reports/debts?module=visa');

        $response->assertOk()
            ->assertJsonPath('data.total_payables', 550)
            ->assertJsonFragment([
                'id' => $agent->id,
                'entity_type' => 'visa_agent',
                'entity_type_label' => 'وكيل تأشيرات',
                'module' => 'visa',
                'department' => 'tourism',
            ]);
    }

    public function test_hajj_umra_executing_company_appears_under_hajj_umra_module(): void
    {
        $execAccount = $this->makeSubjectAccount('AP شركة منفذة', AccountType::Supplier, -2200.00, 'hajj_umra');
        // HajjUmraExecutingCompany::booted() will create its own Account if
        // account_id is missing. We set it explicitly here because we are
        // constructing it directly with no booted hook firing when
        // account_id is preset.
        $exec = new HajjUmraExecutingCompany([
            'name' => 'الشركة المنفذة الأولى',
            'phone' => '01400000001',
            'license_number' => 'LIC-EX-1',
            'is_active' => true,
        ]);
        $exec->account_id = $execAccount->id;
        $exec->save();

        $response = $this->getJson('/api/v1/reports/debts?module=hajj_umra');

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $exec->id,
                'entity_type' => 'executing_company',
                'entity_type_label' => 'شركة منفذة (حج وعمرة)',
                'module' => 'hajj_umra',
            ]);
    }

    public function test_umrah_supplier_appears_under_hajj_umra_module(): void
    {
        $supAccount = $this->makeSubjectAccount('AP مورد عمرة', AccountType::Supplier, -1200.00, 'hajj_umra');
        $sup = UmrahSupplier::query()->create([
            'name' => 'مورد عمرة معتمد',
            'phone' => '01500000001',
            'account_id' => $supAccount->id,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/reports/debts?module=hajj_umra');

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $sup->id,
                'entity_type' => 'umrah_supplier',
                'entity_type_label' => 'مورد عمرة',
            ]);
    }

    public function test_bus_company_appears_under_bus_module(): void
    {
        $busAccount = $this->makeSubjectAccount('AP شركة باصات', AccountType::Supplier, -350.00, 'bus');
        $bus = BusCompany::query()->create([
            'name' => 'باصات الجيزة',
            'phone' => '01600000001',
            'account_id' => $busAccount->id,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/reports/debts?module=bus');

        $response->assertOk()
            ->assertJsonPath('data.total_payables', 350)
            ->assertJsonFragment([
                'id' => $bus->id,
                'entity_type' => 'bus_company',
                'entity_type_label' => 'شركة باصات',
                'module' => 'bus',
                'department' => 'office',
            ]);
    }

    public function test_flight_carrier_negative_balance_is_payable(): void
    {
        $carrier = FlightCarrier::query()->create([
            'name' => 'ناقل طيران تجريبي',
            'code' => 'TST',
            'iata_code' => 'TS',
            'currency' => 'EGP',
            'credit_limit' => 5000.00,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $this->setFlightCarrierBalance($carrier, -1500.00); // company owes carrier → payable

        $response = $this->getJson('/api/v1/reports/debts?entity_type=flight_carrier');

        $response->assertOk()
            ->assertJsonPath('data.total_payables', 1500)
            ->assertJsonPath('data.total_receivables', 0)
            ->assertJsonFragment([
                'id' => $carrier->id,
                'entity_type' => 'flight_carrier',
                'entity_type_label' => 'ناقل طيران',
                'credit_limit' => 5000.00,
            ]);

        // The `available_balance` is credit_limit + balance = 3500.
        $payload = $response->json('data.items.0');
        $this->assertEqualsWithDelta(3500.00, (float) $payload['available_balance'], 0.01);
    }

    public function test_flight_carrier_positive_balance_is_receivable(): void
    {
        $carrier = FlightCarrier::query()->create([
            'name' => 'ناقل إيجابي',
            'code' => 'POS',
            'currency' => 'EGP',
            'credit_limit' => 1000.00,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $this->setFlightCarrierBalance($carrier, 800.00);

        $response = $this->getJson('/api/v1/reports/debts?entity_type=flight_carrier');

        $response->assertOk()
            ->assertJsonPath('data.total_receivables', 800)
            ->assertJsonPath('data.total_payables', 0);
    }

    public function test_flight_carrier_zero_balance_is_excluded(): void
    {
        $carrier = FlightCarrier::query()->create([
            'name' => 'ناقل صفري',
            'code' => 'ZRO',
            'currency' => 'EGP',
            'credit_limit' => 0.0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        // balance stays 0.0
        $this->assertSame(0.0, (float) $carrier->balance);

        $response = $this->getJson('/api/v1/reports/debts?entity_type=flight_carrier');

        $response->assertOk()
            ->assertJsonPath('data.total_receivables', 0)
            ->assertJsonPath('data.total_payables', 0)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_flight_group_uses_group_transactions_balance(): void
    {
        $carrier = FlightCarrier::query()->create([
            'name' => 'ناقل لمجموعة',
            'code' => 'GRP',
            'currency' => 'EGP',
            'credit_limit' => 0.0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $groupAccount = $this->makeSubjectAccount('AP مجموعة طيران', AccountType::Supplier, 0.0, 'flights');
        $group = FlightGroup::query()->create([
            'name' => 'مجموعة طيران اختبار',
            'code' => 'GR-1',
            'flight_carrier_id' => $carrier->id,
            'currency' => 'EGP',
            'account_id' => $groupAccount->id,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Seed the group_transactions table directly — debt 3000, payment 500 → 2500 owed
        \DB::table('flight_group_transactions')->insert([
            [
                'flight_group_id' => $group->id,
                'type' => 'debt',
                'amount' => 3000.00,
                'created_by' => $this->admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'flight_group_id' => $group->id,
                'type' => 'payment',
                'amount' => 500.00,
                'created_by' => $this->admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson('/api/v1/reports/debts?entity_type=supplier&department=tourism&module=flight');

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $group->id,
                'entity_type' => 'flight_group',
                'entity_type_label' => 'مجموعة طيران',
                'balance' => 2500.00,
                'module' => 'flight',
                'department' => 'tourism',
            ]);
    }

    public function test_results_are_sorted_by_absolute_balance_desc(): void
    {
        // balances 50, 1500, 250 — should be ordered 1500, 250, 50.
        $this->makeCustomerWithBalance('صغير', '01000000040', 50.00);
        $this->makeCustomerWithBalance('متوسط', '01000000041', 250.00);
        $this->makeCustomerWithBalance('كبير', '01000000042', 1500.00);

        $response = $this->getJson('/api/v1/reports/debts');

        $balances = array_map(
            fn ($item) => (float) $item['balance'],
            $response->json('data.items'),
        );

        $this->assertSame([1500.00, 250.00, 50.00], $balances);
    }

    public function test_unknown_direction_value_does_not_throw_and_returns_empty(): void
    {
        $this->makeCustomerWithBalance('مستبعد', '01000000050', 100.00);

        $response = $this->getJson('/api/v1/reports/debts?direction=unknown_value');

        $response->assertOk()
            ->assertJsonPath('data.total_receivables', 0)
            ->assertJsonPath('data.total_payables', 0)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_tourism_department_filter_excludes_office_entities(): void
    {
        // Office customer with no module activity — must be excluded.
        $this->makeCustomerWithBalance('مكتب فقط', '01000000060', 800.00);

        $response = $this->getJson('/api/v1/reports/debts?department=tourism');

        $response->assertOk()
            ->assertJsonPath('data.total_receivables', 0)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_office_department_filter_excludes_tourism_entities(): void
    {
        // Tourism entity (airline account) — must be excluded.
        $airline = AirlineAccount::query()->create([
            'name' => 'خط طيران سياحي',
            'code' => 'TRSM',
            'system_type' => 'gds',
            'currency' => 'EGP',
            'credit_limit' => 0.0,
            'is_active' => true,
        ]);
        $this->setAirlineAccountBalance($airline, 500.00);

        $response = $this->getJson('/api/v1/reports/debts?department=office');

        $response->assertOk()
            ->assertJsonPath('data.total_receivables', 0)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_balance_egp_field_present_for_egp_items(): void
    {
        [$customer] = $this->makeCustomerWithBalance('EGP فقط', '01000000070', 4321.00);

        $response = $this->getJson('/api/v1/reports/debts');

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $customer->id,
                'balance_egp' => 4321.00,
            ]);
    }
}
