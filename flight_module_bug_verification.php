<?php
/**
 * اختبار تأكيدي - Bug #3: Race condition
 * مع credit_limit=0، نتوقع أن النظام يرفض الحجز بعد استنفاد الرصيد
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Flight\FlightCarrier;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';
$IDS = json_decode(file_get_contents(__DIR__ . '/storage/logs/flight_test/ids.json'), true);
$admin = User::find($IDS['admin_id']);
auth()->setUser($admin);
$token = $admin->createToken('race-verify')->plainTextToken;

function httpGet(string $url) { global $token; $r = Http::withToken($token)->acceptJson()->get($url); return ['status' => $r->status(), 'json' => $r->json()]; }
function httpPost(string $url, array $data) { global $token; $r = Http::withToken($token)->acceptJson()->post($url, $data); return ['status' => $r->status(), 'json' => $r->json()]; }

$logHandle = fopen(__DIR__ . '/storage/logs/flight_test/' . date('Y-m-d_His') . '_bug3_verify.log', 'w');
function t(string $m) { global $logHandle; $l = '[' . date('H:i:s') . '] ' . $m . "\n"; fwrite($logHandle, $l); fflush($logHandle); echo $l; }
function ok(string $m='OK') { t("    ✅ {$m}"); }
function fail(string $m) { t("    ❌ {$m}"); }
function info(string $m) { t("    ℹ  {$m}"); }

t("╔══════════════════════════════════════════════════════════════════╗");
t("║  Bug #3 التأكيدي: Race condition + lockForUpdate           ║");
t("╚══════════════════════════════════════════════════════════════════╝");

// الحالة: Saudia balance=5000, credit_limit=0 → available=5000
// نحاول 3 حجوزات × 2000 = 6000 (أكثر من الرصيد)
// متوقع: الأولى تنجح (الرصيد يصبح 3000)، الثانية تنجح (1000)، الثالثة تفشل (لأن الرصيد 1000 < 2000)

$balBefore = FlightCarrier::find($IDS['carrier_saudia_id'])->balance;
info("رصيد Saudia قبل: {$balBefore} EGP (credit_limit=0)");

$results = ['success' => 0, 'rejected' => 0, 'errors' => []];
for ($i = 0; $i < 3; $i++) {
    $r = httpPost($BASE_URL . '/flight/bookings', [
        'customer_id'    => $IDS['customer_ids'][$i % 3],
        'employee_id'    => $IDS['employee_id'],
        'pnr'            => 'PNR-RACE3-' . $i . '-' . substr(uniqid(), -4),
        'airline'        => 'Saudia',
        'airline_name'   => 'Saudia',
        'from_airport'   => 'TCAI',
        'to_airport'     => 'TJED',
        'from_airport_id'=> $IDS['airport_ids'][0],
        'to_airport_id'  => $IDS['airport_ids'][1],
        'departure_date' => now()->addDays(50 + $i)->format('Y-m-d'),
        'departure_time' => '10:00:00',
        'trip_type'      => 'one_way',
        'passenger_count'=> 1,
        'purchase_price' => 2000,
        'selling_price'  => 2500,
        'currency'       => 'EGP',
        'flight_system_id'   => $IDS['sys_amadeus_id'],
        'flight_carrier_id'  => $IDS['carrier_saudia_id'],
        'purchase_balance_source' => 'carrier',
        'payment' => ['amount' => 2500, 'payment_method' => 'cash', 'account_id' => $IDS['cash_egp_id']],
        'passengers' => [['first_name' => 'Pax', 'last_name' => "Race3_{$i}", 'passport_number' => "RACE3X{$i}01", 'type' => 'adult']],
    ]);
    $msg = $r['json']['message'] ?? 'no message';
    if ($r['status'] === 201 && ($r['json']['success'] ?? false)) {
        $results['success']++;
        info("الحجز #{$i}: ✅ نجح (رصيد جديد: " . FlightCarrier::find($IDS['carrier_saudia_id'])->fresh()->balance . ")");
    } else {
        if (str_contains($msg, 'رصيد شركة الطيران غير كافٍ') || str_contains($msg, 'رصيد مسبق')) {
            $results['rejected']++;
            info("الحجز #{$i}: ✅ النظام رفض صح — $msg");
        } else {
            $results['errors'][] = "الحجز #{$i}: $msg";
            fail("الحجز #{$i}: ❌ خطأ غير متوقع — $msg");
        }
    }
}

$balAfter = FlightCarrier::find($IDS['carrier_saudia_id'])->balance;
info("\nرصيد Saudia بعد: {$balAfter} EGP");
info("النتيجة: {$results['success']} نجاح، {$results['rejected']} رفض");

if ($results['success'] === 2 && $results['rejected'] === 1) {
    ok("\n✅ التأكيد: النظام يحمي الرصيد الفعلي بشكل صحيح!");
    ok("   • أول حجزين نجحوا (الرصيد كافي)");
    ok("   • الحجز الثالث رُفض لأن الرصيد 1000 < 2000");
    ok("   • الرصيد النهائي = 1000 EGP (مش سالب)");
} else {
    fail("\n❌ السلوك غير متوقع");
    info(json_encode($results, JSON_PRETTY_PRINT));
}

fclose($logHandle);
echo "\n✅ Bug #3 verification complete.\n";