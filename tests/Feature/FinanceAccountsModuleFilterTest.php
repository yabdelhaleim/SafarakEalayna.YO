<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceAccountsModuleFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Accounts Tester',
            'email' => 'accounts-module-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_tourism_tab_filter_includes_flights_hajj_and_visa_module_types(): void
    {
        $flightCashbox = Account::query()->create([
            'name' => 'Flight Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 1000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'module' => 'flight',
        ]);

        Account::query()->create([
            'name' => 'Bus Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 500,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'module' => 'bus',
        ]);

        $response = $this->getJson('/api/v1/finance/accounts?module_type=tourism&per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($flightCashbox->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_accounts_index_returns_pagination_and_liquidity_stats(): void
    {
        Account::query()->create([
            'name' => 'Main Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 2500,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
        ]);

        Account::query()->create([
            'name' => 'رواتب',
            'type' => AccountType::Expense,
            'balance' => 900,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
        ]);

        $response = $this->getJson('/api/v1/finance/accounts?per_page=100');

        $response->assertOk();
        $response->assertJsonPath('data.pagination.total', 1);
        $this->assertSame(2500.0, (float) $response->json('data.stats.total_balance'));
        $this->assertSame(1, (int) $response->json('data.stats.active_count'));
    }

    public function test_is_active_filter_accepts_zero_string(): void
    {
        $active = Account::query()->create([
            'name' => 'Active Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 100,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
        ]);

        Account::query()->create([
            'name' => 'Inactive Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 200,
            'currency' => 'EGP',
            'is_active' => false,
            'owner_type' => 'office',
            'module_type' => 'general',
        ]);

        $response = $this->getJson('/api/v1/finance/accounts?is_active=0&per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($active->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_accounts_stats_performance_uses_clearing_revenue(): void
    {
        $treasury = Account::query()->create([
            'name' => 'Stats Treasury',
            'type' => AccountType::Cashbox,
            'balance' => 10_000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'fawry',
        ]);

        $clearing = app(LedgerClearingAccounts::class);
        $incomeId = $clearing->incomeContraIdForModule('fawry');
        $this->assertNotNull($incomeId);

        Transaction::query()->create([
            'type' => 'transfer',
            'amount' => 1800,
            'module' => 'fawry',
            'from_account_id' => $incomeId,
            'to_account_id' => $treasury->id,
            'created_by' => $this->user->id,
            'notes' => 'بيع فوري',
        ]);

        $response = $this->getJson('/api/v1/finance/accounts?per_page=100');

        $response->assertOk();
        $this->assertEquals(1800.0, (float) $response->json('data.stats.performance.fawry.income'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_search_matches_wallet_number(): void
    {
        $account = Account::query()->create([
            'name' => 'Vodafone Wallet',
            'type' => AccountType::Wallet,
            'balance' => 300,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'wallet_transfer',
            'wallet_number' => '01099887766',
        ]);

        $response = $this->getJson('/api/v1/finance/accounts?search=01099887766&per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($account->id, $ids);
    }
}
