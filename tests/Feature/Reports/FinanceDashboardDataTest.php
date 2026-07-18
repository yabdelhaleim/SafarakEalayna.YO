<?php

namespace Tests\Feature\Reports;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Reports\ReportFinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceDashboardDataTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Dashboard Tester',
            'email' => 'dash-test@example.com',
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

    public function test_accounts_balance_excludes_customer_and_clearing_accounts(): void
    {
        Account::query()->create([
            'name' => 'Office Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 5000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);

        Account::query()->create([
            'name' => 'عميل أحمد',
            'type' => AccountType::Customer,
            'balance' => 99_999,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
        ]);

        $balance = app(ReportFinanceService::class)->getAccountsBalance();

        $this->assertEquals(5000.0, $balance['grand_total']);
        $this->assertCount(1, $balance['accounts']);
    }

    public function test_financial_summary_returns_cogs_and_operating_expenses(): void
    {
        $treasury = Account::query()->create([
            'name' => 'Dash Treasury',
            'type' => AccountType::Cashbox,
            'balance' => 50_000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $clearing = app(LedgerClearingAccounts::class);
        $incomeId = $clearing->incomeContraIdForModule('fawry');
        $expenseId = $clearing->expenseContraIdForModule('fawry');
        $this->assertNotNull($incomeId);
        $this->assertNotNull($expenseId);

        $expenseAccount = Account::query()->create([
            'name' => 'مصروف إيجار',
            'type' => AccountType::Expense,
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
            'created_by' => $this->user->id,
        ]);

        Transaction::query()->create([
            'type' => 'transfer',
            'amount' => 3000,
            'module' => 'fawry',
            'from_account_id' => $incomeId,
            'to_account_id' => $treasury->id,
            'created_by' => $this->user->id,
            'notes' => 'بيع',
        ]);

        Transaction::query()->create([
            'type' => 'transfer',
            'amount' => 800,
            'module' => 'fawry',
            'from_account_id' => $treasury->id,
            'to_account_id' => $expenseId,
            'created_by' => $this->user->id,
            'notes' => 'تكلفة',
        ]);

        Transaction::query()->create([
            'type' => 'transfer',
            'amount' => 200,
            'module' => 'general',
            'from_account_id' => $treasury->id,
            'to_account_id' => $expenseAccount->id,
            'created_by' => $this->user->id,
            'notes' => 'إيجار',
        ]);

        $summary = app(ReportFinanceService::class)->getFinancialSummary([]);

        $this->assertEquals(3000.0, $summary['total_income']);
        $this->assertEquals(800.0, $summary['total_cogs']);
        $this->assertEquals(200.0, $summary['total_operating_expenses']);
        $this->assertEquals(1000.0, $summary['total_expense']);
        $this->assertEquals(2000.0, $summary['net_profit']);
    }

    public function test_dashboard_accounts_api_stats_match_liquidity_only(): void
    {
        Account::query()->create([
            'name' => 'Main Bank',
            'type' => AccountType::Bank,
            'balance' => 12_000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);

        Account::query()->create([
            'name' => 'ذممة عميل',
            'type' => AccountType::Customer,
            'balance' => 50_000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
        ]);

        $response = $this->getJson('/api/v1/finance/accounts?per_page=100&is_active=1');

        $response->assertOk();
        $this->assertEquals(12000.0, (float) $response->json('data.stats.total_balance'));
    }
}
