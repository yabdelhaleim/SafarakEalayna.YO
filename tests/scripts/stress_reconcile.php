<?php

declare(strict_types=1);

/**
 * stress_reconcile.php
 *
 * Phase 25.17/25.20/25.22 — Final reconciliation runner.
 *
 * Computes 10 independent checks against the active stress DB:
 *   1. Per-account: balance == SUM(credit) - SUM(debit)
 *   2. Per-transaction: SUM(debit) == SUM(credit)
 *   3. Orphan AccountEntry rows
 *   4. Orphan Transaction rows
 *   5. Duplicate income keys (related_type + related_id)
 *   6. Reversal integrity (sum, originals count)
 *   7. Global totals balance (credits - debits - balance_sum = 0)
 *   8. FK integrity on common relations
 *   9. Unexpected soft deletes on active bookings
 *   10. (reserved for future expansion)
 *
 * Verdict = PASS only if ALL checks pass.
 *
 * Usage:
 *   php tests/scripts/stress_reconcile.php
 *
 * Output:
 *   - storage/app/stress/phase-FINAL-reconciliation.json (machine-readable)
 *   - storage/app/stress/phase-FINAL-reconciliation.md   (human-readable)
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Tests\Stress\Support\StressReconciliation;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

try {
    StressSafetyGuard::assertSafeEnvironment(null);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "\n🛑  SAFETY ABORT 🛑\n".$e->getMessage()."\n\n");
    exit(2);
}

fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");
fwrite(STDOUT, "  Phase 25 — Final reconciliation\n");
fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");

$report = StressReconciliation::runAll();

fwrite(STDOUT, "\n────────── 1. Per-account  ──────────\n");
fwrite(STDOUT, sprintf("  checked: %d, failed: %d, max_variance: %s\n",
    $report['per_account']['checked'],
    $report['per_account']['failed'],
    $report['per_account']['max_variance']
));

fwrite(STDOUT, "\n────────── 2. Per-transaction  ──────────\n");
fwrite(STDOUT, sprintf("  checked: %d, failed: %d\n",
    $report['per_transaction']['checked'],
    $report['per_transaction']['failed']
));

fwrite(STDOUT, "\n────────── 3. Orphan AccountEntry rows  ──────────\n");
fwrite(STDOUT, sprintf("  count: %d\n", $report['orphan_entries']['count']));

fwrite(STDOUT, "\n────────── 4. Orphan Transaction rows  ──────────\n");
fwrite(STDOUT, sprintf("  count: %d\n", $report['orphan_transactions']['count']));

fwrite(STDOUT, "\n────────── 5. Duplicate income keys  ──────────\n");
fwrite(STDOUT, sprintf("  count: %d\n", $report['duplicate_income']['count']));

fwrite(STDOUT, "\n────────── 6. Reversals  ──────────\n");
fwrite(STDOUT, sprintf("  originals: %d, reversals: %d, net_impact_egp: %s\n",
    $report['reversals']['originals'],
    $report['reversals']['reversals'],
    $report['reversals']['net_impact_egp']
));

fwrite(STDOUT, "\n────────── 7. Global totals  ──────────\n");
fwrite(STDOUT, sprintf("  credits: %s, debits: %s, balance_sum: %s, diff: %s\n",
    $report['totals']['credits'],
    $report['totals']['debits'],
    $report['totals']['balance_sum'],
    $report['totals']['diff']
));

fwrite(STDOUT, "\n────────── 8. FK integrity  ──────────\n");
fwrite(STDOUT, sprintf("  broken: %d\n", $report['fk_integrity']['broken']));

fwrite(STDOUT, "\n────────── 9. Unexpected soft deletes  ──────────\n");
fwrite(STDOUT, sprintf("  issues: %d\n", count($report['soft_deletes'])));

fwrite(STDOUT, "\n═══════════════════════════════════════════════════════════\n");
fwrite(STDOUT, "  VERDICT: {$report['verdict']}\n");
fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");

// Persist artifacts
$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
file_put_contents(
    $dir.'/phase-FINAL-reconciliation.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// Markdown summary
$md = "# Phase 25 — Final Reconciliation\n\n";
$md .= "**Tier**: {$report['tier']}  \n";
$md .= "**Tolerance**: {$report['tolerance']}  \n";
$md .= "**Ran at**: {$report['ran_at']}  \n\n";
$md .= "## Verdict\n\n**{$report['verdict']}**\n\n";
$md .= "## Results\n\n";
$md .= "| Check | Result |\n|---|---|\n";
$md .= "| 1. Per-account balance vs entries | {$report['per_account']['failed']} failures / {$report['per_account']['checked']} checked |\n";
$md .= "| 2. Per-transaction debit == credit | {$report['per_transaction']['failed']} failures / {$report['per_transaction']['checked']} checked |\n";
$md .= "| 3. Orphan AccountEntry rows | {$report['orphan_entries']['count']} |\n";
$md .= "| 4. Orphan Transaction rows | {$report['orphan_transactions']['count']} |\n";
$md .= "| 5. Duplicate income keys | {$report['duplicate_income']['count']} |\n";
$md .= "| 6. Reversal net impact (EGP) | {$report['reversals']['net_impact_egp']} |\n";
$md .= "| 7. Global credits - debits - balance_sum | {$report['totals']['diff']} |\n";
$md .= "| 8. FK integrity broken | {$report['fk_integrity']['broken']} |\n";
$md .= "| 9. Unexpected soft deletes | ".count($report['soft_deletes'])." |\n";
file_put_contents($dir.'/phase-FINAL-reconciliation.md', $md);

exit($report['verdict'] === 'PASS' ? 0 : 1);
