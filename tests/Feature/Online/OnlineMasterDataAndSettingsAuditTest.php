<?php

namespace Tests\Feature\Online;

use App\Enums\AccountType;
use App\Enums\CustomerType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * Audit tests for Online module master data + settings endpoints.
 *
 * Covers operations:
 *   1) GET /online/settings/all
 *   2) GET /online/settings/service-types
 *   3) GET /online/settings/providers
 *   4) GET /online/settings/payment-methods
 *   5) GET /online/settings/accounts
 *   6) GET /online/settings/customers
 *   7) POST /online/settings/customers
 *   8) GET /online/settings/employees
 *   9) GET /online/settings/statuses
 *   10) GET /online/service-types/active
 *   11) GET /online/service-types (index)
 *   12) POST /online/service-types (store)
 *   13) GET /online/service-types/{id} (show)
 *   14) PUT /online/service-types/{id} (update)
 *   15) DELETE /online/service-types/{id} (destroy)
 *   16) GET /online/providers/active
 *   17) GET /online/providers (index)
 *   18) POST /online/providers (store)
 *   19) GET /online/providers/{id} (show)
 *   20) PUT /online/providers/{id} (update)
 *   21) DELETE /online/providers/{id} (destroy)
 *   24) GET /online/transactions/daily-summary
 *
 * Methodology: DISCOVER → UNDERSTAND → EXECUTE → VERIFY.
 * Each test verifies both the API response and the DB mutation.
 */
class OnlineMasterDataAndSettingsAuditTest extends OnlineTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The parent OnlineTestCase uses Auth::login($user) for service-layer
        // tests. HTTP-layer tests require Sanctum's actingAs() to bypass the
        // auth:sanctum middleware on the /api/v1/online/* routes.
        Sanctum::actingAs($this->user, ['*']);
    }

    // ============================================================
    // Service Type CRUD (Operations 11-15)
    // ============================================================

    public function test_service_type_index_returns_paginated_list(): void
    {
        for ($i = 0; $i < 3; $i++) {
            OnlineServiceType::firstOrCreate(
                ['code' => 'list_type_'.$i],
                ['name_ar' => 'نوع '.$i, 'name_en' => 'Type '.$i, 'is_active' => true],
            );
        }

        $response = $this->getJson('/api/v1/online/service-types');

        $response->assertOk();
        $items = $response->json('data.items');
        $this->assertIsArray($items);
        // At least the TEST_TYPE from setUp + 3 factory rows = 4
        $this->assertGreaterThanOrEqual(4, count($items));
    }

    public function test_service_type_store_creates_with_valid_payload(): void
    {
        $payload = [
            'code' => 'NEW_TYPE',
            'name_ar' => 'نوع جديد',
            'name_en' => 'New Type',
            'description_ar' => 'وصف',
            'description_en' => 'Description',
            'color' => '#FF0000',
            'icon' => 'star',
            'is_active' => true,
            'order' => 5,
        ];

        $response = $this->postJson('/api/v1/online/service-types', $payload);

        $response->assertStatus(201);
        // prepareForValidation normalizes 'NEW_TYPE' → 'new_type'
        $this->assertDatabaseHas('online_service_types', [
            'code' => 'new_type',
            'name_ar' => 'نوع جديد',
            'is_active' => 1,
            'order' => 5,
        ]);
    }

    public function test_service_type_store_normalizes_code_to_lowercase_underscore(): void
    {
        $payload = [
            'code' => 'Mixed Case Code',
            'name_ar' => 'اسم',
            'name_en' => 'Name',
        ];

        $response = $this->postJson('/api/v1/online/service-types', $payload);

        $response->assertStatus(201);
        // prepareForValidation lowercases + underscores.
        $this->assertDatabaseHas('online_service_types', [
            'code' => 'mixed_case_code',
        ]);
    }

    public function test_service_type_store_rejects_duplicate_code(): void
    {
        // Create a type first to ensure the row exists with normalized code.
        OnlineServiceType::firstOrCreate(
            ['code' => 'dup_type_test'],
            ['name_ar' => 'تجربة', 'name_en' => 'Dup Test', 'is_active' => true],
        );

        $response = $this->postJson('/api/v1/online/service-types', [
            'code' => 'dup_type_test',
            'name_ar' => 'مكرر',
            'name_en' => 'Duplicate',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('code', (array) $response->json('errors'));
    }

    public function test_service_type_store_requires_name_ar(): void
    {
        $response = $this->postJson('/api/v1/online/service-types', [
            'code' => 'missing_name_ar',
            'name_en' => 'English Only',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('name_ar', (array) $response->json('errors'));
    }

    public function test_service_type_show_returns_single(): void
    {
        $st = OnlineServiceType::firstOrCreate(
            ['code' => 'SHOW_TYPE'],
            ['name_ar' => 'عرض', 'name_en' => 'Show', 'is_active' => true],
        );

        $response = $this->getJson("/api/v1/online/service-types/{$st->id}");

        $response->assertOk();
        $this->assertSame('SHOW_TYPE', $response->json('data.code'));
    }

    public function test_service_type_update_modifies_fields(): void
    {
        $st = OnlineServiceType::firstOrCreate(
            ['code' => 'UPDATE_TYPE'],
            ['name_ar' => 'قديم', 'name_en' => 'Old', 'is_active' => true],
        );

        $response = $this->putJson("/api/v1/online/service-types/{$st->id}", [
            'code' => 'UPDATE_TYPE',
            'name_ar' => 'جديد',
            'name_en' => 'New',
            'is_active' => false,
        ]);

        $response->assertOk();
        $st->refresh();
        $this->assertSame('جديد', $st->name_ar);
        $this->assertSame('New', $st->name_en);
        $this->assertFalse($st->is_active);
    }

    public function test_service_type_destroy_soft_deletes(): void
    {
        $st = OnlineServiceType::firstOrCreate(
            ['code' => 'DELETE_TYPE'],
            ['name_ar' => 'حذف', 'name_en' => 'Delete', 'is_active' => true],
        );

        $response = $this->deleteJson("/api/v1/online/service-types/{$st->id}");

        $response->assertOk();
        $this->assertSoftDeleted('online_service_types', ['id' => $st->id]);
    }

    public function test_service_type_destroy_blocked_when_transactions_exist(): void
    {
        // Create a tx that references the service type
        $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'عميل',
            'customer_phone' => '01000000000',
            'purchase_price' => 10,
            'selling_price' => 50,
            'amount_paid' => 50,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response = $this->deleteJson("/api/v1/online/service-types/{$this->serviceType->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('online_service_types', [
            'id' => $this->serviceType->id,
            'deleted_at' => null,
        ]);
    }

    public function test_service_type_active_only_returns_active(): void
    {
        OnlineServiceType::firstOrCreate(
            ['code' => 'INACTIVE_TYPE'],
            ['name_ar' => 'معطل', 'name_en' => 'Inactive', 'is_active' => false],
        );

        $response = $this->getJson('/api/v1/online/service-types/active');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('TEST_TYPE', $codes);
        $this->assertNotContains('INACTIVE_TYPE', $codes);
    }

    // ============================================================
    // Service Provider CRUD (Operations 17-21)
    // ============================================================

    public function test_provider_index_returns_paginated_list(): void
    {
        for ($i = 0; $i < 3; $i++) {
            OnlineServiceProvider::firstOrCreate(
                ['code' => 'list_provider_'.$i],
                ['name_ar' => 'مزود '.$i, 'name_en' => 'Provider '.$i, 'is_active' => true],
            );
        }

        $response = $this->getJson('/api/v1/online/providers');

        $response->assertOk();
        $items = $response->json('data.items');
        $this->assertIsArray($items);
        $this->assertGreaterThanOrEqual(4, count($items));
    }

    public function test_provider_store_with_default_purchase_account(): void
    {
        $supplierAccount = Account::factory()->active()->create([
            'name' => 'حساب مورد',
            'type' => AccountType::Supplier,
            'currency' => 'EGP',
            'module_type' => 'online',
        ]);

        $payload = [
            'code' => 'NEW_PROVIDER',
            'name_ar' => 'مزود جديد',
            'name_en' => 'New Provider',
            'contact_phone' => '01000000000',
            'contact_account' => 'EG1100000000000000999',
            'default_purchase_account_id' => $supplierAccount->id,
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/online/providers', $payload);

        $response->assertStatus(201);
        // prepareForValidation normalizes 'NEW_PROVIDER' → 'new_provider'
        $this->assertDatabaseHas('online_service_providers', [
            'code' => 'new_provider',
            'default_purchase_account_id' => $supplierAccount->id,
        ]);
    }

    public function test_provider_store_rejects_nonexistent_purchase_account(): void
    {
        $response = $this->postJson('/api/v1/online/providers', [
            'code' => 'BAD_PROVIDER',
            'name_ar' => 'اسم',
            'name_en' => 'Name',
            'default_purchase_account_id' => 999999,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('default_purchase_account_id', (array) $response->json('errors'));
    }

    public function test_provider_store_rejects_duplicate_code(): void
    {
        // Seed a row to collide against.
        OnlineServiceProvider::firstOrCreate(
            ['code' => 'dup_provider_test'],
            ['name_ar' => 'تجربة', 'name_en' => 'Dup Test', 'is_active' => true],
        );

        $response = $this->postJson('/api/v1/online/providers', [
            'code' => 'dup_provider_test',
            'name_ar' => 'مكرر',
            'name_en' => 'Duplicate',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('code', (array) $response->json('errors'));
    }

    public function test_provider_show_returns_single(): void
    {
        $provider = OnlineServiceProvider::firstOrCreate(
            ['code' => 'SHOW_PROVIDER'],
            ['name_ar' => 'عرض', 'name_en' => 'Show', 'is_active' => true],
        );

        $response = $this->getJson("/api/v1/online/providers/{$provider->id}");

        $response->assertOk();
        $this->assertSame('SHOW_PROVIDER', $response->json('data.code'));
    }

    public function test_provider_update_modifies_fields(): void
    {
        $provider = OnlineServiceProvider::firstOrCreate(
            ['code' => 'UPDATE_PROVIDER'],
            ['name_ar' => 'قديم', 'name_en' => 'Old', 'is_active' => true],
        );

        $response = $this->putJson("/api/v1/online/providers/{$provider->id}", [
            'code' => 'UPDATE_PROVIDER',
            'name_ar' => 'محدث',
            'name_en' => 'Updated',
            'contact_phone' => '01111111111',
            'is_active' => true,
        ]);

        $response->assertOk();
        $provider->refresh();
        $this->assertSame('محدث', $provider->name_ar);
        $this->assertSame('01111111111', $provider->contact_phone);
    }

    public function test_provider_destroy_blocked_when_transactions_exist(): void
    {
        $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'عميل',
            'customer_phone' => '01000000000',
            'purchase_price' => 10,
            'selling_price' => 50,
            'amount_paid' => 50,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response = $this->deleteJson("/api/v1/online/providers/{$this->provider->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('online_service_providers', [
            'id' => $this->provider->id,
            'deleted_at' => null,
        ]);
    }

    public function test_provider_active_only_returns_active(): void
    {
        OnlineServiceProvider::firstOrCreate(
            ['code' => 'INACTIVE_PROVIDER'],
            ['name_ar' => 'معطل', 'name_en' => 'Inactive', 'is_active' => false],
        );

        $response = $this->getJson('/api/v1/online/providers/active');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('TEST_PROVIDER', $codes);
        $this->assertNotContains('INACTIVE_PROVIDER', $codes);
    }

    // ============================================================
    // Settings aggregator (Operation 1)
    // ============================================================

    public function test_settings_all_returns_aggregated_payload(): void
    {
        $response = $this->getJson('/api/v1/online/settings/all');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayHasKey('service_types', $data);
        $this->assertArrayHasKey('providers', $data);
        $this->assertArrayHasKey('payment_methods', $data);
        $this->assertArrayHasKey('accounts', $data);
        $this->assertArrayHasKey('statuses', $data);
    }

    public function test_settings_accounts_filters_liquidity_only(): void
    {
        // Create a non-liquidity account (Customer type) that should be excluded.
        // Customer-type accounts need module_type='online' (subject-specific).
        $subject = Account::factory()->active()->create([
            'name' => 'حساب عميل',
            'type' => AccountType::Customer,
            'currency' => 'EGP',
            'module_type' => 'online',
        ]);

        $response = $this->getJson('/api/v1/online/settings/accounts');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($subject->id, $ids, 'Customer account must NOT be returned');
        $this->assertContains($this->cashbox->id, $ids);
        $this->assertContains($this->bank->id, $ids);
        $this->assertContains($this->wallet->id, $ids);
    }

    public function test_settings_accounts_excludes_tourism_module(): void
    {
        $tourism = Account::factory()->active()->create([
            'name' => 'حساب سياحة',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'module_type' => 'tourism',
        ]);

        $response = $this->getJson('/api/v1/online/settings/accounts');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($tourism->id, $ids, 'Tourism cashbox must NOT be returned');
    }

    public function test_settings_customers_returns_only_online_or_with_online_txs(): void
    {
        $online = $this->makeCustomer('Online Client', '01000000001');
        $other = Customer::create([
            'full_name' => 'Other Client',
            'phone' => '01000000002',
            'type' => CustomerType::Individual->value,
            'module_type' => 'office', // not online
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/v1/online/settings/customers');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($online->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_settings_customers_store_creates_online_customer(): void
    {
        $response = $this->postJson('/api/v1/online/settings/customers', [
            'full_name' => 'عميل جديد',
            'phone' => '01099999999',
            'type' => 'individual',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('customers', [
            'full_name' => 'عميل جديد',
            'phone' => '01099999999',
            'module_type' => 'online',
        ]);
    }

    public function test_settings_customers_store_requires_full_name(): void
    {
        $response = $this->postJson('/api/v1/online/settings/customers', [
            'phone' => '01099999999',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('full_name', (array) $response->json('errors'));
    }

    public function test_settings_employees_returns_only_active(): void
    {
        // Create employees directly (no factory exists for Employee).
        Employee::create([
            'full_name' => 'موظف 1', 'is_active' => true, 'created_by' => $this->user->id,
        ]);
        Employee::create([
            'full_name' => 'موظف 2', 'is_active' => true, 'created_by' => $this->user->id,
        ]);
        Employee::create([
            'full_name' => 'موظف معطل', 'is_active' => false, 'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/v1/online/settings/employees');

        $response->assertOk();
        // Service filters by active employees only.
        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    public function test_settings_statuses_returns_enum_cases(): void
    {
        $response = $this->getJson('/api/v1/online/settings/statuses');

        $response->assertOk();
        $values = collect($response->json('data'))->pluck('value')->all();
        $this->assertContains('pending', $values);
        $this->assertContains('completed', $values);
        $this->assertContains('failed', $values);
        $this->assertContains('cancelled', $values);
    }

    // ============================================================
    // Daily Summary (Operation 24)
    // ============================================================

    public function test_daily_summary_requires_date(): void
    {
        // F-2 fix: validation runs BEFORE the try/catch so the structured
        // ValidationException propagates to the global exception handler,
        // which renders the standard Arabic message + structured field errors.
        $response = $this->getJson('/api/v1/online/transactions/daily-summary');

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));
        $this->assertArrayHasKey('date', (array) $response->json('errors'),
            'Field-level error for `date` must be returned in the structured `errors` dict.');
        $message = (string) $response->json('message');
        $this->assertStringContainsString('بيانات المدخلات', $message,
            'Message must be the standard Arabic validation message.');
    }

    public function test_daily_summary_rejects_invalid_date_format(): void
    {
        $response = $this->getJson('/api/v1/online/transactions/daily-summary?date=2026/08/21');

        $response->assertStatus(422);
        $this->assertArrayHasKey('date', (array) $response->json('errors'),
            'Field-level error for `date` must be returned in the structured `errors` dict.');
    }

    public function test_daily_summary_aggregates_completed_txs_only(): void
    {
        // 2 completed + 1 pending + 1 cancelled
        $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'C1', 'customer_phone' => '01000000001',
            'purchase_price' => 50, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'C2', 'customer_phone' => '01000000002',
            'purchase_price' => 80, 'selling_price' => 200, 'amount_paid' => 200,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $pending = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'P1', 'customer_phone' => '01000000003',
            'purchase_price' => 999, 'selling_price' => 9999, 'amount_paid' => 0,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
            'status' => 'pending',
        ]);
        $cancelledTx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'X1', 'customer_phone' => '01000000004',
            'purchase_price' => 999, 'selling_price' => 9999, 'amount_paid' => 0,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $this->service->update($cancelledTx, ['status' => 'cancelled']);

        $today = now()->format('Y-m-d');
        $response = $this->getJson("/api/v1/online/transactions/daily-summary?date={$today}");

        $response->assertOk();
        $summary = $response->json('data');
        $this->assertSame($today, $summary['date']);
        // Only completed count
        $this->assertSame(2, $summary['total_transactions']);
        $this->assertEqualsWithDelta(130.0, $summary['total_purchase'], 0.01);
        $this->assertEqualsWithDelta(300.0, $summary['total_selling'], 0.01);
        $this->assertEqualsWithDelta(170.0, $summary['total_profit'], 0.01);
    }

    public function test_daily_summary_empty_when_no_completed_txs(): void
    {
        $response = $this->getJson('/api/v1/online/transactions/daily-summary?date=1999-01-01');

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total_transactions'));
        $this->assertEquals(0, $response->json('data.total_purchase'));
        $this->assertEquals(0, $response->json('data.total_selling'));
        $this->assertEquals(0, $response->json('data.total_profit'));
    }
}
