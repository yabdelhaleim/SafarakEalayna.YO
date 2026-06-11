<?php

namespace Tests\Feature\Reports;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsHubTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Reports Hub Tester',
            'email' => 'reports-hub@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_reports_hub_summary_endpoint_returns_pl_fields(): void
    {
        $response = $this->getJson('/api/v1/reports/financial/summary');

        $response->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.total_income', 0)
            ->assertJsonPath('data.total_cogs', 0)
            ->assertJsonPath('data.total_operating_expenses', 0)
            ->assertJsonPath('data.total_expense', 0)
            ->assertJsonPath('data.net_profit', 0);
    }

    public function test_reports_hub_accounts_balance_is_liquidity_only(): void
    {
        Account::query()->create([
            'name' => 'Hub Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 7500,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
        ]);

        Account::query()->create([
            'name' => 'Hub Treasury',
            'type' => AccountType::Treasury,
            'balance' => 2500,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
        ]);

        Account::query()->create([
            'name' => 'عميل مرتبط',
            'type' => AccountType::Customer,
            'balance' => 88_888,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
        ]);

        $response = $this->getJson('/api/v1/reports/financial/accounts-balance');

        $response->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.grand_total', 10000)
            ->assertJsonPath('data.total_cashbox', 7500)
            ->assertJsonPath('data.total_treasury', 2500)
            ->assertJsonCount(2, 'data.accounts');
    }

    public function test_reports_hub_capital_analysis_endpoint(): void
    {
        $response = $this->getJson('/api/v1/reports/capital-analysis');

        $response->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'tourism',
                    'office',
                    'currencies',
                ]
            ]);
    }

    public function test_reports_hub_detailed_flight_transactions_endpoint(): void
    {
        $response = $this->getJson('/api/v1/reports/flights/detailed');

        $response->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data',
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ]
            ]);
    }
}
