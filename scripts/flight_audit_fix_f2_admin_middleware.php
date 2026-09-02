<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — F-2 Regression Test (admin middleware on write routes)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Run after applying F-2 fix: routes/api.php wraps Flight write endpoints
 * (POST/PUT/PATCH/DELETE) in `Route::middleware('admin')`.
 *
 * Tests:
 *   T-MW-1: admin POST /api/v1/flight/bookings → 201
 *   T-MW-2: employee POST /api/v1/flight/bookings → 403
 *   T-MW-3: employee GET /api/v1/flight/bookings → 200
 *   T-MW-4: employee POST /api/v1/flight/bookings/{id}/cancel → 403
 *   T-MW-5: admin POST /api/v1/flight/carriers/{id}/recharge → 200
 *   T-MW-6: employee POST /api/v1/flight/refunds/ → 403
 *   T-MW-7: admin POST /api/v1/flight/refunds/ → 422 (no payload) or other validation error
 *   T-MW-8: employee POST /api/v1/flight/modifications/ → 403
 *
 * Output: storage/logs/flight_audit_fix_f2_results.json
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

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$results = [
    'started_at' => date('Y-m-d H:i:s'),
    'tests' => [],
    'count_pass' => 0,
    'count_fail' => 0,
];

function rec(array &$r, string $key, bool $ok, array $detail = []): void
{
    $r['tests'][$key] = array_merge(['status' => $ok ? 'PASS' : 'FAIL'], $detail);
    if ($ok) {
        $r['count_pass']++;
    } else {
        $r['count_fail']++;
    }
    echo ($ok ? '  ✅ PASS ' : '  ❌ FAIL ')."$key: ".json_encode($detail, JSON_UNESCAPED_UNICODE)."\n";
}

echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  F-2 Regression Test — admin middleware on write routes\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

// Restart the dev server so it picks up the new routes. Use `nohup` with
// the env vars explicitly in the command line — the previous `start /B`
// approach failed to propagate `DB_DATABASE` into the cmd subshell on
// Windows Git Bash (cygpath returned empty path), causing the dev server
// to start with an empty DB and reject every Sanctum token with 401.
echo "  ℹ  Restarting dev server (nohup + explicit env vars)...\n";
shell_exec('taskkill //F //IM "php.exe" //FI "MEMUSAGE gt 30000" 2>&1 | head -1');
sleep(2);
$projectRoot = realpath(__DIR__.'/..');
$cmd = sprintf(
    'cd %s && DB_CONNECTION=sqlite DB_DATABASE=%s nohup php artisan serve --port=8080 --host=127.0.0.1 > %s 2>&1 &',
    escapeshellarg($projectRoot),
    escapeshellarg($dbPath),
    escapeshellarg($projectRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'dev_server.log')
);
shell_exec($cmd);
sleep(4);

$baseUrl = 'http://127.0.0.1:8080';
$setup = json_decode(file_get_contents(__DIR__.'/../storage/logs/flight_audit_setup.json'), true);
$adminToken = $setup['admin_token'];
$employeeToken = $setup['employee_token'];
// F-2 test-fix (2026-08-14): resolve a live customer_id at runtime —
// setup.json's customer_id can be stale if the DB has been migrated/seeded
// between audit runs (F-5 recreation deletes test rows that shift AUTOINCREMENT).
$customerIdCandidate = $setup['customer_id'] ?? null;
if (! $customerIdCandidate || ! DB::table('customers')->where('id', $customerIdCandidate)->exists()) {
    $customerId = DB::table('customers')->where('module_type', 'flights')->orderBy('id')->value('id')
        ?? DB::table('customers')->orderBy('id')->value('id');
    echo '  ℹ  setup.json customer_id='.($customerIdCandidate ?? 'null')." stale; using live customer_id=$customerId\n";
} else {
    $customerId = $customerIdCandidate;
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
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http_code' => (int) $code, 'body' => $resp, 'json' => $resp ? json_decode($resp, true) : null];
}

// Sanity: server is up
$health = httpReq('GET', "$baseUrl/api/v1/health", $adminToken);
rec($results, 'T-MW-server-up', $health['http_code'] === 200 || $health['http_code'] === 401, ['http_code' => $health['http_code']]);

// T-MW-3: employee GET (read-only) → 200
$r = httpReq('GET', "$baseUrl/api/v1/flight/bookings?per_page=2", $employeeToken);
rec($results, 'T-MW-3-employee-get-ok', $r['http_code'] === 200, ['http_code' => $r['http_code']]);

// T-MW-2: employee POST booking → 403
$payload = [
    'customer_id' => $customerId,
    'booking_channel_type' => 'manual',
    'booking_channel_provider' => 'F2-Audit',
    'agent_name' => 'F2',
    'from_airport' => 'CAI', 'to_airport' => 'DXB',
    'departure_date' => date('Y-m-d', strtotime('+10 days')),
    'departure_time' => '10:00', 'trip_type' => 'one_way', 'airline' => 'EK',
    'passengers_count' => 1, 'currency' => 'EGP',
    'selling_price' => 1000, 'purchase_price' => 800,
    'passengers' => [['first_name' => 'F', 'last_name' => 'A']],
];
$r = httpReq('POST', "$baseUrl/api/v1/flight/bookings", $employeeToken, $payload);
rec($results, 'T-MW-2-employee-post-403', $r['http_code'] === 403, ['http_code' => $r['http_code'], 'body_excerpt' => substr((string) ($r['body'] ?? ''), 0, 150)]);

// T-MW-1: admin POST booking → 201
$r = httpReq('POST', "$baseUrl/api/v1/flight/bookings", $adminToken, $payload);
$adminBookingOk = $r['http_code'] === 201 && isset($r['json']['data']['id']);
rec($results, 'T-MW-1-admin-post-201', $adminBookingOk, ['http_code' => $r['http_code'], 'id' => $r['json']['data']['id'] ?? null]);
$bookingId = $r['json']['data']['id'] ?? null;
if ($bookingId) {
    // T-MW-4: employee POST cancel → 403
    $r = httpReq('POST', "$baseUrl/api/v1/flight/bookings/$bookingId/cancel", $employeeToken, ['airline_penalty' => 100, 'office_penalty' => 50, 'notes' => 'emp']);
    rec($results, 'T-MW-4-employee-cancel-403', $r['http_code'] === 403, ['http_code' => $r['http_code']]);
}

// T-MW-5: admin POST carrier recharge → 200 (use EGP carrier from setup)
$carrierId = $setup['flight_carrier_ids']['EGP'] ?? null;
$fromAccountId = $setup['cashbox_account_ids']['EGP'] ?? null;
if ($carrierId && $fromAccountId) {
    $r = httpReq('POST', "$baseUrl/api/v1/flight/carriers/$carrierId/recharge", $adminToken, [
        'from_account_id' => $fromAccountId,
        'amount' => 100,
        'notes' => 'F2 audit',
    ]);
    rec($results, 'T-MW-5-admin-recharge', $r['http_code'] === 200, ['http_code' => $r['http_code']]);
}

// T-MW-6: employee POST refund → 403
$r = httpReq('POST', "$baseUrl/api/v1/flight/refunds/", $employeeToken, [
    'airline_penalty' => 100, 'office_penalty' => 50,
]);
rec($results, 'T-MW-6-employee-refund-403', $r['http_code'] === 403, ['http_code' => $r['http_code']]);

// T-MW-7: admin POST refund → 422 (validation: missing fields) — confirms admin can reach endpoint
$r = httpReq('POST', "$baseUrl/api/v1/flight/refunds/", $adminToken, []);
rec($results, 'T-MW-7-admin-reaches-refund', $r['http_code'] === 422, ['http_code' => $r['http_code']]);

// T-MW-8: employee POST modification → 403
$r = httpReq('POST', "$baseUrl/api/v1/flight/modifications/", $employeeToken, [
    'flight_booking_id' => $bookingId ?? 1,
    'modification_type' => 'name_change',
]);
rec($results, 'T-MW-8-employee-mod-403', $r['http_code'] === 403, ['http_code' => $r['http_code']]);

// T-MW-9: Financial integrity unchanged after F-2 changes
$negLiquidity = DB::table('accounts')->whereIn('type', ['cashbox', 'bank', 'wallet'])->where('balance', '<', 0)->count();
rec($results, 'T-MW-9-no-negative-liquidity', $negLiquidity === 0, ['negative_liquidity_count' => $negLiquidity]);

// T-MW-10: No duplicate booking_references introduced
$dup = DB::table('flight_bookings')->select('booking_reference', DB::raw('count(*) as cnt'))
    ->groupBy('booking_reference')->having('cnt', '>', 1)->get();
rec($results, 'T-MW-10-no-dup-refs', $dup->count() === 0, ['duplicate_groups' => $dup->count()]);

$results['finished_at'] = date('Y-m-d H:i:s');
$results['verdict'] = $results['count_fail'] === 0 ? 'PASS' : 'FAIL';

file_put_contents(__DIR__.'/../storage/logs/flight_audit_fix_f2_results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo '  F-2 Regression: '.$results['count_pass'].' PASS / '.$results['count_fail']." FAIL\n";
echo '  Verdict: '.$results['verdict']."\n";
echo "═══════════════════════════════════════════════════════════════════════\n";
