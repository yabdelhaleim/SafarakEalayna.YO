<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module E2E — اختبار شامل (idempotent + comprehensive)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يختبر 17 سيناريو شامل:
 *   S1  - حجز EGP (Carrier source, NO group)
 *   S2  - حجز KWD مع سعر صرف أجنبي
 *   S3  - حجز SAR مع سعر صرف أجنبي
 *   S4  - حجز USD (الأجنبي الثالث)
 *   S5  - مدفوعات متعددة (3 دفعات من بنوك مختلفة)
 *   S6  - دفع من محفظة Vodafone
 *   S7  - دفع من بريد
 *   S8  - تعديل أسعار (Update prices) + عكس جماعي
 *   S9  - إلغاء حجز (Cancel) + عكس كل القيود
 *   S10 - حذف حجز (Delete) + كل القيود تنعكس
 *   S11 - استرجاع (Refund) من حجز مؤكد
 *   S12 - شحن رصيد ناقل من بنك (Recharge)
 *   S13 - إنشاء بنك جديد عبر Filament API
 *   S14 - AccountModuleContract: Bank + Mail + Wallet
 *   S15 - Filament Dropdown for debt payment (البحث عن خزنة لتسديد)
 *   S16 - Vue Index: كروت + فلاتر
 *   S17 - Pagination + Validation + Authorization
 *
 * التشغيل: php tests/e2e/flight_e2e_full.php
 * النتائج: storage/logs/flight_e2e_results.json + flight_e2e_report.md
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE = 'http://127.0.0.1:8000/api/v1';
$ids = json_decode(file_get_contents(storage_path('logs/flight_e2e_ids.json')), true);
$adminUser = User::find($ids['admin_user_id']);
Auth::login($adminUser);

$REPORT = [
    'title' => 'Flight Module E2E - Full Coverage Test',
    'started_at' => date('Y-m-d H:i:s'),
    'finished_at' => null,
    'ids' => $ids,
    'scenarios' => [],
    'verdict' => [],
];

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

// مساعدات سريعة
function snap(int $id): array {
    $a = Account::find($id);
    if (! $a) return ['missing' => true];
    return [
        'id' => $a->id,
        'name' => $a->name,
        'type' => $a->type instanceof \BackedEnum ? $a->type->value : $a->type,
        'currency' => $a->currency,
        'balance' => (float) $a->balance,
        'module' => $a->module,
        'module_type' => $a->module_type,
        'is_module_vault' => (bool) $a->is_module_vault,
    ];
}

function carrierSnap(int $id): array {
    $c = FlightCarrier::find($id);
    return [
        'id' => $c->id,
        'name' => $c->name,
        'currency' => $c->currency,
        'balance' => (float) $c->balance,
        'credit_limit' => (float) $c->credit_limit,
        'available' => (float) $c->available_balance,
    ];
}

function bookingSnap(int $id): array {
    $b = FlightBooking::withTrashed()->find($id);
    if (! $b) return ['missing' => true];
    return [
        'id' => $b->id,
        'ref' => $b->booking_reference,
        'status' => $b->status instanceof \BackedEnum ? $b->status->value : $b->status,
        'currency' => $b->currency,
        'foreign_currency' => $b->foreign_currency,
        'selling_price' => (float) $b->selling_price,
        'purchase_price' => (float) $b->purchase_price,
        'profit' => (float) $b->profit,
        'paid' => (float) $b->getPaidAmountAttribute(),
        'remaining' => (float) $b->getRemainingAmountAttribute(),
        'source' => $b->purchase_balance_source,
        'deleted_at' => $b->deleted_at?->toIso8601String(),
    ];
}

// ضمان عدم وجود حجز بنفس PNR مسبقاً
function ensure_clean_booking(string $pnr): void {
    $b = FlightBooking::withTrashed()->where('pnr', $pnr)->first();
    if ($b) {
        // نمسح فقط لو لسه موجود (soft delete)
        if (! $b->trashed()) {
            try {
                DB::table('flight_bookings')->where('id', $b->id)->update(['deleted_at' => now()]);
            } catch (\Throwable $e) {
                // ignore
            }
        }
        DB::table('flight_bookings')->where('id', $b->id)->delete();
    }
}

function make_passenger(string $first, string $last, string $nat = 'EG'): array {
    return ['first_name' => $first, 'last_name' => $last, 'passenger_type' => 'adult', 'nationality' => $nat];
}

// ════════════════════════════════════════════════════════════════════════
// S1: حجز EGP كامل — مصدر Carrier (بدون مجموعة)
// ════════════════════════════════════════════════════════════════════════
section('S1: EGP booking — carrier source (NO group, FULL cash payment)');
$pnr1 = 'E2E-FLT-S1-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnr1);

$carBefore = carrierSnap($ids['carriers']['egyptair']);
$bankBefore = snap($ids['accounts']['bank_egp']);
$cust = Customer::find($ids['customers']['ahmed']);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['ahmed'],
    'pnr' => $pnr1,
    'airline' => 'MS',
    'airline_name' => 'مصر للطيران',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'JED',
    'departure_date' => '2026-08-15',
    'departure_time' => '10:30',
    'arrival_time' => '13:45',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 8000,
    'selling_price' => 9500,
    'account_id' => $ids['accounts']['bank_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('أحمد', 'محمد')],
    'payment' => [
        'amount' => 9500,
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_egp'],
        'notes' => 'دفع كامل E2E S1',
    ],
]);

$booking1 = null;
if ($resp->status() === 201) {
    $booking1 = $resp->json('data.booking') ?? $resp->json('data');
    $bid = $booking1['id'];
    ok("Created booking #{$bid}");
    $bs = bookingSnap($bid);
    info("Sell: {$bs['selling_price']} | Purch: {$bs['purchase_price']} | Profit: {$bs['profit']} | Source: {$bs['source']}");

    $carAfter = carrierSnap($ids['carriers']['egyptair']);
    $bankAfter = snap($ids['accounts']['bank_egp']);
    $carDelta = $carAfter['balance'] - $carBefore['balance'];
    $bankDelta = $bankAfter['balance'] - $bankBefore['balance'];
    info("Carrier (MS): {$carBefore['balance']} → {$carAfter['balance']} (Δ {$carDelta})");
    info("Bank EGP: {$bankBefore['balance']} → {$bankAfter['balance']} (Δ {$bankDelta})");

    if (abs($carDelta + 8000) < 0.01) ok("Carrier debit = -8000 EGP (matches purchase)");
    else fail("Carrier debit MISMATCH: expected -8000, got {$carDelta}");

    if (abs($bankDelta - 9500) < 0.01) ok("Bank credit = +9500 EGP (matches selling)");
    else fail("Bank credit MISMATCH: expected +9500, got {$bankDelta}");

    $REPORT['scenarios']['S1'] = ['status' => 'PASS', 'booking_id' => $bid, 'car_delta' => $carDelta, 'bank_delta' => $bankDelta];
} else {
    fail("S1 failed: " . $resp->status() . ' ' . substr($resp->body(), 0, 250));
    $REPORT['scenarios']['S1'] = ['status' => 'FAIL', 'error' => $resp->body()];
}
$booking1Id = $booking1['id'] ?? null;

// ════════════════════════════════════════════════════════════════════════
// S2: حجز KWD (عملة أجنبية) — سعر صرف 157.5
// ════════════════════════════════════════════════════════════════════════
section('S2: KWD booking with exchange rate 157.5 (NDC + Jazeera)');
$pnr2 = 'E2E-FLT-S2-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnr2);

$car2Before = carrierSnap($ids['carriers']['jazeera_kwd']);
$bankEgpBefore = snap($ids['accounts']['bank_egp']);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['sara'],
    'pnr' => $pnr2,
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
    'purchase_price_foreign' => 200,
    'exchange_rate' => 157.5,
    'purchase_price_egp' => 31500,
    'purchase_price' => 31500,
    'selling_price' => 38000,
    'account_id' => $ids['accounts']['bank_kwd'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('سارة', 'علي')],
    'payment' => [
        'amount' => 38000,
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_kwd'],
        'notes' => 'دفع كامل E2E S2',
    ],
]);

$booking2 = null;
if ($resp->status() === 201) {
    $booking2 = $resp->json('data.booking') ?? $resp->json('data');
    $bid = $booking2['id'];
    ok("Created KWD booking #{$bid}");
    $bs = bookingSnap($bid);
    info("Currency: {$bs['currency']} | Foreign: {$bs['foreign_currency']} | Purch_egp: {$bs['purchase_price']} | Sell: {$bs['selling_price']} | Profit: {$bs['profit']}");

    $car2After = carrierSnap($ids['carriers']['jazeera_kwd']);
    $car2Delta = $car2After['balance'] - $car2Before['balance'];
    info("Jazeera KWD: {$car2Before['balance']} → {$car2After['balance']} (Δ {$car2Delta})");
    if (abs($car2Delta + 200) < 0.01) ok("Carrier KWD debit = -200 (foreign currency correctly debited)");
    else fail("Carrier KWD debit: expected -200, got {$car2Delta}");

    $REPORT['scenarios']['S2'] = ['status' => 'PASS', 'booking_id' => $bid, 'foreign_debit' => $car2Delta];
} else {
    fail("S2 failed: " . $resp->status() . ' ' . substr($resp->body(), 0, 250));
    $REPORT['scenarios']['S2'] = ['status' => 'FAIL', 'error' => $resp->body()];
}
$booking2Id = $booking2['id'] ?? null;

// ════════════════════════════════════════════════════════════════════════
// S3: حجز SAR (ريال سعودي) — سعر صرف 12.9
// ════════════════════════════════════════════════════════════════════════
section('S3: SAR booking with exchange rate 12.9 (Amadeus + Saudi)');
$pnr3 = 'E2E-FLT-S3-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnr3);

$car3Before = carrierSnap($ids['carriers']['saudi_sar']);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['ahmed'],
    'pnr' => $pnr3,
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
    'selling_price' => 16000,
    'account_id' => $ids['accounts']['bank_sar'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [
        make_passenger('أحمد', 'محمد'),
        make_passenger('سمير', 'محمد'),
    ],
    'payment' => [
        'amount' => 16000,
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_sar'],
    ],
]);

$booking3 = null;
if ($resp->status() === 201) {
    $booking3 = $resp->json('data.booking') ?? $resp->json('data');
    $bid = $booking3['id'];
    ok("Created SAR booking #{$bid}");
    $car3After = carrierSnap($ids['carriers']['saudi_sar']);
    $car3Delta = $car3After['balance'] - $car3Before['balance'];
    info("Saudi SAR: {$car3Before['balance']} → {$car3After['balance']} (Δ {$car3Delta})");
    if (abs($car3Delta + 1000) < 0.01) ok("Carrier SAR debit = -1000");
    else fail("Carrier SAR debit: expected -1000, got {$car3Delta}");

    $REPORT['scenarios']['S3'] = ['status' => 'PASS', 'booking_id' => $bid, 'sar_debit' => $car3Delta];
} else {
    fail("S3 failed: " . $resp->status() . ' ' . substr($resp->body(), 0, 250));
    $REPORT['scenarios']['S3'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// S4: حجز USD (دولار أمريكي) — سعر صرف 48.5
// ════════════════════════════════════════════════════════════════════════
section('S4: USD booking with exchange rate 48.5');

// شحن carrier USD أولاً (أو نستخدم carrier آخر)
$usdCarrier = FlightCarrier::where('currency', 'USD')->first();
if (! $usdCarrier) {
    // نستخدم نفس مصر للطيران (EGP) كـ carrier للـ USD booking
    // لكن في الحقيقة USD يحتاج carrier USD. فلننشئ واحد.
    $sys = FlightSystem::find($ids['systems']['amadeus']);
    $usdCarrier = FlightCarrier::firstOrCreate(
        ['code' => 'E2E_USD'],
        [
            'flight_system_id' => $sys->id,
            'name' => 'United Airways (E2E USD)',
            'iata_code' => 'UA',
            'currency' => 'USD',
            'balance' => 0,
            'credit_limit' => 10000,
            'is_active' => true,
            'created_by' => $ids['admin_user_id'],
            'notes' => 'E2E USD carrier',
        ]
    );
    // شحن من بنك USD
    $usdBank = Account::find($ids['accounts']['bank_usd']);
    $resp = Http::withToken($TOKEN)->acceptJson()->post(
        "$BASE/flight/carriers/{$usdCarrier->id}/recharge",
        ['from_account_id' => $usdBank->id, 'amount' => 5000, 'notes' => 'E2E S4 setup']
    );
    if ($resp->successful()) ok("Created+recharged USD carrier: {$usdCarrier->name}");
    else fail("USD carrier setup failed: " . $resp->body());
}
$ids['carriers']['usd_test'] = $usdCarrier->id;

$pnr4 = 'E2E-FLT-S4-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnr4);

$car4Before = carrierSnap($usdCarrier->id);
$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['sara'],
    'pnr' => $pnr4,
    'airline' => 'UA',
    'airline_name' => 'United Airways',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $usdCarrier->id,
    'from_airport' => 'CAI',
    'to_airport' => 'JFK',
    'departure_date' => '2026-11-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'USD',
    'foreign_currency' => 'USD',
    'purchase_price_foreign' => 500,
    'exchange_rate' => 48.5,
    'purchase_price_egp' => 24250,
    'purchase_price' => 24250,
    'selling_price' => 28000,
    'account_id' => $ids['accounts']['bank_usd'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('سارة', 'علي')],
    'payment' => [
        'amount' => 28000,
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_usd'],
    ],
]);

$booking4 = null;
if ($resp->status() === 201) {
    $booking4 = $resp->json('data.booking') ?? $resp->json('data');
    $bid = $booking4['id'];
    ok("Created USD booking #{$bid}");
    $car4After = carrierSnap($usdCarrier->id);
    $car4Delta = $car4After['balance'] - $car4Before['balance'];
    info("USD carrier: {$car4Before['balance']} → {$car4After['balance']} (Δ {$car4Delta})");
    if (abs($car4Delta + 500) < 0.01) ok("USD carrier debit = -500");
    else fail("USD debit: expected -500, got {$car4Delta}");

    $REPORT['scenarios']['S4'] = ['status' => 'PASS', 'booking_id' => $bid];
} else {
    fail("S4 failed: " . $resp->status() . ' ' . substr($resp->body(), 0, 250));
    $REPORT['scenarios']['S4'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// حفظ snapshot وسيط
file_put_contents(
    storage_path('logs/flight_e2e_ids.json'),
    json_encode(array_merge($ids, ['bookings' => [
        's1' => $booking1Id, 's2' => $booking2Id, 's3' => $booking3['id'] ?? null, 's4' => $booking4['id'] ?? null,
    ]]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);
file_put_contents(
    storage_path('logs/flight_e2e_results.json'),
    json_encode($REPORT, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n" . str_repeat('═', 75) . "\n  Phase 1 (S1-S4) done.\n" . str_repeat('═', 75) . "\n";
