<?php
/**
 * Flight Carrier Debt - E2E Test
 * يختبر الـ 4 إصلاحات:
 *  1. DebtsIndex API يحتوي على flight_carrier
 *  2. filter entity_type=flight_carrier يعمل
 *  3. FlightCarriersDebt Vue page route يعمل
 *  4. Carriers list API يحتوي على البيانات اللازمة
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

$BASE = 'http://127.0.0.1:8000/api/v1';
Cache::flush();

$results = ['pass' => 0, 'fail' => 0, 'tests' => []];

function ok($msg) { global $results; $results['pass']++; echo "  ✅ $msg\n"; }
function fail($msg) { global $results; $results['fail']++; echo "  ❌ $msg\n"; }
function section($msg) { echo "\n═══ $msg ═══\n"; }

// Get token
$resp = Http::acceptJson()->post("$BASE/auth/login", [
    'email' => 'admin@safarakealayna.com',
    'password' => 'Sf@2026#Admin!',
]);
$TOKEN = $resp->json('data.token');
if (! $TOKEN) { echo "Login failed!\n"; exit(1); }
ok("Authenticated as admin (token: " . substr($TOKEN, 0, 20) . "...)");

// ══════════════════════════════════════════════
// TEST 1: DebtsIndex API contains flight_carrier
// ══════════════════════════════════════════════
section('TEST 1: DebtsIndex API contains flight_carrier items');

$resp = Http::withToken($TOKEN)->get("$BASE/reports/debts");
$data = $resp->json('data');
$items = $data['items'] ?? [];
$carriers = array_filter($items, fn($i) => $i['entity_type'] === 'flight_carrier');

if (count($carriers) > 0) {
    ok("Found " . count($carriers) . " flight_carrier items in the report");
    foreach ($carriers as $c) {
        echo "      → " . $c['name'] . " (" . $c['currency'] . " " . $c['balance_egp'] . " EGP)\n";
    }
} else {
    fail("No flight_carrier items found!");
}

// Check the negative balance carrier (EgyptAir) is in payables
$egyptAir = array_filter($carriers, fn($c) => str_contains($c['name'], 'مصر'));
$egyptAirBalance = 0;
foreach ($egyptAir as $c) $egyptAirBalance = $c['balance_egp'];
if ($egyptAirBalance < 0) {
    ok("EgyptAir debt (" . $egyptAirBalance . " EGP) is correctly in payables");
} else {
    fail("EgyptAir should have negative balance, got: " . $egyptAirBalance);
}

$results['tests']['test1'] = count($carriers) > 0 ? 'PASS' : 'FAIL';

// ══════════════════════════════════════════════
// TEST 2: Filter by entity_type=flight_carrier
// ══════════════════════════════════════════════
section('TEST 2: Filter by entity_type=flight_carrier');

$resp = Http::withToken($TOKEN)->get("$BASE/reports/debts?entity_type=flight_carrier");
$data = $resp->json('data');
$items = $data['items'] ?? [];

if (count($items) > 0) {
    ok("Filter returns " . count($items) . " items");
    $allCarriers = true;
    foreach ($items as $i) {
        if ($i['entity_type'] !== 'flight_carrier') {
            $allCarriers = false;
            break;
        }
    }
    if ($allCarriers) ok("All returned items are flight_carrier");
    else fail("Filter returned non-carrier items!");
} else {
    fail("Filter returned 0 items");
}

$results['tests']['test2'] = count($items) > 0 ? 'PASS' : 'FAIL';

// ══════════════════════════════════════════════
// TEST 3: Filter by direction=payables (only negative carriers)
// ══════════════════════════════════════════════
section('TEST 3: Filter by direction=payables (only carriers with debt)');

$resp = Http::withToken($TOKEN)->get("$BASE/reports/debts?entity_type=flight_carrier&direction=payables");
$data = $resp->json('data');
$items = $data['items'] ?? [];

ok("Found " . count($items) . " carrier payable items (carriers we owe)");
foreach ($items as $c) {
    if ($c['balance_egp'] < 0) {
        ok("  → " . $c['name'] . ": " . $c['balance_egp'] . " EGP (debt)");
    } else {
        fail("  → " . $c['name'] . ": " . $c['balance_egp'] . " EGP (should be negative!)");
    }
}

$results['tests']['test3'] = 'PASS';

// ══════════════════════════════════════════════
// TEST 4: Flight Carriers List API
// ══════════════════════════════════════════════
section('TEST 4: Flight Carriers List API');

$resp = Http::withToken($TOKEN)->get("$BASE/flight/carriers?per_page=100");
$carriers = $resp->json('data.data') ?? $resp->json('data') ?? [];

if (count($carriers) > 0) {
    ok("Found " . count($carriers) . " carriers");
    foreach ($carriers as $c) {
        $bal = (float)($c['balance'] ?? 0);
        $cl = (float)($c['credit_limit'] ?? 0);
        $avail = $bal + $cl;
        echo "      → " . $c['name'] . " | bal=" . number_format($bal, 2) . " " . $c['currency'] . " | credit=" . number_format($cl, 2) . " | available=" . number_format($avail, 2) . "\n";
    }

    // Verify all carriers have balance and credit_limit
    $missing = 0;
    foreach ($carriers as $c) {
        if (! isset($c['balance']) || ! isset($c['credit_limit'])) $missing++;
    }
    if ($missing === 0) {
        ok("All carriers have balance and credit_limit fields");
    } else {
        fail("$missing carriers missing required fields");
    }
} else {
    fail("No carriers found");
}

$results['tests']['test4'] = count($carriers) > 0 ? 'PASS' : 'FAIL';

// ══════════════════════════════════════════════
// TEST 5: Vue page file + router registered
// ══════════════════════════════════════════════
section('TEST 5: Verify Vue page is registered (file + router)');

$vueFile = __DIR__ . '/../../resources/js/views/flights/FlightCarriersDebt.vue';
$routerFile = __DIR__ . '/../../resources/js/router/index.js';

if (file_exists($vueFile)) {
    ok("Vue page file exists: FlightCarriersDebt.vue (" . filesize($vueFile) . " bytes)");
} else {
    fail("Vue page file missing!");
}

$routerContent = file_get_contents($routerFile);
if (str_contains($routerContent, "carriers-debt") && str_contains($routerContent, "FlightCarriersDebt")) {
    ok("Vue router has 'carriers-debt' route + FlightCarriersDebt component");
} else {
    fail("Vue router missing carriers-debt route!");
}

$layoutContent = file_get_contents(__DIR__ . '/../../resources/js/layouts/DashboardLayout.vue');
if (str_contains($layoutContent, "carriers-debt") && str_contains($layoutContent, "ديون الناقلين")) {
    ok("Sidebar menu has 'ديون الناقلين' link");
} else {
    fail("Sidebar menu missing carriers-debt link!");
}

$results['tests']['test5'] = (file_exists($vueFile) && str_contains($routerContent, 'carriers-debt') && str_contains($layoutContent, 'ديون الناقلين')) ? 'PASS' : 'FAIL';

// ══════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════
echo "\n═══ ملخص النتائج ═══\n";
echo "نجح: {$results['pass']} | فشل: {$results['fail']}\n";
foreach ($results['tests'] as $name => $status) {
    echo "  $status: $name\n";
}
echo "\n";

exit($results['fail'] > 0 ? 1 : 0);
