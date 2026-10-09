<?php

declare(strict_types=1);

/**
 * stress_safety_check.php
 *
 * Phase 25 — Pre-flight safety guard for the standalone stress scripts.
 *
 *   - Boots Laravel.
 *   - Forces APP_ENV=stress (unless --env= kept).
 *   - Prints STRESS DB / HOST / DATABASE / APP_ENV / PORT / PID banner.
 *   - Hard-aborts (exit 2) if:
 *       * DB_CONNECTION=mysql AND DB_DATABASE ∈ {safarakealayna, safarak_ealayna,
 *         travel_office, production, prod}
 *       * APP_ENV ∈ {production, prod, live}
 *
 * Usage:
 *   php tests/scripts/stress_safety_check.php [sqlite|mysql]
 *
 * Exit codes:
 *   0  environment is safe for stress runs
 *   2  environment is FORBIDDEN — abort before any DB work
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';

// Force stress env (unless caller already set APP_ENV via shell).
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Config;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

$tier = $argv[1] ?? null; // 'sqlite' | 'mysql' | null

// ── Banner
$cfg = Config::get('database.connections.'.Config::get('database.default'));
$connection = Config::get('database.default');
$host = is_array($cfg) ? ($cfg['host'] ?? null) : null;
if (empty($host)) {
    $host = $connection === 'sqlite'
        ? 'sqlite://'.(is_array($cfg) ? ($cfg['database'] ?? 'unknown') : 'unknown')
        : 'unknown';
}
$database = is_array($cfg) ? ($cfg['database'] ?? 'unknown') : 'unknown';
$appEnv = $app->environment();
$pid = (int) getmypid();

fwrite(STDOUT, "\n".str_repeat('=', 60)."\n");
fwrite(STDOUT, "STRESS DB:  {$connection}\n");
fwrite(STDOUT, "HOST:       {$host}\n");
fwrite(STDOUT, "DATABASE:   {$database}\n");
fwrite(STDOUT, "APP_ENV:    {$appEnv}\n");
fwrite(STDOUT, "PID:        {$pid}\n");
fwrite(STDOUT, "TIME:       ".date('Y-m-d H:i:s')."\n");
fwrite(STDOUT, str_repeat('=', 60)."\n");

// ── Hard guard
try {
    StressSafetyGuard::assertSafeEnvironment($tier);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "\n🛑  SAFETY ABORT 🛑\n");
    fwrite(STDERR, $e->getMessage()."\n\n");
    exit(2);
}

fwrite(STDOUT, "✅ Safety guard PASSED — environment is cleared for stress run.\n");
exit(0);
