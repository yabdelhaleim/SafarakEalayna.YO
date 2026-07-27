<?php
/**
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║       Flight Module — Full Production End-to-End Test                    ║
 * ║   يغطي: الشحن · الحجز · الدفع · الإلغاء · الحذف مع العكس · العملات     ║
 * ║   البيئة: MySQL حي + Laravel 13 + PHP 8.3                               ║
 * ╚═══════════════════════════════════════════════════════════════════════════╝
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Setting\Currency;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\FlightSystemRechargeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ─────────────────────────────────────────────────────────────────────────────
// Helper utilities
// ─────────────────────────────────────────────────────────────────────────────
$passed   = 0;
$failed   = 0;
$results  = [];
$startTime = microtime(true);

function assert_check(string $label, bool $condition, string $detail = ''): void {
    global $passed, $failed, $results;
    if ($condition) {
        $passed++;
        $results[] = ['label' => $label, 'passed' => true];
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        $results[] = ['label' => $label, 'passed' => false, 'detail' => $detail];
        echo "  ❌ {$label}" . ($detail ? " → {$detail}" : '') . "\n";
    }
}

function freshBal(Account $acc): float {
    return (float) Account::query()->find($acc->id)?->balance ?? 0.0;
}

function freshCarrierBal(FlightCarrier $c): float {
    return (float) FlightCarrier::query()->find($c->id)?->available_balance ?? 0.0;
}

function freshSystemBal(FlightSystem $s): float {
    return (float) FlightSystem::query()->find($s->id)?->available_balance ?? 0.0;
}

function freshCustomerBal(Customer $cust): float {
    $acct = \App\Models\Account::query()->find($cust->refresh()->account_id);
    return $acct ? (float) $acct->balance : 0.0;
}

function section(string $title): void {
    echo "\n" . str_repeat('═', 70) . "\n";
    echo "  {$title}\n";
    echo str_repeat('═', 70) . "\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap: Login as admin user
// ─────────────────────────────────────────────────────────────────────────────
$adminUser = \App\Models\User::query()->first();
if (!$adminUser) {
    die("FATAL: لا يوجد مستخدم في قاعدة البيانات. أنشئ مستخدماً أولاً.\n");
}
Auth::login($adminUser);
echo "✅ تم تسجيل الدخول كـ: {$adminUser->name} (ID: {$adminUser->id})\n";

// ─────────────────────────────────────────────────────────────────────────────
// Services
// ─────────────────────────────────────────────────────────────────────────────
$bookingService  = app(FlightBookingService::class);
$carrierRecharge = app(FlightCarrierRechargeService::class);
$systemRecharge  = app(FlightSystemRechargeService::class);

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 0 — إعداد الـ fixtures (العملات والحسابات والكيانات)
// ─────────────────────────────────────────────────────────────────────────────
section('S00 — إعداد البيئة: العملات والحسابات والكيانات');

// Currencies
$egpRate  = 1.0;
$usdRate  = Currency::whereRaw('upper(code)=?', ['USD'])->where('is_active', true)->value('exchange_rate') ?: 50.0;
$kwdRate  = Currency::whereRaw('upper(code)=?', ['KWD'])->where('is_active', true)->value('exchange_rate') ?: 160.0;
$sarRate  = Currency::whereRaw('upper(code)=?', ['SAR'])->where('is_active', true)->value('exchange_rate') ?: 13.5;

echo "  سعر USD={$usdRate} EGP  |  KWD={$kwdRate} EGP  |  SAR={$sarRate} EGP\n";

// ─── Source accounts (for recharge): one per currency ───
$srcEGP = Account::firstOrCreate(
    ['name' => 'Test-SrcEGP-Flight'],
    ['type' => 'cashbox', 'currency' => 'EGP', 'balance' => 200000, 'is_active' => true, 'owner_type' => 'owner', 'module_type' => 'tourism', 'created_by' => $adminUser->id]
);
// Ensure enough balance
if ((float)$srcEGP->balance < 200000) {
    $srcEGP->update(['balance' => 200000]);
}

$srcUSD = Account::firstOrCreate(
    ['name' => 'Test-SrcUSD-Flight'],
    ['type' => 'cashbox', 'currency' => 'USD', 'balance' => 5000, 'is_active' => true, 'owner_type' => 'owner', 'module_type' => 'tourism', 'created_by' => $adminUser->id]
);
if ((float)$srcUSD->balance < 5000) {
    $srcUSD->update(['balance' => 5000]);
}

$srcKWD = Account::firstOrCreate(
    ['name' => 'Test-SrcKWD-Flight'],
    ['type' => 'cashbox', 'currency' => 'KWD', 'balance' => 500, 'is_active' => true, 'owner_type' => 'owner', 'module_type' => 'tourism', 'created_by' => $adminUser->id]
);
if ((float)$srcKWD->balance < 500) {
    $srcKWD->update(['balance' => 500]);
}

$srcSAR = Account::firstOrCreate(
    ['name' => 'Test-SrcSAR-Flight'],
    ['type' => 'cashbox', 'currency' => 'SAR', 'balance' => 2000, 'is_active' => true, 'owner_type' => 'owner', 'module_type' => 'tourism', 'created_by' => $adminUser->id]
);
if ((float)$srcSAR->balance < 2000) {
    $srcSAR->update(['balance' => 2000]);
}

// ─── Carriers (EGP / USD / KWD / SAR) ───
$carrierEGP = FlightCarrier::firstOrCreate(
    ['code' => 'TST-EGP'],
    ['name' => 'Test Carrier EGP', 'currency' => 'EGP', 'available_balance' => 0, 'is_active' => true]
);
$carrierUSD = FlightCarrier::firstOrCreate(
    ['code' => 'TST-USD'],
    ['name' => 'Test Carrier USD', 'currency' => 'USD', 'available_balance' => 0, 'is_active' => true]
);
$carrierKWD = FlightCarrier::firstOrCreate(
    ['code' => 'TST-KWD'],
    ['name' => 'Test Carrier KWD', 'currency' => 'KWD', 'available_balance' => 0, 'is_active' => true]
);
$carrierSAR = FlightCarrier::firstOrCreate(
    ['code' => 'TST-SAR'],
    ['name' => 'Test Carrier SAR', 'currency' => 'SAR', 'available_balance' => 0, 'is_active' => true]
);

// ─── Systems (EGP / USD) ───
$systemEGP = FlightSystem::firstOrCreate(
    ['name' => 'Test System EGP'],
    ['code' => 'TST-SYS-EGP', 'currency' => 'EGP', 'is_active' => true]
);
$systemUSD = FlightSystem::firstOrCreate(
    ['name' => 'Test System USD'],
    ['code' => 'TST-SYS-USD', 'currency' => 'USD', 'is_active' => true]
);

// ─── Customers ───
$cust1 = Customer::firstOrCreate(
    ['email' => 'flight.test.cust1@safarak.test'],
    ['name' => 'Test Customer 1 Flight', 'full_name' => 'Test Customer 1 Flight', 'phone' => '0100000001', 'nationality' => 'EG']
);
$cust2 = Customer::firstOrCreate(
    ['email' => 'flight.test.cust2@safarak.test'],
    ['name' => 'Test Customer 2 Flight', 'full_name' => 'Test Customer 2 Flight', 'phone' => '0100000002', 'nationality' => 'KW']
);

echo "  ✅ كيانات الاختبار جاهزة (carriers/systems/customers/accounts)\n";

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 1 — شحن رصيد الكيانات (Recharge)
// ─────────────────────────────────────────────────────────────────────────────
section('S01 — شحن رصيد الكيانات عبر خدمات الشحن الرسمية');

// Recharge EGP carrier 10,000 EGP
$cEGP_before = freshCarrierBal($carrierEGP);
try {
    $carrierRecharge->rechargeFromAccount($carrierEGP, $srcEGP, 10000.0, 'اختبار شحن EGP');
    $cEGP_after = freshCarrierBal($carrierEGP);
    assert_check('S01.1 شحن ناقل EGP بـ 10,000 EGP', abs($cEGP_after - $cEGP_before - 10000) < 0.01, "قبل={$cEGP_before} بعد={$cEGP_after}");
} catch (\Throwable $e) {
    assert_check('S01.1 شحن ناقل EGP', false, $e->getMessage());
}

// Recharge USD carrier 200 USD
$cUSD_before = freshCarrierBal($carrierUSD);
try {
    $carrierRecharge->rechargeFromAccount($carrierUSD, $srcUSD, 200.0, 'اختبار شحن USD');
    $cUSD_after = freshCarrierBal($carrierUSD);
    assert_check('S01.2 شحن ناقل USD بـ 200 USD', abs($cUSD_after - $cUSD_before - 200) < 0.001, "قبل={$cUSD_before} بعد={$cUSD_after}");
} catch (\Throwable $e) {
    assert_check('S01.2 شحن ناقل USD', false, $e->getMessage());
}

// Recharge KWD carrier 100 KWD
$cKWD_before = freshCarrierBal($carrierKWD);
try {
    $carrierRecharge->rechargeFromAccount($carrierKWD, $srcKWD, 100.0, 'اختبار شحن KWD');
    $cKWD_after = freshCarrierBal($carrierKWD);
    assert_check('S01.3 شحن ناقل KWD بـ 100 KWD', abs($cKWD_after - $cKWD_before - 100) < 0.001, "قبل={$cKWD_before} بعد={$cKWD_after}");
} catch (\Throwable $e) {
    assert_check('S01.3 شحن ناقل KWD', false, $e->getMessage());
}

// Recharge SAR carrier 500 SAR
$cSAR_before = freshCarrierBal($carrierSAR);
try {
    $carrierRecharge->rechargeFromAccount($carrierSAR, $srcSAR, 500.0, 'اختبار شحن SAR');
    $cSAR_after = freshCarrierBal($carrierSAR);
    assert_check('S01.4 شحن ناقل SAR بـ 500 SAR', abs($cSAR_after - $cSAR_before - 500) < 0.001, "قبل={$cSAR_before} بعد={$cSAR_after}");
} catch (\Throwable $e) {
    assert_check('S01.4 شحن ناقل SAR', false, $e->getMessage());
}

// Recharge EGP system 8,000 EGP
$sEGP_before = freshSystemBal($systemEGP);
try {
    $systemRecharge->rechargeFromAccount($systemEGP, $srcEGP, 8000.0, 'اختبار شحن system EGP');
    $sEGP_after = freshSystemBal($systemEGP);
    assert_check('S01.5 شحن نظام EGP بـ 8,000 EGP', abs($sEGP_after - $sEGP_before - 8000) < 0.01, "قبل={$sEGP_before} بعد={$sEGP_after}");
} catch (\Throwable $e) {
    assert_check('S01.5 شحن نظام EGP', false, $e->getMessage());
}

// Recharge USD system 100 USD
$sUSD_before = freshSystemBal($systemUSD);
try {
    $systemRecharge->rechargeFromAccount($systemUSD, $srcUSD, 100.0, 'اختبار شحن system USD');
    $sUSD_after = freshSystemBal($systemUSD);
    assert_check('S01.6 شحن نظام USD بـ 100 USD', abs($sUSD_after - $sUSD_before - 100) < 0.001, "قبل={$sUSD_before} بعد={$sUSD_after}");
} catch (\Throwable $e) {
    assert_check('S01.6 شحن نظام USD', false, $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 2 — حجز EGP عبر ناقل EGP
// ─────────────────────────────────────────────────────────────────────────────
section('S02 — حجز EGP عبر ناقل (carrier) — مديونية العميل + خصم رصيد الناقل');

$cust1Bal_beforeS02 = freshCustomerBal($cust1);
$carrierEGP_beforeS02 = freshCarrierBal($carrierEGP);

$bookingEGP = null;
try {
    $bookingEGP = $bookingService->createBooking([
        'customer_id'          => $cust1->id,
        'currency'             => 'EGP',
        'purchase_price'       => 3000.0,
        'selling_price'        => 3500.0,
        'exchange_rate'        => 1.0,
        'flight_carrier_id'    => $carrierEGP->id,
        'purchase_balance_source' => 'carrier',
        'from_airport'         => 'CAI',
        'to_airport'           => 'DXB',
        'departure_date'       => now()->addDays(10)->toDateString(),
        'pnr'                  => 'TSTEGP1',
        'airline_name'         => 'Test Carrier EGP',
        'passengers'           => [['first_name' => 'محمد', 'last_name' => 'علي', 'type' => 'adult']],
    ]);
    assert_check('S02.1 إنشاء حجز EGP', $bookingEGP !== null && $bookingEGP->id > 0, 'فشل الإنشاء');
} catch (\Throwable $e) {
    assert_check('S02.1 إنشاء حجز EGP', false, $e->getMessage());
}

if ($bookingEGP) {
    $cust1Bal_afterS02 = freshCustomerBal($cust1);
    $carrierEGP_afterS02 = freshCarrierBal($carrierEGP);

    // Customer debt should increase by selling price
    $custDelta = $cust1Bal_afterS02 - $cust1Bal_beforeS02;
    assert_check('S02.2 مديونية العميل زادت بـ 3500 EGP', abs($custDelta - 3500) < 0.01, "Δ={$custDelta}");

    // Carrier balance should decrease by purchase price
    $carrierDelta = $carrierEGP_beforeS02 - $carrierEGP_afterS02;
    assert_check('S02.3 رصيد الناقل انخفض بـ 3000 EGP', abs($carrierDelta - 3000) < 0.01, "Δ={$carrierDelta}");

    // Status confirmed (has PNR)
    assert_check('S02.4 حالة الحجز = confirmed', $bookingEGP->status->value === 'confirmed', $bookingEGP->status->value);

    // Profit stored correctly
    assert_check('S02.5 الربح = 500 EGP', abs((float)$bookingEGP->profit - 500) < 0.01, "profit={$bookingEGP->profit}");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 3 — حجز USD عبر ناقل USD
// ─────────────────────────────────────────────────────────────────────────────
section('S03 — حجز USD عبر ناقل USD (عملة أجنبية)');

$cust1Bal_beforeS03 = freshCustomerBal($cust1);
$carrierUSD_beforeS03 = freshCarrierBal($carrierUSD);

$bookingUSD = null;
try {
    // 60 USD purchase, selling_price = 60*usdRate EGP equivalent
    $usdPurchase = 50.0; // USD
    $usdSelling  = round($usdPurchase * $usdRate * 1.1, 2); // EGP (10% profit)
    $bookingUSD = $bookingService->createBooking([
        'customer_id'             => $cust1->id,
        'currency'                => 'USD',
        'purchase_price_foreign'  => $usdPurchase,
        'selling_price'           => $usdSelling, // always EGP
        'exchange_rate'           => $usdRate,
        'flight_carrier_id'       => $carrierUSD->id,
        'purchase_balance_source' => 'carrier',
        'from_airport'            => 'CAI',
        'to_airport'              => 'JFK',
        'departure_date'          => now()->addDays(15)->toDateString(),
        'pnr'                     => 'TSTUSD1',
        'airline_name'            => 'Test Carrier USD',
        'passengers'              => [['first_name' => 'سارة', 'last_name' => 'أحمد', 'type' => 'adult']],
    ]);
    assert_check('S03.1 إنشاء حجز USD', $bookingUSD !== null && $bookingUSD->id > 0);
} catch (\Throwable $e) {
    assert_check('S03.1 إنشاء حجز USD', false, $e->getMessage());
}

if ($bookingUSD) {
    $cust1Bal_afterS03 = freshCustomerBal($cust1);
    $carrierUSD_afterS03 = freshCarrierBal($carrierUSD);

    $custDeltaS03 = $cust1Bal_afterS03 - $cust1Bal_beforeS03;
    $usdSelling2 = (float)$bookingUSD->selling_price;
    assert_check('S03.2 مديونية العميل زادت بسعر البيع EGP', abs($custDeltaS03 - $usdSelling2) < 0.5, "Δ={$custDeltaS03}, expected={$usdSelling2}");

    $carrierDeltaS03 = $carrierUSD_beforeS03 - $carrierUSD_afterS03;
    $usdPurchForeign = (float)$bookingUSD->purchase_price_foreign;
    assert_check('S03.3 رصيد ناقل USD انخفض بـ 50 USD', abs($carrierDeltaS03 - 50.0) < 0.001, "Δ={$carrierDeltaS03}");

    assert_check('S03.4 ربح موجب', (float)$bookingUSD->profit > 0, "profit={$bookingUSD->profit}");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 4 — حجز KWD عبر ناقل KWD
// ─────────────────────────────────────────────────────────────────────────────
section('S04 — حجز KWD عبر ناقل KWD (عملة خليجية ذات سعر مرتفع)');

$cust2Bal_beforeS04 = freshCustomerBal($cust2);
$carrierKWD_beforeS04 = freshCarrierBal($carrierKWD);

$bookingKWD = null;
try {
    $kwdPurchase = 20.0; // KWD
    $kwdSellingEGP = round($kwdPurchase * $kwdRate * 1.12, 2);
    $bookingKWD = $bookingService->createBooking([
        'customer_id'             => $cust2->id,
        'currency'                => 'KWD',
        'purchase_price_foreign'  => $kwdPurchase,
        'selling_price'           => $kwdSellingEGP,
        'exchange_rate'           => $kwdRate,
        'flight_carrier_id'       => $carrierKWD->id,
        'purchase_balance_source' => 'carrier',
        'from_airport'            => 'KWI',
        'to_airport'              => 'CAI',
        'departure_date'          => now()->addDays(5)->toDateString(),
        'pnr'                     => 'TSTKWD1',
        'airline_name'            => 'Test Carrier KWD',
        'passengers'              => [['first_name' => 'خالد', 'last_name' => 'الكويتي', 'type' => 'adult']],
    ]);
    assert_check('S04.1 إنشاء حجز KWD', $bookingKWD !== null && $bookingKWD->id > 0);
} catch (\Throwable $e) {
    assert_check('S04.1 إنشاء حجز KWD', false, $e->getMessage());
}

if ($bookingKWD) {
    $cust2Bal_afterS04 = freshCustomerBal($cust2);
    $carrierKWD_afterS04 = freshCarrierBal($carrierKWD);

    $custDeltaS04 = $cust2Bal_afterS04 - $cust2Bal_beforeS04;
    $kwdSelling = (float)$bookingKWD->selling_price;
    assert_check('S04.2 مديونية العميل 2 زادت بسعر البيع', abs($custDeltaS04 - $kwdSelling) < 0.5, "Δ={$custDeltaS04}, expected={$kwdSelling}");

    $cKWDDelta = $carrierKWD_beforeS04 - $carrierKWD_afterS04;
    assert_check('S04.3 رصيد ناقل KWD انخفض بـ 20 KWD', abs($cKWDDelta - 20.0) < 0.001, "Δ={$cKWDDelta}");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 5 — حجز SAR عبر ناقل SAR
// ─────────────────────────────────────────────────────────────────────────────
section('S05 — حجز SAR عبر ناقل SAR');

$cust2Bal_beforeS05 = freshCustomerBal($cust2);
$carrierSAR_beforeS05 = freshCarrierBal($carrierSAR);

$bookingSAR = null;
try {
    $sarPurchase = 100.0; // SAR
    $sarSellingEGP = round($sarPurchase * $sarRate * 1.1, 2);
    $bookingSAR = $bookingService->createBooking([
        'customer_id'             => $cust2->id,
        'currency'                => 'SAR',
        'purchase_price_foreign'  => $sarPurchase,
        'selling_price'           => $sarSellingEGP,
        'exchange_rate'           => $sarRate,
        'flight_carrier_id'       => $carrierSAR->id,
        'purchase_balance_source' => 'carrier',
        'from_airport'            => 'RUH',
        'to_airport'              => 'CAI',
        'departure_date'          => now()->addDays(7)->toDateString(),
        'pnr'                     => 'TSTSAR1',
        'airline_name'            => 'Test Carrier SAR',
        'passengers'              => [['first_name' => 'فاطمة', 'last_name' => 'السعودية', 'type' => 'adult']],
    ]);
    assert_check('S05.1 إنشاء حجز SAR', $bookingSAR !== null && $bookingSAR->id > 0);
} catch (\Throwable $e) {
    assert_check('S05.1 إنشاء حجز SAR', false, $e->getMessage());
}

if ($bookingSAR) {
    $cust2Bal_afterS05 = freshCustomerBal($cust2);
    $carrierSAR_afterS05 = freshCarrierBal($carrierSAR);

    $custDeltaS05 = $cust2Bal_afterS05 - $cust2Bal_beforeS05;
    $sarSelling = (float)$bookingSAR->selling_price;
    assert_check('S05.2 مديونية العميل 2 زادت بسعر البيع SAR→EGP', abs($custDeltaS05 - $sarSelling) < 0.5, "Δ={$custDeltaS05}");

    $cSARDelta = $carrierSAR_beforeS05 - $carrierSAR_afterS05;
    assert_check('S05.3 رصيد ناقل SAR انخفض بـ 100 SAR', abs($cSARDelta - 100.0) < 0.001, "Δ={$cSARDelta}");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 6 — حجز EGP عبر نظام (system)
// ─────────────────────────────────────────────────────────────────────────────
section('S06 — حجز EGP عبر نظام (system) — خصم من رصيد النظام');

$sEGP_beforeS06 = freshSystemBal($systemEGP);
$cust1Bal_beforeS06 = freshCustomerBal($cust1);

$bookingSystem = null;
try {
    $bookingSystem = $bookingService->createBooking([
        'customer_id'             => $cust1->id,
        'currency'                => 'EGP',
        'purchase_price'          => 2000.0,
        'selling_price'           => 2400.0,
        'exchange_rate'           => 1.0,
        'flight_system_id'        => $systemEGP->id,
        'purchase_balance_source' => 'system',
        'from_airport'            => 'CAI',
        'to_airport'              => 'LHR',
        'departure_date'          => now()->addDays(20)->toDateString(),
        'pnr'                     => 'TSTSYS1',
        'airline_name'            => 'Test System EGP Airline',
        'passengers'              => [['first_name' => 'عمر', 'last_name' => 'حسن', 'type' => 'adult']],
    ]);
    assert_check('S06.1 إنشاء حجز عبر نظام EGP', $bookingSystem !== null && $bookingSystem->id > 0);
} catch (\Throwable $e) {
    assert_check('S06.1 إنشاء حجز عبر نظام EGP', false, $e->getMessage());
}

if ($bookingSystem) {
    $sEGP_afterS06 = freshSystemBal($systemEGP);
    $cust1Bal_afterS06 = freshCustomerBal($cust1);

    $sysDelta = $sEGP_beforeS06 - $sEGP_afterS06;
    assert_check('S06.2 رصيد النظام انخفض بـ 2000 EGP', abs($sysDelta - 2000) < 0.01, "Δ={$sysDelta}");

    $custDeltaS06 = $cust1Bal_afterS06 - $cust1Bal_beforeS06;
    assert_check('S06.3 مديونية العميل زادت بـ 2400 EGP', abs($custDeltaS06 - 2400) < 0.01, "Δ={$custDeltaS06}");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 7 — إضافة دفعة (Payment) على الحجز
// ─────────────────────────────────────────────────────────────────────────────
section('S07 — إضافة دفعة (Payment) — يقلل مديونية العميل');

if ($bookingEGP) {
    $cust1Bal_beforeS07 = freshCustomerBal($cust1);
    $cashboxBal_before = freshBal($srcEGP);

    try {
        $bookingService->addPayment($bookingEGP, [
            'amount'         => 1000.0,
            'payment_method' => 'cash',
            'account_id'     => $srcEGP->id,
            'notes'          => 'دفعة اختبار',
        ]);
        $cust1Bal_afterS07 = freshCustomerBal($cust1);
        $cashboxBal_after = freshBal($srcEGP);

        $custDeltaS07 = $cust1Bal_beforeS07 - $cust1Bal_afterS07;
        assert_check('S07.1 مديونية العميل قلت بـ 1000 بعد الدفع', abs($custDeltaS07 - 1000) < 0.01, "Δ={$custDeltaS07}");

        $cashDelta = $cashboxBal_after - $cashboxBal_before;
        assert_check('S07.2 رصيد الخزينة زاد بـ 1000 بعد استلام الدفعة', abs($cashDelta - 1000) < 0.01, "Δ={$cashDelta}");
    } catch (\Throwable $e) {
        assert_check('S07.1 دفعة على حجز EGP', false, $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 8 — محاولة حذف مباشر بدون الحارس (يجب أن ترفض)
// ─────────────────────────────────────────────────────────────────────────────
section('S08 — حارس الحذف — الحذف المباشر يُرفض');

if ($bookingSystem) {
    $threwGuardException = false;
    try {
        // Force the guard OFF by calling ->delete() directly
        $bookingSystem->delete();
    } catch (\RuntimeException $e) {
        if (str_contains($e->getMessage(), 'لا يمكن حذف حجز الطيران')) {
            $threwGuardException = true;
        }
    }
    assert_check('S08.1 الحذف المباشر يرفضه ModelDeletionGuard', $threwGuardException, 'لم يُرمَ استثناء الحارس');

    // Booking still exists
    $stillExists = FlightBooking::query()->where('id', $bookingSystem->id)->exists();
    assert_check('S08.2 الحجز لا يزال موجوداً بعد رفض الحذف', $stillExists);
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 9 — الحذف مع العكس (deleteBookingWithReversal) — EGP carrier
// ─────────────────────────────────────────────────────────────────────────────
section('S09 — deleteBookingWithReversal — حجز EGP (كامل العكس المحاسبي)');

if ($bookingEGP) {
    $bookingId = $bookingEGP->id;

    // Snapshot before delete
    $cust1Bal_beforeDel = freshCustomerBal($cust1);
    $carrierEGP_beforeDel = freshCarrierBal($carrierEGP);
    $cashboxBal_beforeDel = freshBal($srcEGP);

    // Expected: selling_price was 3500, paid 1000 → remaining debt 2500
    // After delete: customer debt cleared (3500 reversed, 1000 payment reversed)
    // carrier gets back 3000
    try {
        $result = $bookingService->deleteBookingWithReversal($bookingId, $adminUser->id);
        assert_check('S09.1 deleteBookingWithReversal ترجع true', $result === true);
    } catch (\Throwable $e) {
        assert_check('S09.1 deleteBookingWithReversal', false, $e->getMessage());
    }

    // Booking soft-deleted
    $booking = FlightBooking::withTrashed()->find($bookingId);
    assert_check('S09.2 الحجز محذوف Soft Delete', $booking !== null && $booking->trashed());

    // Customer debt reversed (selling price reversed + payment reversal)
    $cust1Bal_afterDel = freshCustomerBal($cust1);
    $custDeltaDel = $cust1Bal_afterDel - $cust1Bal_beforeDel;
    // Net: -3500 (reversal of sale) + 1000 (reversal of payment increases debt back) = -2500
    // Actually: Sale reversal: -3500 from customer AR; Payment reversal: +1000 back to customer AR
    // So net change in customer balance = -3500 + 1000 = -2500 (customer balance decreases by 2500)
    assert_check('S09.3 مديونية العميل انخفضت بـ 2500 (عكس صافي)', abs($custDeltaDel - (-2500)) < 0.5, "Δ={$custDeltaDel}");

    // Carrier got 3000 back
    $carrierEGP_afterDel = freshCarrierBal($carrierEGP);
    $carrierDeltaDel = $carrierEGP_afterDel - $carrierEGP_beforeDel;
    assert_check('S09.4 ناقل EGP استرد 3000 EGP بعد الحذف', abs($carrierDeltaDel - 3000) < 0.01, "Δ={$carrierDeltaDel}");

    // Cashbox lost the reversed payment (1000 returned)
    $cashboxBal_afterDel = freshBal($srcEGP);
    $cashboxDeltaDel = $cashboxBal_afterDel - $cashboxBal_beforeDel;
    assert_check('S09.5 الخزينة خسرت 1000 (إعادة الدفعة)', abs($cashboxDeltaDel - (-1000)) < 0.01, "Δ={$cashboxDeltaDel}");

    // Re-delete must fail (idempotency)
    $redeleted = false;
    try {
        $bookingService->deleteBookingWithReversal($bookingId, $adminUser->id);
    } catch (\RuntimeException $e) {
        if (str_contains($e->getMessage(), 'محذوف بالفعل')) {
            $redeleted = true;
        }
    }
    assert_check('S09.6 إعادة الحذف ترفض (idempotency guard)', $redeleted);
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 10 — الحذف مع العكس — حجز USD
// ─────────────────────────────────────────────────────────────────────────────
section('S10 — deleteBookingWithReversal — حجز USD (عملة أجنبية)');

if ($bookingUSD) {
    $bId = $bookingUSD->id;
    $cust1Bal_bef = freshCustomerBal($cust1);
    $carrierUSD_bef = freshCarrierBal($carrierUSD);

    $sellingEGP_USD = (float)$bookingUSD->selling_price;
    $purchaseUSD    = (float)$bookingUSD->purchase_price_foreign;

    try {
        $bookingService->deleteBookingWithReversal($bId, $adminUser->id);
        assert_check('S10.1 deleteBookingWithReversal USD', true);
    } catch (\Throwable $e) {
        assert_check('S10.1 deleteBookingWithReversal USD', false, $e->getMessage());
    }

    $bUSD = FlightBooking::withTrashed()->find($bId);
    assert_check('S10.2 حجز USD محذوف soft', $bUSD !== null && $bUSD->trashed());

    $cust1Bal_aft = freshCustomerBal($cust1);
    $custDeltaUSD = $cust1Bal_aft - $cust1Bal_bef;
    assert_check('S10.3 مديونية العميل عكست سعر البيع بالـ EGP', abs($custDeltaUSD - (-$sellingEGP_USD)) < 0.5, "Δ={$custDeltaUSD}, expected={$sellingEGP_USD}");

    $carrierUSD_aft = freshCarrierBal($carrierUSD);
    $carrierDeltaUSD = $carrierUSD_aft - $carrierUSD_bef;
    assert_check('S10.4 ناقل USD استرد 50 USD', abs($carrierDeltaUSD - 50.0) < 0.001, "Δ={$carrierDeltaUSD}");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 11 — الحذف مع العكس — حجز KWD
// ─────────────────────────────────────────────────────────────────────────────
section('S11 — deleteBookingWithReversal — حجز KWD');

if ($bookingKWD) {
    $bId = $bookingKWD->id;
    $cust2Bal_bef = freshCustomerBal($cust2);
    $carrierKWD_bef = freshCarrierBal($carrierKWD);
    $kwdSellingEGP = (float)$bookingKWD->selling_price;

    try {
        $bookingService->deleteBookingWithReversal($bId, $adminUser->id);
        assert_check('S11.1 deleteBookingWithReversal KWD', true);
    } catch (\Throwable $e) {
        assert_check('S11.1 deleteBookingWithReversal KWD', false, $e->getMessage());
    }

    $bKWD = FlightBooking::withTrashed()->find($bId);
    assert_check('S11.2 حجز KWD محذوف soft', $bKWD !== null && $bKWD->trashed());

    $cust2Bal_aft = freshCustomerBal($cust2);
    $custDeltaKWD = $cust2Bal_aft - $cust2Bal_bef;
    assert_check('S11.3 مديونية العميل 2 انخفضت بسعر البيع EGP', abs($custDeltaKWD - (-$kwdSellingEGP)) < 0.5, "Δ={$custDeltaKWD}");

    $carrierKWD_aft = freshCarrierBal($carrierKWD);
    $carrierDeltaKWD = $carrierKWD_aft - $carrierKWD_bef;
    assert_check('S11.4 ناقل KWD استرد 20 KWD', abs($carrierDeltaKWD - 20.0) < 0.001, "Δ={$carrierDeltaKWD}");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 12 — الحذف مع العكس — حجز SAR
// ─────────────────────────────────────────────────────────────────────────────
section('S12 — deleteBookingWithReversal — حجز SAR');

if ($bookingSAR) {
    $bId = $bookingSAR->id;
    $cust2Bal_bef = freshCustomerBal($cust2);
    $carrierSAR_bef = freshCarrierBal($carrierSAR);
    $sarSellingEGP = (float)$bookingSAR->selling_price;

    try {
        $bookingService->deleteBookingWithReversal($bId, $adminUser->id);
        assert_check('S12.1 deleteBookingWithReversal SAR', true);
    } catch (\Throwable $e) {
        assert_check('S12.1 deleteBookingWithReversal SAR', false, $e->getMessage());
    }

    $bSAR = FlightBooking::withTrashed()->find($bId);
    assert_check('S12.2 حجز SAR محذوف soft', $bSAR !== null && $bSAR->trashed());

    $cust2Bal_aft = freshCustomerBal($cust2);
    $custDeltaSAR = $cust2Bal_aft - $cust2Bal_bef;
    assert_check('S12.3 مديونية العميل 2 انخفضت بسعر البيع EGP', abs($custDeltaSAR - (-$sarSellingEGP)) < 0.5, "Δ={$custDeltaSAR}");

    $carrierSAR_aft = freshCarrierBal($carrierSAR);
    $carrierDeltaSAR = $carrierSAR_aft - $carrierSAR_bef;
    assert_check('S12.4 ناقل SAR استرد 100 SAR', abs($carrierDeltaSAR - 100.0) < 0.001, "Δ={$carrierDeltaSAR}");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 13 — الحذف مع العكس — حجز نظام EGP (مع دفع + عكس)
// ─────────────────────────────────────────────────────────────────────────────
section('S13 — deleteBookingWithReversal — حجز نظام EGP مع دفع');

if ($bookingSystem) {
    $bId = $bookingSystem->id;
    $sEGP_bef = freshSystemBal($systemEGP);
    $cust1Bal_bef = freshCustomerBal($cust1);

    // Add a payment first
    try {
        $bookingService->addPayment($bookingSystem, [
            'amount'         => 800.0,
            'payment_method' => 'bank_transfer',
            'account_id'     => $srcEGP->id,
        ]);
        $cust1Bal_afterPay = freshCustomerBal($cust1);
        $payDelta = $cust1Bal_bef - $cust1Bal_afterPay;
        assert_check('S13.1 دفع 800 EGP على حجز النظام', abs($payDelta - 800) < 0.01, "Δ={$payDelta}");
    } catch (\Throwable $e) {
        assert_check('S13.1 دفع على حجز النظام', false, $e->getMessage());
    }

    $cust1Bal_bef2 = freshCustomerBal($cust1);
    $cashboxBef2 = freshBal($srcEGP);

    try {
        $bookingService->deleteBookingWithReversal($bId, $adminUser->id);
        assert_check('S13.2 deleteBookingWithReversal نظام EGP', true);
    } catch (\Throwable $e) {
        assert_check('S13.2 deleteBookingWithReversal نظام EGP', false, $e->getMessage());
    }

    $bSys = FlightBooking::withTrashed()->find($bId);
    assert_check('S13.3 حجز النظام محذوف soft', $bSys !== null && $bSys->trashed());

    $sEGP_aft = freshSystemBal($systemEGP);
    $sysDeltaDel = $sEGP_aft - $sEGP_bef;
    assert_check('S13.4 النظام استرد 2000 EGP', abs($sysDeltaDel - 2000) < 0.01, "Δ={$sysDeltaDel}");

    $cust1Bal_aft2 = freshCustomerBal($cust1);
    $custDeltaDel2 = $cust1Bal_aft2 - $cust1Bal_bef2;
    // Sale reversal: -2400; payment reversal: +800 = net -1600
    assert_check('S13.5 مديونية العميل انخفضت بـ 1600 (2400-800)', abs($custDeltaDel2 - (-1600)) < 0.5, "Δ={$custDeltaDel2}");

    $cashboxAft2 = freshBal($srcEGP);
    $cashboxDelta2 = $cashboxAft2 - $cashboxBef2;
    assert_check('S13.6 الخزينة خسرت 800 (إعادة الدفعة)', abs($cashboxDelta2 - (-800)) < 0.01, "Δ={$cashboxDelta2}");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 14 — حجز بدون PNR → PENDING ثم محاولة حذف مع عكس
// ─────────────────────────────────────────────────────────────────────────────
section('S14 — حجز PENDING (بدون PNR) ثم حذف مع عكس');

$bookingPending = null;
$cust1Bal_bef14 = freshCustomerBal($cust1);
$carrierEGP_bef14 = freshCarrierBal($carrierEGP);

try {
    $bookingPending = $bookingService->createBooking([
        'customer_id'          => $cust1->id,
        'currency'             => 'EGP',
        'purchase_price'       => 1500.0,
        'selling_price'        => 1800.0,
        'exchange_rate'        => 1.0,
        'flight_carrier_id'    => $carrierEGP->id,
        'purchase_balance_source' => 'carrier',
        'from_airport'         => 'CAI',
        'to_airport'           => 'AMM',
        'departure_date'       => now()->addDays(30)->toDateString(),
        // No PNR → PENDING
        'airline_name'         => 'Test Pending Booking',
    ]);
    assert_check('S14.1 إنشاء حجز PENDING (بدون PNR)', $bookingPending !== null && $bookingPending->status->value === 'pending', "status={$bookingPending->status->value}");
} catch (\Throwable $e) {
    assert_check('S14.1 إنشاء حجز PENDING', false, $e->getMessage());
}

if ($bookingPending) {
    $cust1Bal_aft14 = freshCustomerBal($cust1);
    $custDelta14 = $cust1Bal_aft14 - $cust1Bal_bef14;
    assert_check('S14.2 مديونية العميل زادت حتى لو PENDING', abs($custDelta14 - 1800) < 0.01, "Δ={$custDelta14}");

    $carrierEGP_aft14 = freshCarrierBal($carrierEGP);
    $cDelta14 = $carrierEGP_bef14 - $carrierEGP_aft14;
    assert_check('S14.3 الناقل انخفض حتى لو PENDING', abs($cDelta14 - 1500) < 0.01, "Δ={$cDelta14}");

    // Now delete with reversal
    $cust1Bal_bef14b = freshCustomerBal($cust1);
    $carrierEGP_bef14b = freshCarrierBal($carrierEGP);
    try {
        $bookingService->deleteBookingWithReversal($bookingPending->id, $adminUser->id);
        assert_check('S14.4 حذف PENDING مع عكس نجح', true);
    } catch (\Throwable $e) {
        assert_check('S14.4 حذف PENDING مع عكس', false, $e->getMessage());
    }

    $cust1Bal_aft14b = freshCustomerBal($cust1);
    $carrierEGP_aft14b = freshCarrierBal($carrierEGP);

    $custDelta14b = $cust1Bal_aft14b - $cust1Bal_bef14b;
    assert_check('S14.5 مديونية العميل عكست 1800 EGP', abs($custDelta14b - (-1800)) < 0.01, "Δ={$custDelta14b}");

    $cDelta14b = $carrierEGP_aft14b - $carrierEGP_bef14b;
    assert_check('S14.6 الناقل استرد 1500 EGP', abs($cDelta14b - 1500) < 0.01, "Δ={$cDelta14b}");

    $bPend = FlightBooking::withTrashed()->find($bookingPending->id);
    assert_check('S14.7 الحجز محذوف soft', $bPend !== null && $bPend->trashed());
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 15 — التحقق من تكامل الميزان المحاسبي بعد جميع العمليات
// ─────────────────────────────────────────────────────────────────────────────
section('S15 — تكامل الميزان: مطابقة account.balance مع آخر account_entry.balance_after');

$accountsToCheck = Account::query()
    ->whereIn('name', [
        'Test-SrcEGP-Flight', 'Test-SrcUSD-Flight', 'Test-SrcKWD-Flight', 'Test-SrcSAR-Flight',
    ])
    ->get();

foreach ($accountsToCheck as $acc) {
    $lastEntry = DB::table('account_entries')
        ->where('account_id', $acc->id)
        ->orderByDesc('id')
        ->first();

    if (!$lastEntry) {
        assert_check("S15 [{$acc->name}] تكامل الميزان — لا توجد قيود", true, 'لا حركات → مقبول');
        continue;
    }

    $diff = abs((float)$acc->balance - (float)$lastEntry->balance_after);
    assert_check(
        "S15 [{$acc->name}] account.balance = آخر balance_after",
        $diff < 0.01,
        "account.balance={$acc->balance}, last_entry.balance_after={$lastEntry->balance_after}, diff={$diff}"
    );
}

// Check carrier balances integrity
foreach ([
    $carrierEGP->id => 'Carrier EGP',
    $carrierUSD->id => 'Carrier USD',
    $carrierKWD->id => 'Carrier KWD',
    $carrierSAR->id => 'Carrier SAR',
] as $cid => $cname) {
    $c = FlightCarrier::find($cid);
    // Carrier balance should be non-negative (no over-withdrawal)
    if ($c) {
        assert_check("S15 [{$cname}] رصيد ≥ 0", (float)$c->available_balance >= -0.001, "balance={$c->available_balance}");
    }
}

// Check system balances
foreach ([
    $systemEGP->id => 'System EGP',
    $systemUSD->id => 'System USD',
] as $sid => $sname) {
    $s = FlightSystem::find($sid);
    if ($s) {
        assert_check("S15 [{$sname}] رصيد ≥ 0", (float)$s->available_balance >= -0.001, "balance={$s->available_balance}");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 16 — منع شحن ناقل بعملة مختلفة (cross-currency guard)
// ─────────────────────────────────────────────────────────────────────────────
section('S16 — حارس عملة الشحن: شحن ناقل USD من حساب EGP → يُرفض');

$crossCurrencyRejected = false;
try {
    $carrierRecharge->rechargeFromAccount($carrierUSD, $srcEGP, 100.0, 'cross-currency test');
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'تضارب في العملة')) {
        $crossCurrencyRejected = true;
    }
}
assert_check('S16.1 شحن ناقل USD من EGP → رُفض بـ تضارب العملة', $crossCurrencyRejected);

$crossCurrencySystemRejected = false;
try {
    $systemRecharge->rechargeFromAccount($systemUSD, $srcEGP, 100.0, 'cross-currency system test');
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'تضارب في العملة')) {
        $crossCurrencySystemRejected = true;
    }
}
assert_check('S16.2 شحن نظام USD من EGP → رُفض بـ تضارب العملة', $crossCurrencySystemRejected);

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 17 — محاولة حجز بالناقل برصيد غير كافٍ → رُفض
// ─────────────────────────────────────────────────────────────────────────────
section('S17 — رصيد الناقل غير كافٍ → رُفض الحجز');

// Reset carrier USD balance to small amount
FlightCarrier::where('id', $carrierUSD->id)->update(['available_balance' => 1.0]);

$insufficientBalRejected = false;
try {
    $bookingService->createBooking([
        'customer_id'             => $cust1->id,
        'currency'                => 'USD',
        'purchase_price_foreign'  => 100.0, // more than available 1.0 USD
        'selling_price'           => 100.0 * $usdRate,
        'exchange_rate'           => $usdRate,
        'flight_carrier_id'       => $carrierUSD->id,
        'purchase_balance_source' => 'carrier',
        'from_airport'            => 'CAI',
        'to_airport'              => 'JFK',
        'departure_date'          => now()->addDays(10)->toDateString(),
        'pnr'                     => 'TSTUSD_FAIL',
        'airline_name'            => 'Test',
    ]);
} catch (\Exception $e) {
    if (str_contains($e->getMessage(), 'غير كافٍ') || str_contains($e->getMessage(), 'insufficient') || str_contains($e->getMessage(), 'رصيد')) {
        $insufficientBalRejected = true;
    }
}
assert_check('S17.1 حجز بناقل رصيده أقل من التكلفة → رُفض', $insufficientBalRejected);

// Restore carrier USD balance
FlightCarrier::where('id', $carrierUSD->id)->update(['available_balance' => 0]);

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 18 — اختبار الـ Tickets والـ Passengers
// ─────────────────────────────────────────────────────────────────────────────
section('S18 — التأكد من إنشاء التذاكر والركاب');

// Create a new booking and check tickets/passengers
$bookingForTicketCheck = null;
try {
    // Recharge first
    $carrierRecharge->rechargeFromAccount($carrierEGP, $srcEGP, 5000.0, 'للتذاكر');
    $bookingForTicketCheck = $bookingService->createBooking([
        'customer_id'          => $cust2->id,
        'currency'             => 'EGP',
        'purchase_price'       => 1000.0,
        'selling_price'        => 1200.0,
        'exchange_rate'        => 1.0,
        'flight_carrier_id'    => $carrierEGP->id,
        'purchase_balance_source' => 'carrier',
        'from_airport'         => 'CAI',
        'to_airport'           => 'BEY',
        'departure_date'       => now()->addDays(14)->toDateString(),
        'pnr'                  => 'TSTTKT1',
        'airline_name'         => 'Test',
        'passengers'           => [
            ['first_name' => 'أحمد', 'last_name' => 'محمود', 'type' => 'adult'],
            ['first_name' => 'لينا',  'last_name' => 'محمود', 'type' => 'child'],
        ],
    ]);
} catch (\Throwable $e) {
    // ignore
}

if ($bookingForTicketCheck) {
    $b = $bookingForTicketCheck->load(['passengers', 'tickets', 'segments']);
    assert_check('S18.1 إنشاء 2 ركاب', $b->passengers->count() === 2, "count={$b->passengers->count()}");
    assert_check('S18.2 إنشاء 2 تذاكر (راكب واحد لكل تذكرة)', $b->tickets->count() === 2, "count={$b->tickets->count()}");
    assert_check('S18.3 إنشاء مسار تلقائي', $b->segments->count() >= 1, "count={$b->segments->count()}");

    // After delete: tickets cancelled, passengers/segments cleaned
    try {
        $bookingService->deleteBookingWithReversal($bookingForTicketCheck->id, $adminUser->id);
        $afterDel = FlightBooking::withTrashed()->with(['tickets', 'passengers', 'segments'])->find($bookingForTicketCheck->id);

        // Tickets should be cancelled
        $cancelledTickets = $afterDel->tickets->where('status', 'cancelled')->count();
        assert_check('S18.4 التذاكر ملغاة بعد الحذف', $cancelledTickets === 2, "cancelled={$cancelledTickets}");

        // Passengers should be hard-deleted
        $remainPassengers = \App\Models\Flight\FlightPassenger::where('flight_booking_id', $afterDel->id)->count();
        assert_check('S18.5 الركاب محذوفون بعد الحذف', $remainPassengers === 0, "remain={$remainPassengers}");

        // Segments hard-deleted
        $remainSegments = \App\Models\Flight\FlightSegment::where('flight_booking_id', $afterDel->id)->count();
        assert_check('S18.6 المسارات محذوفة بعد الحذف', $remainSegments === 0, "remain={$remainSegments}");
    } catch (\Throwable $e) {
        assert_check('S18.4+ حذف حجز التذاكر', false, $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 19 — حجز USD عبر نظام USD مع حذف
// ─────────────────────────────────────────────────────────────────────────────
section('S19 — حجز USD عبر نظام USD + deleteWithReversal');

// Recharge system USD
$systemRecharge->rechargeFromAccount($systemUSD, $srcUSD, 50.0, 'للحجز USD نظام');
$sUSD_bef19 = freshSystemBal($systemUSD);
$cust2Bal_bef19 = freshCustomerBal($cust2);

$bookingSystemUSD = null;
try {
    $bookingSystemUSD = $bookingService->createBooking([
        'customer_id'             => $cust2->id,
        'currency'                => 'USD',
        'purchase_price_foreign'  => 30.0,
        'selling_price'           => round(30.0 * $usdRate * 1.1, 2),
        'exchange_rate'           => $usdRate,
        'flight_system_id'        => $systemUSD->id,
        'purchase_balance_source' => 'system',
        'from_airport'            => 'DXB',
        'to_airport'              => 'CAI',
        'departure_date'          => now()->addDays(8)->toDateString(),
        'pnr'                     => 'TSTSYSUSD1',
        'airline_name'            => 'Test System USD',
    ]);
    assert_check('S19.1 إنشاء حجز USD عبر نظام', $bookingSystemUSD !== null && $bookingSystemUSD->id > 0);
} catch (\Throwable $e) {
    assert_check('S19.1 إنشاء حجز USD عبر نظام', false, $e->getMessage());
}

if ($bookingSystemUSD) {
    $sUSD_aft19 = freshSystemBal($systemUSD);
    $sysDelta19 = $sUSD_bef19 - $sUSD_aft19;
    assert_check('S19.2 نظام USD انخفض بـ 30 USD', abs($sysDelta19 - 30.0) < 0.001, "Δ={$sysDelta19}");

    // Delete with reversal
    $sUSD_bef19b = freshSystemBal($systemUSD);
    try {
        $bookingService->deleteBookingWithReversal($bookingSystemUSD->id, $adminUser->id);
        assert_check('S19.3 deleteWithReversal USD نظام', true);
    } catch (\Throwable $e) {
        assert_check('S19.3 deleteWithReversal USD نظام', false, $e->getMessage());
    }

    $sUSD_aft19b = freshSystemBal($systemUSD);
    $sysDelta19b = $sUSD_aft19b - $sUSD_bef19b;
    assert_check('S19.4 نظام USD استرد 30 USD بعد الحذف', abs($sysDelta19b - 30.0) < 0.001, "Δ={$sysDelta19b}");

    $bSysUSD = FlightBooking::withTrashed()->find($bookingSystemUSD->id);
    assert_check('S19.5 الحجز محذوف soft', $bSysUSD !== null && $bSysUSD->trashed());
}

// ─────────────────────────────────────────────────────────────────────────────
// FINAL SUMMARY
// ─────────────────────────────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $startTime, 2);
$total   = $passed + $failed;

echo "\n" . str_repeat('═', 70) . "\n";
echo "  📊 نتائج الاختبار الشامل لموديول الطيران\n";
echo str_repeat('═', 70) . "\n";
echo "  ✅ نجح : {$passed}/{$total}\n";
echo "  ❌ فشل : {$failed}/{$total}\n";
echo "  ⏱  الوقت: {$elapsed}s\n";
echo str_repeat('═', 70) . "\n";

if ($failed > 0) {
    echo "\n🔴 الاختبارات الفاشلة:\n";
    foreach ($results as $r) {
        if (!$r['passed']) {
            echo "  - {$r['label']}";
            if (!empty($r['detail'])) echo " → {$r['detail']}";
            echo "\n";
        }
    }
}

// Save JSON log
$logData = [
    'timestamp'  => now()->toIso8601String(),
    'passed'     => $passed,
    'failed'     => $failed,
    'total'      => $total,
    'elapsed_s'  => $elapsed,
    'results'    => $results,
];
$logPath = storage_path('logs/flight_production_full_test_' . now()->format('Ymd_His') . '.json');
file_put_contents($logPath, json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\n  📄 تقرير JSON: {$logPath}\n";

if ($failed === 0) {
    echo "\n🎉 100% PASS — موديول الطيران جاهز للإنتاج!\n";
} else {
    echo "\n⚠️ يوجد {$failed} اختبار فاشل — راجع التفاصيل أعلاه.\n";
    exit(1);
}
