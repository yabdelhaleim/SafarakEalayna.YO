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
            'module_type' => 'tourism',
            'module' => 'flights',
        ]);

        Account::query()->create([
            'name' => 'Bus Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 500,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
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
            'module_type' => 'office',
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
            'module_type' => 'office',
        ]);

        Account::query()->create([
            'name' => 'Inactive Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 200,
            'currency' => 'EGP',
            'is_active' => false,
            'owner_type' => 'office',
            'module_type' => 'office',
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
            'module_type' => 'office',
            'module' => 'fawry',
        ]);

        $fawryClearingName = config('accounting.clearing.income.fawry', 'إقفال إيرادات فوري');
        $fawryClearing = Account::query()->create([
            'name' => $fawryClearingName,
            'type' => AccountType::Cashbox,
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'module' => 'fawry',
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

    public function test_module_filter_flight_alias_includes_flights_module_type_from_filament(): void
    {
        $bank = Account::query()->create([
            'name' => 'CIB Flight Bank',
            'type' => AccountType::Bank,
            'balance' => 5000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'flights',
        ]);

        Account::query()->create([
            'name' => 'Bus Bank',
            'type' => AccountType::Bank,
            'balance' => 100,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'module' => 'bus',
        ]);

        $response = $this->getJson('/api/v1/finance/accounts?module=flight&types=bank&per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($bank->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_module_filter_visa_alias_includes_visas_module_type_from_filament(): void
    {
        $wallet = Account::query()->create([
            'name' => 'Visa Vodafone',
            'type' => AccountType::Wallet,
            'balance' => 800,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'visas',
            'wallet_number' => '01011112222',
        ]);

        $response = $this->getJson('/api/v1/finance/accounts?module=visa&types=wallet&per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($wallet->id, $ids);
    }

    public function test_module_filter_hajj_alias_includes_hajj_umra_module_type_from_filament(): void
    {
        $cashbox = Account::query()->create([
            'name' => 'Hajj Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 1200,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
        ]);

        $response = $this->getJson('/api/v1/finance/accounts?module=hajj&types=cashbox&per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($cashbox->id, $ids);
    }

    public function test_module_filter_wallet_alias_includes_wallet_transfer_module_type_from_filament(): void
    {
        $wallet = Account::query()->create([
            'name' => 'Instapay Transfer',
            'type' => AccountType::Wallet,
            'balance' => 900,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'module' => 'wallet_transfer',
            'wallet_number' => '01033334444',
        ]);

        $response = $this->getJson('/api/v1/finance/accounts?module=wallet&types=wallet&per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($wallet->id, $ids);
    }

    public function test_module_filter_bus_fawry_and_online_match_filament_module_types(): void
    {
        $busBank = Account::query()->create([
            'name' => 'Bus Bank',
            'type' => AccountType::Bank,
            'balance' => 300,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'module' => 'bus',
        ]);

        $fawryWallet = Account::query()->create([
            'name' => 'Fawry Wallet',
            'type' => AccountType::Wallet,
            'balance' => 400,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'module' => 'fawry',
            'wallet_number' => '01055556666',
        ]);

        $onlineBank = Account::query()->create([
            'name' => 'Online Bank',
            'type' => AccountType::Bank,
            'balance' => 600,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'module' => 'online',
        ]);

        $busResponse = $this->getJson('/api/v1/finance/accounts?module=bus&types=bank&per_page=100');
        $busResponse->assertOk();
        $this->assertContains($busBank->id, collect($busResponse->json('data.items'))->pluck('id')->all());

        $fawryResponse = $this->getJson('/api/v1/finance/accounts?module=fawry&types=wallet&per_page=100');
        $fawryResponse->assertOk();
        $this->assertContains($fawryWallet->id, collect($fawryResponse->json('data.items'))->pluck('id')->all());

        $onlineResponse = $this->getJson('/api/v1/finance/accounts?module=online&types=bank&per_page=100');
        $onlineResponse->assertOk();
        $this->assertContains($onlineBank->id, collect($onlineResponse->json('data.items'))->pluck('id')->all());
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
            'module_type' => 'office',
            'module' => 'wallet_transfer',
            'wallet_number' => '01099887766',
        ]);

        $response = $this->getJson('/api/v1/finance/accounts?search=01099887766&per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($account->id, $ids);
    }

    public function test_accounts_index_stats_are_filtered_by_module_type(): void
    {
        Account::query()->create([
            'name' => 'Tourism Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 2000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'flights',
        ]);

        Account::query()->create([
            'name' => 'Office Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 3000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'module' => 'bus',
        ]);

        // Unfiltered stats
        $responseAll = $this->getJson('/api/v1/finance/accounts?per_page=100');
        $responseAll->assertOk();
        $this->assertEquals(5000.0, (float) $responseAll->json('data.stats.total_balance'));

        // Tourism filtered stats
        $responseTourism = $this->getJson('/api/v1/finance/accounts?module_type=tourism&per_page=100');
        $responseTourism->assertOk();
        $this->assertEquals(2000.0, (float) $responseTourism->json('data.stats.total_balance'));

        // Office filtered stats
        $responseOffice = $this->getJson('/api/v1/finance/accounts?module_type=office&per_page=100');
        $responseOffice->assertOk();
        $this->assertEquals(3000.0, (float) $responseOffice->json('data.stats.total_balance'));
    }
}
