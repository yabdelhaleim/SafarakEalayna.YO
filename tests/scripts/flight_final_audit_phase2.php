<?php
/**
 * FLIGHT FINAL FULL AUDIT — PHASE 2
 * ==================================
 * Sections:
 *   2. D3 partial payments + idempotency (8 sub-tests including 25× concurrency)
 *   3. D4 price safety (negative + zero + entry-point coverage)
 *   4. D5 recharge (inactive + 25c concurrent + active + repeated)
 *   6. Payment methods (every supported method)
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\AirlineTransaction;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$env = env('APP_ENV'); $db = config('database.connections.mysql.database');
$sel = DB::selectOne('SELECT DATABASE() AS d')->d;
if ($env !== 'stress' || $db !== 'safarak_stress' || $sel !== 'safarak_stress') {
    fwrite(STDERR, "HARD-ABORT: env={$env} db={$db} sel={$sel}\n"); exit(2);
}
echo "ENV: APP_ENV=stress DB_DATABASE=safarak_stress\n\n";

Auth::loginUsingId(1);
$token = User::find(1)->createToken('audit-p2-'.uniqid())->plainTextToken;

$pass = 0; $fail = 0;
function ok(string $n, string $d): void { global $pass,$fail; $pass++; echo "✅ {$n} — {$d}\n"; }
function bad(string $n, string $d): void { global $pass,$fail; $fail++; echo "❌ {$n} — {$d}\n"; }

function api(string $m, string $u, array $b=[], ?string $t=null): array {
    $ch=curl_init(); curl_setopt_array($ch,[CURLOPT_URL=>$u,CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>array_filter(['Accept: application/json','Content-Type: application/json',$t?'Authorization: Bearer '.$t:null])]);
    if(!empty($b))curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($b));
    $r=curl_exec($ch);$s=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    return ['status'=>$s,'body'=>json_decode($r,true),'raw'=>$r];
}
function fireConcurrent(string $m, string $u, array $p, int $w, string $t, bool $vary=false): array {
    $mh=curl_multi_init();$hs=[];
    for($i=0;$i<$w;$i++){$ch=curl_init();$b=$p;if($vary&&isset($b['idempotency_key']))$b['idempotency_key'].='-w'.$i;
        curl_setopt_array($ch,[CURLOPT_URL=>$u,CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$t],CURLOPT_POSTFIELDS=>json_encode($b)]);
        $hs[$i]=$ch;curl_multi_add_handle($mh,$ch);}
    do{$st=curl_multi_exec($mh,$ru);if($ru)curl_multi_select($mh,0.05);}while($ru&&$st===CURLM_OK);
    $r=[];foreach($hs as $i=>$ch){$r[$i]=['status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'raw'=>curl_multi_getcontent($ch)];curl_multi_remove_handle($mh,$ch);curl_close($ch);}
    curl_multi_close($mh);return $r;
}

// Setup
$customer = Customer::firstOrCreate(['national_id'=>'STRS-P2-001'],['full_name'=>'Stress P2','name'=>'Stress P2','phone'=>'01000000002']);
$treasury = Account::where('name','STRESS-FLIGHTS-TREASURY-EGP')->first();
$carrier = FlightCarrier::firstOrCreate(['code'=>'STRESS-FC-AUDIT-P2'],['name'=>'STRESS FC P2','currency'=>'EGP','credit_limit'=>50000,'is_active'=>true]);
$inactiveCarrier = FlightCarrier::firstOrCreate(['code'=>'STRESS-FC-P2-INACTIVE'],['name'=>'INACTIVE P2','currency'=>'EGP','credit_limit'=>0,'is_active'=>false]);
$svc = app(FlightBookingService::class);

// =========================================================================
// SECTION 2: D3 PARTIAL PAYMENTS + IDEMPOTENCY
// =========================================================================
echo "\n=== SECTION 2: D3 PARTIAL PAYMENTS + IDEMPOTENCY ===\n";

function freshBooking(FlightBookingService $svc, $customer, $carrier): FlightBooking {
    // Cleanup any test bookings
    FlightPayment::whereIn('flight_booking_id',
        FlightBooking::where('customer_id', $customer->id)->pluck('id')
    )->delete();
    FlightBooking::where('customer_id', $customer->id)->delete();
    return $svc->createBooking([
        'customer_id'=>$customer->id, 'currency'=>'EGP',
        'selling_price'=>12000, 'purchase_price'=>8000, 'exchange_rate'=>1.0,
        'purchase_balance_source'=>'carrier', 'flight_carrier_id'=>$carrier->id,
        'passengers'=>[['first_name'=>'P2','last_name'=>'T','type'=>'adult']],
    ]);
}

// 2.A 4000 + 4000 + 4000 = 12000
$b = freshBooking($svc, $customer, $carrier);
$total = 0;
foreach ([4000, 4000, 4000] as $amt) {
    $r = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments",
        ['amount'=>$amt,'payment_method'=>'cash','account_id'=>$treasury->id], $token);
    if ($r['status'] === 201) $total += $amt;
}
$b->refresh();
if ($total === 12000 && abs((float)$b->payments->sum('amount') - 12000) < 0.02 && $b->status === \App\Enums\FlightBookingStatus::CONFIRMED) {
    ok('2.A 4000+4000+4000=12000 (CONFIRMED)', 'lifecycle RESTORED, status='.$b->status->value);
} else {
    bad('2.A partial payments', "sum={$total} paid=".($b->payments->sum('amount'))." status=".$b->status->value);
}

// 2.B multiple partial payments on same booking (already tested via 2.A; verify rows/entries/tx)
$paymentCount = FlightPayment::where('flight_booking_id', $b->id)->whereNull('deleted_at')->count();
$txCount = Transaction::whereIn('related_type', [FlightPayment::class])->whereIn('related_id', $b->payments->pluck('id'))->count();
$entryCount = DB::table('account_entries')->whereIn('transaction_id',
    Transaction::whereIn('related_type', [FlightPayment::class])->whereIn('related_id', $b->payments->pluck('id'))->pluck('id')
)->count();
$dupIncome = DB::select("SELECT related_id, COUNT(*) c FROM transactions WHERE related_type='App\\\\Models\\\\Flight\\\\FlightPayment' AND type='income' GROUP BY related_id HAVING COUNT(*)>1");
if ($paymentCount === 3 && $txCount === 3 && count($dupIncome) === 0) {
    ok('2.B 3 payments → 3 tx, 0 duplicate income per payment', "payments={$paymentCount} tx={$txCount} dup_income=".count($dupIncome));
} else {
    bad('2.B consistency', "payments={$paymentCount} tx={$txCount} dup_income=".count($dupIncome));
}

// 2.C different keys, same amount
$b = freshBooking($svc, $customer, $carrier);
$r1 = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments", ['amount'=>2000,'payment_method'=>'cash','account_id'=>$treasury->id,'idempotency_key'=>'P2-C-1'], $token);
$r2 = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments", ['amount'=>2000,'payment_method'=>'cash','account_id'=>$treasury->id,'idempotency_key'=>'P2-C-2'], $token);
if ($r1['status']===201 && $r2['status']===201 && FlightPayment::where('flight_booking_id',$b->id)->whereNull('deleted_at')->count()===2) {
    ok('2.C different keys same amount', '2 distinct payment rows');
} else {
    bad('2.C different keys', "r1={$r1['status']} r2={$r2['status']}");
}

// 2.D same key replay (sequential)
$b = freshBooking($svc, $customer, $carrier);
$r1 = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments", ['amount'=>1500,'payment_method'=>'cash','account_id'=>$treasury->id,'idempotency_key'=>'P2-D-X'], $token);
$r2 = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments", ['amount'=>1500,'payment_method'=>'cash','account_id'=>$treasury->id,'idempotency_key'=>'P2-D-X'], $token);
if ($r1['status']===201 && $r2['status']===200 && FlightPayment::where('flight_booking_id',$b->id)->whereNull('deleted_at')->count()===1) {
    ok('2.D same key replay', "1st=201, 2nd=200, 1 row");
} else {
    bad('2.D same key replay', "r1={$r1['status']} r2={$r2['status']}");
}

// 2.E same key 10× concurrent
$b = freshBooking($svc, $customer, $carrier);
$key = 'P2-E-'.uniqid();
$rs = fireConcurrent('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments",
    ['amount'=>500,'payment_method'=>'cash','account_id'=>$treasury->id,'idempotency_key'=>$key], 10, $token);
$c201=0;$c200=0;$other=0;
foreach($rs as $r){if($r['status']===201)$c201++;elseif($r['status']===200)$c200++;else $other++;}
$pc = FlightPayment::where('flight_booking_id',$b->id)->whereNull('deleted_at')->count();
if ($c201===1 && $pc===1 && $c200+$other===9) ok("2.E 10× same key", "1×201, {$c200}×200, {$other}×other; 1 row");
else bad("2.E 10× same key", "201={$c201} 200={$c200} other={$other} rows={$pc}");

// 2.F same key 25× concurrent
$b = freshBooking($svc, $customer, $carrier);
$key = 'P2-F-'.uniqid();
$rs = fireConcurrent('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments",
    ['amount'=>500,'payment_method'=>'cash','account_id'=>$treasury->id,'idempotency_key'=>$key], 25, $token);
$c201=0;$c200=0;$other=0;
foreach($rs as $r){if($r['status']===201)$c201++;elseif($r['status']===200)$c200++;else $other++;}
$pc = FlightPayment::where('flight_booking_id',$b->id)->whereNull('deleted_at')->count();
if ($c201===1 && $pc===1 && $c200+$other===24) ok("2.F 25× same key", "1×201, {$c200}×200, {$other}×other; 1 row");
else bad("2.F 25× same key", "201={$c201} 200={$c200} other={$other} rows={$pc}");

// 2.G different keys 10× concurrent
$b = freshBooking($svc, $customer, $carrier);
$rs = fireConcurrent('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments",
    ['amount'=>100,'payment_method'=>'cash','account_id'=>$treasury->id,'idempotency_key'=>'P2-G-'.uniqid()], 10, $token, true);
$c201=0;foreach($rs as $r)if($r['status']===201)$c201++;
$pc = FlightPayment::where('flight_booking_id',$b->id)->whereNull('deleted_at')->count();
if ($c201===10 && $pc===10) ok("2.G 10× distinct keys", "10×201, 10 rows");
else bad("2.G 10× distinct keys", "201={$c201}/10 rows={$pc}");

// 2.H different keys 25× concurrent
$b = freshBooking($svc, $customer, $carrier);
$rs = fireConcurrent('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments",
    ['amount'=>100,'payment_method'=>'cash','account_id'=>$treasury->id,'idempotency_key'=>'P2-H-'.uniqid()], 25, $token, true);
$c201=0;foreach($rs as $r)if($r['status']===201)$c201++;
$pc = FlightPayment::where('flight_booking_id',$b->id)->whereNull('deleted_at')->count();
if ($c201===25 && $pc===25) ok("2.H 25× distinct keys", "25×201, 25 rows");
else bad("2.H 25× distinct keys", "201={$c201}/25 rows={$pc}");

// =========================================================================
// SECTION 3: D4 PRICE SAFETY
// =========================================================================
echo "\n=== SECTION 3: D4 PRICE SAFETY ===\n";

$b = $svc->createBooking(['customer_id'=>$customer->id,'currency'=>'EGP','selling_price'=>12000,'purchase_price'=>8000,'exchange_rate'=>1.0,'purchase_balance_source'=>'carrier','flight_carrier_id'=>$carrier->id,'passengers'=>[['first_name'=>'P3','last_name'=>'T','type'=>'adult']]]);

// 3.1 HTTP purchase_price=-1
$r = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/prices", ['purchase_price'=>-1,'selling_price'=>1000], $token);
if ($r['status']===422) ok('3.1 HTTP purchase=-1 rejected', '422');
else bad('3.1 HTTP purchase=-1', "got {$r['status']}");

// 3.2 HTTP purchase_price=-100
$r = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/prices", ['purchase_price'=>-100,'selling_price'=>1000], $token);
if ($r['status']===422) ok('3.2 HTTP purchase=-100 rejected', '422');
else bad('3.2 HTTP purchase=-100', "got {$r['status']}");

// 3.3 HTTP selling_price=-1
$r = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/prices", ['purchase_price'=>1000,'selling_price'=>-1], $token);
if ($r['status']===422) ok('3.3 HTTP selling=-1 rejected', '422');
else bad('3.3 HTTP selling=-1', "got {$r['status']}");

// 3.4 HTTP selling_price=-100
$r = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/prices", ['purchase_price'=>1000,'selling_price'=>-100], $token);
if ($r['status']===422) ok('3.4 HTTP selling=-100 rejected', '422');
else bad('3.4 HTTP selling=-100', "got {$r['status']}");

// 3.5 service purchase=-100
try { $svc->updatePrices($b->fresh(), -100, 1000); bad('3.5 service purchase=-100', 'ACCEPTED'); }
catch (\InvalidArgumentException $e) { ok('3.5 service purchase=-100', 'rejected'); }

// 3.6 service selling=-500
try { $svc->updatePrices($b->fresh(), 1000, -500); bad('3.6 service selling=-500', 'ACCEPTED'); }
catch (\InvalidArgumentException $e) { ok('3.6 service selling=-500', 'rejected'); }

// 3.7 zero purchase allowed
try { $svc->updatePrices($b->fresh(), 0, 1000); ok('3.7 zero purchase allowed', 'accepted per spec'); }
catch (\Throwable $e) { bad('3.7 zero purchase', 'unexpectedly rejected: '.$e->getMessage()); }

// 3.8 zero selling allowed
try { $svc->updatePrices($b->fresh(), 1000, 0); ok('3.8 zero selling allowed', 'accepted per spec'); }
catch (\Throwable $e) { bad('3.8 zero selling', 'unexpectedly rejected: '.$e->getMessage()); }

// 3.9 booking creation with negative price — service guard
try { $svc->createBooking(['customer_id'=>$customer->id,'currency'=>'EGP','selling_price'=>-1000,'purchase_price'=>500,'exchange_rate'=>1.0,'purchase_balance_source'=>'carrier','flight_carrier_id'=>$carrier->id,'passengers'=>[['first_name'=>'T','last_name'=>'T','type'=>'adult']]]); bad('3.9 createBooking selling=-1000', 'ACCEPTED'); }
catch (\InvalidArgumentException $e) { ok('3.9 createBooking with negative selling', 'rejected'); }

// 3.10 no financial mutation after rejected updatePrices
$balBefore = (float) $carrier->fresh()->balance;
$txBefore = AirlineTransaction::where('flight_carrier_id',$carrier->id)->count();
try { $svc->updatePrices($b->fresh(), -100, 1000); } catch (\InvalidArgumentException $e) {}
$balAfter = (float) $carrier->fresh()->balance;
$txAfter = AirlineTransaction::where('flight_carrier_id',$carrier->id)->count();
if (abs($balAfter-$balBefore)<0.02 && $txAfter===$txBefore) ok('3.10 no mutation after rejection', "balance Δ=".($balAfter-$balBefore)." AirlineTx Δ=".($txAfter-$txBefore));
else bad('3.10 no mutation', "balance Δ=".($balAfter-$balBefore)." AirlineTx Δ=".($txAfter-$txBefore));

// =========================================================================
// SECTION 4: D5 RECHARGE
// =========================================================================
echo "\n=== SECTION 4: D5 RECHARGE ===\n";

// 4.1 single inactive carrier (service)
$rechargeSvc = app(FlightCarrierRechargeService::class);
$balBefore = (float) $inactiveCarrier->fresh()->balance;
$airBefore = AirlineTransaction::where('flight_carrier_id',$inactiveCarrier->id)->count();
try { $rechargeSvc->rechargeFromAccount($inactiveCarrier->fresh(), $treasury, 100, '4.1'); bad('4.1 service inactive', 'ACCEPTED'); }
catch (\App\Exceptions\InactiveFlightCarrierException $e) { ok('4.1 service inactive', 'rejected with correct exception'); }
$balAfter = (float) $inactiveCarrier->fresh()->balance;
$airAfter = AirlineTransaction::where('flight_carrier_id',$inactiveCarrier->id)->count();
if (abs($balAfter-$balBefore)<0.02 && $airAfter===$airBefore) ok('4.1 no mutation', 'balance Δ=0 AirlineTx Δ=0');
else bad('4.1 no mutation', 'moved');

// 4.2 single inactive carrier (HTTP)
$r = api('POST', "http://127.0.0.1:18000/api/v1/flight/carriers/{$inactiveCarrier->id}/recharge", ['from_account_id'=>$treasury->id,'amount'=>100], $token);
if ($r['status']===422) ok('4.2 HTTP inactive', '422');
else bad('4.2 HTTP inactive', "got {$r['status']}");

// 4.3 10 concurrent inactive
$rs = fireConcurrent('POST', "http://127.0.0.1:18000/api/v1/flight/carriers/{$inactiveCarrier->id}/recharge", ['from_account_id'=>$treasury->id,'amount'=>50], 10, $token, true);
$accepted=0;$rejected=0;foreach($rs as $r){if($r['status']===200)$accepted++;elseif($r['status']===422)$rejected++;}
if ($accepted===0 && $rejected===10) ok('4.3 10c inactive', "0 accepted, 10 rejected");
else bad('4.3 10c inactive', "accepted={$accepted} rejected={$rejected}");

// 4.4 25 concurrent inactive
$rs = fireConcurrent('POST', "http://127.0.0.1:18000/api/v1/flight/carriers/{$inactiveCarrier->id}/recharge", ['from_account_id'=>$treasury->id,'amount'=>50], 25, $token, true);
$accepted=0;$rejected=0;foreach($rs as $r){if($r['status']===200)$accepted++;elseif($r['status']===422)$rejected++;}
if ($accepted===0 && $rejected===25) ok('4.4 25c inactive', "0 accepted, 25 rejected");
else bad('4.4 25c inactive', "accepted={$accepted} rejected={$rejected}");

// 4.5 single active recharge
$balBefore = (float) $carrier->fresh()->balance;
$r = api('POST', "http://127.0.0.1:18000/api/v1/flight/carriers/{$carrier->id}/recharge", ['from_account_id'=>$treasury->id,'amount'=>100], $token);
$balAfter = (float) $carrier->fresh()->balance;
if ($r['status']===200 && abs($balAfter-($balBefore+100))<0.02) ok('4.5 single active', "balance {$balBefore}→{$balAfter}");
else bad('4.5 single active', "got {$r['status']} bal={$balAfter}");

// 4.6 repeated active recharges
$balBefore = (float) $carrier->fresh()->balance;
for ($i=0;$i<5;$i++) api('POST', "http://127.0.0.1:18000/api/v1/flight/carriers/{$carrier->id}/recharge", ['from_account_id'=>$treasury->id,'amount'=>50], $token);
$balAfter = (float) $carrier->fresh()->balance;
if (abs($balAfter-($balBefore+250))<0.02) ok('4.6 5× active', "balance {$balBefore}→{$balAfter}");
else bad('4.6 5× active', "Δ=".($balAfter-$balBefore));

// 4.7 25 concurrent active recharges
$balBefore = (float) $carrier->fresh()->balance;
$rs = fireConcurrent('POST', "http://127.0.0.1:18000/api/v1/flight/carriers/{$carrier->id}/recharge", ['from_account_id'=>$treasury->id,'amount'=>10], 25, $token, true);
$accepted=0;foreach($rs as $r)if($r['status']===200)$accepted++;
$balAfter = (float) $carrier->fresh()->balance;
if ($accepted===25 && abs($balAfter-($balBefore+250))<0.02) ok('4.7 25c active', "25 accepted, balance Δ=".($balAfter-$balBefore));
else bad('4.7 25c active', "accepted={$accepted}/25 Δ=".($balAfter-$balBefore));

// =========================================================================
// SECTION 6: PAYMENT METHODS
// =========================================================================
echo "\n=== SECTION 6: PAYMENT METHODS ===\n";

$methods = ['cash','bank_transfer','cash_wallet','postal_transfer','office_safe','office_drawer','vodafone_cash','instapay'];
$b = freshBooking($svc, $customer, $carrier);
$results = [];
foreach ($methods as $method) {
    $r = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments",
        ['amount'=>100,'payment_method'=>$method,'account_id'=>$treasury->id,'idempotency_key'=>'PM-'.$method], $token);
    $results[$method] = $r['status'];
}
$okMethods = array_filter($results, fn($s)=>$s===201);
$failMethods = array_filter($results, fn($s)=>$s!==201);
if (count($okMethods) === count($methods)) {
    ok('6.1 all '.count($methods).' payment methods accepted', 'all 201');
} else {
    bad('6.1 payment methods', 'failed: '.implode(',', array_keys($failMethods)).' → '.json_encode($failMethods));
}

echo "\n=== PHASE 2 FINAL ===\n";
echo "PASS: {$pass}    FAIL: {$fail}\n";
exit($fail === 0 ? 0 : 1);
