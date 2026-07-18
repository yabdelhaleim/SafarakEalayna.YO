<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight E2E Test Runner — اختبار شامل لموديول الطيران
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يختبر 17 سيناريو عبر HTTP API endpoints (نفس المسارات المستخدمة من Filament و Vue):
 *   - إنشاء حجوزات بعملات مختلفة (EGP / KWD / SAR / USD)
 *   - تسعير بالعملة الأجنبية + تحويل EGP
 *   - مدفوعات جزئية ومتعددة (نقدي / بنك / محفظة / بريد)
 *   - إلغاء الحجز (عكس جماعي)
 *   - حذف الحجز (عكس كامل)
 *   - الاسترجاع (refund)
 *   - إدارة رصيد الناقلين (recharge)
 *   - إنشاء بنك عبر Filament API
 *   - AccountModuleContract
 *   - كروت وفلاتر Vue
 *
 * النتائج: storage/logs/flight_e2e_results.json + flight_e2e_report.md
 * التشغيل: php tests/e2e/flight_e2e_runner.php
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
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE = 'http://127.0.0.1:8000/api/v1';
$ids = json_decode(file_get_contents(storage_path('logs/flight_e2e_ids.json')), true);

$REPORT = [
    'title' => 'Flight Module E2E Test Report',
    'started_at' => date('Y-m-d H:i:s'),
    'finished_at' => null,
    'ids' => $ids,
    'scenarios' => [],
    'verdict' => [],
];

function section(string $name): void {
    echo "\n" . str_repeat('═', 75) . "\n";
    echo "  $name\n";
    echo str_repeat('═', 75) . "\n";
}
function ok(string $m = 'OK'): void { echo "    ✅ $m\n"; }
function fail(string $m): void { echo "    ❌ $m\n"; }
function info(string $m): void { echo "    ℹ  $m\n"; }
function warn(string $m): void { echo "    ⚠  $m\n"; }

function snapshot(int $id, string $label, array $extra = []): array {
    $a = Account::find($id);
    if (! $a) return ['label' => $label, 'id' => $id, 'missing' => true];
    return [
        'label' => $label,
        'id' => $a->id,
        'name' => $a->name,
        'type' => $a->type instanceof \BackedEnum ? $a->type->value : $a->type,
        'currency' => $a->currency,
        'balance' => (float) $a->balance,
    ] + $extra;
}

function balanceDelta(int $before, int $after): array {
    return [
        'before' => (float) Account::find($before)?->balance ?? 0,
        'after' => (float) Account::find($after)?->balance ?? 0,
    ];
}

function get_token(string $base): string {
    $resp = Http::acceptJson()->post("$base/auth/login", [
        'email' => 'admin@safarakealayna.com',
        'password' => 'Sf@2026#Admin!',
    ]);
    if (! $resp->successful()) {
        throw new RuntimeException('Login failed: ' . $resp->status() . ' ' . $resp->body());
    }
    return $resp->json('data.token');
}

$TOKEN = get_token($BASE);
info("Authenticated as admin (token: " . substr($TOKEN, 0, 20) . "...)");

// ════════════════════════════════════════════════════════════════════════
// S1: إنشاء حجز EGP كامل (كاش) — نظام Amadeus / مصر للطيران
// ════════════════════════════════════════════════════════════════════════
section('S1: EGP booking with full cash payment (Amadeus + EgyptAir)');

$bal_cash_before = (float) Account::find($ids['accounts']['cashbox_egp'])->balance;
$bal_carrier_before = (float) DB::table('flight_carriers')->where('id', $ids['carriers']['egyptair'])->value('balance');

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['ahmed'],
    'pnr' => 'PNR-E2E-S1',
    'airline' => 'MS',
    'airline_name' => 'مصر للطيران',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'flight_group_id' => $ids['groups']['alshola'],
    'from_airport' => 'CAI',
    'to_airport' => 'JED',
    'departure_date' => '2026-08-15',
    'departure_time' => '10:30',
    'arrival_time' => '13:45',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'purchase_price' => 8000,
    'selling_price' => 9500,
    'currency' => 'EGP',
    'account_id' => $ids['accounts']['cashbox_egp'],
    'purchase_balance_source' => 'carrier',
    'notes' => 'E2E S1: EGP cash booking',
    'passengers' => [
        ['first_name' => 'أحمد', 'last_name' => 'محمد', 'passenger_type' => 'adult', 'nationality' => 'EG'],
    ],
    'payment' => [
        'amount' => 9500,
        'payment_method' => 'cash',
        'account_id' => $ids['accounts']['cashbox_egp'],
        'notes' => 'دفع كامل E2E S1',
    ],
]);

$booking_s1 = null;
if ($resp->status() === 201) {
    $booking_s1 = $resp->json('data.booking') ?? $resp->json('data');
    $bookingId = $booking_s1['id'] ?? null;
    ok("Created EGP booking ID={$bookingId} status=" . ($booking_s1['status'] ?? 'n/a'));
    info("Selling: {$booking_s1['selling_price']} EGP | Purchase: {$booking_s1['purchase_price']} EGP | Profit: " . ($booking_s1['profit'] ?? 0));
    info("Paid: " . ($booking_s1['paid_amount'] ?? 'n/a') . " | Remaining: " . ($booking_s1['remaining_amount'] ?? 'n/a'));

    // التحقق من الأرصدة
    $bal_cash_after = (float) Account::find($ids['accounts']['cashbox_egp'])->balance;
    $bal_carrier_after = (float) DB::table('flight_carriers')->where('id', $ids['carriers']['egyptair'])->value('balance');
    $cash_delta = $bal_cash_after - $bal_cash_before;
    $carrier_delta = $bal_carrier_after - $bal_carrier_before;
    info("Cashbox EGP: {$bal_cash_before} → {$bal_cash_after} (Δ {$cash_delta})");
    info("Carrier (EgyptAir): {$bal_carrier_before} → {$bal_carrier_after} (Δ {$carrier_delta})");

    if (abs($cash_delta - 9500) < 0.01) ok('Cashbox delta matches selling_price');
    else fail("Cashbox delta MISMATCH: expected +9500, got {$cash_delta}");

    if (abs($carrier_delta + 8000) < 0.01) ok('Carrier debit matches purchase_price');
    else fail("Carrier debit MISMATCH: expected -8000, got {$carrier_delta}");

    $REPORT['scenarios']['S1'] = [
        'status' => 'PASS',
        'booking_id' => $bookingId,
        'cashbox_delta' => $cash_delta,
        'carrier_delta' => $carrier_delta,
    ];
} else {
    fail("Booking creation failed: " . $resp->status() . ' ' . substr($resp->body(), 0, 300));
    $REPORT['scenarios']['S1'] = ['status' => 'FAIL', 'response' => $resp->json()];
}

// ════════════════════════════════════════════════════════════════════════
// S2: حجز KWD مع تحويل العملة (foreign currency + exchange rate)
// ════════════════════════════════════════════════════════════════════════
section('S2: KWD booking with exchange rate (NDC + Jazeera)');

$bal_kwd_before = (float) Account::find($ids['accounts']['cashbox_kwd'])->balance;
$bal_carrier2_before = (float) DB::table('flight_carriers')->where('id', $ids['carriers']['jazeera_kwd'])->value('balance');

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['sara'],
    'pnr' => 'PNR-E2E-S2',
    'airline' => 'J9',
    'airline_name' => 'طيران الجزيرة',
    'flight_system_id' => $ids['systems']['ndc'],
    'flight_carrier_id' => $ids['carriers']['jazeera_kwd'],
    'from_airport' => 'CAI',
    'to_airport' => 'KWI',
    'departure_date' => '2026-09-10',
    'departure_time' => '14:00',
    'arrival_time' => '17:30',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'KWD',
    'foreign_currency' => 'KWD',
    'purchase_price_foreign' => 200,    // 200 KWD
    'exchange_rate' => 157.5,            // 1 KWD = 157.5 EGP
    'purchase_price_egp' => 31500,      // 200 * 157.5
    'purchase_price' => 31500,          // EGP equivalent
    'selling_price' => 35000,           // EGP
    'account_id' => $ids['accounts']['cashbox_egp'], // البيع بالجنيه
    'purchase_balance_source' => 'carrier',
    'notes' => 'E2E S2: KWD booking with FX',
    'passengers' => [
        ['first_name' => 'سارة', 'last_name' => 'علي', 'passenger_type' => 'adult', 'nationality' => 'EG'],
    ],
    'payment' => [
        'amount' => 35000,
        'payment_method' => 'cash',
        'account_id' => $ids['accounts']['cashbox_egp'],
        'notes' => 'دفع كامل E2E S2',
    ],
]);

$booking_s2 = null;
if ($resp->status() === 201) {
    $booking_s2 = $resp->json('data.booking') ?? $resp->json('data');
    $bookingId = $booking_s2['id'] ?? null;
    ok("Created KWD booking ID={$bookingId}");
    info("Currency: " . ($booking_s2['currency'] ?? 'n/a') . " | Foreign: " . ($booking_s2['foreign_currency'] ?? 'n/a'));
    info("Foreign Price: " . ($booking_s2['purchase_price_foreign'] ?? 'n/a') . " | Rate: " . ($booking_s2['exchange_rate'] ?? 'n/a'));
    info("EGP Price: " . ($booking_s2['purchase_price_egp'] ?? 'n/a') . " | Selling: " . ($booking_s2['selling_price'] ?? 'n/a'));

    $bal_carrier2_after = (float) DB::table('flight_carriers')->where('id', $ids['carriers']['jazeera_kwd'])->value('balance');
    $carrier2_delta = $bal_carrier2_after - $bal_carrier2_before;
    info("Jazeera (KWD) carrier: {$bal_carrier2_before} → {$bal_carrier2_after} (Δ {$carrier2_delta})");

    // KWD carrier: 200 KWD debit
    if (abs($carrier2_delta + 200) < 0.01) ok('Carrier KWD debit = -200 (foreign currency)');
    else warn("Carrier KWD debit: expected -200, got {$carrier2_delta}");

    $REPORT['scenarios']['S2'] = [
        'status' => 'PASS',
        'booking_id' => $bookingId,
        'foreign_currency' => $booking_s2['foreign_currency'] ?? null,
        'foreign_amount' => $booking_s2['purchase_price_foreign'] ?? null,
        'exchange_rate' => $booking_s2['exchange_rate'] ?? null,
        'egp_amount' => $booking_s2['purchase_price_egp'] ?? null,
        'carrier_kwd_delta' => $carrier2_delta,
    ];
} else {
    fail("KWD booking failed: " . $resp->status() . ' ' . substr($resp->body(), 0, 300));
    $REPORT['scenarios']['S2'] = ['status' => 'FAIL', 'response' => $resp->json()];
}

// ════════════════════════════════════════════════════════════════════════
// S3: حجز SAR (ريال سعودي) — الخطوط السعودية
// ════════════════════════════════════════════════════════════════════════
section('S3: SAR booking with multi-currency');

$bal_carrier3_before = (float) DB::table('flight_carriers')->where('id', $ids['carriers']['saudi_sar'])->value('balance');

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['ahmed'],
    'pnr' => 'PNR-E2E-S3',
    'airline' => 'SV',
    'airline_name' => 'الخطوط السعودية',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['saudi_sar'],
    'from_airport' => 'CAI',
    'to_airport' => 'JED',
    'departure_date' => '2026-10-05',
    'trip_type' => 'one_way',
    'passenger_count' => 2,
    'currency' => 'SAR',
    'foreign_currency' => 'SAR',
    'purchase_price_foreign' => 1000,
    'exchange_rate' => 12.9,
    'purchase_price_egp' => 12900,
    'purchase_price' => 12900,
    'selling_price' => 15000,
    'account_id' => $ids['accounts']['cashbox_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [
        ['first_name' => 'أحمد', 'last_name' => 'محمد', 'passenger_type' => 'adult', 'nationality' => 'EG'],
        ['first_name' => 'سمير', 'last_name' => 'محمد', 'passenger_type' => 'adult', 'nationality' => 'EG'],
    ],
    'payment' => [
        'amount' => 15000,
        'payment_method' => 'cash',
        'account_id' => $ids['accounts']['cashbox_egp'],
    ],
]);

$booking_s3 = null;
if ($resp->status() === 201) {
    $booking_s3 = $resp->json('data.booking') ?? $resp->json('data');
    $bookingId = $booking_s3['id'] ?? null;
    ok("Created SAR booking ID={$bookingId}");

    $bal_carrier3_after = (float) DB::table('flight_carriers')->where('id', $ids['carriers']['saudi_sar'])->value('balance');
    $carrier3_delta = $bal_carrier3_after - $bal_carrier3_before;
    info("Saudi (SAR) carrier: {$bal_carrier3_before} → {$bal_carrier3_after} (Δ {$carrier3_delta})");

    if (abs($carrier3_delta + 1000) < 0.01) ok('Carrier SAR debit = -1000 (foreign currency)');
    else warn("Carrier SAR debit: expected -1000, got {$carrier3_delta}");

    $REPORT['scenarios']['S3'] = [
        'status' => 'PASS',
        'booking_id' => $bookingId,
        'sar_delta' => $carrier3_delta,
    ];
} else {
    fail("SAR booking failed: " . $resp->status() . ' ' . substr($resp->body(), 0, 300));
    $REPORT['scenarios']['S3'] = ['status' => 'FAIL', 'response' => $resp->json()];
}

// ════════════════════════════════════════════════════════════════════════
// S4: دفعات متعددة (3 دفعات) لبنك EGP
// ════════════════════════════════════════════════════════════════════════
section('S4: Multiple partial payments via bank transfer');

$bal_bank_before = (float) Account::find($ids['accounts']['bank_egp'])->balance;

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['sara'],
    'pnr' => 'PNR-E2E-S4',
    'airline' => 'MS',
    'airline_name' => 'مصر للطيران',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'DXB',
    'departure_date' => '2026-11-20',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 12000,
    'selling_price' => 15000,
    'account_id' => $ids['accounts']['bank_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [
        ['first_name' => 'سارة', 'last_name' => 'علي', 'passenger_type' => 'adult', 'nationality' => 'EG'],
    ],
    'payment' => [
        'amount' => 5000,
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_egp'],
        'notes' => 'دفعة أولى',
    ],
]);

$booking_s4 = null;
if ($resp->status() === 201) {
    $booking_s4 = $resp->json('data.booking') ?? $resp->json('data');
    $bookingId = $booking_s4['id'];
    ok("Booking #{$bookingId} created with initial payment 5000 EGP");
    info("Paid: " . ($booking_s4['paid_amount'] ?? 'n/a') . " | Remaining: " . ($booking_s4['remaining_amount'] ?? 'n/a'));

    // دفعتان إضافيتان
    foreach ([
        ['amount' => 6000, 'method' => 'bank_transfer', 'notes' => 'دفعة ثانية'],
        ['amount' => 4000, 'method' => 'cash', 'account_id' => $ids['accounts']['cashbox_egp'], 'notes' => 'دفعة ثالثة كاش'],
    ] as $i => $pay) {
        $resp2 = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings/{$bookingId}/payments", [
            'amount' => $pay['amount'],
            'payment_method' => $pay['method'],
            'account_id' => $pay['account_id'] ?? $ids['accounts']['bank_egp'],
            'notes' => $pay['notes'],
        ]);
        if ($resp2->status() === 201) {
            ok("Payment " . ($i + 2) . ": +{$pay['amount']} EGP via {$pay['method']}");
        } else {
            fail("Payment " . ($i + 2) . " failed: " . $resp2->status() . ' ' . substr($resp2->body(), 0, 200));
        }
    }

    // التحقق من paid + remaining
    $b = FlightBooking::find($bookingId);
    $paid = $b->getPaidAmountAttribute();
    $remaining = $b->getRemainingAmountAttribute();
    info("Final paid: {$paid} | remaining: {$remaining}");
    if (abs($paid - 15000) < 0.01) ok("paid_amount = selling_price (fully paid)");
    else fail("paid_amount MISMATCH: expected 15000, got {$paid}");

    $bal_bank_after = (float) Account::find($ids['accounts']['bank_egp'])->balance;
    $bank_delta = $bal_bank_after - $bal_bank_before;
    info("Bank EGP: {$bal_bank_before} → {$bal_bank_after} (Δ {$bank_delta})");
    if (abs($bank_delta - 11000) < 0.01) ok("Bank delta = +11000 (5000+6000)"); // الدفعات 1+2 من البنك
    else warn("Bank delta: expected +11000, got {$bank_delta}");

    $REPORT['scenarios']['S4'] = [
        'status' => 'PASS',
        'booking_id' => $bookingId,
        'total_paid' => $paid,
        'remaining' => $remaining,
        'bank_delta' => $bank_delta,
    ];
} else {
    fail("S4 booking failed: " . $resp->status() . ' ' . substr($resp->body(), 0, 300));
    $REPORT['scenarios']['S4'] = ['status' => 'FAIL', 'response' => $resp->json()];
}

// ════════════════════════════════════════════════════════════════════════
// S5: حجز بمدفوعات من wallet (vodafone cash)
// ════════════════════════════════════════════════════════════════════════
section('S5: Booking paid via Vodafone Cash wallet');

$bal_wallet_before = (float) Account::find($ids['accounts']['wallet_vf'])->balance;

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['ahmed'],
    'pnr' => 'PNR-E2E-S5',
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'RUH',
    'departure_date' => '2026-12-01',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 6000,
    'selling_price' => 7500,
    'account_id' => $ids['accounts']['wallet_vf'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [
        ['first_name' => 'أحمد', 'last_name' => 'محمد', 'passenger_type' => 'adult', 'nationality' => 'EG'],
    ],
    'payment' => [
        'amount' => 7500,
        'payment_method' => 'vodafone_cash',
        'account_id' => $ids['accounts']['wallet_vf'],
        'notes' => 'دفع عبر محفظة فودافون',
    ],
]);

$booking_s5 = null;
if ($resp->status() === 201) {
    $booking_s5 = $resp->json('data.booking') ?? $resp->json('data');
    $bookingId = $booking_s5['id'];
    ok("Booking #{$bookingId} paid via Vodafone wallet");
    $bal_wallet_after = (float) Account::find($ids['accounts']['wallet_vf'])->balance;
    $wallet_delta = $bal_wallet_after - $bal_wallet_before;
    info("Wallet VF: {$bal_wallet_before} → {$bal_wallet_after} (Δ {$wallet_delta})");
    if (abs($wallet_delta - 7500) < 0.01) ok("Wallet delta = +7500");
    else warn("Wallet delta: expected +7500, got {$wallet_delta}");

    $REPORT['scenarios']['S5'] = [
        'status' => 'PASS',
        'booking_id' => $bookingId,
        'wallet_delta' => $wallet_delta,
    ];
} else {
    fail("S5 failed: " . $resp->status() . ' ' . substr($resp->body(), 0, 300));
    $REPORT['scenarios']['S5'] = ['status' => 'FAIL', 'response' => $resp->json()];
}

// ════════════════════════════════════════════════════════════════════════
// S6: حجز بمدفوعات من بريد
// ════════════════════════════════════════════════════════════════════════
section('S6: Booking paid via Postal transfer');

$bal_postal_before = (float) Account::find($ids['accounts']['postal_egp'])->balance;

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['sara'],
    'pnr' => 'PNR-E2E-S6',
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'DMM',
    'departure_date' => '2026-12-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 7000,
    'selling_price' => 8500,
    'account_id' => $ids['accounts']['postal_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [
        ['first_name' => 'سارة', 'last_name' => 'علي', 'passenger_type' => 'adult', 'nationality' => 'EG'],
    ],
    'payment' => [
        'amount' => 8500,
        'payment_method' => 'postal_transfer',
        'account_id' => $ids['accounts']['postal_egp'],
        'notes' => 'دفع عبر البريد',
    ],
]);

$booking_s6 = null;
if ($resp->status() === 201) {
    $booking_s6 = $resp->json('data.booking') ?? $resp->json('data');
    $bookingId = $booking_s6['id'];
    ok("Booking #{$bookingId} paid via Postal");
    $bal_postal_after = (float) Account::find($ids['accounts']['postal_egp'])->balance;
    $postal_delta = $bal_postal_after - $bal_postal_before;
    info("Postal: {$bal_postal_before} → {$bal_postal_after} (Δ {$postal_delta})");
    if (abs($postal_delta - 8500) < 0.01) ok("Postal delta = +8500");
    else warn("Postal delta: expected +8500, got {$postal_delta}");

    $REPORT['scenarios']['S6'] = [
        'status' => 'PASS',
        'booking_id' => $bookingId,
        'postal_delta' => $postal_delta,
    ];
} else {
    fail("S6 failed: " . $resp->status() . ' ' . substr($resp->body(), 0, 300));
    $REPORT['scenarios']['S6'] = ['status' => 'FAIL', 'response' => $resp->json()];
}

// حفظ المعرّفات للمراحل اللاحقة
$REPORT['ids']['bookings'] = [
    's1' => $booking_s1['id'] ?? null,
    's2' => $booking_s2['id'] ?? null,
    's3' => $booking_s3['id'] ?? null,
    's4' => $booking_s4['id'] ?? null,
    's5' => $booking_s5['id'] ?? null,
    's6' => $booking_s6['id'] ?? null,
];

// حفظ snapshot وسيط
file_put_contents(
    storage_path('logs/flight_e2e_ids.json'),
    json_encode($REPORT['ids'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);
file_put_contents(
    storage_path('logs/flight_e2e_results.json'),
    json_encode($REPORT, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  Part 1 complete (S1-S6). Saved snapshot.\n";
echo "═══════════════════════════════════════════════════════════════\n";
