<?php

declare(strict_types=1);

namespace Tests\Stress\Support;

use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Tests\Stress\FinanceStressTestCase;

/**
 * Smoke test — verifies that the foundation (safety guard + base class +
 * bulk factory + reconciliation) actually runs without errors against a
 * fresh SQLite :memory: DB. NOT part of the production audit; this is a
 * sanity check only.
 *
 * @internal
 */
final class SmokeTest extends FinanceStressTestCase
{
    public function test_safety_guard_runs_and_prints_banner(): void
    {
        // Banner was printed in setUp; assert baseline snapshot has tables.
        $this->assertGreaterThanOrEqual(0, $this->baselineSnapshot['counts']['accounts']);
        $this->assertSame(0, $this->baselineSnapshot['counts']['transactions']);
        $this->assertGreaterThan(0, $this->actorId);
        $this->assertSame(1, DB::table('users')->count(), 'Actor user must exist');
    }

    public function test_can_create_50_customers_via_factory(): void
    {
        StressBulkFactory::bulkCustomers(50, 'bus');
        $this->assertSame(50, Customer::count());
        $this->assertSame(50, Customer::where('module_type', 'bus')->count());
    }

    public function test_can_create_30_suppliers_via_bulk(): void
    {
        StressBulkFactory::bulkSuppliers(30, $this->actorId);
        $this->assertSame(30, Supplier::count());
        // codes must be unique
        $this->assertSame(30, DB::table('suppliers')->distinct()->count('code'));
    }

    public function test_can_create_20_liquidity_accounts_and_open_balances(): void
    {
        $accts = StressBulkFactory::bulkLiquidityAccounts(20, 'office');
        $this->assertCount(20, $accts);

        // Open balance on the first account (canonical double-entry pattern)
        StressBulkFactory::openBalance($accts[0], 50000.0, $this->actorId);
        $this->assertSame(50000.0, (float) $accts[0]->fresh()->balance);

        // Capital account must absorb the offset (legitimately negative).
        $capital = StressBulkFactory::openingCapitalAccount($this->actorId);
        $this->assertSame(-50000.0, (float) $capital->fresh()->balance);
    }

    public function test_reconciliation_passes_on_empty_db(): void
    {
        $report = StressReconciliation::runAll();
        $this->assertSame('PASS', $report['verdict']);
        $this->assertSame(0, $report['per_account']['failed']);
        $this->assertSame(0, $report['per_transaction']['failed']);
    }

    public function test_reconciliation_passes_after_bulk_seeding(): void
    {
        StressBulkFactory::bulkCustomers(100, 'bus');
        StressBulkFactory::bulkSuppliers(50, $this->actorId);
        $accts = StressBulkFactory::bulkLiquidityAccounts(10, 'office');
        foreach ($accts as $a) {
            StressBulkFactory::openBalance($a, 10000.0, $this->actorId);
        }

        // Add a few balanced transactions
        for ($i = 0; $i < 5; $i++) {
            StressBulkFactory::directBalancedTransaction();
        }

        $report = StressReconciliation::runAll();
        $this->writeArtifact('smoke', 'reconciliation', $report);
        if ($report['verdict'] !== 'PASS') {
            fwrite(STDOUT, "\n[smoke] reconciliation FAIL: ".json_encode($report, JSON_PRETTY_PRINT)."\n");
        }
        $this->assertSame('PASS', $report['verdict'], 'Reconciliation report: '.json_encode($report));
    }
}
