<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — 2026-08-13 AUDIT SETUP (UI-Driven Full E2E)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يجهز بيئة الـ audit الكامل لموديول الطيران:
 *   - SQLite معزول في storage/app/local_flight_audit.sqlite
 *   - كل الـ migrations تتطبق fresh
 *   - Seed data شامل بـ prefix "TX-FLIGHT-E2E-20260813-" بما فيهم:
 *     * 3 users بأدوار مختلفة (admin, manager, employee) — للـ auth matrix
 *     * Multi-currency setup (EGP, USD, KWD, SAR, EUR, AED)
 *     * 1 Treasury row per currency — للـ refund/recharge flows
 *     * 1 FlightSystem + 1 FlightCarrier + 1 FlightGroup per currency
 *     * 1 Airport pair (CAI / JED) — لـ booking flow
 *     * Exchange rates (EGP base)
 *   - Sanctum auth_token بيتطبع عشان باقي الـ scripts تستفيد منه
 *
 * الـ Design ينسخ الـ pattern من scripts/bus_audit_setup.php
 *
 * التشغيل:
 *   cd C:\travile\SafarakEalayna
 *   php scripts/flight_audit_setup.php
 *
 * الـ Output:
 *   - storage/app/local_flight_audit.sqlite   ← قاعدة البيانات المعزولة
 *   - storage/logs/flight_audit_setup.json    ← metadata (auth_token, IDs, etc.)
 */

// ─── Step 0: Force SQLite BEFORE bootstrap ──────────────────────────────
$dbPath = realpath(__DIR__.'/..').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'local_flight_audit.sqlite';

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

// ─── Step 0.5: Move MySQL-specific migrations out of the way (SQLite can't) ──
//   The Bus audit already handles the income_unique_key migration.
//   Flight-related MySQL-only migrations are guarded by Schema::getConnection()->
//   getDriverName() checks, so they should be safe to run. But we still stage them
//   defensively to avoid surprises.
$_mysqlOnlyDisabled = __DIR__.'/../database/migrations/.disabled_for_sqlite';
@mkdir($_mysqlOnlyDisabled, 0755, true);
$_mysqlOnlyFiles = [
    '2026_08_12_120000_add_income_unique_key_to_transactions.php', // already moved by Bus audit
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
echo "  Flight Module — 2026-08-13 Audit Setup\n";
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

// Re-bind kernel reference (the migrations used the kernel above; safe to refresh)
$kernel = $app->make(Kernel::class);

// ─── Step 2: Seed minimal data ──────────────────────────────────────────
echo "    ℹ  Seeding audit data...\n";

// 2.1 Create 3 users with different roles for auth matrix
$adminId = DB::table('users')->insertGetId([
    'name' => 'TX-FLIGHT-E2E-20260813 Admin',
    'email' => 'admin@tx-flight-audit.local',
    'password' => bcrypt('password'),
    'role' => 'owner',
    'is_active' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);

$managerId = DB::table('users')->insertGetId([
    'name' => 'TX-FLIGHT-E2E-20260813 Manager',
    'email' => 'manager@tx-flight-audit.local',
    'password' => bcrypt('password'),
    'role' => 'manager',
    'is_active' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);

$employeeId = DB::table('users')->insertGetId([
    'name' => 'TX-FLIGHT-E2E-20260813 Employee',
    'email' => 'employee@tx-flight-audit.local',
    'password' => bcrypt('password'),
    'role' => 'employee',
    'is_active' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);

$financeId = DB::table('users')->insertGetId([
    'name' => 'TX-FLIGHT-E2E-20260813 Finance',
    'email' => 'finance@tx-flight-audit.local',
    'password' => bcrypt('password'),
    'role' => 'head_of_finance',
    'is_active' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "    ✅ 4 users created: admin=$adminId, manager=$managerId, employee=$employeeId, finance=$financeId\n";

// 2.2 Insert Sanctum auth tokens for the 4 users (for HTTP contract tests)
$adminToken = null;
$managerToken = null;
$employeeToken = null;
$financeToken = null;

if (Schema::hasTable('personal_access_tokens')) {
    $tokenNames = ['flight-audit-admin', 'flight-audit-manager', 'flight-audit-employee', 'flight-audit-finance'];
    $userIds = [$adminId, $managerId, $employeeId, $financeId];
    $tokenRefs = ['adminToken', 'managerToken', 'employeeToken', 'financeToken'];

    foreach ($userIds as $i => $uid) {
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
        ${$tokenRefs[$i]} = $uid.'|'.$plain;
    }
    echo "    ✅ Sanctum tokens issued for 4 users\n";
} else {
    echo "    ⚠️  personal_access_tokens table missing — API contract tests will need workaround\n";
}

// 2.3 Run clearing accounts + unified vaults seeders (if exist)
$seeders = ['Database\\Seeders\\UnifiedVaultsSeeder', 'Database\\Seeders\\CurrencySeeder', 'Database\\Seeders\\FlightLedgerClearingAccountSeeder'];
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

// 2.4 Exchange rates (EGP base — per FlightBookingService::FALLBACK_EGP_PER_UNIT)
$today = now()->toDateString();
$rates = [
    ['USD', 'EGP', 48.5], ['EGP', 'USD', 1 / 48.5],
    ['SAR', 'EGP', 12.9], ['EGP', 'SAR', 1 / 12.9],
    ['KWD', 'EGP', 157.5], ['EGP', 'KWD', 1 / 157.5],
    ['EUR', 'EGP', 52.3], ['EGP', 'EUR', 1 / 52.3],
    ['AED', 'EGP', 13.2], ['EGP', 'AED', 1 / 13.2],
    ['GBP', 'EGP', 61.2], ['EGP', 'GBP', 1 / 61.2],
];
foreach ($rates as [$from, $to, $rate]) {
    DB::table('exchange_rates')->insert([
        'from_currency' => $from, 'to_currency' => $to,
        'rate' => round($rate, 6), 'effective_date' => $today, 'is_active' => 1,
        'created_by' => $adminId, 'created_at' => now(), 'updated_at' => now(),
    ]);
}
echo "    ✅ Exchange rates seeded (USD/SAR/KWD/EUR/AED/GBP pairs)\n";

// 2.5 Currencies (ensure active currencies exist)
if (Schema::hasTable('currencies')) {
    $existingCount = DB::table('currencies')->count();
    if ($existingCount === 0) {
        $currencies = [
            ['code' => 'EGP', 'name_ar' => 'جنيه مصري', 'name_en' => 'Egyptian Pound', 'symbol' => 'E£', 'exchange_rate' => 1.0, 'is_active' => 1, 'order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'USD', 'name_ar' => 'دولار أمريكي', 'name_en' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 48.5, 'is_active' => 1, 'order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'SAR', 'name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal', 'symbol' => 'SAR', 'exchange_rate' => 12.9, 'is_active' => 1, 'order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'KWD', 'name_ar' => 'دينار كويتي', 'name_en' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'exchange_rate' => 157.5, 'is_active' => 1, 'order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'EUR', 'name_ar' => 'يورو', 'name_en' => 'Euro', 'symbol' => '€', 'exchange_rate' => 52.3, 'is_active' => 1, 'order' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'AED', 'name_ar' => 'درهم إماراتي', 'name_en' => 'UAE Dirham', 'symbol' => 'AED', 'exchange_rate' => 13.2, 'is_active' => 1, 'order' => 6, 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('currencies')->insert($currencies);
        echo "    ✅ 6 currencies seeded (EGP base, USD/SAR/KWD/EUR/AED)\n";
    } else {
        echo "    ℹ  Currencies table already has $existingCount rows\n";
    }
}

// 2.6 Treasuries (one per currency for multi-currency refund/recharge flows)
$treasuryIds = [];
if (Schema::hasTable('treasuries')) {
    $treasuryCurrencies = [
        ['EGP', 'TX-FLIGHT-E2E-20260813 EGP Treasury', 100000.00],
        ['USD', 'TX-FLIGHT-E2E-20260813 USD Treasury', 5000.00],
        ['SAR', 'TX-FLIGHT-E2E-20260813 SAR Treasury', 5000.00],
        ['KWD', 'TX-FLIGHT-E2E-20260813 KWD Treasury', 1000.00],
        ['EUR', 'TX-FLIGHT-E2E-20260813 EUR Treasury', 5000.00],
        ['AED', 'TX-FLIGHT-E2E-20260813 AED Treasury', 5000.00],
    ];
    foreach ($treasuryCurrencies as [$cur, $name, $balance]) {
        $id = DB::table('treasuries')->insertGetId([
            'name' => $name,
            'currency' => $cur,
            'current_balance' => $balance,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $treasuryIds[$cur] = $id;
    }
    echo "    ✅ 6 treasuries seeded (one per currency)\n";
} else {
    echo "    ⚠️  treasuries table missing — refund/recharge flows will be skipped\n";
}

// 2.6.1 Cashbox Accounts (one per currency — needed by existing baseline)
//     The existing flight_module_full_e2e.php looks for Account::where('type', AccountType::Cashbox)
$cashboxAccountIds = [];
if (Schema::hasTable('accounts')) {
    $cashboxCurrencies = [
        ['EGP', 'TX-FLIGHT-E2E-20260813 EGP Cashbox', 100000.00],
        ['USD', 'TX-FLIGHT-E2E-20260813 USD Cashbox', 5000.00],
        ['SAR', 'TX-FLIGHT-E2E-20260813 SAR Cashbox', 5000.00],
        ['KWD', 'TX-FLIGHT-E2E-20260813 KWD Cashbox', 1000.00],
        ['EUR', 'TX-FLIGHT-E2E-20260813 EUR Cashbox', 5000.00],
        ['AED', 'TX-FLIGHT-E2E-20260813 AED Cashbox', 5000.00],
    ];
    foreach ($cashboxCurrencies as [$cur, $name, $balance]) {
        $id = DB::table('accounts')->insertGetId([
            'name' => $name,
            'type' => 'cashbox',
            'currency' => $cur,
            'balance' => $balance,
            'is_active' => 1,
            'module_type' => 'tourism',  // Division, not module — Flight belongs to "tourism" division
            'is_module_vault' => 1,
            'module' => 'flights',
            'treasury_type' => 'cashbox',
            'created_by' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cashboxAccountIds[$cur] = $id;

        // F-4 fix (2026-08-14, audit remediation Group 2.1):
        // Create an opening `account_entries` row alongside each seeded account
        // so the canonical formula `balance = SUM(credit) - SUM(debit)` holds
        // (Account.php docblock lines 27-47). Without this, every seeded
        // account starts with `balance > 0` but `ledger_net = 0`, producing
        // a drift equal to the seed balance. AccountService::createAccount
        // already does this correctly; the seed bypasses AccountService for
        // performance, so we mirror its opening-entry logic here.
        if ((float) $balance != 0.0) {
            DB::table('account_entries')->insert([
                'account_id' => $id,
                'transaction_id' => null, // opening balance, no parent transaction
                'debit' => $balance < 0 ? abs((float) $balance) : 0,
                'credit' => $balance > 0 ? (float) $balance : 0,
                'balance_after' => (float) $balance,
                'notes' => 'رصيد افتتاحي — seed (F-4)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
    echo "    ✅ 6 cashbox accounts seeded (one per currency) + opening entries (F-4)\n";
}

// 2.7 Airports (CAI/JED for popular flow, plus extras for full coverage)
if (Schema::hasTable('airports')) {
    $airports = [
        ['iata_code' => 'CAI', 'icao_code' => 'HECA', 'city_name_ar' => 'القاهرة', 'city_name_en' => 'Cairo', 'airport_name_ar' => 'مطار القاهرة الدولي', 'airport_name_en' => 'Cairo International Airport', 'country_code' => 'EG', 'country_name_ar' => 'مصر', 'country_name_en' => 'Egypt', 'timezone' => 'Africa/Cairo', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['iata_code' => 'JED', 'icao_code' => 'OEJN', 'city_name_ar' => 'جدة', 'city_name_en' => 'Jeddah', 'airport_name_ar' => 'مطار الملك عبدالعزيز الدولي', 'airport_name_en' => 'King Abdulaziz International Airport', 'country_code' => 'SA', 'country_name_ar' => 'السعودية', 'country_name_en' => 'Saudi Arabia', 'timezone' => 'Asia/Riyadh', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['iata_code' => 'RUH', 'icao_code' => 'OERK', 'city_name_ar' => 'الرياض', 'city_name_en' => 'Riyadh', 'airport_name_ar' => 'مطار الملك خالد الدولي', 'airport_name_en' => 'King Khalid International Airport', 'country_code' => 'SA', 'country_name_ar' => 'السعودية', 'country_name_en' => 'Saudi Arabia', 'timezone' => 'Asia/Riyadh', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['iata_code' => 'KWI', 'icao_code' => 'OKBK', 'city_name_ar' => 'الكويت', 'city_name_en' => 'Kuwait', 'airport_name_ar' => 'مطار الكويت الدولي', 'airport_name_en' => 'Kuwait International Airport', 'country_code' => 'KW', 'country_name_ar' => 'الكويت', 'country_name_en' => 'Kuwait', 'timezone' => 'Asia/Kuwait', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['iata_code' => 'DXB', 'icao_code' => 'OMDB', 'city_name_ar' => 'دبي', 'city_name_en' => 'Dubai', 'airport_name_ar' => 'مطار دبي الدولي', 'airport_name_en' => 'Dubai International Airport', 'country_code' => 'AE', 'country_name_ar' => 'الإمارات', 'country_name_en' => 'UAE', 'timezone' => 'Asia/Dubai', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
    ];
    foreach ($airports as $airport) {
        DB::table('airports')->insert($airport);
    }
    echo "    ✅ 5 airports seeded (CAI, JED, RUH, KWI, DXB)\n";
}

// 2.8 Flight System (one per currency for multi-currency carrier coverage)
$flightSystemIds = [];
if (Schema::hasTable('flight_systems')) {
    $systems = [
        ['TX-FLIGHT-E2E-20260813 Amadeus (USD)', 'AMADEUS-USD', 'gds', 'USD', 0, 0, 1, 'Multi-currency GDS system', $adminId],
        ['TX-FLIGHT-E2E-20260813 Sabre (SAR)', 'SABRE-SAR', 'gds', 'SAR', 0, 0, 1, 'Saudi GDS', $adminId],
    ];
    foreach ($systems as [$name, $code, $type, $cur, $bal, $lim, $active, $desc, $uid]) {
        $id = DB::table('flight_systems')->insertGetId([
            'name' => $name, 'code' => $code, 'type' => $type, 'is_active' => $active,
            'currency' => $cur, 'balance' => $bal, 'credit_limit' => $lim,
            'description' => $desc, 'created_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $flightSystemIds[$cur] = $id;
    }
    echo "    ✅ 2 flight systems seeded (Amadeus USD, Sabre SAR)\n";
}

// 2.9 Flight Carriers (one per currency)
$flightCarrierIds = [];
if (Schema::hasTable('flight_carriers')) {
    $carriers = [
        ['TX-FLIGHT-E2E-20260813 EgyptAir (EGP)', 'EGYPTAIR-EGP', 'MS', 'EGP', 0, 50000, $flightSystemIds['USD'] ?? null, $adminId],
        ['TX-FLIGHT-E2E-20260813 Saudia (SAR)', 'SAUDIA-SAR', 'SV', 'SAR', 0, 50000, $flightSystemIds['SAR'] ?? null, $adminId],
        ['TX-FLIGHT-E2E-20260813 Kuwait Airways (KWD)', 'KUWAIT-KWD', 'KU', 'KWD', 0, 500, $flightSystemIds['USD'] ?? null, $adminId],
        ['TX-FLIGHT-E2E-20260813 Emirates (AED)', 'EMIRATES-AED', 'EK', 'AED', 0, 50000, $flightSystemIds['USD'] ?? null, $adminId],
    ];
    foreach ($carriers as [$name, $code, $iata, $cur, $bal, $lim, $sysId, $uid]) {
        $id = DB::table('flight_carriers')->insertGetId([
            'flight_system_id' => $sysId, 'name' => $name, 'code' => $code, 'iata_code' => $iata,
            'currency' => $cur, 'balance' => $bal, 'credit_limit' => $lim, 'is_active' => 1,
            'created_by' => $uid, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $flightCarrierIds[$cur] = $id;
    }
    echo "    ✅ 4 flight carriers seeded (EGP/SAR/KWD/AED)\n";
}

// 2.10 Flight Groups (one per carrier, with notification thresholds)
$flightGroupIds = [];
if (Schema::hasTable('flight_groups')) {
    $groups = [
        ['TX-FLIGHT-E2E-20260813 VIP Group (EGP)', 'VIP-EGP', $flightCarrierIds['EGP'] ?? null, 'EGP', 5.0, 999999999, $adminId],
        ['TX-FLIGHT-E2E-20260813 Corporate KSA (SAR)', 'CORP-SAR', $flightCarrierIds['SAR'] ?? null, 'SAR', 3.5, 999999999, $adminId],
    ];
    foreach ($groups as [$name, $code, $carrierId, $cur, $comm, $lim, $uid]) {
        $id = DB::table('flight_groups')->insertGetId([
            'flight_carrier_id' => $carrierId, 'name' => $name, 'code' => $code,
            'currency' => $cur, 'commission_rate' => $comm, 'credit_limit' => $lim,
            'contact_person' => 'TX TEST', 'contact_phone' => '+201234567890', 'contact_email' => 'test@tx.local',
            'notification_threshold_info' => 5000, 'notification_threshold_warning' => 2000,
            'notification_threshold_danger' => 500, 'notify_via_toast' => 1, 'notify_via_widget' => 1, 'notify_via_bell' => 1,
            'is_active' => 1, 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $flightGroupIds[$cur] = $id;
    }
    echo "    ✅ 2 flight groups seeded (EGP/SAR)\n";
}

// 2.11 Customer + Account (for booking flow)
$customerId = DB::table('customers')->insertGetId([
    'name' => 'TX-FLIGHT-E2E-20260813 Customer',
    'full_name' => 'TX-FLIGHT-E2E-20260813 Customer',
    'phone' => '+201234567890',
    'email' => 'customer@tx-flight-audit.local',
    'module_type' => 'flights',
    'status' => 'active',
    'created_at' => now(),
    'updated_at' => now(),
]);

$accountId = DB::table('accounts')->insertGetId([
    'name' => 'TX-FLIGHT-E2E-20260813 Customer Account',
    'type' => 'customer',
    'currency' => 'EGP',
    'balance' => 0,
    'module_type' => 'flights',
    'is_active' => 1,
    'owner_type' => 'App\\Models\\Customer',
    'created_by' => $adminId,
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "    ✅ 1 customer + 1 account seeded (customer_id=$customerId, account_id=$accountId)\n";

// ─── Step 3: Print metadata for downstream scripts ─────────────────────
$metadata = [
    'audit_id' => 'FLIGHT_E2E_20260813',
    'database' => 'sqlite',
    'db_path' => $dbPath,
    'admin_id' => $adminId,
    'manager_id' => $managerId,
    'employee_id' => $employeeId,
    'finance_id' => $financeId,
    'admin_token' => $adminToken,
    'manager_token' => $managerToken,
    'employee_token' => $employeeToken,
    'finance_token' => $financeToken,
    'customer_id' => $customerId,
    'account_id' => $accountId,
    'treasury_ids' => $treasuryIds,
    'cashbox_account_ids' => $cashboxAccountIds,
    'flight_system_ids' => $flightSystemIds,
    'flight_carrier_ids' => $flightCarrierIds,
    'flight_group_ids' => $flightGroupIds,
    'created_at' => now()->toDateTimeString(),
];

$metadataPath = storage_path('logs/flight_audit_setup.json');
@mkdir(dirname($metadataPath), 0755, true);
file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT));
echo "    ✅ Metadata saved to $metadataPath\n";

// ─── Step 4: Print final summary ─────────────────────────────────────────
echo "\n    Tables after setup:\n";
$tables = ['users', 'accounts', 'customers', 'flight_bookings', 'flight_carriers', 'flight_groups',
    'flight_systems', 'flight_segments', 'flight_payments', 'flight_refunds', 'refund_requests',
    'airline_credits', 'airline_transactions', 'airline_accounts', 'airports', 'transactions',
    'account_entries', 'exchange_rates', 'currencies', 'treasuries', 'ticket_modifications',
    'flight_tickets', 'passengers', 'flight_pricing', 'flight_pricings', 'flight_group_transactions',
    'flight_system_transactions', 'flights'];
foreach ($tables as $t) {
    if (Schema::hasTable($t)) {
        $count = DB::table($t)->count();
        echo "    - $t: $count rows\n";
    } else {
        echo "    - $t: ❌ table missing\n";
    }
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  Flight Audit setup complete\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";
echo "  Next steps:\n";
echo "  1. Run   php scripts/flight_audit_phase_a_auth.php\n";
echo "  2. Run   php scripts/flight_audit_phase_baseline.php (re-runs flight_module_full_e2e.php)\n";
echo "  3. Run   php scripts/flight_audit_phase_h_multicurrency.php\n";
echo "  4. Run   php scripts/flight_audit_phase_i_transaction.php\n";
echo "  5. Run   php scripts/flight_audit_phase_* (remaining)\n";
echo "  6. Generate final report FLIGHT_MODULE_FULL_E2E_AUDIT_20260813.md\n\n";
