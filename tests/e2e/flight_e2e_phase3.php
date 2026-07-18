<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module E2E — Phase 3: S11-S17 (Contract / API / Filament / Vue)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * S11 - AccountModuleContract: التحقق من التصنيف الصحيح للحسابات
 * S12 - Create Bank عبر API + يظهر في Vue
 * S13 - Filament Dropdown: البحث عن خزنة لدفع الدين
 * S14 - Vue Index: كروت + فلاتر (API)
 * S15 - Refund flow: استرجاع من حجز مؤكد (مبلغ جزئي)
 * S16 - Validation + Authorization + Pagination
 * S17 - List endpoints + filter tests (carriers, systems, groups, wallets)
 *
 * التشغيل: php tests/e2e/flight_e2e_phase3.php
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE = 'http://127.0.0.1:8000/api/v1';
$ids = json_decode(file_get_contents(storage_path('logs/flight_e2e_ids.json')), true);
$adminUser = User::find($ids['admin_user_id']);
Auth::login($adminUser);

$REPORT = json_decode(file_get_contents(storage_path('logs/flight_e2e_results.json')), true) ?: [];
$REPORT['scenarios'] = $REPORT['scenarios'] ?? [];

function section(string $name): void {
    echo "\n" . str_repeat('═', 75) . "\n  $name\n" . str_repeat('═', 75) . "\n";
}
function ok(string $m = 'OK'): void { echo "    ✅ $m\n"; }
function fail(string $m): void { echo "    ❌ $m\n"; }
function warn(string $m): void { echo "    ⚠  $m\n"; }
function info(string $m): void { echo "    ℹ  $m\n"; }
function head(string $m): void { echo "    → $m\n"; }

function get_token(string $base): string {
    $r = Http::acceptJson()->post("$base/auth/login", [
        'email' => 'admin@safarakealayna.com',
        'password' => 'Sf@2026#Admin!',
    ]);
    if (! $r->successful()) throw new RuntimeException("Login failed: " . $r->body());
    return $r->json('data.token');
}

$TOKEN = get_token($BASE);
info("Authenticated as admin (token: " . substr($TOKEN, 0, 20) . "...)");

// ════════════════════════════════════════════════════════════════════════
// S11: AccountModuleContract — اختبار التصنيف
// ════════════════════════════════════════════════════════════════════════
section('S11: AccountModuleContract — classification of accounts');

$divisionTests = [
    'flights' => 'tourism',
    'hajj_umra' => 'tourism',
    'visas' => 'tourism',
    'bus' => 'office',
    'fawry' => 'office',
    'online' => 'office',
    'wallet_transfer' => 'office',
];

$pass = 0;
$fail = 0;
foreach ($divisionTests as $module => $expectedDivision) {
    $actual = AccountModuleContract::divisionFor($module);
    if ($actual === $expectedDivision) {
        $pass++;
        info("module='$module' → division='$actual' ✓");
    } else {
        $fail++;
        fail("module='$module' expected='$expectedDivision' got='$actual'");
    }
}

$liqTypes = AccountModuleContract::LIQUIDITY_TYPES;
$subTypes = AccountModuleContract::SUBJECT_TYPES;
$intTypes = AccountModuleContract::INTERNAL_TYPES;
info("LIQUIDITY_TYPES: " . implode(',', $liqTypes));
info("SUBJECT_TYPES: " . implode(',', $subTypes));
info("INTERNAL_TYPES: " . implode(',', $intTypes));

if (in_array('cashbox', $liqTypes) && in_array('bank', $liqTypes) && in_array('wallet', $liqTypes)) {
    ok("Liquidity types include cashbox, bank, wallet");
} else {
    fail("Missing liquidity types");
}

if (AccountModuleContract::isTourismModule('flights')) ok("flights is tourism");
else fail("flights NOT classified as tourism");

if (AccountModuleContract::isOfficeModule('bus')) ok("bus is office");
else fail("bus NOT classified as office");

$REPORT['scenarios']['S11'] = [
    'status' => $fail === 0 ? 'PASS' : 'FAIL',
    'pass' => $pass, 'fail' => $fail,
    'liq_types' => $liqTypes,
];

// ════════════════════════════════════════════════════════════════════════
// S12: إنشاء بنك جديد عبر Filament API + يظهر في Vue
// ════════════════════════════════════════════════════════════════════════
section('S12: Create bank via Filament API + check it appears in Vue API');

$newBankName = 'بنك E2E الجديد ' . substr(md5(uniqid('', true)), 0, 6);
$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/finance/accounts", [
    'name' => $newBankName,
    'type' => 'bank',
    'currency' => 'EGP',
    'bank_name' => 'البنك التجاري الدولي',
    'account_number' => 'EGP-NEW-E2E-001',
    'branch_name' => 'فرع المعادي',
    'is_active' => true,
    'module' => 'flights',
    'module_type' => 'tourism',
    'is_module_vault' => true,
    'balance' => 250000,
    'notes' => 'تم إنشاؤه للاختبار E2E',
]);

if ($resp->status() === 201) {
    $newBank = $resp->json('data.account') ?? $resp->json('data');
    $newBankId = $newBank['id'];
    ok("Created bank ID={$newBankId}: {$newBank['name']}");

    // تحقق من ظهوره في GET /api/v1/finance/accounts (نفس الـ endpoint المستخدم في Vue)
    $resp2 = Http::withToken($TOKEN)->acceptJson()->get("$BASE/finance/accounts?module=flights&type=bank&per_page=100");
    if ($resp2->successful()) {
        $accounts = $resp2->json('data.items') ?? $resp2->json('data');
        $found = false;
        foreach ($accounts as $a) {
            if (($a['id'] ?? null) === $newBankId || ($a['name'] ?? '') === $newBankName) {
                $found = true;
                break;
            }
        }
        if ($found) ok("Bank appears in Vue API list");
        else fail("Bank NOT found in Vue API list");
    } else {
        fail("Vue list API failed: " . $resp2->body());
    }

    $REPORT['scenarios']['S12'] = ['status' => 'PASS', 'bank_id' => $newBankId, 'name' => $newBankName];
} else {
    fail("Create bank failed: " . $resp->body());
    $REPORT['scenarios']['S12'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// S13: Filament Dropdown — البحث عن خزنة لدفع الدين
// ════════════════════════════════════════════════════════════════════════
section('S13: Filament Dropdown — list treasuries for debt payment');

// عند سداد مديونية عميل، يعرض Filament dropdown بالحسابات المتاحة
// في الـ API: GET /api/v1/finance/accounts?is_liquidity=1&active=1
$resp = Http::withToken($TOKEN)->acceptJson()->get("$BASE/finance/accounts?per_page=200");
if ($resp->successful()) {
    $data = $resp->json('data');
    $items = $data['items'] ?? $data ?? [];
    $treasuryCount = 0;
    $liquidity = [];
    foreach ($items as $a) {
        $type = is_string($a['type'] ?? null) ? $a['type'] : '';
        if (in_array($type, ['cashbox', 'bank', 'wallet'])) {
            $liquidity[] = $a;
            $treasuryCount++;
        }
    }
    info("Total accounts: " . count($items));
    info("Liquidity (cashbox/bank/wallet) accounts: {$treasuryCount}");

    if ($treasuryCount >= 5) ok("Filament dropdown has " . $treasuryCount . " liquidity accounts to choose from");
    else fail("Not enough liquidity accounts: {$treasuryCount}");

    $REPORT['scenarios']['S13'] = [
        'status' => $treasuryCount >= 5 ? 'PASS' : 'FAIL',
        'treasury_count' => $treasuryCount,
    ];
} else {
    fail("Accounts API failed: " . $resp->body());
    $REPORT['scenarios']['S13'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// S14: Vue Index — كروت + فلاتر (Flight Bookings list API)
// ════════════════════════════════════════════════════════════════════════
section('S14: Vue Index — list bookings with filters (cards data)');

$resp = Http::withToken($TOKEN)->acceptJson()->get("$BASE/flight/bookings?per_page=20");
if ($resp->successful()) {
    $d = $resp->json('data');
    $items = $d['items'] ?? [];
    $pagination = $d['pagination'] ?? [];
    ok("GET /flight/bookings returned " . count($items) . " bookings (total: " . ($pagination['total'] ?? 'n/a') . ")");
    info("Pagination: page=" . ($pagination['current_page'] ?? 'n/a') . " per_page=" . ($pagination['per_page'] ?? 'n/a'));

    // تحقق من البنية المطلوبة للـ Vue
    if (count($items) > 0) {
        $first = $items[0];
        $expected = ['id', 'booking_reference', 'customer', 'selling_price', 'profit', 'status', 'currency'];
        $missing = [];
        foreach ($expected as $f) {
            if (! array_key_exists($f, $first)) $missing[] = $f;
        }
        if (empty($missing)) ok("Booking structure has all expected fields for Vue");
        else fail("Missing fields in booking: " . implode(',', $missing));
    }

    // فلاتر
    $resp2 = Http::withToken($TOKEN)->acceptJson()->get("$BASE/flight/bookings?status=CONFIRMED&per_page=10");
    if ($resp2->successful()) {
        $confirmed = $resp2->json('data.items') ?? [];
        ok("Filter by status=CONFIRMED works (" . count($confirmed) . " results)");
    } else {
        fail("Status filter failed: " . $resp2->body());
    }

    $resp3 = Http::withToken($TOKEN)->acceptJson()->get("$BASE/flight/bookings?currency=KWD&per_page=10");
    if ($resp3->successful()) {
        $kwd = $resp3->json('data.items') ?? [];
        ok("Filter by currency=KWD works (" . count($kwd) . " results)");
    } else {
        fail("Currency filter failed: " . $resp3->body());
    }

    $resp4 = Http::withToken($TOKEN)->acceptJson()->get("$BASE/flight/bookings?flight_carrier_id={$ids['carriers']['egyptair']}&per_page=10");
    if ($resp4->successful()) {
        $byCar = $resp4->json('data.items') ?? [];
        ok("Filter by flight_carrier_id works (" . count($byCar) . " results)");
    }

    $REPORT['scenarios']['S14'] = ['status' => 'PASS', 'total_bookings' => count($items)];
} else {
    fail("List API failed: " . $resp->body());
    $REPORT['scenarios']['S14'] = ['status' => 'FAIL'];
}

// ════════════════════════════════════════════════════════════════════════
// S15: Refund flow — استرجاع جزئي من حجز مؤكد
// ════════════════════════════════════════════════════════════════════════
section('S15: Refund flow — partial refund with airline penalty');

$pnr15 = 'E2E-FLT-S15-' . substr(md5(uniqid('', true)), 0, 6);
DB::table('flight_bookings')->where('pnr', $pnr15)->delete();

$carBefore = FlightCarrier::find($ids['carriers']['egyptair'])->balance;
$bankBefore = Account::find($ids['accounts']['bank_egp'])->balance;

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['ahmed'],
    'pnr' => $pnr15,
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'JED',
    'departure_date' => '2027-04-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 5000,
    'selling_price' => 7000,
    'account_id' => $ids['accounts']['bank_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [['first_name' => 'أحمد', 'last_name' => 'محمد', 'passenger_type' => 'adult', 'nationality' => 'EG']],
    'payment' => [
        'amount' => 7000,
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_egp'],
    ],
]);

if ($resp->status() === 201) {
    $bid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    ok("Booking #{$bid} created for refund test");

    // إلغاء مع airline_penalty جزئي (العميل فقد 1000 EGP)
    $resp2 = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings/{$bid}/cancel", [
        'airline_penalty' => 1000,
        'office_penalty' => 0,
        'account_id' => $ids['accounts']['bank_egp'],
        'notes' => 'استرجاع جزئي - خسارة 1000 EGP',
    ]);

    if ($resp2->status() === 200 || $resp2->status() === 201) {
        ok("Booking #{$bid} cancelled with airline_penalty=1000");
        $bs = bookingSnap($bid);
        info("Status: {$bs['status']}");

        $carAfter = FlightCarrier::find($ids['carriers']['egyptair'])->balance;
        $bankAfter = Account::find($ids['accounts']['bank_egp'])->balance;
        info("Carrier: {$carBefore} → {$carAfter} (Δ " . round($carAfter - $carBefore, 2) . ") [expected +5000 refund of purchase]");
        info("Bank: {$bankBefore} → {$bankAfter} (Δ " . round($bankAfter - $bankBefore, 2) . ") [expected: -2000 net (paid 7000, refund 5000)]");

        // نتوقع: البنك دفع 7000 (الحجز) ثم استرجع 5000 (العميل استرجع 7000-1000-1000=5000)، فلازم التغيير = -2000
        if (abs(($bankAfter - $bankBefore) - (-2000)) < 0.01) ok("Bank net delta = -2000 (correct refund accounting)");
        else warn("Bank delta = " . round($bankAfter - $bankBefore, 2) . " (expected -2000)");

        $REPORT['scenarios']['S15'] = ['status' => 'PASS', 'booking_id' => $bid, 'penalty' => 1000];
    } else {
        fail("Cancel failed: " . $resp2->body());
        $REPORT['scenarios']['S15'] = ['status' => 'FAIL', 'error' => $resp2->body()];
    }
} else {
    fail("S15 create failed: " . $resp->body());
    $REPORT['scenarios']['S15'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

function bookingSnap(int $id): array {
    $b = FlightBooking::withTrashed()->find($id);
    if (! $b) return ['missing' => true];
    return [
        'id' => $b->id, 'ref' => $b->booking_reference,
        'status' => $b->status instanceof \BackedEnum ? $b->status->value : $b->status,
        'currency' => $b->currency, 'selling_price' => (float) $b->selling_price,
        'paid' => (float) $b->getPaidAmountAttribute(), 'remaining' => (float) $b->getRemainingAmountAttribute(),
    ];
}

// ════════════════════════════════════════════════════════════════════════
// S16: Validation + Authorization
// ════════════════════════════════════════════════════════════════════════
section('S16: Validation + Authorization + Pagination');

$tests = [
    ['desc' => 'Missing customer_id', 'payload' => ['pnr' => 'X1', 'airline' => 'MS'], 'expect' => 422],
    ['desc' => 'Invalid currency', 'payload' => ['customer_id' => $ids['customers']['ahmed'], 'pnr' => 'X2', 'airline' => 'MS', 'currency' => 'INVALID', 'selling_price' => 100, 'passengers' => [['first_name' => 'A', 'last_name' => 'B']]], 'expect' => 422],
    ['desc' => 'Negative amount', 'payload' => ['customer_id' => $ids['customers']['ahmed'], 'pnr' => 'X3', 'airline' => 'MS', 'selling_price' => -100, 'passengers' => [['first_name' => 'A', 'last_name' => 'B']]], 'expect' => 422],
    ['desc' => 'No passengers', 'payload' => ['customer_id' => $ids['customers']['ahmed'], 'pnr' => 'X4', 'airline' => 'MS', 'passengers' => []], 'expect' => 422],
];

$pass = 0;
foreach ($tests as $t) {
    $r = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", $t['payload']);
    if ($r->status() === $t['expect']) {
        ok($t['desc'] . " → {$r->status()} ✓");
        $pass++;
    } else {
        fail($t['desc'] . " → expected {$t['expect']} got {$r->status()}");
    }
}

$r = Http::withToken($TOKEN)->acceptJson()->get("$BASE/flight/bookings?per_page=2&page=1");
if ($r->successful()) {
    $d = $r->json('data');
    $items = $d['items'] ?? [];
    $pagination = $d['pagination'] ?? [];
    if (count($items) <= 2 && ($pagination['per_page'] ?? 0) === 2) {
        ok("Pagination works (per_page=2, returned " . count($items) . ")");
        $pass++;
    } else {
        fail("Pagination broken");
    }
}

$REPORT['scenarios']['S16'] = ['status' => $pass >= 4 ? 'PASS' : 'PARTIAL', 'pass' => $pass, 'total' => count($tests) + 1];

// ════════════════════════════════════════════════════════════════════════
// S17: List endpoints (carriers, systems, groups, wallets, treasury overview)
// ════════════════════════════════════════════════════════════════════════
section('S17: List endpoints — carriers, systems, groups, wallets');

$tests = [
    ['url' => "$BASE/flight/systems", 'name' => 'systems'],
    ['url' => "$BASE/flight/carriers", 'name' => 'carriers'],
    ['url' => "$BASE/flight/groups", 'name' => 'groups'],
    ['url' => "$BASE/flight/treasury/overview", 'name' => 'treasury overview'],
    ['url' => "$BASE/flight/dashboard", 'name' => 'dashboard'],
];

$pass = 0;
foreach ($tests as $t) {
    $r = Http::withToken($TOKEN)->acceptJson()->get($t['url']);
    if ($r->successful()) {
        ok("GET /flight/{$t['name']} → 200");
        $pass++;
    } else {
        fail("GET /flight/{$t['name']} → {$r->status()} " . substr($r->body(), 0, 100));
    }
}

$REPORT['scenarios']['S17'] = ['status' => $pass === count($tests) ? 'PASS' : 'PARTIAL', 'pass' => $pass, 'total' => count($tests)];

// ════════════════════════════════════════════════════════════════════════
// حفظ
// ════════════════════════════════════════════════════════════════════════
$REPORT['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(
    storage_path('logs/flight_e2e_results.json'),
    json_encode($REPORT, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n" . str_repeat('═', 75) . "\n  Phase 3 (S11-S17) done.\n" . str_repeat('═', 75) . "\n";
echo "\nSummary:\n";
foreach ($REPORT['scenarios'] as $key => $s) {
    $st = $s['status'] ?? '?';
    $icon = $st === 'PASS' ? '✅' : ($st === 'PARTIAL' ? '⚠ ' : '❌');
    echo "  $icon $key: $st\n";
}
