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
 * Decomposed per-action tests for Api\V1\Visa\VisaAgentFinanceController.
 *
 * @see \App\Http\Controllers\Api\V1\Visa\VisaAgentFinanceController
 */
class VisaAgentFinanceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Visa Finance Tester',
            'email' => 'visa-finance@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = Account::query()->create([
            'name' => 'Visa Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'visas',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);
    }

    protected function makeAgent(bool $active = true, ?int $accountId = null): VisaAgent
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

        return VisaAgent::query()->create([
            'company_name' => 'وكيل اختبار',
            'contact_person' => 'أحمد محمد',
            'phone' => '+966500000000',
            'country' => 'USA',
            'visa_type' => 'work',
            'default_cost_price' => 1000.00,
            'account_id' => $accountId ?? $account->id,
            'is_active' => $active,
        ]);
    }

    /* =========================================================
     * DUES
     * ========================================================= */

    public function test_dues_returns_active_agents_with_accounts(): void
    {
        $this->makeAgent(true);

        $response = $this->getJson('/api/v1/visa/agents/dues');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        '*' => ['id', 'name', 'account_id', 'total_withdrawn', 'total_repaid', 'net_due'],
                    ],
                ],
            ]);
    }

    public function test_dues_excludes_inactive_agents(): void
    {
        $this->makeAgent(true);
        $this->makeAgent(false);

        $response = $this->getJson('/api/v1/visa/agents/dues');

        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_dues_returns_zero_for_agent_with_no_transactions(): void
    {
        $this->makeAgent(true);

        $response = $this->getJson('/api/v1/visa/agents/dues');

        $items = $response->json('data.items');
        $this->assertEquals(0, $items[0]['total_withdrawn']);
        $this->assertEquals(0, $items[0]['total_repaid']);
        $this->assertEquals(0, $items[0]['net_due']);
    }

    /* =========================================================
     * WITHDRAW
     * ========================================================= */

    public function test_withdraw_records_transaction(): void
    {
        $agent = $this->makeAgent();

        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 1000,
            'to_account_id' => $this->treasury->id,
            'notes' => 'test withdraw',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['transaction_id']]);
    }

    public function test_withdraw_validates_amount_required(): void
    {
        $agent = $this->makeAgent();

        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'to_account_id' => $this->treasury->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_withdraw_rejects_office_division_target_account(): void
    {
        $agent = $this->makeAgent();
        $officeTreasury = Account::query()->create([
            'name' => 'Office Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 1000,
            'to_account_id' => $officeTreasury->id,
        ]);

        $response->assertStatus(422);
    }

    /* =========================================================
     * REPAY
     * ========================================================= */

    public function test_repay_records_transaction(): void
    {
        $agent = $this->makeAgent();

        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/repay", [
            'amount' => 1000,
            'from_account_id' => $this->treasury->id,
            'notes' => 'test repay',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['transaction_id']]);
    }

    public function test_repay_validates_from_account_required(): void
    {
        $agent = $this->makeAgent();

        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/repay", [
            'amount' => 1000,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['from_account_id']);
    }

    public function test_repay_rejects_office_division_source_account(): void
    {
        $agent = $this->makeAgent();
        $officeTreasury = Account::query()->create([
            'name' => 'Office Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/repay", [
            'amount' => 1000,
            'from_account_id' => $officeTreasury->id,
        ]);

        $response->assertStatus(422);
    }
}