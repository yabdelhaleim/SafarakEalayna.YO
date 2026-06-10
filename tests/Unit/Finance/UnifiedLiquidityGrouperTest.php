<?php

namespace Tests\Unit\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\Finance\UnifiedLiquidityGrouper;
use Tests\TestCase;

class UnifiedLiquidityGrouperTest extends TestCase
{
    public function test_groups_same_bank_name_across_modules(): void
    {
        $accounts = collect([
            $this->makeAccount('بنك مصر — طيران', AccountType::Bank, 1000, 'flights'),
            $this->makeAccount('بنك مصر — حج', AccountType::Bank, 500, 'hajj_umra'),
            $this->makeAccount('بنك مصر — تأشيرات', AccountType::Bank, 300, 'visas'),
            $this->makeAccount('البنك الأهلي', AccountType::Bank, 200, 'flights'),
        ]);

        $groups = UnifiedLiquidityGrouper::group($accounts);

        $misr = collect($groups)->first(fn (array $g) => str_contains($g['display_name'], 'بنك مصر') || $g['total_balance'] === 1800.0);
        $this->assertNotNull($misr);
        $this->assertSame(1800.0, $misr['total_balance']);
        $this->assertCount(3, $misr['modules']);
        $this->assertSame(3, $misr['accounts_count']);
    }

    public function test_groups_cashbox_and_wallet_by_normalized_name(): void
    {
        $accounts = collect([
            $this->makeAccount('نقدي مصري — طيران', AccountType::Cashbox, 400, 'flights'),
            $this->makeAccount('نقدي مصري — حج', AccountType::Cashbox, 600, 'hajj_umra'),
            $this->makeAccount('فودافون كاش — طيران', AccountType::Wallet, 150, 'flights', walletProvider: 'vodafone_cash'),
            $this->makeAccount('فودافون كاش — تأشيرات', AccountType::Wallet, 50, 'visas', walletProvider: 'vodafone_cash'),
        ]);

        $groups = UnifiedLiquidityGrouper::group($accounts);

        $cash = collect($groups)->first(fn (array $g) => $g['type'] === 'cashbox');
        $this->assertNotNull($cash);
        $this->assertSame(1000.0, $cash['total_balance']);

        $wallet = collect($groups)->first(fn (array $g) => $g['type'] === 'wallet');
        $this->assertNotNull($wallet);
        $this->assertSame(200.0, $wallet['total_balance']);
    }

    private function makeAccount(
        string $name,
        AccountType $type,
        float $balance,
        string $moduleType,
        ?string $walletProvider = null,
    ): Account {
        $account = new Account([
            'name' => $name,
            'type' => $type,
            'balance' => $balance,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => $moduleType,
        ]);

        if ($walletProvider) {
            $account->wallet_provider = $walletProvider;
        }

        return $account;
    }
}
