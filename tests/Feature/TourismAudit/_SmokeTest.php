<?php

namespace Tests\Feature\TourismAudit;

use App\Models\Account;
use Tests\Feature\TourismAudit\TourismAuditTestCase;

/**
 * Smoke test to verify TourismAuditTestCase compiles and works.
 */
class _SmokeTest extends TourismAuditTestCase
{
    public function test_vaults_are_seeded(): void
    {
        $this->assertNotNull($this->vaultEgp);
        $this->assertNotNull($this->vaultUsd);
        $this->assertNotNull($this->vaultSar);
        $this->assertNotNull($this->bankEgp);
        $this->assertNotNull($this->walletEgp);
        $this->assertEquals(1_000_000.0, round((float) $this->vaultEgp->fresh()->balance, 2));
    }

    public function test_ledger_globally_balanced_after_seed(): void
    {
        $verified = $this->assertLedgerGloballyBalanced();
        $this->assertGreaterThan(0, $verified);
    }

    public function test_independent_tourism_pnl_query_runs(): void
    {
        $result = $this->calculateTourismPnLIndependent();
        $this->assertArrayHasKey('income', $result);
        $this->assertArrayHasKey('expense', $result);
        $this->assertArrayHasKey('profit', $result);
    }
}
