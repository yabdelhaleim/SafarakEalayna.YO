<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Services\Finance\LedgerReconciliationService;
use App\Services\Finance\LedgerRepairService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LedgerOpeningBalanceRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_entries_are_excluded_from_transaction_balance_checks(): void
    {
        $account = $this->createLiquidityAccount('Opening Balance', 1000);

        AccountEntry::query()->create([
            'account_id' => $account->id,
            'transaction_id' => null,
            'debit' => 0,
            'credit' => 1000,
            'balance_after' => 1000,
            'notes' => 'رصيد افتتاحي',
        ]);

        $service = app(LedgerReconciliationService::class);
        $run = $service->runDaily();
        $scan = $service->runPostingAndBalanceIntegrityScan();

        $this->assertSame(0, $run->imbalanced_count);
        $this->assertCount(0, $run->findings);
        $this->assertTrue($scan['global_totals_ok']);
        $this->assertSame(0.0, $scan['global_totals_delta']);
        $this->assertSame(0, $scan['accounts_with_balance_drift']);
    }

    public function test_backdated_opening_entry_uses_chronological_latest_balance(): void
    {
        $account = $this->createLiquidityAccount('Backdated Opening', 500);

        $movement = AccountEntry::query()->create([
            'account_id' => $account->id,
            'transaction_id' => null,
            'debit' => 0,
            'credit' => 100,
            'balance_after' => 500,
            'notes' => 'later movement',
        ]);
        $movement->timestamps = false;
        $movement->created_at = Carbon::parse('2026-08-01 10:00:00');
        $movement->updated_at = Carbon::parse('2026-08-01 10:00:00');
        $movement->saveQuietly();

        $opening = AccountEntry::query()->create([
            'account_id' => $account->id,
            'transaction_id' => null,
            'debit' => 0,
            'credit' => 400,
            'balance_after' => 400,
            'notes' => 'رصيد افتتاحي',
        ]);
        $opening->timestamps = false;
        $opening->created_at = Carbon::parse('2026-07-30 10:00:00');
        $opening->updated_at = Carbon::parse('2026-08-02 10:00:00');
        $opening->saveQuietly();

        $scan = app(LedgerReconciliationService::class)->runPostingAndBalanceIntegrityScan();

        $this->assertSame(0, $scan['accounts_with_balance_drift']);

        $this->artisan('accounts:sync-treasury-balances', [
            '--account' => $account->id,
            '--dry-run' => true,
        ])->expectsOutput('جميع حسابات السيولة متطابقة مع الدفتر.')
            ->assertSuccessful();
    }

    public function test_rebuild_orders_backdated_opening_entry_before_later_movements(): void
    {
        $account = $this->createLiquidityAccount('Chronological Rebuild', 0);

        $movement = AccountEntry::query()->create([
            'account_id' => $account->id,
            'transaction_id' => null,
            'debit' => 100,
            'credit' => 0,
            'balance_after' => -100,
            'notes' => 'later movement',
        ]);
        $movement->timestamps = false;
        $movement->created_at = Carbon::parse('2026-08-01 10:00:00');
        $movement->updated_at = Carbon::parse('2026-08-01 10:00:00');
        $movement->saveQuietly();

        $opening = AccountEntry::query()->create([
            'account_id' => $account->id,
            'transaction_id' => null,
            'debit' => 0,
            'credit' => 100,
            'balance_after' => 100,
            'notes' => 'رصيد افتتاحي',
        ]);
        $opening->timestamps = false;
        $opening->created_at = Carbon::parse('2026-07-30 10:00:00');
        $opening->updated_at = Carbon::parse('2026-08-02 10:00:00');
        $opening->saveQuietly();

        $result = app(LedgerRepairService::class)
            ->rebuildBrokenBalanceAfterChains([$account->id]);

        $this->assertSame(1, $result['accounts_fixed']);
        $this->assertSame(1, $result['entries_fixed']);
        $this->assertSame(100.0, (float) $opening->fresh()->balance_after);
        $this->assertSame(0.0, (float) $movement->fresh()->balance_after);
        $this->assertSame(0.0, (float) $account->fresh()->balance);
    }

    public function test_no_rebuild_option_does_not_mutate_balance_chain(): void
    {
        $account = $this->createLiquidityAccount('Read Only Reconcile', 100);

        $entry = AccountEntry::query()->create([
            'account_id' => $account->id,
            'transaction_id' => null,
            'debit' => 0,
            'credit' => 100,
            'balance_after' => 999,
            'notes' => 'رصيد افتتاحي',
        ]);

        $this->artisan('ledger:reconcile', ['--no-rebuild' => true])
            ->assertSuccessful();

        $this->assertSame(999.0, (float) $entry->fresh()->balance_after);
        $this->assertSame(100.0, (float) $account->fresh()->balance);
    }

    private function createLiquidityAccount(string $name, float $balance): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create([
            'name' => $name,
            'type' => AccountType::Cashbox,
            'balance' => $balance,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]));
    }
}
