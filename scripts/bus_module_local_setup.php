<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Bus Module — LOCAL TEST SETUP
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يجهز SQLite لوكل جديد (storage/app/local_bus_test.sqlite) ويشغّل كل
 * الـ migrations عليه + يستدعي الـ UnifiedVaultsSeeder والـ Bus clearing
 * account setup. بعدها يشغّل bus_module_full_e2e.php على اللوكال.
 *
 * التشغيل:
 *   cd C:\travile\SafarakEalayna
 *   php scripts/bus_module_local_setup.php
 *
 * بعد التشغيل:
 *   - اللوكال SQLite في storage/app/local_bus_test.sqlite (ممكن نمسحه بأي وقت)
 *   - تقرير التيست في storage/logs/bus_full_e2e_results.json
 *   - تقرير التحليل في BUS_MODULE_FULL_E2E_REPORT_20260812.md
 */

// ─── Step 0: Force SQLite BEFORE bootstrap ──────────────────────────────
$dbPath = realpath(__DIR__.'/..').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'local_bus_test.sqlite';

// Delete old DB
if (file_exists($dbPath)) {
    echo "    ℹ  Removing previous local DB: $dbPath\n";
    @unlink($dbPath);
}
@mkdir(dirname($dbPath), 0755, true);
echo "    ℹ  Creating new SQLite file: $dbPath\n";
file_put_contents($dbPath, '');

// Set env vars BEFORE Laravel bootstraps so the migrate command uses SQLite
putenv('DB_CONNECTION=sqlite');
putenv("DB_DATABASE=$dbPath");
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $dbPath;
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $dbPath;

echo "  ✅ SQLite env forced before bootstrap\n\n";

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Bus Module — Local SQLite Setup\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─── Verify config points to SQLite ─────────────────────────────────────
$driver = Config::get('database.default');
$conn = Config::get('database.connections.sqlite.database');
echo "  - Database driver: $driver\n";
echo "  - SQLite path: $conn\n\n";

// ─── Step 1: Run migrations ─────────────────────────────────────────────
echo "    ℹ  Running migrations...\n";
$exitCode = Artisan::call('migrate', ['--force' => true, '--database' => 'sqlite']);
echo Artisan::output();
echo "    ✅ Migrations applied (exit code: $exitCode)\n\n";

// ─── Step 2: Seed minimal data ──────────────────────────────────────────
echo "    ℹ  Seeding minimal local data...\n";

// 2.1 Create admin user
$adminId = DB::table('users')->insertGetId([
    'name' => 'Local Tester',
    'email' => 'local-tester@example.com',
    'password' => bcrypt('password'),
    'role' => 'owner',
    'is_active' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);
echo "    ✅ Admin user created (id=$adminId)\n";

// 2.2 Run seeders for clearing accounts + unified vaults
try {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\UnifiedVaultsSeeder', '--force' => true]);
    echo "    ✅ UnifiedVaultsSeeder applied\n";
} catch (Exception $e) {
    echo '    ⚠️  UnifiedVaultsSeeder failed: '.$e->getMessage()."\n";
}

// 2.3 Seed exchange rates
$today = now()->toDateString();
$rates = [
    ['USD', 'EGP', 50.0],
    ['EGP', 'USD', 0.02],
    ['SAR', 'EGP', 13.33],
    ['EGP', 'SAR', 0.075],
    ['KWD', 'EGP', 162.5],
    ['EGP', 'KWD', 0.00615],
    ['EUR', 'EGP', 54.5],
    ['EGP', 'EUR', 0.0183],
];
foreach ($rates as [$from, $to, $rate]) {
    DB::table('exchange_rates')->insert([
        'from_currency' => $from,
        'to_currency' => $to,
        'rate' => $rate,
        'effective_date' => $today,
        'is_active' => 1,
        'created_by' => $adminId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
echo "    ✅ Exchange rates seeded\n";

// ─── Step 3: Verify setup ───────────────────────────────────────────────
echo "\n    Tables after setup:\n";
$tables = ['users', 'accounts', 'bus_companies', 'bus_inventories', 'bus_bookings',
    'bus_payments', 'bus_refund_requests', 'transactions', 'account_entries',
    'exchange_rates'];
foreach ($tables as $t) {
    $count = DB::table($t)->count();
    echo "    - $t: $count rows\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  Setup complete — now running bus_module_full_e2e.php on local SQLite\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─── Step 4: Run the E2E test against this local DB ─────────────────────
require __DIR__.'/bus_module_full_e2e.php';
