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
 * Decomposed per-action tests for Api\V1\Visa\VisaTreasuryController.
 *
 * @see \App\Http\Controllers\Api\V1\Visa\VisaTreasuryController
 */
class VisaTreasuryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Visa Treasury Tester',
            'email' => 'visa-treasury@'.now()->timestamp.'.test',
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

    /* =========================================================
     * OVERVIEW
     * ========================================================= */

    public function test_overview_returns_three_sections(): void
    {
        $response = $this->getJson('/api/v1/visa/treasury/overview');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'settlement_accounts',
                    'agents',
                    'recent_visa_transactions',
                ],
            ]);
    }

    public function test_overview_includes_active_liquidity_accounts(): void
    {
        $response = $this->getJson('/api/v1/visa/treasury/overview');

        $accounts = $response->json('data.settlement_accounts');
        $this->assertGreaterThanOrEqual(1, count($accounts));
        $this->assertSame($this->treasury->id, $accounts[0]['id']);
    }

    public function test_overview_excludes_inactive_accounts(): void
    {
        $this->treasury->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/visa/treasury/overview');

        $accounts = $response->json('data.settlement_accounts');
        $this->assertCount(0, $accounts);
    }

    public function test_overview_lists_active_agents(): void
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

        VisaAgent::query()->create([
            'company_name' => 'وكيل نشط',
            'contact_person' => 'Contact 1',
            'account_id' => $account->id,
            'is_active' => true,
        ]);
        VisaAgent::query()->create([
            'company_name' => 'وكيل معطل',
            'contact_person' => 'Contact 2',
            'account_id' => $account->id,
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/visa/treasury/overview');

        $agents = $response->json('data.agents');
        $this->assertCount(1, $agents);
        $this->assertSame('وكيل نشط', $agents[0]['company_name']);
    }

    /* =========================================================
     * ACCOUNT TRANSACTIONS
     * ========================================================= */

    public function test_account_transactions_returns_paginated_list(): void
    {
        $response = $this->getJson("/api/v1/visa/treasury/accounts/{$this->treasury->id}/transactions");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                ],
            ]);
    }

    public function test_account_transactions_respects_per_page(): void
    {
        $response = $this->getJson("/api/v1/visa/treasury/accounts/{$this->treasury->id}/transactions?per_page=5");

        $response->assertOk()
            ->assertJsonPath('data.per_page', 5);
    }

    public function test_account_transactions_caps_per_page_at_100(): void
    {
        $response = $this->getJson("/api/v1/visa/treasury/accounts/{$this->treasury->id}/transactions?per_page=500");

        $response->assertOk();
        $this->assertLessThanOrEqual(100, $response->json('data.per_page'));
    }
}