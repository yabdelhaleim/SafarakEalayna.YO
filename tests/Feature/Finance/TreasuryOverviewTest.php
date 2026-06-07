<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TreasuryOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Treasury Tester',
            'email' => 'treasury-overview-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_treasury_overview_excludes_clearing_accounts_and_groups_by_module_type(): void
    {
        Account::query()->create([
            'name' => 'إقفال مبيعات الطيران (نظام)',
            'type' => AccountType::Cashbox,
            'balance' => -1000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
        ]);

        $visaWallet = Account::query()->create([
            'name' => 'فودافون كاش فيزا',
            'type' => AccountType::Wallet,
            'balance' => 360,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'visas',
            'module' => 'general',
        ]);

        Account::query()->create([
            'name' => 'خزينة باص',
            'type' => AccountType::Cashbox,
            'balance' => 200,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
        ]);

        $response = $this->getJson('/api/v1/finance/treasuries/get-overview');

        $response->assertOk();
        $modules = $response->json('data.modules');

        $this->assertArrayHasKey('visas', $modules);
        $this->assertArrayHasKey('bus', $modules);
        $this->assertArrayNotHasKey('general', $modules);

        $visaIds = collect($modules['visas']['accounts'])->pluck('id')->all();
        $this->assertContains($visaWallet->id, $visaIds);
        $this->assertSame('tourism', $modules['visas']['category']);

        $this->assertSame(2, (int) $response->json('data.stats.accounts_count'));
        $this->assertSame(560.0, (float) $response->json('data.stats.total_liquidity'));
    }

    public function test_get_module_accounts_resolves_visa_aliases(): void
    {
        $account = Account::query()->create([
            'name' => 'محفظة تأشيرات',
            'type' => AccountType::Wallet,
            'balance' => 100,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'visas',
        ]);

        $response = $this->getJson('/api/v1/finance/treasuries/get-module-accounts/visas');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($account->id, $ids);
    }
}
