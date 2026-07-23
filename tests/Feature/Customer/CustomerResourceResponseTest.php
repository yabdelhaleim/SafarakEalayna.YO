<?php

namespace Tests\Feature\Customer;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression test for the 2026-07-17 finding:
 *   "Customer resource لا يُرجع account_id — Filament/Vue can't display AR balance directly"
 *
 * Verifies the API response for the customer index/show endpoints now exposes:
 *  - `account_id` (FK column on customers)
 *  - `account`    (nested summary: id/name/type/currency/balance/is_active)
 *
 * Note: {@see \App\Observers\CustomerLedgerObserver} auto-creates a
 * subject Account on customer creation, so `account_id` is never null
 * for a customer that has a `created_by` user.
 */
class CustomerResourceResponseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    public function test_index_includes_account_id_field(): void
    {
        $customer = Customer::factory()->create([
            'full_name' => 'محمد الاختبار',
            'phone' => '01000000001',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/customers');

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $items = $response->json('data.items');
        $this->assertIsArray($items);
        $this->assertNotEmpty($items, 'Customer list should not be empty');

        $customerPayload = collect($items)->firstWhere('id', $customer->id);
        $this->assertNotNull($customerPayload, 'Created customer must appear in the index response');
        $this->assertArrayHasKey('account_id', $customerPayload, 'account_id field must be present in the response');
        $this->assertArrayHasKey('account', $customerPayload, 'account nested block must be present in the response');
        $this->assertIsInt($customerPayload['account_id'], 'account_id should be an integer (auto-created by CustomerLedgerObserver)');
        $this->assertEquals(0.0, $customerPayload['balance'], 'balance must default to 0 for a freshly created customer');
        $this->assertEquals($customerPayload['account_id'], $customerPayload['account']['id'], 'account.id must equal account_id');
    }

    public function test_index_exposes_account_id_and_account_summary_when_customer_has_ledger_account(): void
    {
        $ledgerAccount = Account::create([
            'name' => 'حساب اختبار العميل',
            'type' => AccountType::Customer,
            'currency' => 'EGP',
            'balance' => 1234.56,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'flights',
            'is_module_vault' => false,
            'created_by' => $this->admin->id,
        ]);

        $customer = Customer::factory()->create([
            'full_name' => 'سارة الاختبار',
            'phone' => '01000000002',
            'account_id' => $ledgerAccount->id,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/customers');

        $response->assertOk();
        $customerPayload = collect($response->json('data.items'))->firstWhere('id', $customer->id);

        $this->assertNotNull($customerPayload);
        $this->assertEquals($ledgerAccount->id, $customerPayload['account_id'], 'account_id must match the FK on the customer');

        $this->assertIsArray($customerPayload['account']);
        $this->assertEquals($ledgerAccount->id, $customerPayload['account']['id']);
        $this->assertEquals('حساب اختبار العميل', $customerPayload['account']['name']);
        $this->assertEquals('customer', $customerPayload['account']['type']);
        $this->assertEquals('EGP', $customerPayload['account']['currency']);
        $this->assertEquals(1234.56, $customerPayload['account']['balance']);
        $this->assertTrue($customerPayload['account']['is_active']);

        $this->assertEquals(1234.56, $customerPayload['balance'], 'balance must mirror account.balance');
    }

    public function test_show_endpoint_includes_account_id(): void
    {
        $customer = Customer::factory()->create([
            'full_name' => 'أحمد الاختبار',
            'phone' => '01000000003',
            'created_by' => $this->admin->id,
        ]);

        $ledgerAccount = Account::find($customer->account_id);
        $this->assertNotNull($ledgerAccount, 'CustomerLedgerObserver should have created a ledger account');

        $response = $this->getJson("/api/v1/customers/{$customer->id}");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $customer->id);
        $response->assertJsonPath('data.account_id', $ledgerAccount->id);
        $response->assertJsonPath('data.account.id', $ledgerAccount->id);
        // JSON serializes 0.0 as the integer 0, so use assertEquals on the decoded value
        $this->assertEquals(0.0, $response->json('data.account.balance'));
        $this->assertEquals(0.0, $response->json('data.balance'));
    }
}
