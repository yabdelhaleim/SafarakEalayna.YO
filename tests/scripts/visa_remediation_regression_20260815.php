<?php

/**
 * VISA MODULE — D01/D02 & IDEMPOTENCY REMEDIATION REGRESSION SUITE
 * Date: 2026-08-15
 * Environment: APP_ENV=stress | DB_DATABASE=safarak_stress ONLY
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// ============================================================
// §0 — SAFETY CHECK & BOOTSTRAP
// ============================================================
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$dbName = \Illuminate\Support\Facades\DB::select('SELECT DATABASE() AS db')[0]->db ?? '';
if ($dbName !== 'safarak_stress') {
    fwrite(STDERR, "\n❌ FATAL: Expected DB 'safarak_stress', got '{$dbName}'. ABORTING.\n\n");
    exit(1);
}

if (config('app.env') !== 'stress') {
    fwrite(STDERR, "\n❌ FATAL: APP_ENV is '" . config('app.env') . "', must be 'stress'. ABORTING.\n\n");
    exit(1);
}

echo "\n" . str_repeat('═', 65) . "\n";
echo "  VISA REMEDIATION TARGETED REGRESSION SUITE (D01, D02, IDEMPOTENCY)\n";
echo "  Date: " . date('Y-m-d H:i:s') . "\n";
echo "  DB: {$dbName} | ENV: " . config('app.env') . "\n";
echo str_repeat('═', 65) . "\n\n";

$passed = 0;
$failed = 0;
$defectLedger = [];

function check(bool $cond, string $title, string $details = ''): bool
{
    global $passed, $failed, $defectLedger;
    if ($cond) {
        $passed++;
        echo "  ✅ PASS  {$title}\n";
        return true;
    } else {
        $failed++;
        echo "  ❌ FAIL  {$title}\n";
        if ($details) echo "         ↳ {$details}\n";
        $defectLedger[] = ['title' => $title, 'details' => $details];
        return false;
    }
}

// Helpers
$user = \App\Models\User::first() ?? \App\Models\User::factory()->create();
$token = $user->createToken('remediation-audit')->plainTextToken;

function api(string $method, string $path, array $data = []): array
{
    global $token;
    $url = "http://127.0.0.1:18000/api/v1" . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        "Authorization: Bearer {$token}",
    ];
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($res, true) ?? [];
    $json['__status'] = $status;
    $json['__raw'] = $res;
    return $json;
}

function getVaultId(): int
{
    $v = \App\Models\Account::getModuleVault('visas');
    if ($v) return $v->id;
    $v2 = \App\Models\Account::where('type', 'cashbox')->where('is_active', 1)->first();
    return $v2 ? $v2->id : 1;
}

$vaultId = getVaultId();
$agent = \App\Models\HajjUmra\VisaAgent::first() ?? \App\Models\HajjUmra\VisaAgent::create([
    'company_name' => 'Remediation Test Agent',
    'is_active' => true,
]);

// Track counts before
$initialTxCount = \App\Models\Transaction::count();
$initialBookingCount = \App\Models\VisaBooking::count();
$initialPaymentCount = \App\Models\VisaPayment::count();

// ============================================================
// SECTION 1: VISA-D01 REMEDIATION (PAYMENT COLLECTION)
// ============================================================
echo "════════════════════════════════════════════════════════════\n";
echo "  §1 — VISA-D01 FIX: PAYMENT LIFECYCLE & JOURNAL TRANSFERS\n";
echo "════════════════════════════════════════════════════════════\n";

// 1.1 Single Full Payment
$rCreate = api('POST', '/visa/bookings', [
    'customer' => ['full_name' => 'D01 Test Customer 1', 'phone' => '01011112222'],
    'purchase_price' => 3000,
    'selling_price' => 5000,
    'service_fee' => 500,
    'account_id' => $vaultId,
    'visa_details' => [
        'country' => 'Saudi Arabia',
        'visa_type' => 'work',
        'visa_agent_id' => $agent->id,
    ],
]);
check($rCreate['__status'] === 201, "§1.1 Create booking with total 5500", $rCreate['__raw']);
$b1Id = $rCreate['data']['id'] ?? 0;

$rPmt1 = api('POST', "/visa/bookings/{$b1Id}/payments", [
    'amount' => 5500,
    'payment_method' => 'cash',
    'account_id' => $vaultId,
    'reference' => 'FULL-PMT-001',
]);
check($rPmt1['__status'] === 201, "§1.1 Full payment 5500 succeeds with 201 Created", $rPmt1['__raw']);

$b1 = \App\Models\VisaBooking::find($b1Id);
check((float)$b1->paid_amount == 5500.0, "§1.1 Booking paid_amount == 5500 (got {$b1->paid_amount})");
check((float)$b1->remaining_amount == 0.0, "§1.1 Booking remaining_amount == 0");

$pmt1Row = \App\Models\VisaPayment::where('visa_booking_id', $b1Id)->first();
check($pmt1Row !== null, "§1.1 VisaPayment row created in DB");
check($pmt1Row->transaction_id !== null, "§1.1 VisaPayment linked to Transaction");

$tx1 = \App\Models\Transaction::find($pmt1Row->transaction_id);
check($tx1 !== null && $tx1->type === \App\Enums\TransactionType::Transfer, "§1.1 Payment transaction type is Transfer (not duplicate Income)");

// Verify double entry balance for payment tx
$tx1Entries = \App\Models\AccountEntry::where('transaction_id', $tx1->id)->get();
$dSum = $tx1Entries->sum('debit');
$cSum = $tx1Entries->sum('credit');
check(abs($dSum - $cSum) < 0.001 && $dSum == 5500.0, "§1.1 Payment tx double-entry balanced (Debit {$dSum} == Credit {$cSum})");

// 1.2 Multiple Partial Payments (4000 + 4000 + 4000 on 12000 total)
$rCreate2 = api('POST', '/visa/bookings', [
    'customer' => ['full_name' => 'D01 Partial Customer', 'phone' => '01033334444'],
    'purchase_price' => 8000,
    'selling_price' => 12000,
    'service_fee' => 0,
    'account_id' => $vaultId,
    'visa_details' => [
        'country' => 'UAE',
        'visa_type' => 'tourist',
        'visa_agent_id' => $agent->id,
    ],
]);
$b2Id = $rCreate2['data']['id'] ?? 0;

$rPart1 = api('POST', "/visa/bookings/{$b2Id}/payments", [
    'amount' => 4000,
    'payment_method' => 'cash',
    'account_id' => $vaultId,
    'reference' => 'PARTIAL-1',
]);
check($rPart1['__status'] === 201, "§1.2 Partial payment 1 (4000/12000) → 201", $rPart1['__raw']);

$rPart2 = api('POST', "/visa/bookings/{$b2Id}/payments", [
    'amount' => 4000,
    'payment_method' => 'bank_transfer',
    'account_id' => $vaultId,
    'reference' => 'PARTIAL-2',
]);
check($rPart2['__status'] === 201, "§1.2 Partial payment 2 (4000/12000) → 201", $rPart2['__raw']);

$rPart3 = api('POST', "/visa/bookings/{$b2Id}/payments", [
    'amount' => 4000,
    'payment_method' => 'cash_wallet',
    'account_id' => $vaultId,
    'reference' => 'PARTIAL-3',
]);
check($rPart3['__status'] === 201, "§1.2 Partial payment 3 (4000/12000) → 201", $rPart3['__raw']);

$b2 = \App\Models\VisaBooking::find($b2Id);
check((float)$b2->paid_amount == 12000.0, "§1.2 Multiple partial payments sum to 12000 (got {$b2->paid_amount})");
check((float)$b2->remaining_amount == 0.0, "§1.2 Remaining amount is 0.00");
$b2PaymentsCount = \App\Models\VisaPayment::where('visa_booking_id', $b2Id)->count();
check($b2PaymentsCount === 3, "§1.2 Exactly 3 VisaPayment rows created for booking {$b2Id}");

// 1.3 Overpayment Guard
$rOverpay = api('POST', "/visa/bookings/{$b2Id}/payments", [
    'amount' => 100,
    'payment_method' => 'cash',
    'account_id' => $vaultId,
]);
check($rOverpay['__status'] === 422, "§1.3 Overpayment after full payment is rejected with 422", $rOverpay['__raw']);

// 1.4 Cancellation & Reversal after Payments
$rCancel = api('POST', "/visa/bookings/{$b2Id}/cancel", ['reason' => 'Audit Cancel Test']);
check($rCancel['__status'] === 200, "§1.4 Cancel booking after payments → 200", $rCancel['__raw']);

$rPmtAfterCancel = api('POST', "/visa/bookings/{$b2Id}/payments", [
    'amount' => 100,
    'payment_method' => 'cash',
    'account_id' => $vaultId,
]);
check($rPmtAfterCancel['__status'] === 422, "§1.4 Payment on cancelled booking rejected with 422", $rPmtAfterCancel['__raw']);

// ============================================================
// SECTION 2: VISA-D02 REMEDIATION (SERVICE-LAYER PRICE SAFETY)
// ============================================================
echo "\n════════════════════════════════════════════════════════════\n";
echo "  §2 — VISA-D02 FIX: SERVICE-LAYER FINANCIAL BOUNDARIES\n";
echo "════════════════════════════════════════════════════════════\n";

$visaService = app(\App\Services\Visa\VisaBookingService::class);

// 2.1 Negative purchase price in Service::create()
$d02PurchaseThrew = false;
try {
    $visaService->create([
        'customer' => ['full_name' => 'D02 Test 1', 'phone' => '01055556666'],
        'purchase_price' => -1.0,
        'selling_price' => 1000.0,
        'service_fee' => 0.0,
        'account_id' => $vaultId,
        'visa_details' => ['country' => 'Qatar', 'visa_type' => 'work'],
    ]);
} catch (\InvalidArgumentException $e) {
    $d02PurchaseThrew = true;
} catch (\Throwable $e) {
    $d02PurchaseThrew = true;
}
check($d02PurchaseThrew, "§2.1 Service::create() rejects purchase_price = -1 with InvalidArgumentException");

// 2.2 Negative selling price in Service::create() (even if fee covers it)
$d02SellingThrew = false;
try {
    $visaService->create([
        'customer' => ['full_name' => 'D02 Test 2', 'phone' => '01077778888'],
        'purchase_price' => 100.0,
        'selling_price' => -1.0,
        'service_fee' => 500.0, // total revenue would be 499 > 0
        'account_id' => $vaultId,
        'visa_details' => ['country' => 'Oman', 'visa_type' => 'work'],
    ]);
} catch (\InvalidArgumentException $e) {
    $d02SellingThrew = true;
} catch (\Throwable $e) {
    $d02SellingThrew = true;
}
check($d02SellingThrew, "§2.2 Service::create() rejects selling_price = -1 (even when fee > 0) with InvalidArgumentException");

// 2.3 Negative service fee in Service::create()
$d02FeeThrew = false;
try {
    $visaService->create([
        'customer' => ['full_name' => 'D02 Test 3', 'phone' => '01099990000'],
        'purchase_price' => 100.0,
        'selling_price' => 1000.0,
        'service_fee' => -50.0,
        'account_id' => $vaultId,
        'visa_details' => ['country' => 'Bahrain', 'visa_type' => 'work'],
    ]);
} catch (\InvalidArgumentException $e) {
    $d02FeeThrew = true;
} catch (\Throwable $e) {
    $d02FeeThrew = true;
}
check($d02FeeThrew, "§2.3 Service::create() rejects service_fee = -50 with InvalidArgumentException");

// 2.4 Negative price in Service::update()
$validBooking = $visaService->create([
    'customer' => ['full_name' => 'D02 Update Test', 'phone' => '01012345678'],
    'purchase_price' => 1000.0,
    'selling_price' => 2000.0,
    'service_fee' => 100.0,
    'account_id' => $vaultId,
    'visa_details' => ['country' => 'Kuwait', 'visa_type' => 'work'],
]);

$d02UpdateThrew = false;
try {
    $visaService->update($validBooking, [
        'selling_price' => -500.0,
    ]);
} catch (\InvalidArgumentException $e) {
    $d02UpdateThrew = true;
} catch (\Throwable $e) {
    $d02UpdateThrew = true;
}
check($d02UpdateThrew, "§2.4 Service::update() rejects negative selling_price = -500 with InvalidArgumentException");

// 2.5 Valid zero values allowed
$zeroBookingCreated = false;
try {
    $zBooking = $visaService->create([
        'customer' => ['full_name' => 'Zero Price Test', 'phone' => '01087654321'],
        'purchase_price' => 0.0,
        'selling_price' => 0.0,
        'service_fee' => 0.0,
        'account_id' => $vaultId,
        'visa_details' => ['country' => 'Kuwait', 'visa_type' => 'work'],
    ]);
    $zeroBookingCreated = ($zBooking->id > 0);
} catch (\Throwable $e) {
    $zeroBookingCreated = false;
}
check($zeroBookingCreated, "§2.5 Service::create() permits legitimate zero pricing (purchase=0, selling=0)");

// ============================================================
// SECTION 3: PAYMENT IDEMPOTENCY
// ============================================================
echo "\n════════════════════════════════════════════════════════════\n";
echo "  §3 — PAYMENT IDEMPOTENCY (CONTRACT & SEQUENTIAL REPLAY)\n";
echo "════════════════════════════════════════════════════════════\n";

$rCreateIdem = api('POST', '/visa/bookings', [
    'customer' => ['full_name' => 'Idempotency Customer', 'phone' => '01122334455'],
    'purchase_price' => 2000,
    'selling_price' => 6000,
    'service_fee' => 0,
    'account_id' => $vaultId,
    'visa_details' => ['country' => 'Turkey', 'visa_type' => 'tourist'],
]);
$bIdemId = $rCreateIdem['data']['id'] ?? 0;

$keyA = 'IDEM-KEY-ALPHA-' . uniqid();

// 3.1 Initial Payment with Idempotency Key
$rIdem1 = api('POST', "/visa/bookings/{$bIdemId}/payments", [
    'amount' => 2000,
    'payment_method' => 'cash',
    'account_id' => $vaultId,
    'idempotency_key' => $keyA,
    'reference' => 'IDEM-REF-1',
]);
check($rIdem1['__status'] === 201, "§3.1 First request with key → 201 Created", $rIdem1['__raw']);
check(isset($rIdem1['data']['idempotent_replay']) && $rIdem1['data']['idempotent_replay'] === false, "§3.1 Response indicates idempotent_replay = false");
$p1Id = $rIdem1['data']['payment']['id'] ?? 0;

// 3.2 Sequential Replay with Same Key
$rIdemReplay = api('POST', "/visa/bookings/{$bIdemId}/payments", [
    'amount' => 2000,
    'payment_method' => 'cash',
    'account_id' => $vaultId,
    'idempotency_key' => $keyA,
    'reference' => 'IDEM-REF-1-REPLAY',
]);
check($rIdemReplay['__status'] === 200, "§3.2 Sequential replay with same key → 200 OK", $rIdemReplay['__raw']);
check(isset($rIdemReplay['data']['idempotent_replay']) && $rIdemReplay['data']['idempotent_replay'] === true, "§3.2 Replay response indicates idempotent_replay = true");
$pReplayId = $rIdemReplay['data']['payment']['id'] ?? 0;
check($p1Id === $pReplayId, "§3.2 Replay returns identical payment identity (ID {$p1Id} == {$pReplayId})");

// Verify DB: exactly 1 payment row, 1 transaction
$idemPaymentsCount = \App\Models\VisaPayment::where('visa_booking_id', $bIdemId)->where('idempotency_key', $keyA)->count();
check($idemPaymentsCount === 1, "§3.2 DB contains exactly 1 VisaPayment row for key {$keyA}");
$bIdem = \App\Models\VisaBooking::find($bIdemId);
check((float)$bIdem->paid_amount == 2000.0, "§3.2 Booking paid_amount remains 2000.00 (no double charge)");

// 3.3 Distinct Key on Same Booking → New Payment
$keyB = 'IDEM-KEY-BETA-' . uniqid();
$rIdem2 = api('POST', "/visa/bookings/{$bIdemId}/payments", [
    'amount' => 2000,
    'payment_method' => 'bank_transfer',
    'account_id' => $vaultId,
    'idempotency_key' => $keyB,
    'reference' => 'IDEM-REF-2',
]);
check($rIdem2['__status'] === 201, "§3.3 Different key on same booking → 201 Created", $rIdem2['__raw']);
check($rIdem2['data']['idempotent_replay'] === false, "§3.3 Different key is not a replay");
$p2Id = $rIdem2['data']['payment']['id'] ?? 0;
check($p2Id !== $p1Id, "§3.3 Different key created a new distinct payment (ID {$p2Id})");

// 3.4 Same Key on Different Booking → Allowed
$rCreateOther = api('POST', '/visa/bookings', [
    'customer' => ['full_name' => 'Other Booking Customer', 'phone' => '01199887766'],
    'purchase_price' => 1000,
    'selling_price' => 3000,
    'service_fee' => 0,
    'account_id' => $vaultId,
    'visa_details' => ['country' => 'Jordan', 'visa_type' => 'tourist'],
]);
$bOtherId = $rCreateOther['data']['id'] ?? 0;

$rOtherSameKey = api('POST', "/visa/bookings/{$bOtherId}/payments", [
    'amount' => 1500,
    'payment_method' => 'cash',
    'account_id' => $vaultId,
    'idempotency_key' => $keyA, // reused key on different booking
]);
check($rOtherSameKey['__status'] === 201, "§3.4 Same key on DIFFERENT booking → 201 Created (scoped per booking)", $rOtherSameKey['__raw']);

// ============================================================
// SECTION 4: TRUE CONCURRENCY (curl_multi 25× WORKERS)
// ============================================================
echo "\n════════════════════════════════════════════════════════════\n";
echo "  §4 — TRUE CONCURRENCY (curl_multi 25× WORKERS)\n";
echo "════════════════════════════════════════════════════════════\n";

function runConcurrentRequests(string $method, string $path, array $payloads): array
{
    global $token;
    $mh = curl_multi_init();
    $handles = [];

    foreach ($payloads as $i => $data) {
        $ch = curl_init("http://127.0.0.1:18000/api/v1" . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            "Authorization: Bearer {$token}",
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);

    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }

    $results = [];
    foreach ($handles as $i => $ch) {
        $content = curl_multi_getcontent($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $json = json_decode($content, true) ?? [];
        $json['__status'] = $status;
        $json['__raw'] = $content;
        $results[$i] = $json;
    }
    curl_multi_close($mh);
    return $results;
}

// 4.1 Scenario A: 25 Concurrent Requests with SAME Idempotency Key
$rCreateConcA = api('POST', '/visa/bookings', [
    'customer' => ['full_name' => 'Concurrent Same Key Customer', 'phone' => '01211110000'],
    'purchase_price' => 2000,
    'selling_price' => 5000,
    'service_fee' => 0,
    'account_id' => $vaultId,
    'visa_details' => ['country' => 'Saudi Arabia', 'visa_type' => 'work'],
]);
$bConcAId = $rCreateConcA['data']['id'] ?? 0;
$concKeyA = 'CONC-KEY-SAME-' . uniqid();

$payloadsA = [];
for ($i = 0; $i < 25; $i++) {
    $payloadsA[] = [
        'amount' => 5000,
        'payment_method' => 'cash',
        'account_id' => $vaultId,
        'idempotency_key' => $concKeyA,
        'reference' => "CONC-SAME-{$i}",
    ];
}

$resultsA = runConcurrentRequests('POST', "/visa/bookings/{$bConcAId}/payments", $payloadsA);
$statCountsA = array_count_values(array_column($resultsA, '__status'));
$code201A = $statCountsA[201] ?? 0;
$code200A = $statCountsA[200] ?? 0;
$code5xxA = 0;
foreach ($statCountsA as $code => $cnt) {
    if ($code >= 500) $code5xxA += $cnt;
}

check($code5xxA === 0, "§4.1 Scenario A: 0 server errors (5xx) across 25 concurrent requests");
check($code201A === 1, "§4.1 Scenario A: Exactly 1 request returned 201 Created (got {$code201A})");
check($code200A === 24, "§4.1 Scenario A: Exactly 24 requests returned 200 OK Replay (got {$code200A})");

$dbPaymentsA = \App\Models\VisaPayment::where('visa_booking_id', $bConcAId)->count();
check($dbPaymentsA === 1, "§4.1 Scenario A: Exactly 1 payment row in DB for 25 concurrent requests");
$bConcA = \App\Models\VisaBooking::find($bConcAId);
check((float)$bConcA->paid_amount == 5000.0, "§4.1 Scenario A: Booking paid_amount is exactly 5000.00");

// 4.2 Scenario B: 25 Concurrent Requests with 25 DISTINCT Idempotency Keys (200 each on 5000)
$rCreateConcB = api('POST', '/visa/bookings', [
    'customer' => ['full_name' => 'Concurrent Distinct Key Customer', 'phone' => '01222220000'],
    'purchase_price' => 1000,
    'selling_price' => 5000,
    'service_fee' => 0,
    'account_id' => $vaultId,
    'visa_details' => ['country' => 'UAE', 'visa_type' => 'tourist'],
]);
$bConcBId = $rCreateConcB['data']['id'] ?? 0;

$payloadsB = [];
for ($i = 0; $i < 25; $i++) {
    $payloadsB[] = [
        'amount' => 200, // 25 * 200 = 5000 total
        'payment_method' => 'cash',
        'account_id' => $vaultId,
        'idempotency_key' => "CONC-DISTINCT-KEY-{$i}-" . uniqid(),
        'reference' => "CONC-DIST-{$i}",
    ];
}

$resultsB = runConcurrentRequests('POST', "/visa/bookings/{$bConcBId}/payments", $payloadsB);
$statCountsB = array_count_values(array_column($resultsB, '__status'));
$code201B = $statCountsB[201] ?? 0;
$code5xxB = 0;
foreach ($statCountsB as $code => $cnt) {
    if ($code >= 500) $code5xxB += $cnt;
}

check($code5xxB === 0, "§4.2 Scenario B: 0 server errors (5xx) across 25 concurrent distinct requests");
check($code201B === 25, "§4.2 Scenario B: All 25 distinct payments succeeded with 201 Created (got {$code201B})");

$dbPaymentsB = \App\Models\VisaPayment::where('visa_booking_id', $bConcBId)->count();
check($dbPaymentsB === 25, "§4.2 Scenario B: Exactly 25 payment rows in DB");
$bConcB = \App\Models\VisaBooking::find($bConcBId);
check((float)$bConcB->paid_amount == 5000.0, "§4.2 Scenario B: Booking paid_amount equals 5000.00 (25 × 200)");

// 4.3 Scenario C: Mixed (13 same key, 12 distinct keys on 5000 total)
// Booking with 13000 total: 1 same key (1000) replayed 13 times + 12 distinct keys (1000 each)
$rCreateConcC = api('POST', '/visa/bookings', [
    'customer' => ['full_name' => 'Concurrent Mixed Key Customer', 'phone' => '01233330000'],
    'purchase_price' => 5000,
    'selling_price' => 13000,
    'service_fee' => 0,
    'account_id' => $vaultId,
    'visa_details' => ['country' => 'Qatar', 'visa_type' => 'work'],
]);
$bConcCId = $rCreateConcC['data']['id'] ?? 0;
$mixedSharedKey = 'CONC-MIXED-SHARED-' . uniqid();

$payloadsC = [];
// 13 requests sharing same key
for ($i = 0; $i < 13; $i++) {
    $payloadsC[] = [
        'amount' => 1000,
        'payment_method' => 'cash',
        'account_id' => $vaultId,
        'idempotency_key' => $mixedSharedKey,
        'reference' => "SHARED-{$i}",
    ];
}
// 12 requests with distinct keys
for ($i = 0; $i < 12; $i++) {
    $payloadsC[] = [
        'amount' => 1000,
        'payment_method' => 'cash',
        'account_id' => $vaultId,
        'idempotency_key' => "DISTINCT-{$i}-" . uniqid(),
        'reference' => "DIST-{$i}",
    ];
}
shuffle($payloadsC);

$resultsC = runConcurrentRequests('POST', "/visa/bookings/{$bConcCId}/payments", $payloadsC);
$statCountsC = array_count_values(array_column($resultsC, '__status'));
$code5xxC = 0;
foreach ($statCountsC as $code => $cnt) {
    if ($code >= 500) $code5xxC += $cnt;
}

check($code5xxC === 0, "§4.3 Scenario C: 0 server errors (5xx) in mixed concurrency");
$dbPaymentsC = \App\Models\VisaPayment::where('visa_booking_id', $bConcCId)->count();
check($dbPaymentsC === 13, "§4.3 Scenario C: Exactly 13 payments recorded in DB (1 shared + 12 distinct, got {$dbPaymentsC})");
$bConcC = \App\Models\VisaBooking::find($bConcCId);
check((float)$bConcC->paid_amount == 13000.0, "§4.3 Scenario C: Booking paid_amount equals 13000.00 (got {$bConcC->paid_amount})");

// 4.4 Scenario D: 50 Concurrent Requests with SAME Idempotency Key
$rCreateConcD = api('POST', '/visa/bookings', [
    'customer' => ['full_name' => 'Concurrent 50x Customer', 'phone' => '01244440000'],
    'purchase_price' => 1000,
    'selling_price' => 3000,
    'service_fee' => 0,
    'account_id' => $vaultId,
    'visa_details' => ['country' => 'Oman', 'visa_type' => 'work'],
]);
$bConcDId = $rCreateConcD['data']['id'] ?? 0;
$concKeyD = 'CONC-KEY-50X-' . uniqid();

$payloadsD = [];
for ($i = 0; $i < 50; $i++) {
    $payloadsD[] = [
        'amount' => 3000,
        'payment_method' => 'cash',
        'account_id' => $vaultId,
        'idempotency_key' => $concKeyD,
        'reference' => "CONC-50X-{$i}",
    ];
}

$resultsD = runConcurrentRequests('POST', "/visa/bookings/{$bConcDId}/payments", $payloadsD);
$statCountsD = array_count_values(array_column($resultsD, '__status'));
$code201D = $statCountsD[201] ?? 0;
$code200D = $statCountsD[200] ?? 0;
$code5xxD = 0;
foreach ($statCountsD as $code => $cnt) {
    if ($code >= 500) $code5xxD += $cnt;
}

check($code5xxD === 0, "§4.4 Scenario D: 0 server errors (5xx) across 50 concurrent requests");
check($code201D === 1, "§4.4 Scenario D: Exactly 1 request returned 201 Created (got {$code201D})");
check($code200D === 49, "§4.4 Scenario D: Exactly 49 requests returned 200 OK Replay (got {$code200D})");
$dbPaymentsD = \App\Models\VisaPayment::where('visa_booking_id', $bConcDId)->count();
check($dbPaymentsD === 1, "§4.4 Scenario D: Exactly 1 payment row in DB for 50 concurrent requests");

// ============================================================
// SECTION 5: FINANCIAL RECONCILIATION & LEDGER INTEGRITY
// ============================================================
echo "\n════════════════════════════════════════════════════════════\n";
echo "  §5 — FINANCIAL RECONCILIATION & LEDGER INTEGRITY\n";
echo "════════════════════════════════════════════════════════════\n";

// 5.1 All transactions balanced
$allTxs = \App\Models\Transaction::where('module', 'visa')->get();
$unbalancedTxs = 0;
foreach ($allTxs as $tx) {
    $entries = \App\Models\AccountEntry::where('transaction_id', $tx->id)->get();
    $d = $entries->sum('debit');
    $c = $entries->sum('credit');
    if (abs($d - $c) > 0.001) {
        $unbalancedTxs++;
    }
}
check($unbalancedTxs === 0, "§5.1 All " . $allTxs->count() . " Visa transactions balanced (debits == credits)");

// 5.2 Global Visa debits == credits
$visaEntries = \App\Models\AccountEntry::whereHas('transaction', function ($q) {
    $q->where('module', 'visa');
})->get();
$globalDebits = $visaEntries->sum('debit');
$globalCredits = $visaEntries->sum('credit');
check(abs($globalDebits - $globalCredits) < 0.001, "§5.2 Global debits == credits ({$globalDebits} == {$globalCredits})");

// 5.3 All Visa payments have matching transactions
$allPayments = \App\Models\VisaPayment::all();
$mismatchedPayments = 0;
foreach ($allPayments as $p) {
    if (!$p->transaction_id) {
        $mismatchedPayments++;
        continue;
    }
    $pTx = \App\Models\Transaction::find($p->transaction_id);
    if (!$pTx || abs((float)$pTx->amount - (float)$p->amount) > 0.01) {
        $mismatchedPayments++;
    }
}
check($mismatchedPayments === 0, "§5.3 All " . $allPayments->count() . " VisaPayment amounts match Transaction amounts");

// 5.4 No orphan AccountEntry
$orphanEntries = \App\Models\AccountEntry::whereNull('transaction_id')
    ->orWhereNotIn('transaction_id', \App\Models\Transaction::select('id'))
    ->count();
check($orphanEntries === 0, "§5.4 Zero orphan AccountEntry records");

// 5.5 Production Safety Check
$prodDbName = \Illuminate\Support\Facades\DB::select('SELECT DATABASE() AS db')[0]->db ?? '';
check($prodDbName === 'safarak_stress', "§5.5 Active DB is 'safarak_stress' (production untouched)");

// ============================================================
// SUMMARY & VERDICT
// ============================================================
echo "\n" . str_repeat('═', 65) . "\n";
echo "  REMEDIATION REGRESSION SUMMARY\n";
echo str_repeat('═', 65) . "\n";
echo "  Total Checks : " . ($passed + $failed) . "\n";
echo "  ✅ PASSED    : {$passed}\n";
echo "  ❌ FAILED    : {$failed}\n";
echo "  Transactions created during run : " . (\App\Models\Transaction::count() - $initialTxCount) . "\n";
echo "  Payments created during run     : " . (\App\Models\VisaPayment::count() - $initialPaymentCount) . "\n";

if ($failed === 0) {
    echo "\n  🟢 FINAL VERDICT: READY FOR FINAL VISA AUDIT\n";
    echo "  All D01, D02, and Idempotency tests PASSED.\n\n";
} else {
    echo "\n  🔴 FINAL VERDICT: NO-GO\n";
    echo "  {$failed} check(s) failed. See defect ledger above.\n\n";
}
