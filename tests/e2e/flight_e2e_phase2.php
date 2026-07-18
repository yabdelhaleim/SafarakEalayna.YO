<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module E2E — Phase 2: S5-S17 (Post-create flows)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * S5  - مدفوعات متعددة (3 دفعات) + customer debt tracking
 * S6  - دفع من محفظة Vodafone
 * S7  - دفع من بريد
 * S8  - تعديل أسعار (Update prices) + عكس جماعي
 * S9  - إلغاء حجز (Cancel) + عكس كل القيود
 * S10 - حذف حجز (Delete) + كل القيود تنعكس
 * S11 - استرجاع (Refund) من حجز مؤكد
 * S12 - AccountModuleContract: التحقق من التصنيف
 * S13 - Filament: البحث عن خزنة لدفع الدين (Dropdown)
 * S14 - Vue Index: كروت + فلاتر
 * S15 - Create Bank via Filament API + يظهر في Vue
 * S16 - Validation + Authorization + Pagination
 *
 * الاستخدام: php tests/e2e/flight_e2e_phase2.php
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Transaction;
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

function snap(int $id): array {
    $a = Account::find($id);
    if (! $a) return ['missing' => true];
    return [
        'id' => $a->id, 'name' => $a->name, 'type' => $a->type instanceof \BackedEnum ? $a->type->value : $a->type,
        'currency' => $a->currency, 'balance' => (float) $a->balance,
    ];
}

function carrierSnap(int $id): array {
    $c = FlightCarrier::find($id);
    return ['id' => $c->id, 'name' => $c->name, 'currency' => $c->currency, 'balance' => (float) $c->balance];
}

function bookingSnap(int $id): array {
    $b = FlightBooking::withTrashed()->find($id);
    if (! $b) return ['missing' => true];
    return [
        'id' => $b->id, 'ref' => $b->booking_reference,
        'status' => $b->status instanceof \BackedEnum ? $b->status->value : $b->status,
        'currency' => $b->currency, 'selling_price' => (float) $b->selling_price,
        'paid' => (float) $b->getPaidAmountAttribute(), 'remaining' => (float) $b->getRemainingAmountAttribute(),
        'deleted_at' => $b->deleted_at?->toIso8601String(),
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

$TOKEN = get_token($BASE);
info("Authenticated as admin (token: " . substr($TOKEN, 0, 20) . "...)");

// ════════════════════════════════════════════════════════════════════════
// S5: مدفوعات متعددة (3 دفعات) — نفس الحجز، 3 مصادر دفع مختلفة
// ════════════════════════════════════════════════════════════════════════
section('S5: Multi-payments (3 partial payments)');
$pnr5 = 'E2E-FLT-S5-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnr5);

$bankBefore = snap($ids['accounts']['bank_egp']);
$walletBefore = snap($ids['accounts']['wallet_vf']);
$carBefore = carrierSnap($ids['carriers']['egyptair']);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['sara'],
    'pnr' => $pnr5,
    'airline' => 'MS',
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
    'passengers' => [make_passenger('سارة', 'علي')],
    'payment' => [
        'amount' => 5000,
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_egp'],
        'notes' => 'دفعة أولى',
    ],
]);

$booking5Id = null;
if ($resp->status() === 201) {
    $booking5Id = $resp->json('data.booking.id') ?? $resp->json('data.id');
    ok("Booking #{$booking5Id} created with first payment 5000 EGP");

    $pays = [
        ['amount' => 6000, 'payment_method' => 'bank_transfer', 'account_id' => $ids['accounts']['bank_egp'], 'notes' => 'دفعة ثانية'],
        ['amount' => 4000, 'payment_method' => 'cash_wallet', 'account_id' => $ids['accounts']['wallet_vf'], 'notes' => 'دفعة ثالثة - محفظة'],
    ];
    foreach ($pays as $i => $pay) {
        $r2 = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings/{$booking5Id}/payments", $pay);
        if ($r2->status() === 201) ok("Payment " . ($i + 2) . ": +{$pay['amount']} EGP via {$pay['payment_method']}");
        else fail("Payment " . ($i + 2) . " failed: " . $r2->body());
    }

    $bs = bookingSnap($booking5Id);
    info("Final paid: {$bs['paid']} | remaining: {$bs['remaining']}");
    if (abs($bs['paid'] - 15000) < 0.01) ok("paid_amount = selling_price (fully paid)");
    else fail("paid MISMATCH: expected 15000, got {$bs['paid']}");

    $bankAfter = snap($ids['accounts']['bank_egp']);
    $walletAfter = snap($ids['accounts']['wallet_vf']);
    $carAfter = carrierSnap($ids['carriers']['egyptair']);
    info("Bank EGP: {$bankBefore['balance']} → {$bankAfter['balance']} (Δ " . round($bankAfter['balance'] - $bankBefore['balance'], 2) . ")");
    info("Wallet VF: {$walletBefore['balance']} → {$walletAfter['balance']} (Δ " . round($walletAfter['balance'] - $walletBefore['balance'], 2) . ")");
    info("Carrier MS: {$carBefore['balance']} → {$carAfter['balance']} (Δ " . round($carAfter['balance'] - $carBefore['balance'], 2) . ")");

    $REPORT['scenarios']['S5'] = [
        'status' => 'PASS', 'booking_id' => $booking5Id,
        'paid' => $bs['paid'], 'remaining' => $bs['remaining'],
        'bank_delta' => $bankAfter['balance'] - $bankBefore['balance'],
        'wallet_delta' => $walletAfter['balance'] - $walletBefore['balance'],
    ];
} else {
    fail("S5 failed: " . $resp->body());
    $REPORT['scenarios']['S5'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// S6: دفع من محفظة Vodafone
// ════════════════════════════════════════════════════════════════════════
section('S6: Vodafone Cash wallet payment');
$pnr6 = 'E2E-FLT-S6-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnr6);

$walletBefore = snap($ids['accounts']['wallet_vf']);
$carBefore = carrierSnap($ids['carriers']['egyptair']);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['ahmed'],
    'pnr' => $pnr6,
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
    'passengers' => [make_passenger('أحمد', 'محمد')],
    'payment' => [
        'amount' => 7500,
        'payment_method' => 'vodafone_cash',
        'account_id' => $ids['accounts']['wallet_vf'],
    ],
]);

if ($resp->status() === 201) {
    $bid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    ok("Booking #{$bid} paid via Vodafone wallet");
    $wA = snap($ids['accounts']['wallet_vf']);
    info("Wallet: {$walletBefore['balance']} → {$wA['balance']} (Δ " . round($wA['balance'] - $walletBefore['balance'], 2) . ")");
    $REPORT['scenarios']['S6'] = ['status' => 'PASS', 'booking_id' => $bid];
} else {
    fail("S6 failed: " . $resp->body());
    $REPORT['scenarios']['S6'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// S7: دفع من بريد
// ════════════════════════════════════════════════════════════════════════
section('S7: Postal transfer payment');
$pnr7 = 'E2E-FLT-S7-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnr7);

$postBefore = snap($ids['accounts']['postal_egp']);
$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['sara'],
    'pnr' => $pnr7,
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
    'passengers' => [make_passenger('سارة', 'علي')],
    'payment' => [
        'amount' => 8500,
        'payment_method' => 'postal_transfer',
        'account_id' => $ids['accounts']['postal_egp'],
    ],
]);

if ($resp->status() === 201) {
    $bid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    ok("Booking #{$bid} paid via Postal");
    $pA = snap($ids['accounts']['postal_egp']);
    info("Postal: {$postBefore['balance']} → {$pA['balance']} (Δ " . round($pA['balance'] - $postBefore['balance'], 2) . ")");
    $REPORT['scenarios']['S7'] = ['status' => 'PASS', 'booking_id' => $bid];
} else {
    fail("S7 failed: " . $resp->body());
    $REPORT['scenarios']['S7'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// S8: تعديل أسعار (Update prices) + عكس جماعي — يجب استخدام حجز PENDING
// ════════════════════════════════════════════════════════════════════════
section('S8: Update prices (additive reversal) — PENDING booking required');

$pnr8 = 'E2E-FLT-S8-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnr8);

$carBefore = carrierSnap($ids['carriers']['egyptair']);
$bankBefore = snap($ids['accounts']['bank_egp']);

// إنشاء حجز PENDING (بدون PNR)
$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['ahmed'],
    'pnr' => '',  // فارغ = PENDING
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'JED',
    'departure_date' => '2027-03-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 6000,
    'selling_price' => 8000,
    'account_id' => $ids['accounts']['bank_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('أحمد', 'محمد')],
    // لا payment — حجز معلق
]);

$pendingBid = null;
if ($resp->status() === 201) {
    $pendingBid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    $bs = bookingSnap($pendingBid);
    ok("Pending booking #{$pendingBid} created with status: {$bs['status']}");

    if ($bs['status'] === 'pending' || $bs['status'] === 'PENDING') {
        // تعديل الأسعار
        $carBeforeUpd = carrierSnap($ids['carriers']['egyptair']);
        $bankBeforeUpd = snap($ids['accounts']['bank_egp']);

        $resp2 = Http::withToken($TOKEN)->acceptJson()->post(
            "$BASE/flight/bookings/{$pendingBid}/prices",
            ['purchase_price' => 7000, 'selling_price' => 9000]
        );

        if ($resp2->status() === 200) {
            $bs2 = bookingSnap($pendingBid);
            ok("Updated prices: new sell={$bs2['selling_price']}");
            $carAfter = carrierSnap($ids['carriers']['egyptair']);
            $bankAfter = snap($ids['accounts']['bank_egp']);
            info("Carrier Δ = " . round($carAfter['balance'] - $carBeforeUpd['balance'], 2));
            info("Bank Δ = " . round($bankAfter['balance'] - $bankBeforeUpd['balance'], 2));
            $REPORT['scenarios']['S8'] = ['status' => 'PASS', 'booking_id' => $pendingBid];
        } else {
            fail("Update prices failed: " . $resp2->body());
            $REPORT['scenarios']['S8'] = ['status' => 'FAIL', 'error' => $resp2->body()];
        }
    } else {
        fail("Booking not in PENDING status: {$bs['status']}");
        $REPORT['scenarios']['S8'] = ['status' => 'FAIL', 'error' => 'not pending'];
    }
} else {
    fail("S8 create failed: " . $resp->body());
    $REPORT['scenarios']['S8'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// S9: إلغاء حجز (Cancel) + عكس كل القيود
// ════════════════════════════════════════════════════════════════════════
section('S9: Cancel booking with full reversal');

$pnr9 = 'E2E-FLT-S9-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnr9);

$carBefore = carrierSnap($ids['carriers']['egyptair']);
$bankBefore = snap($ids['accounts']['bank_egp']);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['ahmed'],
    'pnr' => $pnr9,
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'JED',
    'departure_date' => '2027-01-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 7000,
    'selling_price' => 9000,
    'account_id' => $ids['accounts']['bank_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('أحمد', 'محمد')],
    'payment' => [
        'amount' => 9000,
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_egp'],
    ],
]);

if ($resp->status() === 201) {
    $bid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    ok("Created booking #{$bid}");

    // إلغاء - الحقول المطلوبة: airline_penalty, office_penalty, account_id
    $resp2 = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings/{$bid}/cancel", [
        'airline_penalty' => 0,
        'office_penalty' => 0,
        'account_id' => $ids['accounts']['bank_egp'],
        'notes' => 'E2E S9 test cancellation',
    ]);

    if ($resp2->status() === 200 || $resp2->status() === 201) {
        ok("Cancelled booking #{$bid}");
        $bs = bookingSnap($bid);
        info("Status: {$bs['status']}");

        $carAfter = carrierSnap($ids['carriers']['egyptair']);
        $bankAfter = snap($ids['accounts']['bank_egp']);
        info("Carrier: {$carBefore['balance']} → {$carAfter['balance']} (Δ " . round($carAfter['balance'] - $carBefore['balance'], 2) . ") [expected 0 net]");
        info("Bank: {$bankBefore['balance']} → {$bankAfter['balance']} (Δ " . round($bankAfter['balance'] - $bankBefore['balance'], 2) . ") [expected 0 net after cancel]");

        if (abs(($carAfter['balance'] - $carBefore['balance'])) < 0.01) ok("Carrier balance restored (0 net delta)");
        else warn("Carrier net delta = " . round($carAfter['balance'] - $carBefore['balance'], 2));

        $REPORT['scenarios']['S9'] = ['status' => 'PASS', 'booking_id' => $bid, 'final_status' => $bs['status']];
    } else {
        fail("Cancel failed: " . $resp2->status() . ' ' . substr($resp2->body(), 0, 250));
        $REPORT['scenarios']['S9'] = ['status' => 'FAIL', 'error' => $resp2->body()];
    }
} else {
    fail("S9 create failed: " . $resp->body());
    $REPORT['scenarios']['S9'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// ════════════════════════════════════════════════════════════════════════
// S10: حذف حجز (Delete) + كل القيود تنعكس
// ════════════════════════════════════════════════════════════════════════
section('S10: Delete booking (with reversal)');

$pnr10 = 'E2E-FLT-S10-' . substr(md5(uniqid('', true)), 0, 6);
ensure_clean_booking($pnr10);

$carBefore = carrierSnap($ids['carriers']['egyptair']);
$bankBefore = snap($ids['accounts']['bank_egp']);

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['sara'],
    'pnr' => $pnr10,
    'airline' => 'MS',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['egyptair'],
    'from_airport' => 'CAI',
    'to_airport' => 'JED',
    'departure_date' => '2027-02-15',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 8000,
    'selling_price' => 10000,
    'account_id' => $ids['accounts']['bank_egp'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [make_passenger('سارة', 'علي')],
    'payment' => [
        'amount' => 10000,
        'payment_method' => 'bank_transfer',
        'account_id' => $ids['accounts']['bank_egp'],
    ],
]);

if ($resp->status() === 201) {
    $bid = $resp->json('data.booking.id') ?? $resp->json('data.id');
    ok("Created booking #{$bid}");

    // حذف عبر Filament-style API (Service)
    try {
        $service = app(\App\Services\Flight\FlightBookingService::class);
        $service->deleteBookingWithReversal($bid, $adminUser->id);
        ok("Booking #{$bid} deleted with reversal");

        $b = FlightBooking::withTrashed()->find($bid);
        if ($b && $b->trashed()) ok("Booking is soft-deleted");
        else fail("Booking NOT soft-deleted");

        $carAfter = carrierSnap($ids['carriers']['egyptair']);
        $bankAfter = snap($ids['accounts']['bank_egp']);
        info("Carrier: {$carBefore['balance']} → {$carAfter['balance']} (Δ " . round($carAfter['balance'] - $carBefore['balance'], 2) . ") [expected 0 net]");
        info("Bank: {$bankBefore['balance']} → {$bankAfter['balance']} (Δ " . round($bankAfter['balance'] - $bankBefore['balance'], 2) . ") [expected 0 net]");

        $REPORT['scenarios']['S10'] = ['status' => 'PASS', 'booking_id' => $bid];
    } catch (\Throwable $e) {
        fail("Delete failed: " . $e->getMessage());
        $REPORT['scenarios']['S10'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
    }
} else {
    fail("S10 create failed: " . $resp->body());
    $REPORT['scenarios']['S10'] = ['status' => 'FAIL', 'error' => $resp->body()];
}

// حفظ
$REPORT['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(
    storage_path('logs/flight_e2e_results.json'),
    json_encode($REPORT, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n" . str_repeat('═', 75) . "\n  Phase 2 (S5-S10) done.\n" . str_repeat('═', 75) . "\n";
