<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — Group Balance Comprehensive Tests
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يختبر كل حالات رصيد المجموعة (FlightGroup):
 * - رصيد صفر (لا prepaid، لا دين)
 * - رصيد موجب (prepaid)
 * - رصيد سالب (دين)
 * - رصيد غير كافٍ
 * - حدود الشراء
 *
 * الـ Bugs المطلوب التحقق منها:
 * - Bug #15: لا يوجد فحص للرصيد في recordPurchaseFromGroup
 * - Bug #16 (جديد): الإصلاح الحالي يفشل مع المجموعات prepaid
 *                    لأن accounts.credit_limit غير موجود
 *                    والمنطق يحسب available بطريقة خاطئة
 *
 * بعد الإصلاح يجب أن:
 * ✅ prepaid group: يقبل الحجز إذا الرصيد يكفي
 * ✅ prepaid group: يرفض الحجز إذا الرصيد أقل من المطلوب
 * ✅ debt group: يقبل الحجز إذا الدين لن يتجاوز الحد
 * ✅ zero balance group: يرفض أي حجز
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Account;
use App\Models\Airport;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightGroup;
use App\Enums\AccountType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';
$admin = User::first();
auth()->setUser($admin);
$token = $admin->createToken('group-balance-test-' . uniqid())->plainTextToken;

function httpPost(string $url, array $data = []) {
    global $token;
    $r = Http::withToken($token)->acceptJson()->post($url, $data);
    return ['status' => $r->status(), 'json' => $r->json()];
}

function safeCleanupBooking($id) {
    if (!$id) return;
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    try {
        DB::table('flight_payments')->where('flight_booking_id', $id)->delete();
        DB::table('passengers')->where('flight_booking_id', $id)->delete();
        DB::table('flight_segments')->where('flight_booking_id', $id)->delete();
        DB::table('airline_transactions')->where('flight_booking_id', $id)->delete();
        DB::table('flight_system_transactions')->where('flight_booking_id', $id)->delete();
        DB::table('flight_bookings')->where('id', $id)->delete();
        DB::table('transactions')->where('related_type', 'App\\Models\\Flight\\FlightBooking')->where('related_id', $id)->delete();
    } catch (\Throwable $e) {}
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function safeCleanupGroup($groupId, $accountId = null) {
    if (!$groupId) return;
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    try {
        DB::table('flight_group_transactions')->where('flight_group_id', $groupId)->delete();
        DB::table('flight_bookings')->where('flight_group_id', $groupId)->delete();
        DB::table('flight_groups')->where('id', $groupId)->delete();
        if ($accountId) {
            DB::table('transactions')->where('to_account_id', $accountId)->orWhere('from_account_id', $accountId)->delete();
            DB::table('accounts')->where('id', $accountId)->delete();
        }
    } catch (\Throwable $e) {}
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

$logDir = __DIR__ . '/storage/logs/flight_test';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$logHandle = fopen($logDir . '/' . date('Y-m-d_His') . '_group_balance.log', 'w');

function t(string $m) {
    global $logHandle;
    $l = '[' . date('H:i:s') . '] ' . $m . "\n";
    fwrite($logHandle, $l); fflush($logHandle); echo $l;
}
function ok(string $m='OK') { t("    ✅ {$m}"); }
function fail(string $m) { t("    ❌ {$m}"); }
function info(string $m) { t("    ℹ  {$m}"); }
function warn(string $m) { t("    ⚠  {$m}"); }
function section(string $title) {
    t("\n" . str_repeat('═', 70));
    t('  ' . $title);
    t(str_repeat('═', 70));
}

$RESULTS = [
    'passed'        => 0,
    'failed'        => 0,
    'unexpected'    => 0,
];

t("╔══════════════════════════════════════════════════════════════════╗");
t("║  Flight Module — Group Balance Comprehensive Tests           ║");
t("║  يختبر Bug #15 (لا يوجد فحص) + Bug #16 (prepaid مكسور)     ║");
t("╚══════════════════════════════════════════════════════════════════╝");

// ═══════════════════════════════════════════════════════════════════════════
// تنظيف leftovers من اختبارات سابقة
// ═══════════════════════════════════════════════════════════════════════════
section('🧹 تنظيف leftovers من اختبارات سابقة');
DB::statement('SET FOREIGN_KEY_CHECKS = 0');
DB::table('accounts')->where('name', 'like', 'TEST_GRP_BAL_%')->delete();
DB::table('customers')->where('full_name', 'like', 'TEST_GRP_BAL_%')->delete();
DB::table('employees')->where('full_name', 'like', 'TEST_GRP_BAL_%')->delete();
DB::table('flight_systems')->where('name', 'like', 'TEST_GRP_BAL_%')->delete();
DB::table('flight_carriers')->where('name', 'like', 'TEST_GRP_BAL_%')->delete();
DB::table('flight_groups')->where('name', 'like', 'TEST_GRP_BAL_%')->delete();
DB::table('airports')->where('iata_code', 'like', 'GB%')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS = 1');
info("تم تنظيف leftovers");

// ═══════════════════════════════════════════════════════════════════════════
// الإعداد: entities مشتركة
// ═══════════════════════════════════════════════════════════════════════════
section('⚙️ الإعداد: إنشاء entities مشتركة');

// عميل
$customer = Customer::create([
    'full_name'      => 'TEST_GRP_BAL_CUST_' . substr(uniqid(), -5),
    'phone'          => '01200000001',
    'national_id'    => 'NC' . substr(md5(uniqid()), 0, 12),
    'passport_number'=> 'GB' . substr(uniqid(), -8),
    'module_type'    => 'flights',
    'created_by'     => $admin->id,
]);
info("عميل ID={$customer->id}");

// موظف
$employee = Employee::create([
    'full_name'    => 'TEST_GRP_BAL_EMP_' . substr(uniqid(), -5),
    'phone'        => '01200000002',
    'national_id'  => 'TE' . substr(md5(uniqid()), 0, 6),
    'created_by'   => $admin->id,
]);
info("موظف ID={$employee->id}");

// خزينة EGP كبيرة (لدفع الـ selling_price فقط)
$cashAccount = Account::create([
    'name'        => 'TEST_GRP_BAL_Cash',
    'type'        => AccountType::Cashbox->value,
    'currency'    => 'EGP',
    'balance'     => 0,
    'is_active'   => true,
    'owner_type'  => Account::OWNER_TYPE_OFFICE,
    'module_type' => 'tourism',
    'created_by'  => $admin->id,
]);
DB::table('accounts')->where('id', $cashAccount->id)->update(['balance' => 1000000]);
info("خزينة EGP ID={$cashAccount->id} balance=1,000,000 EGP");

// نظام + شركة + مطار
$airport = Airport::create([
    'iata_code'        => 'GB' . substr(uniqid(), -2),
    'city_name_ar'     => 'Group Bal Test',
    'city_name_en'     => 'Group Bal Test',
    'airport_name_ar'  => 'Group Bal Test',
    'airport_name_en'  => 'Group Bal Test',
    'country_code'     => 'GB',
    'country_name_ar'  => 'GroupBal',
    'country_name_en'  => 'GroupBal',
    'is_active'        => true,
]);
$airportFrom = $airport->iata_code;

$system = FlightSystem::create([
    'name'        => 'TEST_GRP_BAL_System_' . substr(uniqid(), -4),
    'code'        => 'GB' . substr(md5(uniqid()), 0, 4),
    'type'        => 'GDS',
    'currency'    => 'EGP',
    'balance'     => 1000000,
    'credit_limit'=> 0,
    'is_active'   => true,
    'created_by'  => $admin->id,
]);
info("نظام ID={$system->id} balance=1M EGP");

$carrier = FlightCarrier::create([
    'name'            => 'TEST_GRP_BAL_Carrier_' . substr(uniqid(), -4),
    'code'            => 'GB' . substr(md5(uniqid()), 0, 4),
    'iata_code'       => 'GB',
    'flight_system_id'=> $system->id,
    'currency'        => 'EGP',
    'balance'         => 1000000,
    'credit_limit'    => 0,
    'is_active'       => true,
    'created_by'      => $admin->id,
]);
info("شركة طيران ID={$carrier->id} balance=1M EGP");

// ═══════════════════════════════════════════════════════════════════════════
// helper: إنشاء مجموعة بحالة رصيد محددة
// ═══════════════════════════════════════════════════════════════════════════
function createGroupWithBalance(
    string $name,
    FlightCarrier $carrier,
    User $admin,
    float $initialBalance,
    float $creditLimit = 0
): FlightGroup {
    $group = FlightGroup::create([
        'name'             => $name,
        'code'             => 'GB' . strtoupper(substr(md5(uniqid()), 0, 5)),
        'flight_carrier_id'=> $carrier->id,
        'credit_limit'     => $creditLimit,
        'is_active'        => true,
        'created_by'       => $admin->id,
    ]);

    // إنشاء حساب للمجموعة تلقائياً
    $account = Account::create([
        'name'        => 'حساب مجموعة: ' . $name,
        'type'        => AccountType::Supplier->value,
        'currency'    => $carrier->currency ?: 'EGP',
        'balance'     => $initialBalance,
        'is_active'   => true,
        'owner_type'  => Account::OWNER_TYPE_OWNER,
        'module_type' => 'flights',
        'created_by'  => $admin->id,
    ]);

    // ربط الحساب بالمجموعة
    $group->account_id = $account->id;
    $group->save();

    return $group;
}

function bookOnGroup(
    FlightGroup $group,
    Customer $customer,
    Employee $employee,
    Account $cashAccount,
    FlightCarrier $carrier,
    FlightSystem $system,
    Airport $airport,
    string $airportCode,
    float $purchasePrice,
    float $sellingPrice,
    string $currency = 'EGP'
): array {
    global $BASE_URL;
    return httpPost($BASE_URL . '/flight/bookings', [
        'customer_id'    => $customer->id,
        'employee_id'    => $employee->id,
        'pnr'            => 'PNR-GB-' . strtoupper(substr(md5(uniqid()), 0, 6)),
        'airline'        => 'GroupBalTest',
        'airline_name'   => 'GroupBalTest',
        'from_airport'   => $airportCode,
        'to_airport'     => $airportCode,
        'from_airport_id'=> $airport->id,
        'to_airport_id'  => $airport->id,
        'departure_date' => now()->addDays(rand(30, 60))->format('Y-m-d'),
        'departure_time' => '10:00:00',
        'trip_type'      => 'one_way',
        'passenger_count'=> 1,
        'purchase_price' => $purchasePrice,
        'selling_price'  => $sellingPrice,
        'currency'       => $currency,
        'flight_system_id'      => $system->id,
        'flight_carrier_id'     => $carrier->id,
        'flight_group_id'       => $group->id,
        'purchase_balance_source' => 'group',
        'payment' => ['amount' => $sellingPrice, 'payment_method' => 'cash', 'account_id' => $cashAccount->id],
        'passengers' => [['first_name' => 'T', 'last_name' => 'Test', 'passport_number' => 'GB' . substr(uniqid(), -6), 'type' => 'adult']],
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 1: مجموعة برصيد = 0 (لا prepaid، لا دين) — يجب أن يرفض
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 1: Group balance = 0 → يجب الرفض (Bug #15)');

$groupZero = createGroupWithBalance('TEST_GRP_BAL_Zero', $carrier, $admin, 0.0);
info("مجموعة ID={$groupZero->id} balance=0 EGP");

$r = bookOnGroup($groupZero, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportFrom, 200, 300);
$msg = $r['json']['message'] ?? '';
if ($r['status'] >= 400) {
    if (str_contains($msg, 'رصيد') || str_contains($msg, 'كافٍ')) {
        $RESULTS['passed']++;
        ok("✅ النظام رفض صح (balance=0, purchase=200): {$msg}");
    } else {
        $RESULTS['unexpected']++;
        warn("⚠️ رفض برسالة: {$msg}");
    }
} else {
    $RESULTS['failed']++;
    fail("❌ الباج #15 مازال موجوداً: النظام قبل حجز والـ balance=0!");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 2: مجموعة prepaid برصيد موجب (1000 EGP) — يجب أن يقبل حجز 200 EGP
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 2: Group balance = 1000 (prepaid) → يجب أن يقبل حجز 200');

$groupPrepaid = createGroupWithBalance('TEST_GRP_BAL_Prepaid', $carrier, $admin, 1000.0);
info("مجموعة ID={$groupPrepaid->id} balance=1,000 EGP (prepaid)");

$r = bookOnGroup($groupPrepaid, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportFrom, 200, 300);

if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ النظام قبل الحجز صح (prepaid=1000, purchase=200)");
    if (isset($r['json']['data']['id'])) {
        // التحقق من الرصيد بعد الحجز
        $acc = Account::find($groupPrepaid->account_id);
        info("رصيد المجموعة بعد الحجز: {$acc->balance} EGP (المتوقع: 800)");
        if (abs((float)$acc->balance - 800.0) < 0.01) {
            ok("✅ الرصيد تم خصمه صح: 1000 - 200 = 800");
        } else {
            $RESULTS['unexpected']++;
            warn("⚠️ الرصيد بعد الخصم غير متوقع: {$acc->balance} (المتوقع: 800)");
        }
        safeCleanupBooking($r['json']['data']['id']);
    }
} else {
    $msg = $r['json']['message'] ?? '';
    if (str_contains($msg, 'رصيد') || str_contains($msg, 'كافٍ')) {
        $RESULTS['failed']++;
        fail("❌ الباج #16: النظام رفض حجز صحيح على مجموعة prepaid! {$msg}");
    } else {
        $RESULTS['unexpected']++;
        warn("⚠️ رفض غير متوقع: {$msg}");
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 3: مجموعة prepaid (500 EGP) مع حجز أكبر (800 EGP) → يجب الرفض
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 3: Group balance = 500 (prepaid) مع حجز 800 → يجب الرفض');

$groupPrepaidSmall = createGroupWithBalance('TEST_GRP_BAL_PrepaidSmall', $carrier, $admin, 500.0);
info("مجموعة ID={$groupPrepaidSmall->id} balance=500 EGP");

$r = bookOnGroup($groupPrepaidSmall, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportFrom, 800, 1000);
$msg = $r['json']['message'] ?? '';
if ($r['status'] >= 400) {
    if (str_contains($msg, 'رصيد') || str_contains($msg, 'كافٍ')) {
        $RESULTS['passed']++;
        ok("✅ النظام رفض صح (prepaid=500, purchase=800): {$msg}");
    } else {
        $RESULTS['unexpected']++;
        warn("⚠️ رفض برسالة: {$msg}");
    }
} else {
    $RESULTS['failed']++;
    fail("❌ الباج: النظام قبل حجز أكبر من الرصيد!");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 4: مجموعة عليها دين (balance = -300) بدون credit_limit → يجب الرفض
// (لأن credit_limit = 0 فلا دين مسموح)
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 4: Group balance = -300 (دين) بدون credit_limit → يجب الرفض');

$groupDebt = createGroupWithBalance('TEST_GRP_BAL_Debt', $carrier, $admin, -300.0, 0);
info("مجموعة ID={$groupDebt->id} balance=-300 EGP, credit_limit=0");

$r = bookOnGroup($groupDebt, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportFrom, 200, 300);
$msg = $r['json']['message'] ?? '';

if ($r['status'] >= 400) {
    if (str_contains($msg, 'رصيد') || str_contains($msg, 'كافٍ')) {
        $RESULTS['passed']++;
        ok("✅ النظام رفض صح (debt=-300, credit_limit=0, purchase=200): {$msg}");
    } else {
        $RESULTS['unexpected']++;
        warn("⚠️ رفض برسالة: {$msg}");
    }
} else {
    $RESULTS['failed']++;
    fail("❌ الباج: النظام قبل حجز من مجموعة مديونة بدون credit_limit!");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 4b: مجموعة عليها دين + credit_limit = 500 → يجب أن يقبل حجز 200
// (لأن totalSpend = -300 + 500 = 200 ≥ 200 المطلوبة)
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 4b: Group balance = -300 + credit_limit = 500 → يجب أن يقبل حجز 200');

$groupDebtWithCL = createGroupWithBalance('TEST_GRP_BAL_DebtWithCL', $carrier, $admin, -300.0, 500.0);
info("مجموعة ID={$groupDebtWithCL->id} balance=-300 EGP, credit_limit=500 EGP");

$r = bookOnGroup($groupDebtWithCL, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportFrom, 200, 300);
$msg = $r['json']['message'] ?? '';

if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ النظام قبل الحجز صح (debt=-300, credit_limit=500, purchase=200)");
    if (isset($r['json']['data']['id'])) {
        $acc = Account::find($groupDebtWithCL->account_id);
        info("رصيد المجموعة بعد الحجز: {$acc->balance} EGP (المتوقع: -500)");
        safeCleanupBooking($r['json']['data']['id']);
    }
} else {
    $RESULTS['failed']++;
    fail("❌ الباج: النظام رفض حجز صحيح مع وجود credit_limit! {$msg}");
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 5: مجموعة prepaid بمبلغ كبير جداً (10000 EGP) وحجز 500 → يجب أن يقبل
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 5: Group balance = 10000 (prepaid كبير) مع حجز 500 → يجب أن يقبل');

$groupBig = createGroupWithBalance('TEST_GRP_BAL_Big', $carrier, $admin, 10000.0);
info("مجموعة ID={$groupBig->id} balance=10,000 EGP");

$r = bookOnGroup($groupBig, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportFrom, 500, 700);
$msg = $r['json']['message'] ?? '';

if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ النظام قبل الحجز صح (prepaid=10000, purchase=500)");
    if (isset($r['json']['data']['id'])) {
        $acc = Account::find($groupBig->account_id);
        info("رصيد المجموعة بعد الحجز: {$acc->balance} EGP (المتوقع: 9500)");
        safeCleanupBooking($r['json']['data']['id']);
    }
} else {
    if (str_contains($msg, 'رصيد') || str_contains($msg, 'كافٍ')) {
        $RESULTS['failed']++;
        fail("❌ الباج #16: النظام رفض حجز صحيح على مجموعة prepaid كبيرة! {$msg}");
    } else {
        $RESULTS['unexpected']++;
        warn("⚠️ رفض غير متوقع: {$msg}");
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 6: Edge case - حجز يساوي الرصيد تماماً
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 6: Group balance = 500 مع حجز 500 (حد متساوي) → يجب أن يقبل');

$groupEdge = createGroupWithBalance('TEST_GRP_BAL_Edge', $carrier, $admin, 500.0);
info("مجموعة ID={$groupEdge->id} balance=500 EGP");

$r = bookOnGroup($groupEdge, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportFrom, 500, 700);
$msg = $r['json']['message'] ?? '';

if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ النظام قبل الحجز صح (balance=500, purchase=500)");
    if (isset($r['json']['data']['id'])) {
        $acc = Account::find($groupEdge->account_id);
        info("رصيد المجموعة بعد الحجز: {$acc->balance} EGP (المتوقع: 0)");
        safeCleanupBooking($r['json']['data']['id']);
    }
} else {
    if (str_contains($msg, 'رصيد') || str_contains($msg, 'كافٍ')) {
        $RESULTS['failed']++;
        fail("❌ الباج: النظام رفض حجز يساوي الرصيد! {$msg}");
    } else {
        $RESULTS['unexpected']++;
        warn("⚠️ رفض غير متوقع: {$msg}");
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 7: Edge case - حجز أكبر من الرصيد بـ 1 جنيه
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 7: Group balance = 500 مع حجز 501 (أكبر بـ 1) → يجب الرفض');

$groupEdge2 = createGroupWithBalance('TEST_GRP_BAL_Edge2', $carrier, $admin, 500.0);
info("مجموعة ID={$groupEdge2->id} balance=500 EGP");

$r = bookOnGroup($groupEdge2, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportFrom, 501, 700);
$msg = $r['json']['message'] ?? '';

if ($r['status'] >= 400) {
    if (str_contains($msg, 'رصيد') || str_contains($msg, 'كافٍ')) {
        $RESULTS['passed']++;
        ok("✅ النظام رفض صح (balance=500, purchase=501): {$msg}");
    } else {
        $RESULTS['unexpected']++;
        warn("⚠️ رفض برسالة غير واضحة: {$msg}");
    }
} else {
    $RESULTS['failed']++;
    fail("❌ الباج: النظام قبل حجز أكبر من الرصيد بـ 1!");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
}

// ═══════════════════════════════════════════════════════════════════════════
// تنظيف كل المجموعات اللي أنشأناها
// ═══════════════════════════════════════════════════════════════════════════
section('🧹 تنظيف');
safeCleanupGroup($groupZero->id, $groupZero->account_id);
safeCleanupGroup($groupPrepaid->id, $groupPrepaid->account_id);
safeCleanupGroup($groupPrepaidSmall->id, $groupPrepaidSmall->account_id);
safeCleanupGroup($groupDebt->id, $groupDebt->account_id);
if (isset($groupDebtWithCL)) safeCleanupGroup($groupDebtWithCL->id, $groupDebtWithCL->account_id);
safeCleanupGroup($groupBig->id, $groupBig->account_id);
safeCleanupGroup($groupEdge->id, $groupEdge->account_id);
safeCleanupGroup($groupEdge2->id, $groupEdge2->account_id);
info("تم تنظيف {$RESULTS['passed']} حالة اختبار");

// ═══════════════════════════════════════════════════════════════════════════
// ملخص
// ═══════════════════════════════════════════════════════════════════════════
section('📊 الملخص النهائي');

t("  ✅ Passed (النظام تصرف صح):         {$RESULTS['passed']}");
t("  ❌ Failed (الباج تأكد):               {$RESULTS['failed']}");
t("  ⚠️  Unexpected (نتيجة غير متوقعة):   {$RESULTS['unexpected']}");

if ($RESULTS['failed'] == 0) {
    ok("\n🎉 النتيجة: لا توجد bugs في فحص رصيد المجموعة");
} else {
    fail("\n❌ الباج: {$RESULTS['failed']} حالة فشل!");
    t("\n📋 الـ Bugs المكتشفة:");
    if ($RESULTS['failed'] > 0) {
        t("   - Bug #15: " . ($RESULTS['failed'] > 0 ? "مجموعة balance=0 مازالت تُقبل" : "✓"));
        t("   - Bug #16: " . ($RESULTS['failed'] > 0 ? "مجموعة prepaid مكسورة (credit_limit غير موجود)" : "✓"));
    }
}

file_put_contents($logDir . '/group_balance_report_' . date('Y-m-d_His') . '.json',
    json_encode($RESULTS, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

fclose($logHandle);
echo "\n📄 Log: {$logDir}/" . date('Y-m-d_His') . "_group_balance.log\n";
echo "✅ Group Balance Tests DONE.\n";
