<?php

declare(strict_types=1);

/**
 * stress_teardown.php
 *
 * Phase 25 — Tear down the dedicated stress artifacts:
 *   - DROP DATABASE safarak_stress (only if active connection matches)
 *   - DELETE storage/app/stress.sqlite (only if it exists)
 *
 * Refuses to drop ANY database whose name is in the forbidden list.
 *
 * Usage:
 *   php tests/scripts/stress_teardown.php
 *
 * Exit codes:
 *   0  cleanup complete
 *   2  safety guard aborted
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

try {
    StressSafetyGuard::assertSafeEnvironment(null);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "\n🛑  SAFETY ABORT 🛑\n".$e->getMessage()."\n\n");
    exit(2);
}

// ── 1. Drop MySQL stress schema (if active connection is mysql AND db is safarak_stress).
$cfg = Config::get('database.connections.'.Config::get('database.default'));
$connection = Config::get('database.default');
$database = is_array($cfg) ? ($cfg['database'] ?? null) : null;

if ($connection === 'mysql' && strtolower((string) $database) === StressSafetyGuard::STRESS_MYSQL_DB) {
    $host = $cfg['host'] ?? '';
    $allowed = ['127.0.0.1', 'localhost', '::1'];
    if (in_array($host, $allowed, true)) {
        fwrite(STDOUT, "→ Dropping MySQL schema '{$database}' on {$host}…\n");
        DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$database}`");
        fwrite(STDOUT, "✓ Dropped '{$database}'.\n");
    } else {
        fwrite(STDERR, "🛑 Refusing to DROP remote MySQL host '{$host}'.\n");
    }
} elseif ($connection === 'mysql') {
    fwrite(STDERR, "🛑 Active MySQL DB is '{$database}', not '".StressSafetyGuard::STRESS_MYSQL_DB."'. Refusing to drop.\n");
}

// ── 2. Delete SQLite stress file (only if path is the stress file).
if ($connection === 'sqlite') {
    $allowedPaths = [
        realpath(StressSafetyGuard::STRESS_SQLITE_PATH) ?: '',
        ':memory:',
    ];
    $activePath = realpath($database) ?: $database;
    if (in_array($activePath, $allowedPaths, true) || str_ends_with((string) $database, 'stress.sqlite')) {
        if ($database !== ':memory:' && file_exists($database)) {
            fwrite(STDOUT, "→ Deleting SQLite stress file '{$database}'…\n");
            @unlink($database);
            fwrite(STDOUT, "✓ Deleted '{$database}'.\n");
        } else {
            fwrite(STDOUT, "→ SQLite stress file does not exist (or is :memory:); nothing to delete.\n");
        }
    } else {
        fwrite(STDERR, "🛑 Active SQLite DB '{$database}' is not the stress file. Refusing to delete.\n");
    }
}

// ── 3. Delete storage/app/stress/* artifacts.
$artifactsDir = __DIR__ . '/../../storage/app/stress';
if (is_dir($artifactsDir)) {
    fwrite(STDOUT, "→ Deleting artifacts under storage/app/stress/…\n");
    $rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($artifactsDir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rii as $file) {
        $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
    }
    @rmdir($artifactsDir);
    fwrite(STDOUT, "✓ Artifacts cleaned.\n");
}

fwrite(STDOUT, "\n✅ Teardown complete.\n");
exit(0);
