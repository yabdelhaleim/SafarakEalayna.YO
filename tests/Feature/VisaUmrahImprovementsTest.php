<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\Program;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisaUmrahImprovementsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $collectionAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Visa Umrah Tester',
            'email' => 'visa-umrah-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user, ['*']);

        // Set up a vault / collection account
        $this->collectionAccount = Account::query()->create([
            'name' => 'حساب صندوق تجريبي',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module' => 'hajj_umra',
            'created_by' => $this->user->id,
        ]);

        // Inject some balance into the cashbox to pay for purchase costs
        LedgerBalanceMutationGuard::run(function () {
            $this->collectionAccount->update(['balance' => 100000.00]);
        });
    }

    public function test_visa_agent_creation_and_cost_price_endpoint(): void
    {
        // 1. Create a visa agent
        $response = $this->postJson('/api/v1/visa-agents', [
            'name' => 'الوكيل السريع لخدمات التأشيرات',
            'phone' => '0123456789',
            'visa_type' => 'Tourist',
            'default_cost_price' => 1250.50,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'name', 'phone', 'visa_type', 'default_cost_price']]);

        $agentId = $response->json('data.id');

        // 2. Fetch all agents
        $listResponse = $this->getJson('/api/v1/visa-agents');
        $listResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        // 3. Resolve agent cost price
        $costResponse = $this->getJson("/api/v1/visa-agents/{$agentId}/cost-price?visa_type=Tourist");
        $costResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cost_price', 1250.50);

        // Try mismatching visa type to ensure it returns agent default
        $costMismatchResponse = $this->getJson("/api/v1/visa-agents/{$agentId}/cost-price?visa_type=Work");
        $costMismatchResponse->assertOk()
            ->assertJsonPath('data.cost_price', 1250.50);
    }

    public function test_umrah_supplier_creation_and_listing(): void
    {
        // 1. Create Umrah Supplier
        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'name' => 'شركة مكة للنقل والخدمات',
            'phone' => '050111222',
            'default_cost_price' => 5000.00,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'name', 'phone', 'supplier_cost_price']]);

        $supplierId = $response->json('data.id');

        // 2. Get Umrah Suppliers List
        $listResponse = $this->getJson('/api/v1/umrah-suppliers');
        $listResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $supplierId)
            ->assertJsonPath('data.0.supplier_cost_price', 5000);
    }

    public function test_collection_accounts_filters_correctly(): void
    {
        // 1. Create a non-collection account (like revenue or equity account)
        Account::query()->create([
            'name' => 'إيرادات عامة',
            'type' => 'revenue',
            'currency' => 'EGP',
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->user->id,
        ]);

        // 2. Request collection accounts
        $response = $this->getJson('/api/v1/finance/accounts?type=collection');
        $response->assertOk();

        // 3. Verify it returns our cashbox account but filters out the revenue account
        $items = $response->json('data.items');
        $this->assertNotEmpty($items);

        $types = collect($items)->pluck('type')->unique();
        foreach ($types as $type) {
            $this->assertTrue(in_array($type, ['cashbox', 'wallet', 'bank', 'treasury', 'post']));
        }
    }

    public function test_hajj_umra_booking_creation_with_pricing_grid_and_supplier(): void
    {
        // Create dependencies
        $program = Program::query()->create([
            'program_name' => 'برنامج التوفير الممتاز',
            'program_type' => 'umrah',
            'total_nights' => 14,
            'mecca_hotel_name' => 'فندق أنوار المدينة',
            'mecca_nights' => 7,
            'medina_hotel_name' => 'فندق دار التقوى',
            'medina_nights' => 7,
            'airline' => 'مصر للطيران',
            'executing_company' => 'COUNTER_COMPANY',
            'trip_supervisor' => 'SUPERVISOR',
            'accommodation_type' => 'QUADRUPLE',
            'default_purchase_price' => 15000.00,
            'default_selling_price' => 18000.00,
            'departure_date' => now()->addDays(7)->toDateString(),
            'return_date' => now()->addDays(21)->toDateString(),
            'departure_point' => 'Cairo',
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'full_name' => 'أحمد العميل الأساسي',
            'phone' => '01011122233',
        ]);

        $companion = Customer::query()->create([
            'full_name' => 'سارة المرافق',
            'phone' => '01044455566',
        ]);

        $supplier = UmrahSupplier::query()->create([
            'name' => 'شركة نسور مكة للسياحة',
            'phone' => '055554443',
            'default_cost_price' => 15000.00,
            'account_id' => $this->collectionAccount->id,
        ]);

        // Create booking with supplier, companion, private room (+3000 EGP), and family breakdowns
        $payload = [
            'customer_id' => $customer->id,
            'companion_customer_id' => $companion->id,
            'program_id' => $program->id,
            'supplier_id' => $supplier->id,
            'purchase_price' => 15000.00,
            'companion_purchase_price' => 14000.00,
            'selling_price' => 18000.00,
            'companion_selling_price' => 17000.00,
            'currency' => 'EGP',
            'per_person' => true,
            'accommodation_choice' => 'private',
            'accommodation_extra_charge' => 3000.00,
            'status' => 'confirmed',
            'account_id' => $this->collectionAccount->id,
            'agent_name' => 'أحمد العميل الأساسي',
            'notes' => 'حجز عائلي شامل السكن الخاص والمرافقين',
            'passengers' => [
                [
                    'category' => 'adult',
                    'count' => 2,
                    'unit_price' => 17500.00,
                    'subtotal' => 35000.00,
                ],
                [
                    'category' => 'infant',
                    'count' => 1,
                    'unit_price' => 3000.00,
                    'subtotal' => 3000.00,
                ],
            ],
            'initial_payment' => [
                'amount' => 5000.00,
                'payment_method' => 'cash',
                'payment_date' => now()->toDateString(),
                'paid_by' => 'أحمد العميل الأساسي',
            ],
        ];

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated()
            ->assertJsonPath('success', true);

        $bookingId = $response->json('data.id');
        $this->assertDatabaseHas('hajj_umra_bookings', [
            'id' => $bookingId,
            'customer_id' => $customer->id,
            'companion_customer_id' => $companion->id,
            'supplier_id' => $supplier->id,
            'purchase_price' => 15000.00,
            'companion_purchase_price' => 14000.00,
            'selling_price' => 18000.00,
            'companion_selling_price' => 17000.00,
            'accommodation_choice' => 'private',
            'accommodation_extra_charge' => 3000.00,
            'profit' => 9000.00, // (18000 + 17000 + 3000) - (15000 + 14000) = 38000 - 29000 = 9000
        ]);

        // Verify passengers details saved in DB
        $this->assertDatabaseHas('umrah_transaction_passengers', [
            'transaction_id' => $bookingId,
            'category' => 'adult',
            'count' => 2,
            'unit_price' => 17500.00,
            'subtotal' => 35000.00,
        ]);

        $this->assertDatabaseHas('umrah_transaction_passengers', [
            'transaction_id' => $bookingId,
            'category' => 'infant',
            'count' => 1,
            'unit_price' => 3000.00,
            'subtotal' => 3000.00,
        ]);

        // Verify customer balances endpoint
        $balancesResponse = $this->getJson('/api/v1/hajj-umra/customer-balances');
        $balancesResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'client_id',
                        'client_name',
                        'phone',
                        'total_sales',
                        'total_paid',
                        'total_debt',
                        'booking_count',
                        'last_booking',
                    ],
                ],
            ])
            ->assertJsonPath('data.0.client_id', $customer->id)
            ->assertJsonPath('data.0.total_sales', 38000) // 18000 + 17000 + 3000 = 38000
            ->assertJsonPath('data.0.total_paid', 5000)
            ->assertJsonPath('data.0.total_debt', 33000);

        // Verify customer statement endpoint
        $statementResponse = $this->getJson('/api/v1/hajj-umra/customer-statement?client_id='.$customer->id);
        $statementResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'customer' => ['id', 'name', 'phone'],
                    'summary' => ['total_sales', 'total_paid', 'total_debt'],
                    'transactions' => [
                        '*' => [
                            'id',
                            'date',
                            'type',
                            'type_label',
                            'debit',
                            'credit',
                            'description',
                            'employee',
                            'running_balance',
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('data.summary.total_sales', 38000)
            ->assertJsonPath('data.summary.total_paid', 5000)
            ->assertJsonPath('data.summary.total_debt', 33000)
            ->assertJsonCount(2, 'data.transactions'); // 1 booking and 1 payment
    }
}
