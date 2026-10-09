<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Payment EGP-only regression — focused subset of §9 + §3 edge cases
 * ════════════════════════════════════════════════════════════════════════════
 *
 * NOT the full 43-test audit. Only the payment-related cases that prove the
 * CP-EGP fix did not break existing legitimate flows:
 *   R-01 EGP booking payment (partial → multiple → final → overpayment rejected)
 *   R-02 Zero/negative payment rejected
 *   R-03 Foreign booking + EGP payment (partial + multiple)
 *
 * Output: storage/logs/flight_payment_egp_regression_results.json
 */
$dbPath = realpath(__DIR__.'/..').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'local_flight_audit.sqlite';
putenv('DB_CONNECTION=sqlite');
putenv("DB_DATABASE=$dbPath");
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $dbPath;
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $dbPath;

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$results = ['started_at' => date('Y-m-d H:i:s'), 'purpose' => 'payment EGP-only regression (subset of §9)', 'tests' => [], 'count_pass' => 0, 'count_fail' => 0];

function rec(array &$r, string $key, bool $ok, array $detail = []): void
{
    $r['tests'][$key] = array_merge(['status' => $ok ? 'PASS' : 'FAIL'], $detail);
    if ($ok) {
        $r['count_pass']++;
        echo "  ✅ PASS $key ".json_encode(array_filter($detail), JSON_UNESCAPED_UNICODE)."\n";
    } else {
        $r['count_fail']++;
        echo "  ❌ FAIL $key ".json_encode(array_filter($detail), JSON_UNESCAPED_UNICODE)."\n";
    }
}

function httpReq(string $method, string $url, ?string $token = null, ?array $body = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http_code' => (int) $code, 'body' => $resp, 'json' => $resp ? json_decode($resp, true) : null];
}

function makeCustomer(string $tag): array
{
    $tag = substr($tag, 0, 50);
    $cust = Customer::create([
        'name' => 'REG-'.$tag, 'full_name' => 'REG-'.$tag,
        'phone' => '+2012'.substr(md5(uniqid($tag, true)), 0, 7),
        'email' => 'reg-'.substr(md5(uniqid('', true)), 0, 5).'@reg.local',
        'module_type' => 'flights', 'status' => 'active',
    ]);
    $acct = Account::create([
        'name' => 'REG-CUST-'.$tag.' '.$cust->id,
        'type' => 'customer', 'currency' => 'EGP', 'balance' => 0,
        'is_active' => 1, 'module_type' => 'flights', 'owner_type' => 'App\\Models\\Customer',
    ]);
    $cust->account_id = $acct->id;
    $cust->save();

    return ['customer' => $cust, 'account' => $acct];
}

echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  Flight Payment Regression (subset of §9)\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

$setupJson = json_decode(file_get_contents(__DIR__.'/../storage/logs/flight_audit_setup.json'), true);
$adminToken = $setupJson['admin_token'];
$employeeToken = $setupJson['employee_token'];
$baseUrl = 'http://127.0.0.1:8080';

$egpCarrier = DB::table('flight_carriers')->where('currency', 'EGP')->whereNull('deleted_at')->first();
$egpCashbox = DB::table('accounts')->where('type', 'cashbox')->where('currency', 'EGP')->whereNull('deleted_at')->first();
$egpCashboxId = $egpCashbox->id;

// Top up EGP carrier
httpReq('POST', "$baseUrl/api/v1/flight/carriers/{$egpCarrier->id}/recharge", $adminToken, [
    'from_account_id' => $egpCashboxId, 'amount' => 50000, 'notes' => 'regression top-up',
]);

function make_booking_egp(string $tag, string $adminToken, string $baseUrl, int $carrierId, int $cashboxId, float $sellingPrice = 5000, float $purchasePrice = 4500): int
{
    $set = makeCustomer($tag);
    $r = httpReq('POST', "$baseUrl/api/v1/flight/bookings", $adminToken, [
        'customer_id' => $set['customer']->id,
        'booking_reference' => 'TX-REG-'.strtoupper(substr(md5(uniqid()), 0, 8)),
        'booking_channel_type' => 'SIGN', 'agent_name' => 'TX-REG',
        'from_airport' => 'CAI', 'to_airport' => 'JED',
        'departure_date' => date('Y-m-d', strtotime('+10 days')),
        'departure_time' => '08:00', 'trip_type' => 'one_way', 'airline' => 'XX',
        'passenger_count' => 1, 'currency' => 'EGP',
        'selling_price' => $sellingPrice, 'purchase_price' => $purchasePrice,
        'flight_carrier_id' => $carrierId,
        'passengers' => [['first_name' => 'A', 'last_name' => 'B']],
    ]);
    if ($r['http_code'] !== 201) {
        throw new RuntimeException("Booking create failed: HTTP {$r['http_code']} body=".substr((string) $r['body'], 0, 200));
    }

    return $r['json']['data']['id'];
}

function pay(int $bookingId, string $adminToken, string $baseUrl, int $cashboxId, float $amount): array
{
    return httpReq('POST', "$baseUrl/api/v1/flight/bookings/$bookingId/payments", $adminToken, [
        'amount' => $amount, 'payment_method' => 'cash', 'account_id' => $cashboxId,
    ]);
}

// R-01 EGP booking: partial → multiple → final → overpayment rejected
echo "── R-01: EGP booking — partial / multiple / final / overpayment ──\n";
$b1 = make_booking_egp('R01', $adminToken, $baseUrl, $egpCarrier->id, $egpCashboxId);
$r1a = pay($b1, $adminToken, $baseUrl, $egpCashboxId, 2000);
$r1b = pay($b1, $adminToken, $baseUrl, $egpCashboxId, 2000);
$r1c = pay($b1, $adminToken, $baseUrl, $egpCashboxId, 1000);
$r1d = pay($b1, $adminToken, $baseUrl, $egpCashboxId, 100);
$ok = ($r1a['http_code'] === 200 || $r1a['http_code'] === 201)
    && ($r1b['http_code'] === 200 || $r1b['http_code'] === 201)
    && ($r1c['http_code'] === 200 || $r1c['http_code'] === 201)
    && $r1d['http_code'] === 422;
rec($results, 'R-01-egp-partial-multiple-final-over', $ok, [
    'partial_1' => $r1a['http_code'],
    'partial_2' => $r1b['http_code'],
    'final' => $r1c['http_code'],
    'over_rejected' => $r1d['http_code'],
]);

// R-02 Zero + negative payment rejected
echo "\n── R-02: Zero + negative payment rejected ──\n";
$b2 = make_booking_egp('R02', $adminToken, $baseUrl, $egpCarrier->id, $egpCashboxId, 5000, 4500);
$r2a = pay($b2, $adminToken, $baseUrl, $egpCashboxId, 0);
$r2b = pay($b2, $adminToken, $baseUrl, $egpCashboxId, -100);
$r2c = httpReq('POST', "$baseUrl/api/v1/flight/bookings/$b2/payments", $adminToken, [
    'amount' => 100, 'payment_method' => 'cash',
]);  // missing account_id
$ok = $r2a['http_code'] === 422
    && $r2b['http_code'] === 422
    && $r2c['http_code'] >= 400;
rec($results, 'R-02-zero-negative-missing-rejected', $ok, [
    'zero' => $r2a['http_code'],
    'negative' => $r2b['http_code'],
    'missing_account' => $r2c['http_code'],
]);

// R-03 Foreign booking + EGP payment (partial + multiple)
echo "\n── R-03: Foreign booking + EGP payment (partial + multiple) ──\n";
$sarCarrier = DB::table('flight_carriers')->where('currency', 'SAR')->whereNull('deleted_at')->first();
httpReq('POST', "$baseUrl/api/v1/flight/carriers/{$sarCarrier->id}/recharge", $adminToken, [
    'from_account_id' => $egpCashboxId, 'amount' => 50000, 'notes' => 'regression top-up SAR',
]);
$set = makeCustomer('R03');
$r = httpReq('POST', "$baseUrl/api/v1/flight/bookings", $adminToken, [
    'customer_id' => $set['customer']->id,
    'booking_reference' => 'TX-REG-R03',
    'booking_channel_type' => 'SIGN', 'agent_name' => 'TX-REG',
    'from_airport' => 'CAI', 'to_airport' => 'RUH',
    'departure_date' => date('Y-m-d', strtotime('+10 days')),
    'departure_time' => '08:00', 'trip_type' => 'one_way', 'airline' => 'SV',
    'passenger_count' => 1, 'currency' => 'SAR',
    'selling_price' => 10000, 'purchase_price' => 9000,
    'selling_price_foreign' => 775.19,
    'flight_carrier_id' => $sarCarrier->id,
    'passengers' => [['first_name' => 'X', 'last_name' => 'Y']],
]);
$b3 = $r['json']['data']['id'] ?? null;
$r3a = pay($b3, $adminToken, $baseUrl, $egpCashboxId, 3000);  // partial in EGP
$r3b = pay($b3, $adminToken, $baseUrl, $egpCashboxId, 2000);  // another partial in EGP
$bookingCurrencyAfter = DB::table('flight_bookings')->where('id', $b3)->value('currency');
$ok = ($r3a['http_code'] === 200 || $r3a['http_code'] === 201)
    && ($r3b['http_code'] === 200 || $r3b['http_code'] === 201)
    && strtoupper((string) $bookingCurrencyAfter) === 'SAR';
rec($results, 'R-03-sar-booking-egp-multiple-payments', $ok, [
    'partial_1' => $r3a['http_code'],
    'partial_2' => $r3b['http_code'],
    'booking_currency_preserved' => $bookingCurrencyAfter,
]);

// R-04 Employee cannot post payment (F-2 admin-only)
echo "\n── R-04: Employee cannot post payment (F-2 admin-only) ──\n";
$b4 = make_booking_egp('R04', $adminToken, $baseUrl, $egpCarrier->id, $egpCashboxId, 5000, 4500);
$r4 = httpReq('POST', "$baseUrl/api/v1/flight/bookings/$b4/payments", $employeeToken, [
    'amount' => 100, 'payment_method' => 'cash', 'account_id' => $egpCashboxId,
]);
rec($results, 'R-04-employee-payment-403', $r4['http_code'] === 403, [
    'http_code' => $r4['http_code'],
]);

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo sprintf("  PAYMENT REGRESSION: %d PASS / %d FAIL\n", $results['count_pass'], $results['count_fail']);
echo "═══════════════════════════════════════════════════════════════════════\n";

$outPath = __DIR__.'/../storage/logs/flight_payment_egp_regression_results.json';
file_put_contents($outPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "  Results saved: storage/logs/flight_payment_egp_regression_results.json\n";
