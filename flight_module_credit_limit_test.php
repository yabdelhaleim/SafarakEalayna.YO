<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — credit_limit column on flight_groups (Bug #16 final test)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يستهدف عمود credit_limit الجديد على flight_groups للتحقق من:
 * - المجموعات بدون credit_limit (افتراضي 0) لا يسمح لها بالدين
 * - المجموعات بـ credit_limit > 0 يسمح لها بالدين حتى الحد المحدد
 * - الحد + الرصيد الحالي = أقصى ما يمكن إنفاقه
 * - حدود: -credit_limit = أقل رصيد مسموح
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
$token = $admin->createToken('credit-limit-test-' . uniqid())->plainTextToken;

function httpPost(string $url, array $data = []) {
    global $token;
    $r = Http::withToken($token)->acceptJson()->post($url, $data);
    return ['status' => $r->status(), 'json' => $r->json()];
}

function safeCleanup($ids) {
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($ids['bookingIds'] ?? [] as $bid) {
        DB::table('flight_payments')->where('flight_booking_id', $bid)->delete();
        DB::table('passengers')->where('flight_booking_id', $bid)->delete();
        DB::table('flight_segments')->where('flight_booking_id', $bid)->delete();
        DB::table('flight_bookings')->where('id', $bid)->delete();
        DB::table('transactions')->where('related_type', 'App\\Models\\Flight\\FlightBooking')->where('related_id', $bid)->delete();
    }
    foreach ($ids['groupIds'] ?? [] as $gid) {
        DB::table('flight_group_transactions')->where('flight_group_id', $gid)->delete();
        DB::table('flight_groups')->where('id', $gid)->delete();
    }
    foreach ($ids['accountIds'] ?? [] as $aid) {
        DB::table('transactions')->where('to_account_id', $aid)->orWhere('from_account_id', $aid)->delete();
        DB::table('accounts')->where('id', $aid)->delete();
    }
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

$logDir = __DIR__ . '/storage/logs/flight_test';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$logHandle = fopen($logDir . '/' . date('Y-m-d_His') . '_credit_limit.log', 'w');
function t(string $m) {
    global $logHandle;
    $l = '[' . date('H:i:s') . '] ' . $m . "\n";
    fwrite($logHandle, $l); fflush($logHandle); echo $l;
}
function ok(string $m='OK') { t("    ✅ {$m}"); }
function fail(string $m) { t("    ❌ {$m}"); }
function info(string $m) { t("    ℹ  {$m}"); }
function section(string $title) {
    t("\n" . str_repeat('═', 70));
    t('  ' . $title);
    t(str_repeat('═', 70));
}

$RESULTS = ['passed' => 0, 'failed' => 0];
$cleanups = ['bookingIds' => [], 'groupIds' => [], 'accountIds' => []];

t("╔══════════════════════════════════════════════════════════════════╗");
t("║  Flight Module — credit_limit على flight_groups (Bug #16)      ║");
t("╚══════════════════════════════════════════════════════════════════╝");

// تنظيف leftovers
section('🧹 تنظيف leftovers');
DB::statement('SET FOREIGN_KEY_CHECKS = 0');
DB::table('accounts')->where('name', 'like', 'TEST_CL_%')->delete();
DB::table('flight_groups')->where('name', 'like', 'TEST_CL_%')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS = 1');

// ═════════════════ الإعداد ═════════════════
section('⚙️ الإعداد');
$customer = Customer::create([
    'full_name' => 'TEST_CL_CUST_' . substr(uniqid(), -4),
    'phone' => '01200000999',
    'national_id' => 'CL' . substr(md5(uniqid()), 0, 12),
    'passport_number' => 'CL' . substr(uniqid(), -8),
    'module_type' => 'flights',
    'created_by' => $admin->id,
]);

$employee = Employee::create([
    'full_name' => 'TEST_CL_EMP_' . substr(uniqid(), -4),
    'phone' => '01200000998',
    'national_id' => 'CLEM' . substr(md5(uniqid()), 0, 6),
    'created_by' => $admin->id,
]);

$cashAccount = Account::create([
    'name' => 'TEST_CL_Cash',
    'type' => AccountType::Cashbox->value,
    'currency' => 'EGP',
    'balance' => 1000000,
    'is_active' => true,
    'owner_type' => Account::OWNER_TYPE_OFFICE,
    'module_type' => 'tourism',
    'created_by' => $admin->id,
]);

$airport = Airport::create([
    'iata_code' => 'CL' . substr(uniqid(), -2),
    'city_name_ar' => 'CL Test',
    'city_name_en' => 'CL Test',
    'airport_name_ar' => 'CL Test Airport',
    'airport_name_en' => 'CL Test Airport',
    'country_code' => 'CL',
    'country_name_ar' => 'CL',
    'country_name_en' => 'CL',
    'is_active' => true,
]);
$airportCode = $airport->iata_code;

$system = FlightSystem::create([
    'name' => 'TEST_CL_System_' . substr(uniqid(), -4),
    'code' => 'CLS' . substr(md5(uniqid()), 0, 3),
    'type' => 'GDS',
    'currency' => 'EGP',
    'balance' => 1000000,
    'is_active' => true,
    'created_by' => $admin->id,
]);

$carrier = FlightCarrier::create([
    'name' => 'TEST_CL_Carrier_' . substr(uniqid(), -4),
    'code' => 'CLC' . substr(md5(uniqid()), 0, 3),
    'iata_code' => 'CL',
    'flight_system_id' => $system->id,
    'currency' => 'EGP',
    'balance' => 1000000,
    'is_active' => true,
    'created_by' => $admin->id,
]);

info("cashAccount={$cashAccount->id} | airport={$airport->iata_code} | carrier={$carrier->id} | system={$system->id}");

function mkGroupWithAccount(FlightCarrier $carrier, User $admin, float $initialBalance, float $creditLimit, string $suffix): array {
    $group = FlightGroup::create([
        'name' => 'TEST_CL_Grp_' . $suffix,
        'code' => 'CLG' . strtoupper(substr(md5(uniqid() . $suffix), 0, 5)),
        'flight_carrier_id' => $carrier->id,
        'credit_limit' => $creditLimit,
        'is_active' => true,
        'created_by' => $admin->id,
    ]);
    $account = Account::create([
        'name' => 'TEST_CL_Acc_' . $suffix,
        'type' => AccountType::Supplier->value,
        'currency' => 'EGP',
        'balance' => $initialBalance,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'flights',
        'created_by' => $admin->id,
    ]);
    $group->account_id = $account->id;
    $group->save();
    return [$group, $account];
}

function bookOnGroup($group, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportCode, float $purchase): array {
    global $BASE_URL;
    return httpPost($BASE_URL . '/flight/bookings', [
        'customer_id' => $customer->id,
        'employee_id' => $employee->id,
        'pnr' => 'CL-' . strtoupper(substr(md5(uniqid()), 0, 6)),
        'airline' => 'CLTest',
        'airline_name' => 'CLTest',
        'from_airport' => $airportCode,
        'to_airport' => $airportCode,
        'from_airport_id' => $airport->id,
        'to_airport_id' => $airport->id,
        'departure_date' => now()->addDays(rand(20, 40))->format('Y-m-d'),
        'departure_time' => '10:00:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'purchase_price' => $purchase,
        'selling_price' => $purchase + 100,
        'currency' => 'EGP',
        'flight_system_id' => $system->id,
        'flight_carrier_id' => $carrier->id,
        'flight_group_id' => $group->id,
        'purchase_balance_source' => 'group',
        'payment' => ['amount' => $purchase + 100, 'payment_method' => 'cash', 'account_id' => $cashAccount->id],
        'passengers' => [['first_name' => 'CL', 'last_name' => 'Test', 'passport_number' => 'CL' . substr(uniqid(), -6), 'type' => 'adult']],
    ]);
}

// ═════════════════ TEST 1: credit_limit=0 + balance=0 → reject ═════════════════
section('TEST 1: balance=0, credit_limit=0 → REJECT (لا دين)');
[$g1, $a1] = mkGroupWithAccount($carrier, $admin, 0, 0, 'ZeroNoCL');
$cleanups['groupIds'][] = $g1->id;
$cleanups['accountIds'][] = $a1->id;
$r = bookOnGroup($g1, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportCode, 100);
$msg = $r['json']['message'] ?? '';
if ($r['status'] >= 400 && str_contains($msg, 'كافٍ')) {
    $RESULTS['passed']++;
    ok("✅ رُفض (لا دين مسموح): {$msg}");
} else {
    $RESULTS['failed']++;
    fail("❌ الباج: balance=0+CL=0 لم يُرفض");
    if (isset($r['json']['data']['id'])) $cleanups['bookingIds'][] = $r['json']['data']['id'];
}

// ═════════════════ TEST 2: credit_limit=1000 + balance=0 → حد=1000 ═════════════════
section('TEST 2: balance=0, credit_limit=1000 → حجز 500 ينجح، حجز 600 يفشل');
[$g2, $a2] = mkGroupWithAccount($carrier, $admin, 0, 1000, 'ZeroWithCL');
$cleanups['groupIds'][] = $g2->id;
$cleanups['accountIds'][] = $a2->id;

// 2a: حجز 500 (≤ 1000)
$r = bookOnGroup($g2, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportCode, 500);
if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ حجز 500 ينجح (credit_limit=1000)");
    if (isset($r['json']['data']['id'])) $cleanups['bookingIds'][] = $r['json']['data']['id'];

    $acc = Account::find($a2->id);
    if (abs((float)$acc->balance - (-500.0)) < 0.01) {
        $RESULTS['passed']++;
        ok("✅ الرصيد = -500 (مستحق دين ضمن الحد)");
    } else {
        $RESULTS['failed']++;
        fail("❌ الرصيد بعد الحجز = {$acc->balance} (متوقع -500)");
    }

    // 2b: محاولة حجز 600 (سيتجاوز الحد: 500 + 600 = 1100 > 1000)
    $r = bookOnGroup($g2, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportCode, 600);
    $msg = $r['json']['message'] ?? '';
    if ($r['status'] >= 400 && str_contains($msg, 'كافٍ')) {
        $RESULTS['passed']++;
        ok("✅ حجز 600 رُفض (لأنه سيتجاوز credit_limit): {$msg}");
    } else {
        $RESULTS['failed']++;
        fail("❌ الباج: حجز 600 قُبل رغم تجاوز credit_limit");
        if (isset($r['json']['data']['id'])) $cleanups['bookingIds'][] = $r['json']['data']['id'];
    }
} else {
    $RESULTS['failed']++;
    fail("❌ الباج: حجز 500 مع credit_limit=1000 رُفض: {$r['json']['message']}");
}

// ═════════════════ TEST 3: balance=2000 + credit_limit=500 → maxSpend=2500 ═════════════════
section('TEST 3: balance=2000, credit_limit=500 → maxSpend=2500');
[$g3, $a3] = mkGroupWithAccount($carrier, $admin, 2000, 500, 'PrepaidAndCL');
$cleanups['groupIds'][] = $g3->id;
$cleanups['accountIds'][] = $a3->id;

// 3a: حجز 2500 (الحد الأقصى) → ينجح
$r = bookOnGroup($g3, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportCode, 2500);
if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ حجز 2500 ينجح (الحد الأقصى)");
    if (isset($r['json']['data']['id'])) $cleanups['bookingIds'][] = $r['json']['data']['id'];

    $acc = Account::find($a3->id);
    if (abs((float)$acc->balance - (-500.0)) < 0.01) {
        $RESULTS['passed']++;
        ok("✅ الرصيد = -500 (exactly at -credit_limit)");
    } else {
        $RESULTS['failed']++;
        fail("❌ الرصيد بعد الحجز = {$acc->balance} (متوقع -500)");
    }
} else {
    $RESULTS['failed']++;
    fail("❌ الباج: حجز 2500 (max) رُفض: {$r['json']['message']}");
}

// ═════════════════ TEST 4: balance=-400 + credit_limit=500 → maxSpend=100 ═════════════════
section('TEST 4: balance=-400, credit_limit=500 → maxSpend=100');
[$g4, $a4] = mkGroupWithAccount($carrier, $admin, -400, 500, 'DebtWithCL');
$cleanups['groupIds'][] = $g4->id;
$cleanups['accountIds'][] = $a4->id;

// 4a: حجز 100 (الحد المتبقي) → ينجح
$r = bookOnGroup($g4, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportCode, 100);
if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ حجز 100 ينجح (الحد المتبقي)");
    if (isset($r['json']['data']['id'])) $cleanups['bookingIds'][] = $r['json']['data']['id'];

    $acc = Account::find($a4->id);
    if (abs((float)$acc->balance - (-500.0)) < 0.01) {
        $RESULTS['passed']++;
        ok("✅ الرصيد = -500 (exactly at -credit_limit)");
    } else {
        $RESULTS['failed']++;
        fail("❌ الرصيد = {$acc->balance} (متوقع -500)");
    }
} else {
    $RESULTS['failed']++;
    fail("❌ الباج: حجز 100 رُفض: {$r['json']['message']}");
}

// ═════════════════ TEST 5: حجز أكبر من الحد المتبقي → رُفض ═════════════════
section('TEST 5: حجز 1 جنيه أكبر من الحد → رُفض');
$r = bookOnGroup($g4, $customer, $employee, $cashAccount, $carrier, $system, $airport, $airportCode, 1);
$msg = $r['json']['message'] ?? '';
if ($r['status'] >= 400 && str_contains($msg, 'كافٍ')) {
    $RESULTS['passed']++;
    ok("✅ حجز 1 رُفض (الرصيد -500 = الحد): {$msg}");
} else {
    $RESULTS['failed']++;
    fail("❌ الباج: حجز 1 قُبل رغم بلوغ الحد الأقصى للدين");
    if (isset($r['json']['data']['id'])) $cleanups['bookingIds'][] = $r['json']['data']['id'];
}

// ═════════════════ TEST 6: عمود credit_limit له default=0 ═════════════════
section('TEST 6: التأكد من default(0) للعمود الجديد');
$col = DB::selectOne("SHOW COLUMNS FROM flight_groups WHERE Field = 'credit_limit'");
if ($col && str_contains($col->Default, '0')) {
    $RESULTS['passed']++;
    ok("✅ العمود credit_limit موجود بـ default=0");
    info("Type: {$col->Type} | Default: {$col->Default}");
} else {
    $RESULTS['failed']++;
    fail("❌ العمود credit_limit غير موجود أو default ليس 0");
}

// ═════════════════ تنظيف ═════════════════
section('🧹 تنظيف');
safeCleanup($cleanups);
info("تم تنظيف المجموعات والحجوزات");

// ═════════════════ ملخص ═════════════════
section('📊 الملخص النهائي');
t("  ✅ Passed: {$RESULTS['passed']}");
t("  ❌ Failed: {$RESULTS['failed']}");

if ($RESULTS['failed'] == 0) {
    ok("\n🎉 كل اختبارات credit_limit نجحت!");
} else {
    fail("\n❌ {$RESULTS['failed']} اختبار فشل!");
}

file_put_contents($logDir . '/credit_limit_report_' . date('Y-m-d_His') . '.json',
    json_encode($RESULTS, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

fclose($logHandle);
echo "\n📄 Log: {$logDir}/" . date('Y-m-d_His') . "_credit_limit.log\n";
echo "✅ Credit Limit Tests DONE.\n";
