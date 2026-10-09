<?php

declare(strict_types=1);

/**
 * stress_setup_mysql.php
 *
 * Phase 25 — Create the dedicated MySQL stress schema (safarak_stress)
 * and run all migrations + seeders against it.
 *
 * Hard-guards (via stress_safety_check):
 *   - Refuses to run if active DB is in the forbidden list
 *   - Verifies host is 127.0.0.1 (refuses to seed remote MySQL)
 *
 * Usage:
 *   php tests/scripts/stress_setup_mysql.php
 *
 * Exit codes:
 *   0  schema created + migrated successfully
 *   2  safety guard aborted
 *   3  migration failure
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

// ── Safety guard — refuse to touch forbidden DBs.
try {
    StressSafetyGuard::assertSafeEnvironment('mysql');
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "\n🛑  SAFETY ABORT 🛑\n".$e->getMessage()."\n\n");
    exit(2);
}

// ── Refuse to seed remote MySQL.
$cfg = Config::get('database.connections.mysql');
$host = $cfg['host'] ?? '';
$allowed = ['127.0.0.1', 'localhost', '::1'];
if (!in_array($host, $allowed, true)) {
    fwrite(STDERR, "🛑 Refusing to seed MySQL host '{$host}'. Allowed: ".implode(', ', $allowed)."\n");
    exit(2);
}

$dbName = $cfg['database'];
fwrite(STDOUT, "→ Creating schema '{$dbName}' on {$host}:".($cfg['port'] ?? 3306)."\n");

// ── Drop + recreate schema. We MUST connect without a database first because
// the default connection points at $dbName which doesn't exist yet.
// Strategy: add a temporary "mysql_nodb" connection with no database, use
// that to issue DROP/CREATE, then switch the default to the new schema.
Config::set('database.connections.mysql_nodb', [
    'driver'   => 'mysql',
    'host'     => $cfg['host'],
    'port'     => $cfg['port'] ?? 3306,
    'database' => null, // no database — required to issue CREATE DATABASE
    'username' => $cfg['username'] ?? 'root',
    'password' => $cfg['password'] ?? '',
    'charset'  => 'utf8mb4',
    'collation'=> 'utf8mb4_unicode_ci',
    'prefix'   => '',
    'strict'   => false,
]);
DB::purge('mysql_nodb');
DB::reconnect('mysql_nodb');

DB::connection('mysql_nodb')->statement("DROP DATABASE IF EXISTS `{$dbName}`");
DB::connection('mysql_nodb')->statement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
fwrite(STDOUT, "✓ Schema '{$dbName}' created.\n");

// ── Switch the default connection to the new schema.
Config::set('database.connections.mysql.database', $dbName);
DB::purge('mysql');
DB::reconnect('mysql');

// Sanity check: confirm SELECT DATABASE() returns the new schema.
$currentDb = DB::connection('mysql')->selectOne('SELECT DATABASE() AS d')->d ?? null;
if ($currentDb !== $dbName) {
    fwrite(STDERR, "🛑 After reconnect, SELECT DATABASE() returned '{$currentDb}', expected '{$dbName}'.\n");
    exit(3);
}
fwrite(STDOUT, "✓ SELECT DATABASE() = {$currentDb}\n");

// ── Run all migrations.
fwrite(STDOUT, "→ Running migrations against '{$dbName}'…\n");
$exit = $app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate', [
    '--force' => true,
    '--database' => 'mysql',
]);
if ($exit !== 0) {
    fwrite(STDERR, "🛑 Migration failed with exit code {$exit}\n");
    exit(3);
}
fwrite(STDOUT, "✓ Migrations complete.\n");

// ── Optional: seed User (we need an actor).
\App\Models\User::query()->updateOrCreate(
    ['email' => 'stress-actor@safarakealayna.test'],
    [
        'name'              => 'STRESS-ACTOR',
        'password'          => bcrypt('stress-password'),
        'email_verified_at' => now(),
        'role'              => 'admin',
        'is_active'         => true,
    ]
);
fwrite(STDOUT, "✓ Seeded stress actor user.\n");

fwrite(STDOUT, "\n✅ Setup complete. Schema '{$dbName}' is ready for stress runs.\n");
exit(0);
