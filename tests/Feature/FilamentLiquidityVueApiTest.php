<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ensures accounts created like Filament (same accounts table) appear in Vue API endpoints.
 */
class FilamentLiquidityVueApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Filament Sync Tester',
            'email' => 'filament-sync@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);
    }

    public function test_finance_accounts_index_returns_filament_style_liquidity_accounts(): void
    {
        $bank = $this->createLiquidityAccount([
            'name' => 'البنك الأهلي — فوري',
            'type' => AccountType::Bank,
            'module_type' => 'fawry',
            'module' => 'fawry',
        ]);

        $wallet = $this->createLiquidityAccount([
            'name' => 'فودافون كاش — فوري',
            'type' => AccountType::Wallet,
            'module_type' => 'fawry',
            'module' => 'fawry',
            'wallet_provider' => WalletProvider::VodafoneCash,
            'wallet_number' => '01012345678',
        ]);

        $response = $this->getJson('/api/v1/finance/accounts?per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($bank->id, $ids);
        $this->assertContains($wallet->id, $ids);
        $this->assertSame('bank', collect($response->json('data.items'))->firstWhere('id', $bank->id)['type']);
    }

    public function test_fawry_treasury_overview_groups_wallets_and_banks_for_vue(): void
    {
        $bank = $this->createLiquidityAccount([
            'name' => 'بنك فوري',
            'type' => AccountType::Bank,
            'module_type' => 'fawry',
            'module' => 'fawry',
        ]);

        $wallet = $this->createLiquidityAccount([
            'name' => 'محفظة فوري',
            'type' => AccountType::Wallet,
            'module_type' => 'fawry',
            'module' => 'fawry',
            'wallet_provider' => WalletProvider::VodafoneCash,
            'wallet_number' => '01099998888',
        ]);

        $response = $this->getJson('/api/v1/fawry/treasury/overview');

        $response->assertOk();
        $bankIds = collect($response->json('data.banks'))->pluck('id')->all();
        $walletIds = collect($response->json('data.wallets'))->pluck('id')->all();

        $this->assertContains($bank->id, $bankIds);
        $this->assertContains($wallet->id, $walletIds);
        $this->assertNotEmpty($response->json('data.accounts'));
    }

    public function test_bus_treasury_overview_returns_settlement_accounts_for_vue(): void
    {
        $cashbox = $this->createLiquidityAccount([
            'name' => 'خزينة باصات',
            'type' => AccountType::Cashbox,
            'module_type' => 'bus',
            'module' => 'bus',
        ]);

        $response = $this->getJson('/api/v1/bus/treasury/overview');

        $response->assertOk();
        $ids = collect($response->json('data.settlement_accounts'))->pluck('id')->all();
        $this->assertContains($cashbox->id, $ids);
    }

    public function test_flight_treasury_overview_returns_settlement_accounts_for_vue(): void
    {
        $wallet = $this->createLiquidityAccount([
            'name' => 'محفظة طيران',
            'type' => AccountType::Wallet,
            'module_type' => 'flights',
            'module' => 'flight',
            'wallet_provider' => WalletProvider::VodafoneCash,
            'wallet_number' => '01055554444',
        ]);

        $response = $this->getJson('/api/v1/flight/treasury/overview');

        $response->assertOk();
        $ids = collect($response->json('data.settlement_accounts'))->pluck('id')->all();
        $this->assertContains($wallet->id, $ids);
    }

    public function test_wallet_transfer_treasury_overview_groups_accounts(): void
    {
        $wallet = $this->createLiquidityAccount([
            'name' => 'محفظة تحويل',
            'type' => AccountType::Wallet,
            'module_type' => 'wallet_transfer',
            'module' => 'wallet_transfer',
            'wallet_provider' => WalletProvider::Instapay,
            'wallet_number' => '01077776666',
        ]);

        $response = $this->getJson('/api/v1/wallet/treasury/overview');

        $response->assertOk();
        $walletIds = collect($response->json('data.wallets'))->pluck('id')->all();
        $this->assertContains($wallet->id, $walletIds);
    }

    public function test_online_settings_bundle_includes_module_accounts(): void
    {
        $bank = $this->createLiquidityAccount([
            'name' => 'بنك أونلاين',
            'type' => AccountType::Bank,
            'module_type' => 'online',
            'module' => 'online',
        ]);

        $response = $this->getJson('/api/v1/online/settings/all');

        $response->assertOk();
        $ids = collect($response->json('data.accounts'))->pluck('id')->all();
        $this->assertContains($bank->id, $ids);
    }

    public function test_hajj_umra_treasury_overview_returns_filament_accounts(): void
    {
        $bank = $this->createLiquidityAccount([
            'name' => 'Hajj Bank',
            'type' => AccountType::Bank,
            'module_type' => 'hajj_umra',
            'module' => 'hajj_umra',
        ]);

        $response = $this->getJson('/api/v1/hajj-umra/treasury/overview');

        $response->assertOk();
        $ids = collect($response->json('data.settlement_accounts'))->pluck('id')->all();
        $this->assertContains($bank->id, $ids);
    }

    public function test_visa_treasury_overview_returns_filament_accounts(): void
    {
        $wallet = $this->createLiquidityAccount([
            'name' => 'Visa Wallet',
            'type' => AccountType::Wallet,
            'module_type' => 'visas',
            'module' => 'visas',
            'wallet_number' => '01088887777',
        ]);

        $response = $this->getJson('/api/v1/visa/treasury/overview');

        $response->assertOk();
        $ids = collect($response->json('data.settlement_accounts'))->pluck('id')->all();
        $this->assertContains($wallet->id, $ids);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLiquidityAccount(array $overrides): Account
    {
        return Account::query()->create(array_merge([
            'name' => 'Test Account',
            'type' => AccountType::Cashbox,
            'balance' => 500,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
            'module' => 'general',
            'created_by' => $this->admin->id,
        ], $overrides));
    }
}
