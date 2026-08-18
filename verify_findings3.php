<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Inspecting Flight cross-customer payment validation ===" . PHP_EOL;

$ctrl = file_get_contents('app/Http/Controllers/Api/V1/Flight/FlightController.php');
echo "  Controller has 'addPayment' method? " . (strpos($ctrl, 'addPayment') !== false ? 'YES' : 'NO') . PHP_EOL;

$req = file_get_contents('app/Http/Requests/Flight/StoreFlightPaymentRequest.php');
echo "  StoreFlightPaymentRequest validation rules relevant to (booking_id, customer_id) cross-check:" . PHP_EOL;
preg_match_all("/'[^']+'\s*=>\s*'[^']*customer[^']*'/i", $req, $m);
foreach ($m[0] as $rule) echo "    " . $rule . PHP_EOL;
if (empty($m[0])) echo "    (no customer-validation rules in FormRequest)" . PHP_EOL;

echo PHP_EOL . "  FlightBookingService::addPayment — does it verify customer_id matches booking?" . PHP_EOL;
$svc = file_get_contents('app/Services/Flight/FlightBookingService.php');
$start = strpos($svc, 'public function addPayment');
$end = $start === false ? 0 : strpos($svc, '}', $start + 100);
$excerpt = $start !== false ? substr($svc, $start, $end - $start) : '';
echo "    excerpt of addPayment method (first 800 chars):" . PHP_EOL;
echo substr($excerpt, 0, 1200) . "..." . PHP_EOL;

echo PHP_EOL . "=== Account 818 (إقفال مبيعات الطيران — flight clearing) ===" . PHP_EOL;
$a = DB::table('accounts')->find(818);
echo "  id=" . $a->id . " name=" . $a->name . " mt=" . $a->module_type . " vault=" .
    var_export($a->is_module_vault, true) . " balance=" . $a->balance . PHP_EOL;

// When was this account first touched?
$first = DB::table('account_entries')->where('account_id', 818)->orderBy('id')->first();
$last = DB::table('account_entries')->where('account_id', 818)->orderBy('id', 'desc')->first();
echo "  First entry id=" . ($first->id ?? 'NONE') . " on tx=" . ($first->transaction_id ?? 'NONE') . PHP_EOL;
echo "  Last entry id=" . ($last->id ?? 'NONE') . " on tx=" . ($last->transaction_id ?? 'NONE') . PHP_EOL;
echo "  Total entries on account 818: " . DB::table('account_entries')->where('account_id', 818)->count() . PHP_EOL;

// Was it created/used by the audit?
$auditEntries = DB::table('account_entries as ae')
    ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->where('ae.account_id', 818)
    ->where(function ($q) {
        $q->where('t.notes', 'like', 'TOURISM_FULL_AUDIT_20260818_%')
          ->orWhereExists(function ($q2) {
              $q2->from('flight_bookings')
                 ->whereColumn('flight_bookings.account_id', 'ae.account_id');
          });
    })
    ->count();
echo "  Audit-touched entries on account 818 (rough): " . $auditEntries . PHP_EOL;
