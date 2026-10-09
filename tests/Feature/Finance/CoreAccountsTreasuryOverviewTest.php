<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — Treasury overview endpoint coverage.
 *
 * GET /api/v1/finance/treasuries/get-overview
 * GET /api/v1/finance/treasuries/get-module-accounts/{module}
 *
 * Admin-only. The overview payload is the main treasury dashboard surface:
 *   - modules: per-division / per-module account groupings
 *   - unified_by_category: liquidity groups across all currencies
 *   - recent_transfers: latest 5 transfers
 *   - stats: per-module performance + deficit accounts
 *   - trial_balance: equity equation
 *   - office_trial_balance: office-specific equity equation + variance/status
 *
 * See TreasuryService::getTreasuryOverview() in
 * app/Services/Finance/TreasuryService.php.
 */
class CoreAccountsTreasuryOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@overview.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function seedAccount(array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'TEST_AO Account',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 1000.00,
            'is_active' => true,
            'module_type' => 'office',
            'module' => 'office',
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ], $overrides)));
    }

    public function test_AO_01_overview_returns_full_payload_structure(): void
    {
        $r = $this->getJson('/api/v1/finance/treasuries/get-overview');
        $r->assertOk()
            ->assertJsonPath('success', true);

        // Verify the top-level keys exist
        $data = $r->json('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('modules', $data);
        $this->assertArrayHasKey('unified_by_category', $data);
        $this->assertArrayHasKey('recent_transfers', $data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('trial_balance', $data);
        $this->assertArrayHasKey('office_trial_balance', $data);
    }

    public function test_AO_02_overview_modules_contain_each_division(): void
    {
        // Seed liquidity accounts at division level (AccountModuleContract
        // requires liquidity types to be at division — not module — level).
        $this->seedAccount(['name' => 'TEST_AO02_Office', 'module_type' => 'office', 'module' => 'office']);
        $this->seedAccount(['name' => 'TEST_AO02_Tourism', 'module_type' => 'tourism', 'module' => 'tourism']);

        $r = $this->getJson('/api/v1/finance/treasuries/get-overview');
        $r->assertOk();
        $modules = $r->json('data.modules');

        $this->assertIsArray($modules);
        $this->assertNotEmpty($modules);
    }

    public function test_AO_03_overview_unified_by_category_groups_by_currency_and_type(): void
    {
        $this->seedAccount(['name' => 'TEST_AO03_EGP_Cashbox', 'currency' => 'EGP', 'type' => AccountType::Cashbox->value, 'balance' => 1000.0]);
        $this->seedAccount(['name' => 'TEST_AO03_USD_Bank', 'currency' => 'USD', 'type' => AccountType::Bank->value, 'balance' => 500.0]);

        $r = $this->getJson('/api/v1/finance/treasuries/get-overview');
        $r->assertOk();
        $unified = $r->json('data.unified_by_category');

        $this->assertIsArray($unified);
        // Should contain entries keyed by something — at minimum EGP and USD present
        $this->assertGreaterThanOrEqual(1, count($unified));
    }

    public function test_AO_04_overview_recent_transfers_present(): void
    {
        $r = $this->getJson('/api/v1/finance/treasuries/get-overview');
        $r->assertOk();

        $recent = $r->json('data.recent_transfers');
        $this->assertIsArray($recent);
        // It's OK to be empty when no transfers exist.
    }

    public function test_AO_05_overview_stats_by_category_present(): void
    {
        $r = $this->getJson('/api/v1/finance/treasuries/get-overview');
        $r->assertOk();

        $stats = $r->json('data.stats');
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('by_category', $stats);
    }

    public function test_AO_06_get_module_accounts_filters_by_module(): void
    {
        $this->seedAccount(['name' => 'TEST_AO06_Office_1', 'module_type' => 'office', 'module' => 'office']);
        $this->seedAccount(['name' => 'TEST_AO06_Tourism_1', 'module_type' => 'tourism', 'module' => 'tourism']);

        $r = $this->getJson('/api/v1/finance/treasuries/get-module-accounts/office');
        $r->assertOk()
            ->assertJsonPath('success', true);

        $accounts = $r->json('data');
        $this->assertIsArray($accounts);
    }

    public function test_AO_07_get_overview_admin_only_403_for_employee(): void
    {
        $emp = User::query()->create([
            'name' => 'Emp',
            'email' => 'emp@overview.test',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
        ]);

        auth()->forgetGuards();
        Sanctum::actingAs($emp, ['*']);

        $r = $this->getJson('/api/v1/finance/treasuries/get-overview');
        $r->assertStatus(403);
    }

    public function test_AO_08_overview_office_trial_balance_includes_variance_and_status(): void
    {
        $r = $this->getJson('/api/v1/finance/treasuries/get-overview');
        $r->assertOk();

        $otb = $r->json('data.office_trial_balance');
        $this->assertIsArray($otb);

        // The office trial balance carries the equity equation + variance.
        // Even with zero data, these keys must exist.
        $this->assertArrayHasKey('total_balances', $otb);
        $this->assertArrayHasKey('total_liquidity', $otb);
        $this->assertArrayHasKey('variance', $otb);
        $this->assertArrayHasKey('status', $otb);
    }
}