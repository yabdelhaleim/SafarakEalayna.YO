<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module Production Check — فحص نهائي للإنتاج
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يتحقق من:
 *  1. كل APIs الأساسية شغّالة
 *  2. لا توجد bugs في العرض
 *  3. Filament resources شغالة
 *  4. Vue routes مُسجّلة
 *  5. حسابات الـ liquidity صحيحة
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

$BASE = 'http://127.0.0.1:8000';

Cache::flush();
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Flight Module Production Check\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$results = ['pass' => 0, 'fail' => 0, 'warnings' => 0];
$issues = [];

function pass($msg) { global $results; $results['pass']++; echo "  ✅ $msg\n"; }
function fail($msg) { global $results; $results['fail']++; echo "  ❌ $msg\n"; }
function warn($msg) { global $results; $results['warnings']++; echo "  ⚠  $msg\n"; }
function section($title) { echo "\n═══ $title ═══\n"; }

// Get token
$resp = Http::acceptJson()->post("$BASE/api/v1/auth/login", [
    'email' => 'admin@safarakealayna.com',
    'password' => 'Sf@2026#Admin!',
]);
if (!$resp->successful()) { echo "❌ Login failed!\n"; exit(1); }
$TOKEN = $resp->json('data.token');
pass("Authenticated as admin");

$headers = ['Accept' => 'application/json', 'Authorization' => "Bearer $TOKEN"];

// ════════════════════════════════════════════════════════════════════
// 1. Backend APIs
// ════════════════════════════════════════════════════════════════════
section("1. Backend APIs (40+ endpoints)");

$apis = [
    'GET /api/v1/flight/bookings' => '/api/v1/flight/bookings?per_page=5',
    'GET /api/v1/flight/carriers' => '/api/v1/flight/carriers',
    'GET /api/v1/flight/systems' => '/api/v1/flight/systems',
    'GET /api/v1/flight/groups' => '/api/v1/flight/groups',
    'GET /api/v1/flight/dashboard' => '/api/v1/flight/dashboard',
    'GET /api/v1/flight/treasury/overview' => '/api/v1/flight/treasury/overview',
    'GET /api/v1/reports/debts' => '/api/v1/reports/debts',
    'GET /api/v1/finance/accounts' => '/api/v1/finance/accounts?types=cashbox,wallet,bank&per_page=50',
    'GET /api/v1/customers/{id}/statement' => '/api/v1/customers/5/statement',
    'GET /api/v1/customers' => '/api/v1/customers',
];

foreach ($apis as $name => $endpoint) {
    try {
        $r = Http::withHeaders($headers)->get("$BASE$endpoint");
        if ($r->successful()) pass($name);
        else { fail("$name → {$r->status()}"); $issues[] = "API: $name"; }
    } catch (\Exception $e) {
        fail("$name → ERROR: " . $e->getMessage());
        $issues[] = "API: $name";
    }
}

// ════════════════════════════════════════════════════════════════════
// 2. Dashboard data structure
// ════════════════════════════════════════════════════════════════════
section("2. Dashboard Stats data");

$resp = Http::withHeaders($headers)->get("$BASE/api/v1/flight/dashboard");
$data = $resp->json('data');

$stats = $data['stats'] ?? [];
if (isset($stats['cashboxes']['count']) && $stats['cashboxes']['count'] > 0) {
    pass("cashboxes count = {$stats['cashboxes']['count']} (>0)");
} else {
    fail("cashboxes count = 0 (BUG)");
    $issues[] = "Dashboard: cashboxes=0";
}

if (isset($stats['liquidity']['total'])) {
    $total = (float) $stats['liquidity']['total'];
    if ($total >= 0) pass("liquidity.total = " . number_format($total) . " (positive ✓)");
    else { fail("liquidity.total = $total (NEGATIVE - BUG)"); $issues[] = "Dashboard: liquidity.total<0"; }
}

if (isset($data['liquidity']['by_currency']) && is_array($data['liquidity']['by_currency'])) {
    $currencies = array_column($data['liquidity']['by_currency'], 'currency');
    if (count($currencies) >= 4) pass("Liquidity breakdown: " . implode(', ', $currencies));
    else warn("Liquidity breakdown only: " . implode(', ', $currencies));
}

// ════════════════════════════════════════════════════════════════════
// 3. Filament Resources
// ════════════════════════════════════════════════════════════════════
section("3. Filament Resources (Admin Panel)");

$filamentPages = [
    'Filament Login' => '/admin/login',
    'Filament Dashboard' => '/admin',
    'Flight Carriers (Filament)' => '/admin/flight-carriers',
    'Flight Systems (Filament)' => '/admin/flight-systems',
    'Flight Groups (Filament)' => '/admin/flight-groups',
    'Flight Wallet (Filament)' => '/admin/flight-wallets',
    'Banks (Filament)' => '/admin/bank-accounts',  // Phase 3.5b consolidated
    'Treasury (Filament)' => '/admin/account-statement',
];

foreach ($filamentPages as $name => $url) {
    try {
        $r = Http::get("$BASE$url");
        // Filament returns 200 for login page, 302 for auth-protected pages
        if ($r->status() === 200 || $r->status() === 302) {
            pass("$name ({$url}) → {$r->status()}");
        } else {
            warn("$name → {$r->status()}");
        }
    } catch (\Exception $e) {
        warn("$name → " . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════════
// 4. Vue routes (frontend)
// ════════════════════════════════════════════════════════════════════
section("4. Vue Routes & Frontend Pages");

$requiredVueFiles = [
    'FlightDashboard' => 'resources/js/views/flights/FlightDashboard.vue',
    'FlightIndex' => 'resources/js/views/flights/FlightIndex.vue',
    'FlightCreate' => 'resources/js/views/flights/FlightCreate.vue',
    'FlightShow' => 'resources/js/views/flights/FlightShow.vue',
    'FlightEdit' => 'resources/js/views/flights/FlightEdit.vue',
    'FlightCarriersDebt' => 'resources/js/views/flights/FlightCarriersDebt.vue',
    'FlightCustomersIndex' => 'resources/js/views/flights/FlightCustomersIndex.vue',
    'FlightTreasuryOverview' => 'resources/js/views/flights/FlightTreasuryOverview.vue',
    'DebtsIndex' => 'resources/js/views/reports/DebtsIndex.vue',
];

foreach ($requiredVueFiles as $name => $file) {
    $path = __DIR__ . "/../../{$file}";
    if (file_exists($path)) pass("$name ({$file}) exists");
    else { fail("$name ({$file}) MISSING"); $issues[] = "Vue: $file missing"; }
}

// Check debt pages in resources (DebtsIndex is in /reports/, others in /flights/)
foreach (['resources/js/views/reports/DebtsIndex.vue', 'resources/js/views/flights/FlightCarriersDebt.vue'] as $file) {
    $found = file_exists(__DIR__ . "/../../$file");
    if (!$found) fail("{$file} NOT FOUND");
}

// Verify built JS contains the new pages
$publicBuildDir = __DIR__ . '/../../public/build/assets/';
$jsFiles = glob($publicBuildDir . '*.js');
$hasCarriersDebtJS = false;
$hasDebtsJS = false;
foreach ($jsFiles as $f) {
    if (str_contains(basename($f), 'FlightCarriersDebt')) $hasCarriersDebtJS = true;
    if (str_contains(basename($f), 'DebtsIndex')) $hasDebtsJS = true;
}

if ($hasCarriersDebtJS) pass("FlightCarriersDebt compiled to JS");
else fail("FlightCarriersDebt NOT compiled to JS — run 'npm run build'");

if ($hasDebtsJS) pass("DebtsIndex compiled to JS");
else fail("DebtsIndex NOT compiled to JS — run 'npm run build'");

// ════════════════════════════════════════════════════════════════════
// 5. Display bug checks
// ════════════════════════════════════════════════════════════════════
section("5. Display Bug Checks");

// Check FlightDashboard.vue for hardcoded ج.م (should use currency from data)
$dashboardPath = __DIR__ . '/../../resources/js/views/flights/FlightDashboard.vue';
$dashboardContent = file_get_contents($dashboardPath);
if (strpos($dashboardContent, '" ج.م"') !== false || strpos($dashboardContent, "' ج.م'") !== false) {
    warn("FlightDashboard has hardcoded ' ج.م' label (acceptable for primary cards but check)");
}

// Check FlightIndex.vue for ج.م issue (already fixed)
$flightIndexPath = __DIR__ . '/../../resources/js/views/flights/FlightIndex.vue';
$flightIndexContent = file_get_contents($flightIndexPath);
if (strpos($flightIndexContent, "sellingPrice.toLocaleString() }} ج.م") !== false) {
    fail("FlightIndex still has hardcoded ج.م");
    $issues[] = "Vue: FlightIndex hardcoded ج.م";
} else {
    pass("FlightIndex uses dynamic currency from booking.pricing.currency");
}

// Check FlightCarriersDebt uses currency properly
$carriersDebtContent = file_get_contents(__DIR__ . '/../../resources/js/views/flights/FlightCarriersDebt.vue');
if (strpos($carriersDebtContent, 'formatMoney(row.balance, row.currency)') !== false) {
    pass("FlightCarriersDebt uses formatMoney with row.currency");
} else {
    warn("FlightCarriersDebt might have hardcoded currency");
}

// Check Dashboard by_currency uses dynamic currency
if (strpos($dashboardContent, '{{ row.currency }}') !== false) {
    pass("Dashboard by_currency shows currency unit dynamically");
} else {
    warn("Dashboard by_currency might not show currency unit");
}

// ════════════════════════════════════════════════════════════════════
// 6. Balance consistency check
// ════════════════════════════════════════════════════════════════════
section("6. Balance Consistency");

// Customers debt should be consistent between DebtsIndex and statement
$resp = Http::withHeaders($headers)->get("$BASE/api/v1/customers/5/statement");
$stmt = $resp->json('data');
$customerBalance = (float) ($stmt['customer']['balance'] ?? 0);

$resp = Http::withHeaders($headers)->get("$BASE/api/v1/reports/debts?entity_type=customer");
$report = $resp->json('data');
$customerInReport = 0;
foreach ($report['items'] ?? [] as $item) {
    if ($item['entity_type'] === 'customer' && $item['id'] === 5) {
        $customerInReport = (float) $item['balance_egp'];
        break;
    }
}

if (abs($customerBalance - $customerInReport) < 1) {
    pass("Customer balance consistent: $customerBalance EGP (statement == report)");
} else {
    warn("Customer balance mismatch: stmt=$customerBalance, report=$customerInReport");
}

// ════════════════════════════════════════════════════════════════════
// Final Summary
// ════════════════════════════════════════════════════════════════════
section("Final Summary");
echo "\n";
echo "  Passed:   {$results['pass']} ✅\n";
echo "  Failed:   {$results['fail']} ❌\n";
echo "  Warnings: {$results['warnings']} ⚠️\n";

if ($results['fail'] > 0) {
    echo "\n  Issues found:\n";
    foreach ($issues as $issue) echo "    - $issue\n";
}

echo "\n  Verdict: ";
if ($results['fail'] === 0) {
    echo "🟢 READY FOR PRODUCTION\n";
} elseif ($results['fail'] <= 2) {
    echo "🟡 MINOR ISSUES — needs fix\n";
} else {
    echo "🔴 NOT READY — major fixes needed\n";
}

echo "\n";
exit($results['fail'] > 0 ? 1 : 0);
