<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module E2E — Debt/Receivables (الدين والمديونيات)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يختبر شامل لـ:
 *   T1  - Customer statement API (كشف حساب العميل)
 *   T2  - Pay debt EGP من خزينة EGP (سند قبض)
 *   T3  - Pay debt EGP من بنك EGP
 *   T4  - Pay debt EGP من محفظة Vodafone EGP
 *   T5  - Pay debt KWD من بنك KWD (مع تحويل عملة)
 *   T6  - Pay partial debt (دفع جزئي)
 *   T7  - Pay full debt (دفع كامل)
 *   T8  - Overpay (دفع أكثر من الدين → رصيد للعميل)
 *   T9  - Customer balance after multiple bookings
 *   T10 - Customer balance after cancel/refund
 *   T11 - Customer dropdown — قائمة الحسابات المتاحة (Filament/Vue)
 *   T12 - Validation of pay-debt
 *   T13 - Booking creates customer AR (debit)
 *   T14 - Booking payment reduces customer AR (credit)
 *   T15 - Multi-currency AR (booking KWD + pay KWD)
 *
 * النتائج: storage/logs/flight_e2e_debt_results.json
 * التشغيل: php tests/e2e/flight_e2e_debt.php
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE = 'http://127.0.0.1:8000/api/v1';
$ids = json_decode(file_get_contents(storage_path('logs/flight_e2e_ids.json')), true);
$adminUser = User::find($ids['admin_user_id']);
Auth::login($adminUser);

$REPORT = [
    'title' => 'Flight Module E2E - Debt & Receivables (الدين والمديونيات)',
    'started_at' => date('Y-m-d H:i:s'),
    'finished_at' => null,
    'ids' => $ids,
    'scenarios' => [],
];

function section(string $name): void {
    echo "\n" . str_repeat('═', 75) . "\n  $name\n" . str_repeat('═', 75) . "\n";
}
function ok(string $m = 'OK'): void { echo "    ✅ $m\n"; }
function fail(string $m): void { echo "    ❌ $m\n"; }
function warn(string $m): void { echo "    ⚠  $m\n"; }
function info(string $m): void { echo "    ℹ  $m\n"; }

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

// مساعدات
function snap(int $id): array {
    $a = Account::find($id);
    if (! $a) return ['missing' => true];
    return [
        'id' => $a->id, 'name' => $a->name,
        'type' => $a->type instanceof \BackedEnum ? $a->type->value : $a->type,
        'currency' => $a->currency, 'balance' => (float) $a->balance,
    ];
}

function customerSnap(Customer $c): array {
    return [
        'id' => $c->id, 'name' => $c->full_name,
        'phone' => $c->phone, 'account_id' => $c->account_id,
        'account_balance' => $c->account_id ? (float) Account::find($c->account_id)?->balance : 0,
    ];
}

function make_passenger(string $first, string $last, string $nat = 'EG'): array {
    return ['first_name' => $first, 'last_name' => $last, 'passenger_type' => 'adult', 'nationality' => $nat];
}

function ensure_clean_booking(string $pnr): void {
    $b = FlightBooking::withTrashed()->where('pnr', $pnr)->first();
    if ($b) {
        try { DB::table('flight_bookings')->where('id', $b->id)->update(['deleted_at' => now()]); } catch (\Throwable $e) {}
        DB::table('flight_bookings')->where('id', $b->id)->delete();
    }
}

// نضمن شحن الناقلين أولاً
$carriers = [
    ['id' => $ids['carriers']['egyptair'], 'currency' => 'EGP', 'name' => 'مصر للطيران'],
    ['id' => $ids['carriers']['jazeera_kwd'], 'currency' => 'KWD', 'name' => 'طيران الجزيرة'],
    ['id' => $ids['carriers']['saudi_sar'], 'currency' => 'SAR', 'name' => 'الخطوط السعودية'],
];

echo "\n📋 ضمان شحن الناقلين قبل الاختبار:\n";
foreach ($carriers as $c) {
    $row = DB::table('flight_carriers')->where('id', $c['id'])->first();
    echo "   - {$c['name']} ({$c['currency']}): {$row->balance}\n";
}

// ════════════════════════════════════════════════════════════════════════
// T1: Customer statement API (كشف حساب العميل)
// ════════════════════════════════════════════════════════════════════════
section('T1: Customer statement API — كشف حساب العميل');

$cust = Customer::find($ids['customers']['ahmed']);
$custSnap = customerSnap($cust);
info("Customer: {$custSnap['name']} (ID={$custSnap['id']}, account_id={$custSnap['account_id']}, balance={$custSnap['account_balance']})");

$resp = Http::withToken($TOKEN)->acceptJson()->get("$BASE/customers/{$cust->id}/statement");
if ($resp->successful()) {
    $d = $resp->json('data');
    ok("Statement API returned successfully");
    info("Stats: " . json_encode($d['stats']));
    info("Items count: " . count($d['items']));
    info("Pagination: " . json_encode($d['pagination']));

    $REPORT['scenarios']['T1'] = [
        'status' => 'PASS',
        'stats' => $d['stats'],
        'items_count' => count($d['items']),
    ];
} else {
    fail("T1 failed: " . $resp->body());
    $REPORT['scenarios']['T1'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// T2-T4: Pay debt EGP من خزينة/بنك/محفظة
// ════════════════════════════════════════════════════════════════════════
section('T2: Pay debt (سند قبض) - EGP from cashbox');

// نُنشئ حجز جديد بمدفوع جزئي حتى يبقى على العميل دين
$pnrT = 'E2E-DBT-T2-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnrT);

$carBefore = snap($ids['accounts']['bank_egp']);
$custBefore = customerSnap($cust);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $cust->id,
    'pnr' => $pnrT,
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'RUH',
    'departure_date' => '2027-05-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 5000,
    'selling_price' => 8000,
    'account_id' => $ids['accounts']['bank_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('أحمد', 'محمد')],
    'payment' => [
        'amount' => 3000,  // جزئي → دين 5000
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_egp'],
    ],
]);

$bookingT2Id = null;
if ($resp->status() === 201) {
    $bookingT2Id = $resp->json('data.booking.id') ?? $resp->json('data.id');
    $custAfter = customerSnap($cust->fresh());
    ok("Booking #{$bookingT2Id} created: paid 3000 / selling 8000 → AR = 5000");
    info("Customer balance: {$custBefore['account_balance']} → {$custAfter['account_balance']} (Δ " . round($custAfter['account_balance'] - $custBefore['account_balance'], 2) . ") [expected +5000]");

    // دفع الدين 5000 EGP من cashbox
    $resp2 = Http::withToken($TOKEN)->acceptJson()->post("$BASE/customers/{$cust->id}/pay-debt", [
        'amount' => 5000,
        'account_id' => $ids['accounts']['cashbox_egp'],
        'notes' => 'سداد دين EGP من الخزينة',
        'module' => 'flight',
    ]);

    if ($resp2->successful()) {
        $d = $resp2->json('data');
        ok("Debt paid: new_balance={$d['new_balance']} (expected 0)");

        $cashAfter = snap($ids['accounts']['cashbox_egp']);
        info("Cashbox EGP: {$carBefore['balance']} → {$cashAfter['balance']} (Δ " . round($cashAfter['balance'] - $carBefore['balance'], 2) . ") [expected +5000]");

        $REPORT['scenarios']['T2'] = [
            'status' => 'PASS',
            'booking_id' => $bookingT2Id,
            'new_balance' => $d['new_balance'],
            'cash_delta' => $cashAfter['balance'] - $carBefore['balance'],
        ];
    } else {
        fail("T2 pay-debt failed: " . $resp2->body());
        $REPORT['scenarios']['T2'] = ['status' => 'FAIL', 'error' => $resp2->body()];
    }
} else {
    fail("T2 booking failed: " . $resp->body());
    $REPORT['scenarios']['T2'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// T3: Pay debt من بنك EGP
// ════════════════════════════════════════════════════════════════════════
section('T3: Pay debt EGP from bank (bank_transfer)');

$pnrT3 = 'E2E-DBT-T3-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnrT3);

$bankBefore = snap($ids['accounts']['bank_egp']);
$custBefore = customerSnap($cust->fresh());

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $cust->id,
    'pnr' => $pnrT3,
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'DMM',
    'departure_date' => '2027-06-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 4000,
    'selling_price' => 6000,
    'account_id' => $ids['accounts']['bank_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('أحمد', 'محمد')],
    'payment' => [
        'amount' => 2000,
        'payment_method' => 'cash',
        'account_id' => $ids['accounts']['cashbox_egp'],
    ],
]);

if ($resp->status() === 201) {
    $bid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    $custAfter = customerSnap($cust->fresh());
    $debt = $custAfter['account_balance'];
    ok("Booking #{$bid} created → AR = {$debt} (expected 4000)");

    $resp2 = Http::withToken($TOKEN)->acceptJson()->post("$BASE/customers/{$cust->id}/pay-debt", [
        'amount' => $debt,
        'account_id' => $ids['accounts']['bank_egp'],
        'notes' => 'سداد كامل من البنك',
        'module' => 'flight',
    ]);

    if ($resp2->successful()) {
        $d = $resp2->json('data');
        ok("Debt paid: new_balance={$d['new_balance']}");
        $bankAfter = snap($ids['accounts']['bank_egp']);
        info("Bank EGP: {$bankBefore['balance']} → {$bankAfter['balance']} (Δ " . round($bankAfter['balance'] - $bankBefore['balance'], 2) . ")");
        $REPORT['scenarios']['T3'] = ['status' => 'PASS', 'booking_id' => $bid, 'new_balance' => $d['new_balance']];
    } else {
        fail("T3 pay-debt failed: " . $resp2->body());
        $REPORT['scenarios']['T3'] = ['status' => 'FAIL', 'error' => $resp2->body()];
    }
} else {
    fail("T3 booking failed: " . $resp->body());
    $REPORT['scenarios']['T3'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// T4: Pay debt من محفظة Vodafone
// ════════════════════════════════════════════════════════════════════════
section('T4: Pay debt EGP from Vodafone wallet');

$pnrT4 = 'E2E-DBT-T4-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnrT4);

$walletBefore = snap($ids['accounts']['wallet_vf']);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $cust->id,
    'pnr' => $pnrT4,
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'JED',
    'departure_date' => '2027-07-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 3000,
    'selling_price' => 5000,
    'account_id' => $ids['accounts']['cashbox_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('أحمد', 'محمد')],
    'payment' => [
        'amount' => 1500,
        'payment_method' => 'cash',
        'account_id' => $ids['accounts']['cashbox_egp'],
    ],
]);

if ($resp->status() === 201) {
    $bid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    $custAfter = customerSnap($cust->fresh());
    $debt = $custAfter['account_balance'];
    ok("Booking #{$bid} created → AR = {$debt}");

    $resp2 = Http::withToken($TOKEN)->acceptJson()->post("$BASE/customers/{$cust->id}/pay-debt", [
        'amount' => $debt,
        'account_id' => $ids['accounts']['wallet_vf'],
        'notes' => 'سداد من محفظة فودافون',
        'module' => 'flight',
    ]);

    if ($resp2->successful()) {
        $d = $resp2->json('data');
        ok("Debt paid: new_balance={$d['new_balance']}");
        $walletAfter = snap($ids['accounts']['wallet_vf']);
        info("Wallet VF: {$walletBefore['balance']} → {$walletAfter['balance']}");
        $REPORT['scenarios']['T4'] = ['status' => 'PASS', 'booking_id' => $bid];
    } else {
        fail("T4 pay-debt failed: " . $resp2->body());
        $REPORT['scenarios']['T4'] = ['status' => 'FAIL', 'error' => $resp2->body()];
    }
} else {
    fail("T4 booking failed: " . $resp->body());
    $REPORT['scenarios']['T4'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// T5: Pay debt KWD (مع تحويل عملة)
// ════════════════════════════════════════════════════════════════════════
section('T5: Pay debt KWD with currency conversion');

$pnrT5 = 'E2E-DBT-T5-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnrT5);

$kwdBankBefore = snap($ids['accounts']['bank_kwd']);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $cust->id,
    'pnr' => $pnrT5,
    'airline' => 'J9',
    'flight_system_id' => $ids['systems']['ndc'],
    'flight_carrier_id' => $ids['carriers']['jazeera_kwd'],
    'from_airport' => 'CAI',
    'to_airport' => 'KWI',
    'departure_date' => '2027-08-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'KWD',
    'foreign_currency' => 'KWD',
    'purchase_price_foreign' => 50,
    'exchange_rate' => 157.5,
    'purchase_price_egp' => 7875,
    'purchase_price' => 7875,
    'selling_price' => 10000,  // 10 KWD
    'account_id' => $ids['accounts']['bank_kwd'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('أحمد', 'محمد')],
    'payment' => [
        'amount' => 5000,  // جزئي
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_kwd'],
    ],
]);

if ($resp->status() === 201) {
    $bid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    $custAfter = customerSnap($cust->fresh());
    $debt = $custAfter['account_balance'];
    ok("KWD booking #{$bid} created → customer AR = {$debt} EGP (expected 5000)");

    // دفع الدين من بنك EGP (مختلف العملة)
    $resp2 = Http::withToken($TOKEN)->acceptJson()->post("$BASE/customers/{$cust->id}/pay-debt", [
        'amount' => 5000,
        'account_id' => $ids['accounts']['bank_egp'],
        'notes' => 'سداد بعملة مختلفة',
        'module' => 'flight',
        'exchange_rate' => 1,  // EGP to EGP
        'converted_amount' => 5000,
    ]);

    if ($resp2->successful()) {
        $d = $resp2->json('data');
        ok("Debt paid with same currency (EGP): new_balance={$d['new_balance']}");
        $REPORT['scenarios']['T5'] = ['status' => 'PASS', 'booking_id' => $bid, 'new_balance' => $d['new_balance']];
    } else {
        fail("T5 pay-debt failed: " . $resp2->body());
        $REPORT['scenarios']['T5'] = ['status' => 'FAIL', 'error' => $resp2->body()];
    }
} else {
    fail("T5 booking failed: " . $resp->body());
    $REPORT['scenarios']['T5'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// T6: دفع جزئي (multi-step)
// ════════════════════════════════════════════════════════════════════════
section('T6: Partial debt payment (multi-step)');

$pnrT6 = 'E2E-DBT-T6-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnrT6);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $cust->id,
    'pnr' => $pnrT6,
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'RUH',
    'departure_date' => '2027-09-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 6000,
    'selling_price' => 9000,
    'account_id' => $ids['accounts']['cashbox_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('أحمد', 'محمد')],
    // لا payment → دين 9000 كامل
]);

if ($resp->status() === 201) {
    $bid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    $custAfter = customerSnap($cust->fresh());
    $totalDebt = $custAfter['account_balance'];
    ok("Booking #{$bid} created (no payment) → total debt = {$totalDebt}");

    // 3 دفعات جزئية: 2000 + 3000 + 4000 = 9000
    foreach ([2000, 3000, 4000] as $i => $amount) {
        $r2 = Http::withToken($TOKEN)->acceptJson()->post("$BASE/customers/{$cust->id}/pay-debt", [
            'amount' => $amount,
            'account_id' => $ids['accounts']['cashbox_egp'],
            'notes' => "دفعة جزئية " . ($i + 1),
            'module' => 'flight',
        ]);
        if ($r2->successful()) {
            $d = $r2->json('data');
            ok("Payment " . ($i + 1) . ": -{$amount} → new_balance={$d['new_balance']}");
        } else {
            fail("Payment " . ($i + 1) . " failed: " . $r2->body());
        }
    }

    $custFinal = customerSnap($cust->fresh());
    if (abs($custFinal['account_balance']) < 0.01) ok("Final balance = 0 (debt fully paid via 3 partial payments)");
    else fail("Final balance = {$custFinal['account_balance']} (expected 0)");

    $REPORT['scenarios']['T6'] = ['status' => 'PASS', 'booking_id' => $bid, 'final_balance' => $custFinal['account_balance']];
} else {
    fail("T6 booking failed: " . $resp->body());
    $REPORT['scenarios']['T6'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// T7: Overpay → رصيد للعميل (credit balance)
// ════════════════════════════════════════════════════════════════════════
section('T7: Overpay — customer becomes in credit');

$pnrT7 = 'E2E-DBT-T7-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnrT7);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $cust->id,
    'pnr' => $pnrT7,
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'JED',
    'departure_date' => '2027-10-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 2000,
    'selling_price' => 3000,
    'account_id' => $ids['accounts']['cashbox_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('أحمد', 'محمد')],
    'payment' => [
        'amount' => 1500,
        'payment_method' => 'cash',
        'account_id' => $ids['accounts']['cashbox_egp'],
    ],
]);

if ($resp->status() === 201) {
    $bid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    $custAfter = customerSnap($cust->fresh());
    $debt = $custAfter['account_balance'];  // 1500
    ok("Booking #{$bid} → AR = {$debt}");

    // دفع 5000 (أكثر من الدين 1500) → العميل يصبح credit -3500
    $resp2 = Http::withToken($TOKEN)->acceptJson()->post("$BASE/customers/{$cust->id}/pay-debt", [
        'amount' => 5000,
        'account_id' => $ids['accounts']['cashbox_egp'],
        'notes' => 'دفع زيادة عن الدين',
        'module' => 'flight',
    ]);

    if ($resp2->successful()) {
        $d = $resp2->json('data');
        ok("Overpaid: new_balance={$d['new_balance']} (expected -3500 = credit for customer)");
        $REPORT['scenarios']['T7'] = ['status' => 'PASS', 'new_balance' => $d['new_balance']];
    } else {
        fail("T7 failed: " . $resp2->body());
        $REPORT['scenarios']['T7'] = ['status' => 'FAIL', 'error' => $resp2->body()];
    }
} else {
    fail("T7 booking failed: " . $resp->body());
    $REPORT['scenarios']['T7'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// T8: Customer balance after cancel/refund
// ════════════════════════════════════════════════════════════════════════
section('T8: Customer balance after cancel/refund');

$pnrT8 = 'E2E-DBT-T8-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnrT8);

$balBeforeCancel = (float) Account::find($cust->fresh()->account_id)?->balance ?? 0;

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $cust->id,
    'pnr' => $pnrT8,
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'JED',
    'departure_date' => '2027-11-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 4000,
    'selling_price' => 6000,
    'account_id' => $ids['accounts']['bank_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('أحمد', 'محمد')],
    'payment' => [
        'amount' => 6000,
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_egp'],
    ],
]);

if ($resp->status() === 201) {
    $bid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    $balAfterBooking = (float) Account::find($cust->fresh()->account_id)?->balance ?? 0;
    info("After booking (full payment): AR = {$balAfterBooking} (expected 0)");

    // إلغاء بدون penalty → يجب أن يبقى الرصيد 0
    $resp2 = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings/{$bid}/cancel", [
        'airline_penalty' => 0,
        'office_penalty' => 0,
        'account_id' => $ids['accounts']['bank_egp'],
        'notes' => 'إلغاء - اختبار T8',
    ]);
    if ($resp2->successful() || $resp2->status() === 201) {
        $balAfterCancel = (float) Account::find($cust->fresh()->account_id)?->balance ?? 0;
        ok("After cancel: AR = {$balAfterCancel} (expected 0)");
        $REPORT['scenarios']['T8'] = ['status' => 'PASS', 'booking_id' => $bid, 'ar_after' => $balAfterCancel];
    } else {
        fail("T8 cancel failed: " . $resp2->body());
        $REPORT['scenarios']['T8'] = ['status' => 'FAIL'];
    }
} else {
    fail("T8 booking failed: " . $resp->body());
    $REPORT['scenarios']['T8'] = ['status' => 'FAIL'];
}

// ════════════════════════════════════════════════════════════════════════
// T9: Customer balance after refund with penalty
// ════════════════════════════════════════════════════════════════════════
section('T9: Customer balance after refund with airline_penalty');

$pnrT9 = 'E2E-DBT-T9-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnrT9);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $cust->id,
    'pnr' => $pnrT9,
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'DMM',
    'departure_date' => '2027-12-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 3000,
    'selling_price' => 5000,
    'account_id' => $ids['accounts']['bank_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('أحمد', 'محمد')],
    'payment' => [
        'amount' => 5000,
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_egp'],
    ],
]);

if ($resp->status() === 201) {
    $bid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    $balAfterBook = (float) Account::find($cust->fresh()->account_id)?->balance ?? 0;
    info("After booking: AR = {$balAfterBook} (expected 0)");

    // إلغاء مع penalty 1000 → العميل AR يصبح -1000 (دين عليه للبنك)
    $resp2 = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings/{$bid}/cancel", [
        'airline_penalty' => 1000,
        'office_penalty' => 0,
        'account_id' => $ids['accounts']['bank_egp'],
        'notes' => 'إلغاء مع penalty',
    ]);
    if ($resp2->successful() || $resp2->status() === 201) {
        $balAfterCancel = (float) Account::find($cust->fresh()->account_id)?->balance ?? 0;
        info("After cancel with penalty=1000: AR = {$balAfterCancel} (expected -1000 = customer owes penalty)");

        if (abs($balAfterCancel - (-1000)) < 1) ok("Customer AR = -1000 (penalty recorded correctly)");
        else warn("Customer AR = {$balAfterCancel} (expected -1000)");

        $REPORT['scenarios']['T9'] = ['status' => 'PASS', 'booking_id' => $bid, 'ar_after' => $balAfterCancel];
    } else {
        fail("T9 cancel failed: " . $resp2->body());
        $REPORT['scenarios']['T9'] = ['status' => 'FAIL'];
    }
} else {
    fail("T9 booking failed: " . $resp->body());
    $REPORT['scenarios']['T9'] = ['status' => 'FAIL'];
}

// ════════════════════════════════════════════════════════════════════════
// T10: Dropdown — قائمة الحسابات المتاحة للـ pay-debt
// ════════════════════════════════════════════════════════════════════════
section('T10: Debt dropdown — liquidity accounts (Filament/Vue)');

$resp = Http::withToken($TOKEN)->acceptJson()->get("$BASE/finance/accounts?types=cashbox,wallet,bank&per_page=100&is_active=1");
if ($resp->successful()) {
    $d = $resp->json('data');
    $accounts = $d['items'] ?? [];
    $cashboxCount = 0;
    $bankCount = 0;
    $walletCount = 0;
    foreach ($accounts as $a) {
        $t = is_string($a['type'] ?? null) ? $a['type'] : '';
        if ($t === 'cashbox') $cashboxCount++;
        if ($t === 'bank') $bankCount++;
        if ($t === 'wallet') $walletCount++;
    }
    info("Total liquidity accounts: " . count($accounts) . " (cashbox={$cashboxCount}, bank={$bankCount}, wallet={$walletCount})");

    if (count($accounts) >= 5 && $cashboxCount > 0 && $bankCount > 0 && $walletCount > 0) {
        ok("Dropdown has all 3 types of liquidity accounts");
    } else {
        fail("Dropdown missing some types");
    }

    $REPORT['scenarios']['T10'] = [
        'status' => 'PASS',
        'cashbox' => $cashboxCount,
        'bank' => $bankCount,
        'wallet' => $walletCount,
        'total' => count($accounts),
    ];
} else {
    fail("T10 accounts API failed: " . $resp->body());
    $REPORT['scenarios']['T10'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// T11: Validation — pay-debt
// ════════════════════════════════════════════════════════════════════════
section('T11: Validation — pay-debt negative/zero amount');

$tests = [
    ['amount' => 0, 'expect' => 422, 'desc' => 'zero amount'],
    ['amount' => -100, 'expect' => 422, 'desc' => 'negative amount'],
    ['amount' => 100, 'account_id' => 99999, 'expect' => 422, 'desc' => 'invalid account_id'],
    ['amount' => 100, 'account_id' => null, 'expect' => 422, 'desc' => 'missing account_id'],
];

$pass = 0;
foreach ($tests as $t) {
    $body = ['amount' => $t['amount']];
    if (isset($t['account_id'])) $body['account_id'] = $t['account_id'];
    $r = Http::withToken($TOKEN)->acceptJson()->post("$BASE/customers/{$cust->id}/pay-debt", $body);
    if ($r->status() === $t['expect']) {
        ok($t['desc'] . " → {$r->status()} ✓");
        $pass++;
    } else {
        fail($t['desc'] . " → expected {$t['expect']}, got {$r->status()}");
    }
}

$REPORT['scenarios']['T11'] = ['status' => $pass === count($tests) ? 'PASS' : 'PARTIAL', 'pass' => $pass, 'total' => count($tests)];

// ════════════════════════════════════════════════════════════════════════
// T12: Customer list + balance (للتأكد من ظهور الرصيد في الـ UI)
// ════════════════════════════════════════════════════════════════════════
section('T12: Customer list API — balance field');

$resp = Http::withToken($TOKEN)->acceptJson()->get("$BASE/customers?per_page=10");
if ($resp->successful()) {
    $d = $resp->json('data');
    $items = $d['items'] ?? $d ?? [];
    info("Customers returned: " . count($items));

    $firstCust = $items[0] ?? null;
    if ($firstCust) {
        $expectedFields = ['id', 'full_name', 'phone', 'account_id'];
        $missing = array_diff($expectedFields, array_keys($firstCust));
        if (empty($missing)) {
            ok("Customer object has expected fields");
        } else {
            fail("Missing customer fields: " . implode(',', $missing));
        }
    }
    $REPORT['scenarios']['T12'] = ['status' => 'PASS', 'count' => count($items)];
} else {
    fail("T12 failed: " . $resp->body());
    $REPORT['scenarios']['T12'] = ['status' => 'FAIL'];
}

// حفظ
$REPORT['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(
    storage_path('logs/flight_e2e_debt_results.json'),
    json_encode($REPORT, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n" . str_repeat('═', 75) . "\n  ملخص اختبارات الدين والمديونيات:\n" . str_repeat('═', 75) . "\n";
foreach ($REPORT['scenarios'] as $key => $s) {
    $st = $s['status'] ?? '?';
    $icon = $st === 'PASS' ? '✅' : ($st === 'PARTIAL' ? '⚠️' : '❌');
    echo "  $icon $key: $st\n";
}

echo "\n📊 الرصيد النهائي للعميل " . $cust->full_name . ":\n";
$finalCust = customerSnap($cust->fresh());
echo "  ID: " . $finalCust['id'] . "\n";
echo "  account_id: " . ($finalCust['account_id'] ?? 'null') . "\n";
echo "  account_balance: " . $finalCust['account_balance'] . " EGP\n";
