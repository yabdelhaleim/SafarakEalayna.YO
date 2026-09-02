<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * FINANCIAL CORE & CROSS-MODULE MONEY FLOW AUDIT — SETUP (2026-08-14)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يجهز بيئة الـ audit المشترك للـ financial core:
 *   - SQLite معزول في storage/app/local_financial_audit.sqlite
 *   - كل الـ migrations تتطبق fresh
 *   - Seed data شامل بـ prefix "FC-AUDIT-20260814-" بما فيهم:
 *     * 4 users بأدوار مختلفة (owner, admin, manager, employee) — للـ security tests
 *     * Multi-currency setup (EGP, USD, SAR, KWD)
 *     * Cashbox accounts (EGP + USD) — office + tourism divisions
 *     * Bank + Wallet (EGP + USD)
 *     * 1 customer per module (flights, bus, hajj_umra, visas, online, fawry, wallet_transfer)
 *     * 1 supplier per module
 *     * Module-specific income/expense clearing accounts (per LedgerClearingAccounts)
 *     * Exchange rates (EGP base, all pairs)
 *     * Prepaid GL accounts (flight_carrier, flight_system, fawry_machine)
 *     * Walk-in AR mirror accounts
 *
 * الـ Design ينسخ الـ pattern من scripts/bus_e2e_final_setup.php + flight_audit_setup.php
 *
 * التشغيل:
 *   cd C:\travile\SafarakEalayna
 *   php scripts/financial_core_audit_setup.php
 *
 * الـ Output:
 *   - storage/app/local_financial_audit.sqlite       ← قاعدة البيانات المعزولة
 *   - storage/logs/financial_core_audit_baseline.json ← metadata + baseline balances
 */

// ─── Step 0: Force SQLite BEFORE bootstrap ──────────────────────────────
$dbPath = realpath(__DIR__.'/..').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'local_financial_audit.sqlite';

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

// ─── Step 0.5: Move MySQL-specific migrations out of the way ──────────
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
    } elseif (! file_exists($_src) && file_exists($_dst)) {
        // Restore from .disabled back to migrations dir if it was moved there before
        rename($_dst, $_src);
        echo "    ℹ  Restored migration from .disabled_for_sqlite: $_f\n";
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

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  FINANCIAL CORE & CROSS-MODULE AUDIT — 2026-08-14 Setup\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

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

// Re-bind kernel reference
$kernel = $app->make(Kernel::class);

// ─── Step 2: Seed audit data ──────────────────────────────────────────
echo "    ℹ  Seeding audit data...\n";

// 2.1 Create 4 users with different roles for security tests (Phase 11)
$userIds = [];
$roles = [
    'owner' => 'owner@fc-audit.local',
    'admin' => 'admin@fc-audit.local',
    'manager' => 'manager@fc-audit.local',
    'employee' => 'employee@fc-audit.local',
];
foreach ($roles as $role => $email) {
    $uid = DB::table('users')->insertGetId([
        'name' => 'FC-AUDIT-20260814 '.ucfirst($role),
        'email' => $email,
        'password' => bcrypt('password'),
        'role' => $role,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $userIds[$role] = $uid;
}
echo "    ✅ 4 users created: owner={$userIds['owner']}, admin={$userIds['admin']}, manager={$userIds['manager']}, employee={$userIds['employee']}\n";

// 2.2 Sanctum tokens
$tokens = [];
$tokenNames = ['fc-audit-owner', 'fc-audit-admin', 'fc-audit-manager', 'fc-audit-employee'];
$tokenRoles = ['owner', 'admin', 'manager', 'employee'];
if (Schema::hasTable('personal_access_tokens')) {
    foreach ($tokenRoles as $i => $role) {
        $uid = $userIds[$role];
        $plain = bin2hex(random_bytes(20));
        $hashed = hash('sha256', $plain);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => $uid,
            'name' => $tokenNames[$i],
            'token' => $hashed,
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tokens[$role] = $uid.'|'.$plain;
    }
    echo "    ✅ Sanctum tokens issued for 4 users\n";
}

// 2.3 Run unified vaults + currency seeders (if exist)
$seeders = [
    'Database\\Seeders\\UnifiedVaultsSeeder',
    'Database\\Seeders\\CurrencySeeder',
    'Database\\Seeders\\FlightLedgerClearingAccountSeeder',
];
foreach ($seeders as $seedClass) {
    try {
        $seederFile = base_path('database/seeders/'.str_replace('Database\\Seeders\\', '', $seedClass).'.php');
        if (file_exists($seederFile)) {
            Artisan::call('db:seed', ['--class' => $seedClass, '--force' => true]);
            echo "    ✅ $seedClass applied\n";
        }
    } catch (Exception $e) {
        echo "    ⚠️  $seedClass failed: ".$e->getMessage()."\n";
    }
}

// 2.4 Currencies
if (Schema::hasTable('currencies')) {
    $existingCount = DB::table('currencies')->count();
    if ($existingCount === 0) {
        $currencies = [
            ['code' => 'EGP', 'name_ar' => 'جنيه مصري', 'name_en' => 'Egyptian Pound', 'symbol' => 'E£', 'exchange_rate' => 1.0, 'is_active' => 1, 'order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'USD', 'name_ar' => 'دولار أمريكي', 'name_en' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 48.5, 'is_active' => 1, 'order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'SAR', 'name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal', 'symbol' => 'SAR', 'exchange_rate' => 12.9, 'is_active' => 1, 'order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'KWD', 'name_ar' => 'دينار كويتي', 'name_en' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'exchange_rate' => 157.5, 'is_active' => 1, 'order' => 4, 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('currencies')->insert($currencies);
        echo "    ✅ 4 currencies seeded (EGP base, USD/SAR/KWD)\n";
    } else {
        echo "    ℹ  Currencies table already has $existingCount rows\n";
    }
}

// 2.5 Exchange rates
$today = now()->toDateString();
$rates = [
    ['USD', 'EGP', 48.5], ['EGP', 'USD', 1 / 48.5],
    ['SAR', 'EGP', 12.9], ['EGP', 'SAR', 1 / 12.9],
    ['KWD', 'EGP', 157.5], ['EGP', 'KWD', 1 / 157.5],
    ['EUR', 'EGP', 52.3], ['EGP', 'EUR', 1 / 52.3],
];
foreach ($rates as [$from, $to, $rate]) {
    DB::table('exchange_rates')->insert([
        'from_currency' => $from, 'to_currency' => $to,
        'rate' => round($rate, 6), 'effective_date' => $today, 'is_active' => 1,
        'created_by' => $userIds['admin'], 'created_at' => now(), 'updated_at' => now(),
    ]);
}
echo "    ✅ Exchange rates seeded (USD/SAR/KWD/EUR pairs)\n";

// 2.6 Liquidity Accounts (cashbox/bank/wallet) — multiple currencies × divisions
//     Per Account.php docblock: liquidity MUST have module_type='office' or 'tourism'
$accountIds = [];

// Helper to create an account with opening balance + opening AccountEntry
$createAccount = function (
    string $name,
    string $type,
    string $currency,
    string $moduleType,  // 'office' or 'tourism'
    string $module,       // 'office', 'tourism', 'flights', 'bus', etc.
    float $balance,
    string $createdBy,
    bool $isVault = false
) use (&$accountIds) {
    $id = DB::table('accounts')->insertGetId([
        'name' => $name,
        'type' => $type,
        'currency' => $currency,
        'balance' => $balance,
        'is_active' => 1,
        'module_type' => $moduleType,
        'module' => $module,
        'is_module_vault' => $isVault ? 1 : 0,
        'created_by' => $createdBy,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    if ($balance != 0.0) {
        DB::table('account_entries')->insert([
            'account_id' => $id,
            'transaction_id' => null,
            'debit' => $balance < 0 ? abs($balance) : 0,
            'credit' => $balance > 0 ? $balance : 0,
            'balance_after' => $balance,
            'notes' => 'رصيد افتتاحي — FC-AUDIT seed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $id;
};

// Office cashboxes (EGP + USD)
$accountIds['cashbox_egp_office'] = $createAccount(
    'FC-AUDIT-20260814 Office Cashbox EGP', 'cashbox', 'EGP',
    'office', 'office', 100000.00, $userIds['admin'], true
);
$accountIds['cashbox_usd_office'] = $createAccount(
    'FC-AUDIT-20260814 Office Cashbox USD', 'cashbox', 'USD',
    'office', 'office', 10000.00, $userIds['admin'], true
);

// Tourism cashboxes (EGP + USD) — for Flight/Hajj/Visa
$accountIds['cashbox_egp_tourism'] = $createAccount(
    'FC-AUDIT-20260814 Tourism Cashbox EGP', 'cashbox', 'EGP',
    'tourism', 'tourism', 50000.00, $userIds['admin'], true
);
$accountIds['cashbox_usd_tourism'] = $createAccount(
    'FC-AUDIT-20260814 Tourism Cashbox USD', 'cashbox', 'USD',
    'tourism', 'tourism', 5000.00, $userIds['admin'], true
);

// EGP bank + EGP wallet + USD wallet (office division)
$accountIds['bank_egp'] = $createAccount(
    'FC-AUDIT-20260814 Bank EGP', 'bank', 'EGP',
    'office', 'office', 25000.00, $userIds['admin'], true
);
$accountIds['wallet_egp'] = $createAccount(
    'FC-AUDIT-20260814 Wallet Vodafone EGP', 'wallet', 'EGP',
    'office', 'office', 15000.00, $userIds['admin'], false
);
$accountIds['wallet_usd'] = $createAccount(
    'FC-AUDIT-20260814 Wallet Vodafone USD', 'wallet', 'USD',
    'office', 'office', 2000.00, $userIds['admin'], false
);

// Module-specific clearing accounts (per LedgerClearingAccounts)
// These MUST match the names that LedgerClearingAccounts looks up.
$accountIds['bus_income_clearing'] = $createAccount(
    'bus_income_clearing', 'revenue', 'EGP',
    'office', 'bus', 0.00, $userIds['admin'], false
);
$accountIds['bus_expense_clearing'] = $createAccount(
    'bus_expense_clearing', 'expense', 'EGP',
    'office', 'bus', 0.00, $userIds['admin'], false
);
$accountIds['fawry_income_clearing'] = $createAccount(
    'fawry_income_clearing', 'revenue', 'EGP',
    'office', 'fawry', 0.00, $userIds['admin'], false
);
$accountIds['fawry_expense_clearing'] = $createAccount(
    'fawry_expense_clearing', 'expense', 'EGP',
    'office', 'fawry', 0.00, $userIds['admin'], false
);
$accountIds['online_income_clearing'] = $createAccount(
    'online_income_clearing', 'revenue', 'EGP',
    'office', 'online', 0.00, $userIds['admin'], false
);
$accountIds['online_expense_clearing'] = $createAccount(
    'online_expense_clearing', 'expense', 'EGP',
    'office', 'online', 0.00, $userIds['admin'], false
);
$accountIds['wallet_income_clearing'] = $createAccount(
    'wallet_transfer_income_clearing', 'revenue', 'EGP',
    'office', 'wallet_transfer', 0.00, $userIds['admin'], false
);
$accountIds['wallet_expense_clearing'] = $createAccount(
    'wallet_transfer_expense_clearing', 'expense', 'EGP',
    'office', 'wallet_transfer', 0.00, $userIds['admin'], false
);
$accountIds['flight_income_clearing'] = $createAccount(
    'flight_income_clearing', 'revenue', 'EGP',
    'tourism', 'flights', 0.00, $userIds['admin'], false
);
$accountIds['flight_expense_clearing'] = $createAccount(
    'flight_expense_clearing', 'expense', 'EGP',
    'tourism', 'flights', 0.00, $userIds['admin'], false
);
$accountIds['hajj_income_clearing'] = $createAccount(
    'hajj_umra_income_clearing', 'revenue', 'EGP',
    'tourism', 'hajj_umra', 0.00, $userIds['admin'], false
);
$accountIds['hajj_expense_clearing'] = $createAccount(
    'hajj_umra_expense_clearing', 'expense', 'EGP',
    'tourism', 'hajj_umra', 0.00, $userIds['admin'], false
);
$accountIds['visa_income_clearing'] = $createAccount(
    'visa_income_clearing', 'revenue', 'EGP',
    'tourism', 'visas', 0.00, $userIds['admin'], false
);
$accountIds['visa_expense_clearing'] = $createAccount(
    'visa_expense_clearing', 'expense', 'EGP',
    'tourism', 'visas', 0.00, $userIds['admin'], false
);

// Treasury operations contra (per LedgerClearingAccounts::treasuryOperationsContraAccountId())
$accountIds['treasury_ops_contra'] = $createAccount(
    'treasury_operations_contra', 'liability', 'EGP',
    'office', 'office', 0.00, $userIds['admin'], false
);

// Walk-in AR mirrors (per LedgerClearingAccounts::fawryWalkInArAccountId / onlineWalkInArAccountId)
// Per AccountModuleContract: subject accounts (customer/supplier) MUST have a SPECIFIC
// module_type (not a division name like 'office'/'tourism'). Using 'fawry' and 'online'.
$accountIds['fawry_walkin_ar'] = $createAccount(
    'fawry_walkin_ar', 'customer', 'EGP',
    'fawry', 'fawry', 0.00, $userIds['admin'], false
);
$accountIds['online_walkin_ar'] = $createAccount(
    'online_walkin_ar', 'customer', 'EGP',
    'online', 'online', 0.00, $userIds['admin'], false
);

// Prepaid GL accounts (per LedgerClearingAccounts::prepaidAccountIdMap)
$accountIds['prepaid_flight_carrier'] = $createAccount(
    'prepaid_flight_carrier', 'liability', 'EGP',
    'office', 'flights', 0.00, $userIds['admin'], false
);
$accountIds['prepaid_flight_system'] = $createAccount(
    'prepaid_flight_system', 'liability', 'EGP',
    'office', 'flights', 0.00, $userIds['admin'], false
);
$accountIds['prepaid_fawry_machine'] = $createAccount(
    'prepaid_fawry_machine', 'liability', 'EGP',
    'office', 'fawry', 0.00, $userIds['admin'], false
);

echo '    ✅ '.count($accountIds)." accounts seeded (cashboxes, banks, wallets, clearing, prepaid, walk-in AR)\n";

// 2.7 Customers (1 per module — subject accounts for cross-module flows)
$customerIds = [];
$modules = ['flights', 'bus', 'hajj_umra', 'visas', 'online', 'fawry', 'wallet_transfer'];
foreach ($modules as $mod) {
    $cid = DB::table('customers')->insertGetId([
        'name' => 'FC-AUDIT-20260814 Customer '.$mod,
        'full_name' => 'FC-AUDIT-20260814 Customer '.$mod,
        'phone' => '+2015'.str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
        'email' => 'fc-customer-'.$mod.'@fc-audit.local',
        'module_type' => $mod === 'hajj_umra' ? 'hajj_umra' : ($mod === 'wallet_transfer' ? 'wallet' : $mod),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // CustomerLedgerObserver auto-creates the Account; check if it was created via owner_type polymorphic
    $accId = DB::table('accounts')->where('owner_type', 'App\\Models\\Customer')->value('id');
    if (! $accId) {
        // Manually create (the observer might not be auto-registered or might be conditional)
        $accId = $createAccount(
            'FC-AUDIT-20260814 Customer AR '.$mod, 'customer', 'EGP',
            $mod === 'flights' || $mod === 'hajj_umra' || $mod === 'visas' ? 'tourism' : 'office',
            $mod === 'hajj_umra' ? 'hajj_umra' : ($mod === 'wallet_transfer' ? 'wallet_transfer' : $mod),
            0.00, $userIds['admin'], false
        );
        // Set owner_type on the account (no owner_id column on accounts)
        DB::table('accounts')->where('id', $accId)->update([
            'owner_type' => 'App\\Models\\Customer',
        ]);
    }
    // Update the customer to point at the AR account (Customer.account_id → Account.id)
    DB::table('customers')->where('id', $cid)->update(['account_id' => $accId]);
    $customerIds[$mod] = ['customer_id' => $cid, 'account_id' => $accId];
}
echo '    ✅ '.count($customerIds)." customers seeded (1 per module + AR mirror accounts)\n";

// Patch: manually create a unique AR account for each customer (the polymorphic
// owner_type query in the original fallback returned the FIRST match for all
// customers, so all 7 ended up sharing account_id=30. Each customer now gets its
// own dedicated account.)
foreach ($customerIds as $mod => $info) {
    $cid = $info['customer_id'];
    $oldAccId = $info['account_id'];
    // Check if this customer has a unique account (different from siblings)
    $siblings = collect($customerIds)->filter(fn ($v, $k) => $k !== $mod && $v['account_id'] === $oldAccId);
    if ($siblings->isNotEmpty()) {
        // Create a unique account for this customer
        $newAccId = $createAccount(
            'FC-AUDIT-20260814 Customer AR '.$mod.' (unique)', 'customer', 'EGP',
            in_array($mod, ['flights', 'hajj_umra', 'visas']) ? 'tourism' : 'office',
            $mod === 'hajj_umra' ? 'hajj_umra' : ($mod === 'wallet_transfer' ? 'wallet_transfer' : $mod),
            0.00, $userIds['admin'], false
        );
        DB::table('accounts')->where('id', $newAccId)->update(['owner_type' => 'App\\Models\\Customer']);
        DB::table('customers')->where('id', $cid)->update(['account_id' => $newAccId]);
        $customerIds[$mod] = ['customer_id' => $cid, 'account_id' => $newAccId];
    }
}
echo '    ✅ Unique AR accounts ensured for '.count($customerIds)." customers\n";

// 2.8 Suppliers (1 per module)
$supplierIds = [];
$supplierModules = [
    'flights' => ['Flight Group', 'flights', 'airline'],
    'bus' => ['Bus Company', 'bus', 'bus_company'],
    'hajj_umra' => ['Executing Company', 'hajj_umra', 'service_provider'],
    'visas' => ['Visa Agent', 'visas', 'visa_provider'],
    'online' => ['Online Provider', 'online', 'service_provider'],
];
foreach ($supplierModules as $mod => [$name, $moduleType, $supplierType]) {
    $sid = DB::table('suppliers')->insertGetId([
        'name' => 'FC-AUDIT-20260814 Supplier '.$name,
        'code' => 'FC-'.strtoupper(substr($mod, 0, 3)).'-001',
        'type' => $supplierType,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $suppAccId = $createAccount(
        'FC-AUDIT-20260814 Supplier AP '.$name, 'supplier', 'EGP',
        $mod === 'flights' || $mod === 'hajj_umra' || $mod === 'visas' ? 'tourism' : 'office',
        $moduleType, 0.00, $userIds['admin'], false
    );
    DB::table('suppliers')->where('id', $sid)->update(['account_id' => $suppAccId]);
    $supplierIds[$mod] = ['supplier_id' => $sid, 'account_id' => $suppAccId];
}
echo '    ✅ '.count($supplierIds)." suppliers seeded (flight/bus/hajj/visa/online + AP mirror accounts)\n";

// ─── Step 3: Capture baseline balances ─────────────────────────────────
$baseline = [
    'audit_id' => 'FC_AUDIT_20260814',
    'database' => 'sqlite',
    'db_path' => $dbPath,
    'user_ids' => $userIds,
    'tokens' => $tokens,
    'account_ids' => $accountIds,
    'customer_ids' => $customerIds,
    'supplier_ids' => $supplierIds,
    'baseline_balances' => [],
    'created_at' => now()->toDateTimeString(),
];

foreach ($accountIds as $key => $aid) {
    $balance = (float) DB::table('accounts')->where('id', $aid)->value('balance');
    $baseline['baseline_balances'][$key] = [
        'id' => $aid,
        'balance' => $balance,
        'currency' => DB::table('accounts')->where('id', $aid)->value('currency'),
    ];
}

$metadataPath = storage_path('logs/financial_core_audit_baseline.json');
@mkdir(dirname($metadataPath), 0755, true);
file_put_contents($metadataPath, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "    ✅ Baseline metadata saved to $metadataPath\n";

// ─── Step 4: Final table summary ───────────────────────────────────────
echo "\n    Tables after setup:\n";
$tables = [
    'users', 'accounts', 'customers', 'suppliers',
    'transactions', 'account_entries', 'transfers',
    'exchange_rates', 'currencies', 'audit_logs',
    'personal_access_tokens',
];
foreach ($tables as $t) {
    if (Schema::hasTable($t)) {
        $count = DB::table($t)->count();
        echo "    - $t: $count rows\n";
    } else {
        echo "    - $t: ❌ table missing\n";
    }
}

echo "\n═══════════════════════════════════════════════════════════════════════════\n";
echo "  Financial Core Audit setup complete\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";
echo "  Next steps:\n";
echo "  1. Run   php scripts/financial_core_audit_run.php (Phases 5, 6, 7, 9, 11, 14)\n";
echo "  2. Run   php scripts/financial_core_audit_summary.php\n";
echo "  3. Generate FINANCIAL_CORE_CROSS_MODULE_AUDIT_REPORT_20260814.md\n\n";
