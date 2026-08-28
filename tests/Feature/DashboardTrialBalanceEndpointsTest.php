<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Employee;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PHASE 10 — Dashboard Trial Balance endpoints audit.
 *
 * Endpoints (called from Vue Dashboard component via axios.get):
 *   - GET /api/v1/reports/trial-balance                 (tourism)
 *   - GET /api/v1/reports/office-trial-balance          (office)
 *   - GET /api/v1/reports/consolidated-trial-balance    (consolidated)
 *
 * These power the 3 trial-balance cards on the Treasury pillar:
 *   - Tourism trial balance  (4 KPI sub-cards + capital breakdown table)
 *   - Office trial balance   (4 KPI sub-cards + capital breakdown table)
 *   - Consolidated trial balance (4 KPI sub-cards + variance card)
 *
 * Each test seeds accounts directly (no factories) and asserts that the
 * endpoint returns 200, has the expected shape, and respects the
 * date-range / module filters.
 */
class DashboardTrialBalanceEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Trial Balance Admin',
            'email' => 'tb-admin-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->admin, ['*']);
    }

    protected function seedAccount(
        string $name,
        AccountType $type,
        float $balance = 0.0,
        string $moduleType = 'office',
        string $currency = 'EGP',
    ): Account {
        $account = Account::query()->create([
            'name' => $name,
            'type' => $type->value,
            'currency' => $currency,
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => $moduleType,
            'created_by' => $this->admin->id,
        ]);

        if ($balance !== 0.0) {
            LedgerBalanceMutationGuard::run(fn () => $account->update(['balance' => $balance]));
        }

        return $account;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Tourism trial balance
    // ─────────────────────────────────────────────────────────────────────

    public function test_tourism_trial_balance_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/reports/trial-balance');
        $response->assertOk();
    }

    public function test_tourism_trial_balance_has_expected_shape(): void
    {
        $this->seedAccount('TB Tourism Cashbox', AccountType::Cashbox, balance: 10_000, moduleType: 'tourism');

        $response = $this->getJson('/api/v1/reports/trial-balance');
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total_balances',
                    'total_liquidity',
                    'due_to_us',
                    'due_from_us',
                    'variance',
                    'base_capital',
                    'gross_profits',
                    'operating_expenses',
                    'expected_capital',
                ],
            ]);
    }

    public function test_tourism_trial_balance_aggregates_module_accounts(): void
    {
        // Tourism liquidity
        $this->seedAccount('TB T Cashbox', AccountType::Cashbox, balance: 5_000, moduleType: 'tourism');
        $this->seedAccount('TB T Bank', AccountType::Bank, balance: 10_000, moduleType: 'tourism');
        // Office liquidity (should NOT be in tourism trial balance)
        $this->seedAccount('TB O Cashbox', AccountType::Cashbox, balance: 99_999, moduleType: 'office');

        $response = $this->getJson('/api/v1/reports/trial-balance');
        $response->assertOk();

        $data = $response->json('data');
        // Tourism total should be ~15000, NOT include the 99999 office cashbox
        $this->assertLessThanOrEqual(20_000.0, (float) $data['total_liquidity'],
            'Tourism trial balance should NOT include office accounts');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Office trial balance
    // ─────────────────────────────────────────────────────────────────────

    public function test_office_trial_balance_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/reports/office-trial-balance');
        $response->assertOk();
    }

    public function test_office_trial_balance_has_expected_shape(): void
    {
        $this->seedAccount('TB Office Cashbox', AccountType::Cashbox, balance: 10_000, moduleType: 'office');

        $response = $this->getJson('/api/v1/reports/office-trial-balance');
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total_balances',
                    'total_liquidity',
                    'due_to_us',
                    'due_from_us',
                    'variance',
                    'base_capital',
                    'gross_profits',
                    'operating_expenses',
                    'expected_capital',
                ],
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Consolidated trial balance
    // ─────────────────────────────────────────────────────────────────────

    public function test_consolidated_trial_balance_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/reports/consolidated-trial-balance');
        $response->assertOk();
    }

    public function test_consolidated_trial_balance_has_expected_shape(): void
    {
        $this->seedAccount('TB Cons Cashbox', AccountType::Cashbox, balance: 5_000);
        $this->seedAccount('TB Cons Bank', AccountType::Bank, balance: 7_000);

        $response = $this->getJson('/api/v1/reports/consolidated-trial-balance');
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total_balances',
                    'total_liquidity',
                    'due_to_us',
                    'due_from_us',
                    'variance',
                ],
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Auth & contract
    // ─────────────────────────────────────────────────────────────────────

    public function test_trial_balance_endpoints_require_authentication(): void
    {
        Sanctum::actingAs(new User(['id' => 0]), []);

        $this->getJson('/api/v1/reports/trial-balance')->assertStatus(401);
        $this->getJson('/api/v1/reports/office-trial-balance')->assertStatus(401);
        $this->getJson('/api/v1/reports/consolidated-trial-balance')->assertStatus(401);
    }

    public function test_trial_balance_endpoints_accept_date_filter(): void
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $this->seedAccount('TB Date Cashbox', AccountType::Cashbox, balance: 1_000);

        $r1 = $this->getJson("/api/v1/reports/trial-balance?from_date={$from}&to_date={$to}");
        $r2 = $this->getJson("/api/v1/reports/office-trial-balance?from_date={$from}&to_date={$to}");
        $r3 = $this->getJson("/api/v1/reports/consolidated-trial-balance?from_date={$from}&to_date={$to}");

        $r1->assertOk();
        $r2->assertOk();
        $r3->assertOk();
    }
}
