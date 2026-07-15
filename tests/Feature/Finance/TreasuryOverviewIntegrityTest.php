<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Services\Finance\TreasuryService;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TreasuryOverviewIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_stats_match_account_sums_per_category(): void
    {
        $this->seedAccounts();

        $overview = app(TreasuryService::class)->getTreasuryOverview();
        $stats = $overview['stats']['by_category'];

        $this->assertCategoryStatsMatchAccounts('tourism', $stats['tourism']);
        $this->assertCategoryStatsMatchAccounts('office', $stats['office']);

        $this->assertUnifiedMatchesStats($overview['unified_by_category']['tourism'], $stats['tourism']);
        $this->assertUnifiedMatchesStats($overview['unified_by_category']['office'], $stats['office']);

        $this->assertModulesMatchCategory($overview['modules'], $stats);
    }

    public function test_overview_stats_handle_multi_currency_correctly(): void
    {
        $user = User::query()->create([
            'name' => 'Exchange Rate Creator',
            'email' => 'rate-creator@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        \Illuminate\Support\Facades\DB::table('exchange_rates')->insert([
            'from_currency' => 'KWD',
            'to_currency' => 'EGP',
            'rate' => 160.0,
            'effective_date' => now()->toDateString(),
            'is_active' => true,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Create a bank account in KWD (Dinar)
        Account::query()->create([
            'name' => 'بنك بالدينار',
            'type' => AccountType::Bank,
            'balance' => 100, // 100 Dinar
            'currency' => 'KWD',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
        ]);

        // 2. Create a bank account in EGP
        Account::query()->create([
            'name' => 'بنك بالجنيه',
            'type' => AccountType::Bank,
            'balance' => 100000, // 100,000 EGP
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
        ]);

        // 3. Call service
        $overview = app(TreasuryService::class)->getTreasuryOverview();
        $stats = $overview['stats']['by_category']['tourism'];

        // KWD exchange rate is 160.0. So 100 * 160 = 16,000 EGP.
        // Total liquidity should be 100,000 + 16,000 = 116,000 EGP.
        $this->assertEquals(116000.0, (float) $stats['total_liquidity']);
        $this->assertEquals(116000.0, (float) $stats['total_banks']);
    }

    public function test_api_overview_passes_integrity_checks(): void
    {
        $user = User::query()->create([
            'name' => 'Integrity Tester',
            'email' => 'treasury-integrity@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->seedAccounts();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finance/treasuries/get-overview');

        $response->assertOk();

        $tourism = $response->json('data.stats.by_category.tourism');
        $this->assertSame(2300.0, (float) $tourism['total_liquidity']);
        $this->assertSame(1800.0, (float) $tourism['total_banks']);
        $this->assertSame(500.0, (float) $tourism['total_cashbox']);

        $office = $response->json('data.stats.by_category.office');
        $this->assertSame(350.0, (float) $office['total_liquidity']);
        $this->assertSame(150.0, (float) $office['total_wallets']);
        $this->assertSame(200.0, (float) $office['total_cashbox']);
    }

    private function seedAccounts(): void
    {
        $rows = [
            ['name' => 'بنك مصر — طيران', 'type' => AccountType::Bank, 'balance' => 1000, 'module_type' => 'flights'],
            ['name' => 'بنك مصر — حج', 'type' => AccountType::Bank, 'balance' => 500, 'module_type' => 'hajj_umra'],
            ['name' => 'بنك مصر — تأشيرات', 'type' => AccountType::Bank, 'balance' => 300, 'module_type' => 'visas'],
            ['name' => 'نقدي طيران', 'type' => AccountType::Cashbox, 'balance' => 500, 'module_type' => 'flights'],
            ['name' => 'فودافون كاش باص', 'type' => AccountType::Wallet, 'balance' => 150, 'module_type' => 'bus', 'wallet_provider' => 'vodafone_cash'],
            ['name' => 'خزينة باص', 'type' => AccountType::Cashbox, 'balance' => 200, 'module_type' => 'bus'],
            ['name' => 'بريد فوري', 'type' => AccountType::Bank, 'balance' => 0, 'module_type' => 'fawry'],
        ];

        foreach ($rows as $row) {
            Account::query()->create([
                'name' => $row['name'],
                'type' => $row['type'],
                'balance' => $row['balance'],
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => $row['module_type'],
                'wallet_provider' => $row['wallet_provider'] ?? null,
            ]);
        }
    }

  /**
     * @param  array<string, mixed>  $categoryStats
     */
    private function assertCategoryStatsMatchAccounts(string $category, array $categoryStats): void
    {
        $accounts = Account::query()
            ->tap(fn ($q) => AccountModuleDivision::applyLiquidityTreasuryScope($q))
            ->active()
            ->get()
            ->filter(function (Account $account) use ($category) {
                $moduleKey = AccountModuleDivision::resolveModuleTypeKey($account->module_type, $account->module);

                return AccountModuleDivision::divisionForModuleType($moduleKey) === $category;
            });

        $expectedLiquidity = (float) $accounts->sum('balance');
        $this->assertSame($expectedLiquidity, (float) $categoryStats['total_liquidity'], "liquidity mismatch for {$category}");
        $this->assertSame($accounts->count(), (int) $categoryStats['accounts_count'], "count mismatch for {$category}");

        $typeSum = (float) $categoryStats['total_banks']
            + (float) $categoryStats['total_cashbox']
            + (float) $categoryStats['total_wallets']
            + (float) $categoryStats['total_post']
            + (float) $categoryStats['total_treasury'];

        $this->assertSame($expectedLiquidity, $typeSum, "type breakdown mismatch for {$category}");
    }

    /**
     * @param  array<int, array<string, mixed>>  $unified
     * @param  array<string, mixed>  $categoryStats
     */
    private function assertUnifiedMatchesStats(array $unified, array $categoryStats): void
    {
        $unifiedTotal = array_reduce(
            $unified,
            fn (float $carry, array $group): float => $carry + (float) $group['total_balance'],
            0.0
        );

        $this->assertSame((float) $categoryStats['total_liquidity'], $unifiedTotal);

        foreach ($unified as $group) {
            $moduleSum = array_reduce(
                $group['modules'],
                fn (float $carry, array $mod): float => $carry + (float) $mod['balance'],
                0.0
            );
            $this->assertSame((float) $group['total_balance'], $moduleSum, 'group module sum mismatch');

            $accountSum = 0.0;
            foreach ($group['modules'] as $mod) {
                foreach ($mod['accounts'] as $acc) {
                    $accountSum += (float) $acc['balance'];
                }
            }
            $this->assertSame((float) $group['total_balance'], $accountSum, 'group account sum mismatch');
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $modules
     * @param  array<string, array<string, mixed>>  $stats
     */
    private function assertModulesMatchCategory(array $modules, array $stats): void
    {
        foreach (['office', 'tourism'] as $category) {
            $moduleTotal = 0.0;
            foreach ($modules as $mod) {
                if (($mod['category'] ?? '') !== $category) {
                    continue;
                }
                foreach ($mod['accounts'] as $acc) {
                    $moduleTotal += (float) $acc['balance'];
                }
            }
            $this->assertSame((float) $stats[$category]['total_liquidity'], $moduleTotal);
        }
    }
}
