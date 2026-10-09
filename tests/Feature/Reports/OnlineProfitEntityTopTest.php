<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for GET /api/v1/reports/profit-entity-top?module=online.
 *
 * Bug fixed (2026-08-30):
 *   The online entry of PROFIT_ENTITY_MAP used `entity_column => 'provider_id'`
 *   even though that column was dropped from `online_transactions` by
 *   migration 2026_08_28_000000_convert_online_service_type_and_provider_to_text.
 *   The endpoint therefore crashed with
 *       "Unknown column 'provider_id' in 'SELECT'".
 *
 * The fix switches `entity_column` to `provider_code` (the free-text code
 * column that replaced the FK) and adds `lookup_column => 'code'` so the
 * label resolver joins on `online_service_providers.code` instead of the
 * model's PK `id`.
 *
 * These tests assert BOTH halves of the fix:
 *   1. The endpoint returns 200 and aggregates revenue by `provider_code`.
 *   2. The endpoint resolves `entity_label` from `name_ar` (proving the
 *      `lookup_column` override works — without it, the label_map lookup
 *      would silently fall back to "#0" for every provider).
 *   3. `entity_id` is surfaced as a STRING, not an int — which is the
 *      observable contract change for this module.
 */
class OnlineProfitEntityTopTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected Account $onlineIncomeClearing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Online PL Tester',
            'email' => 'online-pl-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);

        // The cashbox acts as the treasury that receives online revenue.
        $this->treasury = Account::create([
            'name' => 'Online Treasury EGP',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        // Resolve — and therefore seed — the online income-clearing account.
        $clearing = app(LedgerClearingAccounts::class);
        $incomeId = $clearing->incomeContraIdForModule('online');
        $this->assertNotNull($incomeId, 'Online income clearing account must exist from config/accounting.php');

        $this->onlineIncomeClearing = Account::query()->findOrFail($incomeId);
    }

    /**
     * POST a single online "revenue" transfer (income-clearing → treasury)
     * tied to the given OnlineTransaction so that getProfitByEntity()
     * can group it under the transaction's provider_code.
     */
    private function postRevenueTransfer(OnlineTransaction $tx, float $amount): Transaction
    {
        return Transaction::query()->create([
            'type' => 'transfer',
            'amount' => $amount,
            'module' => 'online',
            'from_account_id' => $this->onlineIncomeClearing->id,
            'to_account_id' => $this->treasury->id,
            'related_type' => OnlineTransaction::class,
            'related_id' => $tx->id,
            'created_by' => $this->user->id,
            'notes' => 'بيع خدمة أونلاين',
        ]);
    }

    public function test_endpoint_returns_200_and_groups_revenue_by_provider_code(): void
    {
        // Two distinct providers with two distinct codes.
        $providerA = OnlineServiceProvider::create([
            'code' => 'FAWRY_VISA',
            'name_ar' => 'مزود فوري للتأشيرات',
            'name_en' => 'Fawry Visa Provider',
            'is_active' => true,
            'order' => 1,
        ]);
        $providerB = OnlineServiceProvider::create([
            'code' => 'CUSTOMS_STAMP',
            'name_ar' => 'مزود الطوابع الجمركية',
            'name_en' => 'Customs Stamp Provider',
            'is_active' => true,
            'order' => 2,
        ]);

        // Two transactions, one per provider.
        $txA = OnlineTransaction::query()->create([
            'service_type_code' => 'TEST_TYPE',
            'provider_code' => $providerA->code,
            'customer_name' => 'Customer A',
            'customer_phone' => '01000000001',
            'purchase_price' => 100,
            'selling_price' => 150,
            'amount_paid' => 150,
            'profit' => 50,
            'payment_method' => 'cash',
            'account_id' => $this->treasury->id,
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);
        $txB = OnlineTransaction::query()->create([
            'service_type_code' => 'TEST_TYPE',
            'provider_code' => $providerB->code,
            'customer_name' => 'Customer B',
            'customer_phone' => '01000000002',
            'purchase_price' => 200,
            'selling_price' => 300,
            'amount_paid' => 300,
            'profit' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->treasury->id,
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);

        // Post revenue transfers (income-clearing → treasury).
        $this->postRevenueTransfer($txA, 500.0);
        $this->postRevenueTransfer($txB, 1200.0);

        // Hit the endpoint that previously crashed with
        // "Unknown column 'provider_id' in 'SELECT'".
        $response = $this->getJson(
            '/api/v1/reports/profit-entity-top?module=online'
                .'&from_date='.now()->subDay()->toDateString()
                .'&to_date='.now()->addDay()->toDateString()
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.module', 'online');

        $entityTypes = $response->json('data.entity_types');
        $this->assertIsArray($entityTypes);
        $this->assertCount(1, $entityTypes, 'Online module exposes exactly one entity_type: provider');
        $this->assertSame('provider', $entityTypes[0]['entity_type']);
        $this->assertSame('مزود خدمة', $entityTypes[0]['entity_type_label']);

        $items = $entityTypes[0]['items'];
        $this->assertCount(2, $items);

        // Items are ordered by profit DESC by default. Provider B has
        // higher revenue (1200 > 500) so it should come first.
        $top = $items[0];
        $this->assertSame($providerB->code, $top['entity_id']);
        $this->assertSame($providerB->name_ar, $top['entity_label']);
        $this->assertEquals(1200.0, (float) $top['income']);
        $this->assertEquals(1200.0, (float) $top['profit']);

        $second = $items[1];
        $this->assertSame($providerA->code, $second['entity_id']);
        $this->assertSame($providerA->name_ar, $second['entity_label']);
        $this->assertEquals(500.0, (float) $second['income']);
    }

    public function test_entity_id_is_returned_as_string_not_int_for_online_module(): void
    {
        // Single provider — proves the label lookup doesn't silently
        // fall back to "#0" when the value is a string code.
        $provider = OnlineServiceProvider::create([
            'code' => 'TEST_LOOKUP_CODE',
            'name_ar' => 'مزود اختبار',
            'name_en' => 'Test Lookup Provider',
            'is_active' => true,
            'order' => 1,
        ]);

        $tx = OnlineTransaction::query()->create([
            'service_type_code' => 'TEST_TYPE',
            'provider_code' => $provider->code,
            'customer_name' => 'Lookup Customer',
            'customer_phone' => '01000000999',
            'purchase_price' => 50,
            'selling_price' => 75,
            'amount_paid' => 75,
            'profit' => 25,
            'payment_method' => 'cash',
            'account_id' => $this->treasury->id,
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);
        $this->postRevenueTransfer($tx, 250.0);

        $response = $this->getJson(
            '/api/v1/reports/profit-entity-top?module=online'
                .'&from_date='.now()->subDay()->toDateString()
                .'&to_date='.now()->addDay()->toDateString()
        );

        $response->assertOk();

        $items = $response->json('data.entity_types.0.items');
        $this->assertCount(1, $items);

        // The critical assertion: entity_id must surface as the string
        // code (e.g. "TEST_LOOKUP_CODE") — NOT the int-cast zero fallback.
        $item = $items[0];
        $this->assertIsString($item['entity_id'], 'entity_id for the online module must be a string (provider_code)');
        $this->assertSame($provider->code, $item['entity_id']);
        $this->assertNotSame('0', $item['entity_id'], 'entity_id must not collapse to "0" via the int-cast regression');
        $this->assertSame($provider->name_ar, $item['entity_label']);
    }

    public function test_endpoint_returns_empty_list_when_no_online_revenue_exists(): void
    {
        OnlineServiceProvider::create([
            'code' => 'PROVIDER_NO_TX',
            'name_ar' => 'مزود بدون معاملات',
            'name_en' => 'Provider Without Tx',
            'is_active' => true,
            'order' => 1,
        ]);

        $response = $this->getJson('/api/v1/reports/profit-entity-top?module=online');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame([], $response->json('data.entity_types.0.items'));
    }
}