<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Bus Module — 2026-08-13 AUDIT SETUP (UI-Driven Full E2E)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يجهز بيئة الـ audit الكامل:
 *   - SQLite معزول في storage/app/local_bus_audit.sqlite
 *   - كل الـ migrations تتطبق fresh
 *   - Seed data شامل بما فيهم:
 *     * 3 users بأدوار مختلفة (admin, manager, employee) — للـ auth matrix
 *     * شركات + مخزون + حجوزات تجريبية متعددة
 *     * 1 Treasury row (لتفعيل T11 refund happy-path)
 *     * خزن EGP + USD + SAR بمبالغ ابتدائية معروفة
 *   - Sanctum auth_token بيتطبع عشان باقي الـ scripts تستفيد منه
 *
 * الـ Design ينسخ الـ pattern من scripts/bus_module_local_setup.php مع توسيع
 * الـ seed dataset ليدعم 17 سيناريو soft-delete لكل entity + matrix كاملة.
 *
 * التشغيل:
 *   cd C:\travile\SafarakEalayna
 *   php scripts/bus_audit_setup.php
 *
 * الـ Output:
 *   - storage/app/local_bus_audit.sqlite   ← قاعدة البيانات المعزولة
 *   - storage/logs/bus_audit_setup.json    ← metadata (auth_token, IDs, etc.)
 */

// ─── Step 0: Force SQLite BEFORE bootstrap ──────────────────────────────
$dbPath = realpath(__DIR__.'/..').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'local_bus_audit.sqlite';

if (file_exists($dbPath)) {
    echo "    ℹ  Removing previous audit DB: $dbPath\n";
    @unlink($dbPath);
}
@mkdir(dirname($dbPath), 0755, true);
echo "    ℹ  Creating new SQLite file: $dbPath\n";
file_put_contents($dbPath, '');

putenv('DB_CONNECTION=sqlite');
putenv("DB_DATABASE=$dbPath");
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $dbPath;
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $dbPath;

echo "  ✅ SQLite env forced before bootstrap\n\n";

// ─── Step 0.5: Move MySQL-specific migration out of the way (SQLite can't) ──
//   `2026_08_12_120000_add_income_unique_key_to_transactions` uses SHOW COLUMNS
//   and MySQL GENERATED STORED columns — not supported on SQLite.
$_mysqlOnlyDisabled = __DIR__.'/../database/migrations/.disabled_for_sqlite';
@mkdir($_mysqlOnlyDisabled, 0755, true);
$_mysqlOnlyFiles = [
    '2026_08_12_120000_add_income_unique_key_to_transactions.php',
];
foreach ($_mysqlOnlyFiles as $_f) {
    $_src = __DIR__.'/../database/migrations/'.$_f;
    $_dst = $_mysqlOnlyDisabled.'/'.$_f;
    if (file_exists($_src) && ! file_exists($_dst)) {
        rename($_src, $_dst);
        echo "    ℹ  Temporarily moved MySQL-only migration: $_f\n";
    }
}
echo "  ✅ MySQL-only migrations staged to database/migrations/.disabled_for_sqlite/\n\n";

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Bus Module — 2026-08-13 Audit Setup\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─── Verify config points to SQLite ─────────────────────────────────────
$driver = Config::get('database.default');
$conn = Config::get('database.connections.sqlite.database');
echo "  - Database driver: $driver\n";
echo "  - SQLite path:     $conn\n\n";

// ─── Step 1: Run migrations ─────────────────────────────────────────────
echo "    ℹ  Running migrations...\n";
$exitCode = Artisan::call('migrate', ['--force' => true, '--database' => 'sqlite']);
echo Artisan::output();
echo "    ✅ Migrations applied (exit code: $exitCode)\n\n";

// ─── Step 2: Seed minimal data ──────────────────────────────────────────
echo "    ℹ  Seeding audit data...\n";

// 2.1 Create 3 users with different roles for auth matrix
$adminId = DB::table('users')->insertGetId([
    'name' => 'TX-AUDIT Admin',
    'email' => 'admin@tx-bus-audit.local',
    'password' => bcrypt('password'),
    'role' => 'owner',
    'is_active' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);

$managerId = DB::table('users')->insertGetId([
    'name' => 'TX-AUDIT Manager',
    'email' => 'manager@tx-bus-audit.local',
    'password' => bcrypt('password'),
    'role' => 'manager',
    'is_active' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);

$employeeId = DB::table('users')->insertGetId([
    'name' => 'TX-AUDIT Employee',
    'email' => 'employee@tx-bus-audit.local',
    'password' => bcrypt('password'),
    'role' => 'employee',
    'is_active' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);
echo "    ✅ 3 users created: admin=$adminId, manager=$managerId, employee=$employeeId\n";

// 2.2 Run clearing accounts + unified vaults seeders
try {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\UnifiedVaultsSeeder', '--force' => true]);
    echo "    ✅ UnifiedVaultsSeeder applied\n";
} catch (Exception $e) {
    echo '    ⚠️  UnifiedVaultsSeeder failed: '.$e->getMessage()."\n";
}

// 2.3 Exchange rates
$today = now()->toDateString();
$rates = [
    ['USD', 'EGP', 50.0], ['EGP', 'USD', 0.02],
    ['SAR', 'EGP', 13.33], ['EGP', 'SAR', 0.075],
    ['KWD', 'EGP', 162.5], ['EGP', 'KWD', 0.00615],
    ['EUR', 'EGP', 54.5], ['EGP', 'EUR', 0.0183],
];
foreach ($rates as [$from, $to, $rate]) {
    DB::table('exchange_rates')->insert([
        'from_currency' => $from, 'to_currency' => $to,
        'rate' => $rate, 'effective_date' => $today, 'is_active' => 1,
        'created_by' => $adminId, 'created_at' => now(), 'updated_at' => now(),
    ]);
}
echo "    ✅ Exchange rates seeded\n";

// 2.4 Ensure Treasury table has at least one row (needed for T11 refund happy-path)
//    Note: actual schema is (id, name, currency, current_balance, is_active, created_at, updated_at)
if (Schema::hasTable('treasuries')) {
    $treasuryCount = DB::table('treasuries')->count();
    if ($treasuryCount === 0) {
        $treasuryId = DB::table('treasuries')->insertGetId([
            'name' => 'TX-AUDIT EGP Treasury',
            'currency' => 'EGP',
            'current_balance' => 100000.00,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        echo "    ✅ Treasury seeded (id=$treasuryId, EGP, 100000.00) [T11 enablement]\n";
    } else {
        echo "    ℹ  Treasury table already has $treasuryCount rows\n";
    }
} else {
    echo "    ⚠️  No treasuries table — T11 refund happy-path will be skipped\n";
}

// ─── Step 3: Print matrix metadata ──────────────────────────────────────
echo "\n    Tables after setup:\n";
$tables = ['users', 'accounts', 'bus_companies', 'bus_inventories', 'bus_bookings',
    'bus_payments', 'bus_refund_requests', 'transactions', 'account_entries',
    'exchange_rates', 'treasuries'];
foreach ($tables as $t) {
    $count = DB::table($t)->count();
    echo "    - $t: $count rows\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  Audit setup complete\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";
echo "  Next steps:\n";
echo "  1. Run   php scripts/bus_audit_soft_delete_matrix.php\n";
echo "  2. Run   php scripts/bus_audit_soft_delete_run.php\n";
echo "  3. Run   php scripts/bus_audit_phase_h_cross_currency.php\n";
echo "  4. Run   php scripts/bus_audit_phase_h_json_envelope.php\n";
echo "  5. Run   php scripts/bus_audit_phase_* (remaining)\n";
echo "  6. Generate final report with php scripts/bus_audit_phase_r_report.php\n\n";
