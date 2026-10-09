<?php

declare(strict_types=1);

/**
 * stress_cli_workers.php
 *
 * Phase 25 — CLI workers parallel runner.
 * Bootstraps N parallel PHP processes that each call the Laravel service
 * layer directly (no HTTP). Used as a fallback or complement to curl_multi.
 *
 * Currently delegates to stress_concurrent_transfers.php — kept as a
 * dispatcher entry point so the plan's "Mode 2: direct-service parallel
 * PHP CLI" remains documented.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

try {
    StressSafetyGuard::assertSafeEnvironment(null);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "\n🛑  SAFETY ABORT 🛑\n".$e->getMessage()."\n\n");
    exit(2);
}

$workers = 25;
foreach ($argv as $arg) {
    if (preg_match('/^--workers=(\d+)$/', $arg, $m)) $workers = (int) $m[1];
}

// Delegate to the concurrent-transfers runner (the canonical direct-service
// worker is in stress_concurrent_transfers_worker.php which uses the
// TransactionService directly — no HTTP layer).
fwrite(STDOUT, "Delegating to stress_concurrent_transfers.php with --workers={$workers}\n");
passthru('php -d memory_limit=2G '.escapeshellarg(__DIR__.'/stress_concurrent_transfers.php').' --workers='.$workers);
exit(0);
