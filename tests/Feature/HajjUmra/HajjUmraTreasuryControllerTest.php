<?php

namespace Tests\Feature\HajjUmra;

use App\Models\Account;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decomposed per-action tests for Api\V1\HajjUmra\HajjUmraTreasuryController.
 *
 * @see \App\Http\Controllers\Api\V1\HajjUmra\HajjUmraTreasuryController
 */
class HajjUmraTreasuryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Treasury Tester',
            'email' => 'hajj-treasury@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = Account::query()->create([
            'name' => 'Hajj Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);
    }

    /* =========================================================
     * OVERVIEW
     * ========================================================= */

    public function test_overview_returns_three_sections(): void
    {
        $response = $this->getJson('/api/v1/hajj-umra/treasury/overview');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'settlement_accounts',
                    'executing_companies',
                    'recent_hajj_umra_transactions',
                ],
            ]);
    }

    public function test_overview_includes_active_liquidity_accounts(): void
    {
        $this->getJson('/api/v1/hajj-umra/treasury/overview');

        $response = $this->getJson('/api/v1/hajj-umra/treasury/overview');
        $accounts = $response->json('data.settlement_accounts');

        $this->assertGreaterThanOrEqual(1, count($accounts));
        $this->assertSame($this->treasury->id, $accounts[0]['id']);
    }

    public function test_overview_excludes_inactive_accounts(): void
    {
        $this->treasury->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/hajj-umra/treasury/overview');

        $accounts = $response->json('data.settlement_accounts');
        $this->assertCount(0, $accounts);
    }

    public function test_overview_lists_active_executing_companies(): void
    {
        HajjUmraExecutingCompany::query()->create([
            'name' => 'شركة تنفيذ نشطة',
            'phone' => '+966500000001',
            'is_active' => true,
        ]);
        HajjUmraExecutingCompany::query()->create([
            'name' => 'شركة تنفيذ معطلة',
            'phone' => '+966500000002',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/hajj-umra/treasury/overview');

        $companies = $response->json('data.executing_companies');
        $this->assertCount(1, $companies);
        $this->assertSame('شركة تنفيذ نشطة', $companies[0]['name']);
    }

    /* =========================================================
     * ACCOUNT TRANSACTIONS
     * ========================================================= */

    public function test_account_transactions_returns_paginated_list(): void
    {
        $response = $this->getJson("/api/v1/hajj-umra/treasury/accounts/{$this->treasury->id}/transactions");

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
        $response = $this->getJson("/api/v1/hajj-umra/treasury/accounts/{$this->treasury->id}/transactions?per_page=5");

        $response->assertOk()
            ->assertJsonPath('data.per_page', 5);
    }

    public function test_account_transactions_caps_per_page_at_100(): void
    {
        $response = $this->getJson("/api/v1/hajj-umra/treasury/accounts/{$this->treasury->id}/transactions?per_page=500");

        $response->assertOk();
        $this->assertLessThanOrEqual(100, $response->json('data.per_page'));
    }

    /* =========================================================
     * LIQUIDITY BY CURRENCY (Bug #3 extended fix)
     * ========================================================= */

    public function test_overview_returns_liquidity_by_currency_section(): void
    {
        $response = $this->getJson('/api/v1/hajj-umra/treasury/overview');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'liquidity_by_currency',
                ],
            ]);
    }

    public function test_liquidity_groups_accounts_per_currency(): void
    {
        // Existing treasury (EGP, 100000) from setUp().
        // Add a KWD-denominated account so we get 2 currency buckets.
        Account::query()->create([
            'name' => 'محفظة كاش كويتي',
            'type' => 'cashbox',
            'currency' => 'KWD',
            'balance' => 50.000,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/v1/hajj-umra/treasury/overview');

        $rows = $response->json('data.liquidity_by_currency');
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);

        $byCurrency = collect($rows)->keyBy('currency');
        $this->assertSame(100000.0, (float) $byCurrency['EGP']['accounts_balance']);
        $this->assertSame(50.0, (float) $byCurrency['KWD']['accounts_balance']);
    }

    public function test_liquidity_sorts_egp_first_then_alphabetically(): void
    {
        // EGP already exists from setUp(). Add KWD + SAR so we have 3 currencies.
        Account::query()->create([
            'name' => 'KWD account',
            'type' => 'cashbox',
            'currency' => 'KWD',
            'balance' => 100,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'created_by' => $this->user->id,
        ]);
        Account::query()->create([
            'name' => 'SAR account',
            'type' => 'bank',
            'currency' => 'SAR',
            'balance' => 200,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/v1/hajj-umra/treasury/overview');

        $rows = $response->json('data.liquidity_by_currency');
        $currencies = array_column($rows, 'currency');
        $this->assertSame(['EGP', 'KWD', 'SAR'], $currencies);
    }

    public function test_liquidity_excludes_inactive_accounts(): void
    {
        // EGP account from setUp is active. Mark it inactive → liquidity must be empty.
        $this->treasury->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/hajj-umra/treasury/overview');

        $rows = $response->json('data.liquidity_by_currency');
        $this->assertSame([], $rows);
    }
}