<?php

namespace Tests\Feature\Visa;

use App\Models\Account;
use App\Models\HajjUmra\VisaAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decomposed per-action tests for Api\V1\Visa\VisaAgentApiController.
 *
 * @see \App\Http\Controllers\Api\V1\Visa\VisaAgentApiController
 */
class VisaAgentApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Visa Agent Tester',
            'email' => 'visa-agent@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->user, ['*']);
    }

    protected function makeAgent(array $overrides = []): VisaAgent
    {
        $account = Account::query()->create([
            'name' => 'Agent Account',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'visas',
            'created_by' => $this->user->id,
        ]);

        return VisaAgent::query()->create(array_merge([
            'company_name' => 'وكيل اختبار',
            'contact_person' => 'أحمد محمد',
            'phone' => '+966500000000',
            'country' => 'USA',
            'visa_type' => 'work',
            'default_cost_price' => 1500.00,
            'account_id' => $account->id,
            'is_active' => true,
        ], $overrides));
    }

    /* =========================================================
     * INDEX
     * ========================================================= */

    public function test_index_returns_list_of_agents(): void
    {
        $this->makeAgent(['company_name' => 'وكيل 1']);
        $this->makeAgent(['company_name' => 'وكيل 2']);

        $response = $this->getJson('/api/v1/visa-agents');

        $response->assertOk();
        $items = $response->json('data');
        $this->assertGreaterThanOrEqual(2, count($items));
        $this->assertArrayHasKey('id', $items[0]);
        $this->assertArrayHasKey('name', $items[0]);
        $this->assertArrayHasKey('default_cost_price', $items[0]);
    }

    public function test_index_includes_account_name_when_linked(): void
    {
        $agent = $this->makeAgent();

        $response = $this->getJson('/api/v1/visa-agents');

        $items = $response->json('data');
        $first = collect($items)->firstWhere('id', $agent->id);
        $this->assertNotNull($first);
        $this->assertSame('Agent Account', $first['account_name']);
    }

    public function test_index_orders_agents_by_company_name(): void
    {
        $this->makeAgent(['company_name' => 'Z Agent']);
        $this->makeAgent(['company_name' => 'A Agent']);
        $this->makeAgent(['company_name' => 'M Agent']);

        $response = $this->getJson('/api/v1/visa-agents');

        $names = collect($response->json('data'))->pluck('company_name')->toArray();
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    /* =========================================================
     * STORE
     * ========================================================= */

    public function test_store_creates_agent_and_returns_201(): void
    {
        $response = $this->postJson('/api/v1/visa-agents', [
            'name' => 'وكيل جديد',
            'phone' => '+966500000111',
            'visa_type' => 'tourist',
            'default_cost_price' => 800.00,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'phone', 'visa_type', 'default_cost_price', 'account_id'],
            ]);

        $this->assertDatabaseHas('visa_agents', [
            'company_name' => 'وكيل جديد',
            'phone' => '+966500000111',
            'visa_type' => 'tourist',
            'default_cost_price' => 800.00,
            'is_active' => 1,
        ]);
    }

    public function test_store_auto_creates_supplier_account(): void
    {
        $response = $this->postJson('/api/v1/visa-agents', [
            'name' => 'وكيل بحساب تلقائي',
            'default_cost_price' => 500.00,
        ]);

        $response->assertCreated();
        $accountId = $response->json('data.account_id');
        $this->assertNotNull($accountId);

        $this->assertDatabaseHas('accounts', [
            'id' => $accountId,
            'type' => 'supplier',
            'module_type' => 'visas',
        ]);
    }

    public function test_store_validates_name_required(): void
    {
        $response = $this->postJson('/api/v1/visa-agents', [
            'phone' => '+966500000111',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_validates_account_id_must_exist(): void
    {
        $response = $this->postJson('/api/v1/visa-agents', [
            'name' => 'وكيل بحساب غير موجود',
            'account_id' => 999999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    /* =========================================================
     * COST PRICE
     * ========================================================= */

    public function test_cost_price_returns_agent_default_cost(): void
    {
        $agent = $this->makeAgent(['default_cost_price' => 1234.56]);

        $response = $this->getJson("/api/v1/visa-agents/{$agent->id}/cost-price");

        $response->assertOk()
            ->assertJsonPath('data.agent_id', $agent->id)
            ->assertJsonPath('data.cost_price', 1234.56);
    }

    public function test_cost_price_returns_404_for_unknown_agent(): void
    {
        $response = $this->getJson('/api/v1/visa-agents/999999/cost-price');

        $response->assertNotFound();
    }
}