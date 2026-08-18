<?php
/**
 * Tourism — Full Real-World E2E + Financial Stress Test
 * Orchestrator entry point.
 *
 * Audit ID:    TOURISM_FULL_E2E_AUDIT_20260818
 * Scope:       Flight + Hajj/Umra + Visa only. Bus is strictly out of scope.
 * Mode:        Report-only (no code fixes).
 * Database:    Local MySQL `safarakealayna` (APP_ENV=local).
 *
 * Run:  php tourism_full_e2e_audit_20260818.php
 *
 * Output:
 *   tests/reports/TOURISM_FULL_E2E_AUDIT_20260818.md
 *   tests/reports/TOURISM_FULL_E2E_AUDIT_20260818.json
 */

// ── Bootstrap Laravel ─────────────────────────────────────────────────────
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// ── Audit globals ─────────────────────────────────────────────────────────
$auditStartedAt = microtime(true);
$auditStartDate = date('c');
$prefix = 'TOURISM_FULL_AUDIT_20260818_';

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║  Tourism Full E2E + Financial Stress Test                          ║\n";
echo "║  Audit ID: TOURISM_FULL_E2E_AUDIT_20260818                        ║\n";
echo "║  Scope: Flight + Hajj/Umra + Visa (Bus out of scope)               ║\n";
echo "║  Mode:  Report-only                                                ║\n";
echo "║  DB:    Local MySQL safarakealayna (APP_ENV=local)                 ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

set_time_limit(0);
ini_set('memory_limit', '1024M');

// ── Load helpers ──────────────────────────────────────────────────────────
$baseDir = __DIR__;
require $baseDir . '/audit_helpers/Finding.php';
require $baseDir . '/audit_helpers/PhaseResult.php';
require $baseDir . '/audit_helpers/AuditContext.php';
require $baseDir . '/audit_helpers/AuditReconciliation.php';
require $baseDir . '/audit_helpers/AuditHttp.php';
require $baseDir . '/audit_helpers/ReportWriter.php';

// ── Register audit autoload for phase classes ─────────────────────────────
spl_autoload_register(function ($class) use ($baseDir) {
    if (str_starts_with($class, 'AuditPhases\\')) {
        $short = substr($class, strlen('AuditPhases\\'));
        $path  = $baseDir . '/audit_phases/' . $short . '.php';
        if (file_exists($path)) require $path;
    }
});

// ── Initialize context + report ───────────────────────────────────────────
$ctx = new \AuditHelpers\AuditContext();
$ctx->setPrefix($prefix);
$recon = new \AuditHelpers\AuditReconciliation();
$http = new \AuditHelpers\AuditHttp();

$reportDir = $baseDir . '/tests/reports';
$writer = new \AuditHelpers\ReportWriter($reportDir);
$writer->phases = [];   // will append as phases complete

// ── Build phase registry ─────────────────────────────────────────────────
// Key = phase alias. Value = constructor arg array (no class name).
$phaseFiles = [
    'Phase0_Safety'                  => [$ctx, $recon, $http],
    'Phase1_Inventory'               => [$ctx, $recon, $http],
    'Phase2_EmployeeJourney'         => [$ctx, $recon, $http],
    'Phase3_AdminJourney'            => [$ctx, $recon, $http],
    'Phase4_PaymentMatrix'           => [$ctx, $recon, $http],
    'Phase5_Debt'                    => [$ctx, $recon, $http],
    'Phase6_InvalidPayment'          => [$ctx, $recon, $http],
    'Phase7_Refund'                  => [$ctx, $recon, $http],
    'Phase8_RefundAttack'            => [$ctx, $recon, $http],
    'Phase9_Cancellation'            => [$ctx, $recon, $http],
    'Phase10_SoftDelete'             => [$ctx, $recon, $http],
    'Phase11_PostSaveEditLock'       => [$ctx, $recon, $http],
    'Phase12_PreCompletionEdit'      => [$ctx, $recon, $http],
    'Phase13_Concurrency'            => [$ctx, $recon, $http],
    'Phase14_CrossModuleAttack'      => [$ctx, $recon, $http],
    'Phase15_Reconciliation'         => [$ctx, $recon, $http],
    'Phase16_Profit'                 => [$ctx, $recon, $http],
    'Phase17_TransactionDuplication' => [$ctx, $recon, $http],
    'Phase18_ReportConsistency'      => [$ctx, $recon, $http],
    'Phase19_FailureInjection'       => [$ctx, $recon, $http],
    'Phase20_FinalVerdict'           => [$ctx, $recon, $http, $writer],
];

// ── Run phases in order ───────────────────────────────────────────────────
$allResults = [];
$phase0Ok = false;

foreach ($phaseFiles as $alias => $args) {
    $className = 'AuditPhases\\' . $alias;
    if (!class_exists($className)) {
        echo "[!!] Phase class missing: {$className}\n";
        $r = new \AuditHelpers\PhaseResult('PHASE MISSING: ' . $alias);
        $r->fatalError = "Class not found";
        $writer->addPhase($r);
        $allResults[] = $r;
        continue;
    }

    // Phase 0 (safety) is special — must pass before any other phase runs.
    $isSafety = ($alias === 'Phase0_Safety');
    $isVerdict = ($alias === 'Phase20_FinalVerdict');

    $phaseInstance = new $className(...$args);
    $phaseLabel = $phaseInstance->phaseLabel ?? $alias;

    echo "\n────── {$phaseLabel} ──────\n";
    $r = $phaseInstance->run();

    $writer->addPhase($r);
    $allResults[] = $r;

    $counts = sprintf('exec=%d pass=%d fail=%d block=%d skip=%d',
        $r->testsExecuted, $r->testsPassed, $r->testsFailed, $r->testsBlocked, $r->testsSkipped);
    echo "  → {$counts}";

    if ($r->hasFatalError()) {
        echo "  FATAL: " . substr($r->fatalError, 0, 80);
    }
    echo "\n";

    if ($isSafety) {
        // Phase 0 distinguishes between FATAL env issues (no DB, wrong
        // env, missing classes) and AUDIT FINDINGS (missing prerequisites
        // such as tourism vault). Only fatal errors should abort immediately;
        // findings are recorded and the audit proceeds with all phases.
        $phase0Fatal = $r->hasFatalError();
        if ($phase0Fatal) {
            echo "\n!!! PHASE 0 (SAFETY) FATAL — aborting audit. !!!\n";
            echo "Reason: " . ($r->fatalError ?? 'safety fatal') . "\n";

            // Write what we have so far, cleanup, exit
            $writer->writeAll();
            echo "\nReport (partial) written:\n  - {$writer->mdPath}\n  - {$writer->jsonPath}\n";

            // Attempt cleanup
            try { $ctx->cleanup(); } catch (\Throwable $e) {}
            echo "\nCleanup attempted. Audit aborted.\n";
            exit(1);
        }
        // Phase 0 audit findings (test failures) are NOT fatal — continue
        // running all phases. Financial phases will use AuditContext::resolveCashbox()
        // which falls back to existing office cashboxes (WL_CASH_EGP) when
        // the canonical tourism vault is missing. This is documented in the
        // report as a separate finding.
    }

    // All phases run. Phase 20 computes the final verdict from finding counts.
}

// ── Cleanup ───────────────────────────────────────────────────────────────
echo "\n────── Cleanup ──────\n";
try {
    $cleaned = $ctx->cleanup();
    echo "Cleanup summary:\n";
    foreach ($cleaned as $table => $count) {
        echo "  - {$table}: {$count} row(s) deleted\n";
    }
} catch (\Throwable $e) {
    echo "Cleanup error (non-fatal): " . $e->getMessage() . "\n";
}

// ── Write final report ────────────────────────────────────────────────────
$paths = $writer->writeAll();
$totalElapsed = microtime(true) - $auditStartedAt;

echo "\n══════ FINAL REPORT ══════\n";
echo "Markdown: {$paths['md']}\n";
echo "JSON:     {$paths['json']}\n";
echo "Elapsed:  " . round($totalElapsed, 1) . "s\n";
echo "\n";

// Compact console summary
$counts = ['executed' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0, 'skipped' => 0];
foreach ($allResults as $r) {
    $counts['executed'] += $r->testsExecuted;
    $counts['passed']   += $r->testsPassed;
    $counts['failed']   += $r->testsFailed;
    $counts['blocked']  += $r->testsBlocked;
    $counts['skipped']  += $r->testsSkipped;
}

$verdict = $counts['failed'] === 0 ? 'GO ✅' : 'NO-GO ❌';
echo "Verdict:    {$verdict}\n";
echo "Executed:   {$counts['executed']}\n";
echo "Passed:     {$counts['passed']}\n";
echo "Failed:     {$counts['failed']}\n";
echo "Blocked:    {$counts['blocked']}\n";
echo "Skipped:    {$counts['skipped']}\n";
echo "\n";
