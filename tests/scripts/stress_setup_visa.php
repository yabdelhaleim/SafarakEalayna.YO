<?php

/**
 * Phase 9.9a — Stress tier setup for Visa HTTP concurrency.
 *
 * Creates `storage/app/stress.sqlite` with full migrations + minimal seed
 * data (1 admin user, 1 customer, 1 visa agent + supplier account, 1 vault,
 * 1 visa duration). The DB is in the safe list per StressSafetyGuard.
 *
 * Falls back from MySQL safarak_stress (preferred) to SQLite file-backed
 * because MySQL is not running in this environment. SQLite supports
 * SERIALIZABLE-equivalent isolation via BEGIN IMMEDIATE/EXCLUSIVE for
 * the lockForUpdate() path.
 *
 * Usage:
 *   php tests/scripts/stress_setup_visa.php
 */

declare(strict_types=1);

// ─── 0. Force stress-tier env vars BEFORE Laravel bootstraps ────────────
// StressSafetyGuard refuses the production-like safarakealayna MySQL DB.
// Set env vars here so Laravel reads stress values when it parses .env.
$stressDbPath = 'storage/app/stress.sqlite';  // relative path — guard requires this literal
putenv('APP_ENV=local');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=' . $stressDbPath);
$_ENV['APP_ENV'] = 'local';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $stressDbPath;
$_SERVER['APP_ENV'] = 'local';
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $stressDbPath;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Tests\Stress\Support\StressSafetyGuard;

// ─── 1. SAFETY GUARD ────────────────────────────────────────────────────
try {
    StressSafetyGuard::assertSafeEnvironment();
} catch (\Throwable $e) {
    fwrite(STDERR, "\n❌ STRESS SETUP ABORTED: " . $e->getMessage() . "\n\n");
    exit(2);
}

echo "┌─────────────────────────────────────────────────────────────┐\n";
echo "│  Phase 9.9a — Stress tier setup (Visa)                      │\n";
echo "│  DB: storage/app/stress.sqlite (file-backed fallback)       │\n";
echo "└─────────────────────────────────────────────────────────────┘\n\n";

// ─── 2. Override DB connection to stress SQLite ─────────────────────────
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => storage_path('app/stress.sqlite'),
]);

// ─── 3. Drop + recreate fresh schema ─────────────────────────────────────
// (migrate:fresh is FORBIDDEN in prod-like DBs; safe here because we just
// created a brand-new file. This is destructive ONLY of the stress file,
// never the production-like DB.)
echo "Step 1: Fresh migration on stress.sqlite...\n";
Artisan::call('migrate:fresh', ['--force' => true]);

// ─── 4. Seed minimal data ────────────────────────────────────────────────
echo "Step 2: Seeding minimal stress data...\n";

$db = $app->make('db');

$adminId = $db->table('users')->insertGetId([
    'name' => 'Stress Admin',
    'email' => 'stress-admin@safarakealayna.test',
    'password' => bcrypt('stress-password'),
    'role' => 'admin',
    'is_active' => true,
    'created_at' => now(),
    'updated_at' => now(),
]);

$vaultId = $db->table('accounts')->insertGetId([
    'name' => 'Stress Vault EGP',
    'type' => 'cashbox',
    'currency' => 'EGP',
    'balance' => 1000000.00,
    'is_active' => true,
    'owner_type' => 'office',
    'module_type' => 'tourism',
    'is_module_vault' => true,
    'notes' => 'Stress tier — safe to wipe',
    'created_by' => $adminId,
    'created_at' => now(),
    'updated_at' => now(),
]);

$bankId = $db->table('accounts')->insertGetId([
    'name' => 'Stress Bank EGP',
    'type' => 'bank',
    'currency' => 'EGP',
    'balance' => 500000.00,
    'is_active' => true,
    'owner_type' => 'office',
    'module_type' => 'tourism',
    'is_module_vault' => false,
    'notes' => 'Stress tier — safe to wipe',
    'created_by' => $adminId,
    'created_at' => now(),
    'updated_at' => now(),
]);

$supplierId = $db->table('accounts')->insertGetId([
    'name' => 'Stress Supplier EGP',
    'type' => 'supplier',
    'currency' => 'EGP',
    'balance' => 0.00,
    'is_active' => true,
    'owner_type' => 'owner',
    'module_type' => 'visas',
    'is_module_vault' => false,
    'notes' => 'Stress tier — safe to wipe',
    'created_by' => $adminId,
    'created_at' => now(),
    'updated_at' => now(),
]);

$agentId = $db->table('visa_agents')->insertGetId([
    'company_name' => 'Stress Agent Co',
    'contact_person' => 'Stress Contact',
    'phone' => '01000000999',
    'email' => 'stress-agent@safarakealayna.test',
    'country' => 'EG',
    'visa_type' => 'tourist',
    'default_cost_price' => 1000.0,
    'account_id' => $supplierId,
    'is_active' => true,
    'notes' => 'Stress tier — safe to wipe',
    'created_at' => now(),
    'updated_at' => now(),
]);

$durationId = $db->table('visa_durations')->insertGetId([
    'code' => 'STRESS-30D',
    'label_ar' => '30 يوم',
    'label_en' => '30 days',
    'months' => 1,
    'entry_type' => 'single',
    'sort_order' => 1,
    'is_active' => true,
    'created_at' => now(),
    'updated_at' => now(),
]);

$customerId = $db->table('customers')->insertGetId([
    'full_name' => 'Stress Customer',
    'name' => 'Stress Customer',
    'phone' => '01000000001',
    'national_id' => '11111111111111',
    'passport_number' => 'A11111111',
    'type' => 'individual',
    'status' => 'active',
    'created_by' => $adminId,
    'created_at' => now(),
    'updated_at' => now(),
]);

// ─── 5. Issue Sanctum token for admin ────────────────────────────────────
$tokenRecord = $db->table('personal_access_tokens')->insertGetId([
    'tokenable_type' => 'App\\Models\\User',
    'tokenable_id' => $adminId,
    'name' => 'stress-tier',
    'token' => hash('sha256', 'stress-tier-fixed-token-for-curl-scripts'),
    'abilities' => json_encode(['*']),
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "Step 3: Created stress admin (id={$adminId}), vault ({$vaultId}), bank ({$bankId}), supplier ({$supplierId}), agent ({$agentId}), duration ({$durationId}), customer ({$customerId}).\n";

echo "\n┌─────────────────────────────────────────────────────────────┐\n";
echo "│  ✅ Stress tier ready                                       │\n";
echo "│                                                             │\n";
echo "│  ADMIN_TOKEN = 'stress-tier-fixed-token-for-curl-scripts'   │\n";
echo "│  STRESS_URL  = http://127.0.0.1:18000                       │\n";
echo "│  DB PATH     = storage/app/stress.sqlite                    │\n";
echo "│                                                             │\n";
echo "│  Start the server:                                          │\n";
echo "│  APP_ENV=local DB_CONNECTION=sqlite \\                       │\n";
echo "│    DB_DATABASE=$(pwd)/storage/app/stress.sqlite \\            │\n";
echo "│    php artisan serve --port=18000                            │\n";
echo "│                                                             │\n";
echo "│  Run a stress script:                                       │\n";
echo "│  APP_ENV=local DB_CONNECTION=sqlite \\                       │\n";
echo "│    DB_DATABASE=$(pwd)/storage/app/stress.sqlite \\            │\n";
echo "│    php tests/scripts/stress_visa_concurrent_payments.php    │\n";
echo "└─────────────────────────────────────────────────────────────┘\n";

exit(0);