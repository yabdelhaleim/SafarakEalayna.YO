<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardFinancialStatsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Dashboard Finance Tester',
            'email' => 'dash-fin-test@example.com',
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

    public function test_dashboard_financial_stats_use_pl_cogs_and_operating_split(): void
    {
        $treasury = Account::query()->create([
            'name' => 'Dash Main Treasury',
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
            'name' => 'مصروف إيجار لوحة',
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
            'amount' => 4000,
            'module' => 'fawry',
            'from_account_id' => $incomeId,
            'to_account_id' => $treasury->id,
            'created_by' => $this->user->id,
            'notes' => 'بيع فوري',
        ]);

        Transaction::query()->create([
            'type' => 'transfer',
            'amount' => 900,
            'module' => 'fawry',
            'from_account_id' => $treasury->id,
            'to_account_id' => $expenseId,
            'created_by' => $this->user->id,
            'notes' => 'تكلفة فوري',
        ]);

        Transaction::query()->create([
            'type' => 'transfer',
            'amount' => 150,
            'module' => 'general',
            'from_account_id' => $treasury->id,
            'to_account_id' => $expenseAccount->id,
            'created_by' => $this->user->id,
            'notes' => 'إيجار',
        ]);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $response = $this->getJson("/api/v1/dashboard?from_date={$from}&to_date={$to}");

        $response->assertOk();
        $response->assertJsonPath('data.financial.total_income', 4000);
        $response->assertJsonPath('data.financial.total_cogs', 900);
        $response->assertJsonPath('data.financial.total_operating_expenses', 150);
        $response->assertJsonPath('data.financial.total_expense', 1050);
        $response->assertJsonPath('data.financial.net_profit', 2950);
    }
}
