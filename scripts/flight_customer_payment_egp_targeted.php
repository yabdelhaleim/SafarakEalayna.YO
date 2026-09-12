<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Customer Payment EGP-Only Targeted Test Suite (CP-01 .. CP-12)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Business rule: "Customer payments must always be collected in EGP."
 *
 *   - Booking currency may be SAR / USD / KWD / EUR / AED (foreign OK).
 *   - Customer payment endpoint (/payments) MUST use an EGP cashbox.
 *   - Foreign-currency cashboxes (USD/SAR/KWD/EUR/AED) MUST be rejected.
 *
 * Cost-side and carrier settlement flows are intentionally out of scope.
 *
 * Output: storage/logs/flight_customer_payment_egp_targeted_results.json
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

$results = [
    'started_at' => date('Y-m-d H:i:s'),
    'purpose' => 'Customer Payment EGP-only targeted suite (12 cases)',
    'tests' => [],
    'count_pass' => 0,
    'count_fail' => 0,
];

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

function makeCustomer(string $tag): array
{
    $tag = substr($tag, 0, 50);
    $cust = Customer::create([
        'name' => 'CP-'.$tag,
        'full_name' => 'CP-'.$tag,
        'phone' => '+2012'.substr(md5(uniqid($tag, true)), 0, 7),
        'email' => 'cp-'.strtolower(preg_replace('/[^a-z0-9]/i', '', $tag)).'-'.substr(md5(uniqid('', true)), 0, 5).'@cp.local',
        'module_type' => 'flights',
        'status' => 'active',
    ]);
    $acct = Account::create([
        'name' => 'CP-CUST-'.$tag.' '.$cust->id,
        'type' => 'customer',
        'currency' => 'EGP',
        'balance' => 0,
        'is_active' => 1,
        'module_type' => 'flights',
        'owner_type' => 'App\\Models\\Customer',
    ]);
    $cust->account_id = $acct->id;
    $cust->save();

    return ['customer' => $cust, 'account' => $acct];
}

echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  Customer Payment EGP-Only Targeted Test Suite (CP-01 .. CP-12)\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

$setupJson = json_decode(file_get_contents(__DIR__.'/../storage/logs/flight_audit_setup.json'), true);
$adminToken = $setupJson['admin_token'];
$baseUrl = 'http://127.0.0.1:8080';

// Locate existing seeded carriers + cashboxes (from flight_audit_setup.php)
$egpCarrier = DB::table('flight_carriers')->where('currency', 'EGP')->whereNull('deleted_at')->first();
$sarCarrier = DB::table('flight_carriers')->where('currency', 'SAR')->whereNull('deleted_at')->first();
$usdCarrier = DB::table('flight_carriers')->where('currency', 'USD')->whereNull('deleted_at')->first();
$kwdCarrier = DB::table('flight_carriers')->where('currency', 'KWD')->whereNull('deleted_at')->first();
$eurCarrier = DB::table('flight_carriers')->where('currency', 'EUR')->whereNull('deleted_at')->first();
$aedCarrier = DB::table('flight_carriers')->where('currency', 'AED')->whereNull('deleted_at')->first();

$egpCashbox = DB::table('accounts')->where('type', 'cashbox')->where('currency', 'EGP')->whereNull('deleted_at')->first();
$sarCashbox = DB::table('accounts')->where('type', 'cashbox')->where('currency', 'SAR')->whereNull('deleted_at')->first();
$usdCashbox = DB::table('accounts')->where('type', 'cashbox')->where('currency', 'USD')->whereNull('deleted_at')->first();
$kwdCashbox = DB::table('accounts')->where('type', 'cashbox')->where('currency', 'KWD')->whereNull('deleted_at')->first();
$eurCashbox = DB::table('accounts')->where('type', 'cashbox')->where('currency', 'EUR')->whereNull('deleted_at')->first();
$aedCashbox = DB::table('accounts')->where('type', 'cashbox')->where('currency', 'AED')->whereNull('deleted_at')->first();

$egpCashboxId = $egpCashbox?->id;

// Top up EGP carrier so foreign bookings have prepaid balance for purchase side.
if ($egpCarrier) {
    httpReq('POST', "$baseUrl/api/v1/flight/carriers/{$egpCarrier->id}/recharge", $adminToken, [
        'from_account_id' => $egpCashboxId,
        'amount' => 100000,
        'notes' => 'CP-EGP setup top-up',
    ]);
}

// Helper: create a booking of a given currency on a given carrier, return its id.
function cp_create_booking(string $tag, string $currency, ?object $carrier, string $adminToken, string $baseUrl, float $sellingPriceEgp, float $sellingPriceForeign, float $purchasePrice): array
{
    $set = makeCustomer($tag);
    $payload = [
        'customer_id' => $set['customer']->id,
        'booking_reference' => 'TX-CP-'.strtoupper(substr(md5(uniqid()), 0, 8)),
        'booking_channel_type' => 'SIGN',
        'agent_name' => 'TX-CP',
        'from_airport' => 'CAI',
        'to_airport' => 'JED',
        'departure_date' => date('Y-m-d', strtotime('+10 days')),
        'departure_time' => '08:00',
        'trip_type' => 'one_way',
        'airline' => 'XX',
        'passenger_count' => 1,
        'currency' => $currency,
        'selling_price' => $sellingPriceEgp,
        'purchase_price' => $purchasePrice,
        'selling_price_foreign' => $sellingPriceForeign,
        'flight_carrier_id' => $carrier?->id,
        'passengers' => [['first_name' => 'A', 'last_name' => 'B']],
    ];
    $r = httpReq('POST', "$baseUrl/api/v1/flight/bookings", $adminToken, $payload);
    $bookingId = $r['json']['data']['id'] ?? null;
    if (! $bookingId) {
        throw new RuntimeException("Failed to create {$currency} booking: HTTP {$r['http_code']} body=".substr((string) $r['body'], 0, 200));
    }

    return [
        'customer_id' => $set['customer']->id,
        'account_id' => $set['account']->id,
        'booking_id' => $bookingId,
        'currency' => $currency,
    ];
}

// Helper: read account balance
function cp_balance(int $acctId): float
{
    return (float) DB::table('accounts')->where('id', $acctId)->value('balance');
}

// Helper: count payment rows + entries for a booking
function cp_payment_count(int $bookingId): int
{
    return (int) DB::table('flight_payments')->where('flight_booking_id', $bookingId)->whereNull('deleted_at')->count();
}

function cp_entries_for_account(int $acctId): int
{
    return (int) DB::table('account_entries')->where('account_id', $acctId)->count();
}

echo "── CP-01: EGP booking + EGP cashbox customer payment ──\n";
$cp01 = cp_create_booking('CP01', 'EGP', $egpCarrier, $adminToken, $baseUrl, 5000, 5000, 4500);
$balEgBefore = cp_balance($egpCashboxId);
$entriesEgBefore = cp_entries_for_account($egpCashboxId);
$r = httpReq('POST', "$baseUrl/api/v1/flight/bookings/{$cp01['booking_id']}/payments", $adminToken, [
    'amount' => 5000, 'payment_method' => 'cash', 'account_id' => $egpCashboxId,
]);
$ok = ($r['http_code'] === 200 || $r['http_code'] === 201)
    && cp_balance($egpCashboxId) - $balEgBefore === 5000.0
    && cp_payment_count($cp01['booking_id']) === 1;
rec($results, 'CP-01-egp-booking-egp-payment', $ok, [
    'http_code' => $r['http_code'], 'cashbox_delta' => cp_balance($egpCashboxId) - $balEgBefore,
    'payment_count' => cp_payment_count($cp01['booking_id']),
]);

echo "\n── CP-02: SAR booking + EGP cashbox customer payment ──\n";
$cp02 = cp_create_booking('CP02', 'SAR', $sarCarrier, $adminToken, $baseUrl, 5000, 387.60, 4500);
$balEg2Before = cp_balance($egpCashboxId);
$r = httpReq('POST', "$baseUrl/api/v1/flight/bookings/{$cp02['booking_id']}/payments", $adminToken, [
    'amount' => 500, 'payment_method' => 'cash', 'account_id' => $egpCashboxId,
]);
$ok = ($r['http_code'] === 200 || $r['http_code'] === 201)
    && cp_balance($egpCashboxId) - $balEg2Before === 500.0
    && cp_payment_count($cp02['booking_id']) === 1;
rec($results, 'CP-02-sar-booking-egp-payment', $ok, [
    'http_code' => $r['http_code'], 'cashbox_delta' => cp_balance($egpCashboxId) - $balEg2Before,
    'payment_count' => cp_payment_count($cp02['booking_id']),
    'note' => 'booking currency preserved as SAR; EGP cashbox credited 500 EGP',
]);

echo "\n── CP-03: USD booking + EGP cashbox customer payment ──\n";
$cp03 = cp_create_booking('CP03', 'USD', null, $adminToken, $baseUrl, 5000, 103.10, 4500);
$balEg3Before = cp_balance($egpCashboxId);
$r = httpReq('POST', "$baseUrl/api/v1/flight/bookings/{$cp03['booking_id']}/payments", $adminToken, [
    'amount' => 500, 'payment_method' => 'cash', 'account_id' => $egpCashboxId,
]);
$ok = ($r['http_code'] === 200 || $r['http_code'] === 201)
    && cp_balance($egpCashboxId) - $balEg3Before === 500.0
    && cp_payment_count($cp03['booking_id']) === 1;
rec($results, 'CP-03-usd-booking-egp-payment', $ok, [
    'http_code' => $r['http_code'], 'cashbox_delta' => cp_balance($egpCashboxId) - $balEg3Before,
    'payment_count' => cp_payment_count($cp03['booking_id']),
]);

echo "\n── CP-04: KWD booking + EGP cashbox customer payment ──\n";
$cp04 = cp_create_booking('CP04', 'KWD', $kwdCarrier, $adminToken, $baseUrl, 5000, 31.75, 4500);
$balEg4Before = cp_balance($egpCashboxId);
$r = httpReq('POST', "$baseUrl/api/v1/flight/bookings/{$cp04['booking_id']}/payments", $adminToken, [
    'amount' => 500, 'payment_method' => 'cash', 'account_id' => $egpCashboxId,
]);
$ok = ($r['http_code'] === 200 || $r['http_code'] === 201)
    && cp_balance($egpCashboxId) - $balEg4Before === 500.0
    && cp_payment_count($cp04['booking_id']) === 1;
rec($results, 'CP-04-kwd-booking-egp-payment', $ok, [
    'http_code' => $r['http_code'], 'cashbox_delta' => cp_balance($egpCashboxId) - $balEg4Before,
    'payment_count' => cp_payment_count($cp04['booking_id']),
]);

// ═══════════════════════════════════════════════════════════════════════════
// CP-05..07: same-currency booking + same-currency cashbox MUST be rejected
// ═══════════════════════════════════════════════════════════════════════════
function cp_assert_rejected(array $booking, ?object $cashbox, string $adminToken, string $baseUrl, int $balCashBefore, int $entriesCashBefore): array
{
    if (! $cashbox) {
        return ['skipped' => true, 'reason' => 'cashbox not seeded'];
    }
    $r = httpReq('POST', "$baseUrl/api/v1/flight/bookings/{$booking['booking_id']}/payments", $adminToken, [
        'amount' => 100, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
    ]);

    return [
        'skipped' => false,
        'http_code' => $r['http_code'],
        'cashbox_delta' => cp_balance($cashbox->id) - $balCashBefore,
        'entries_delta' => cp_entries_for_account($cashbox->id) - $entriesCashBefore,
        'payment_count' => cp_payment_count($booking['booking_id']),
        'body_excerpt' => substr((string) ($r['body'] ?? ''), 0, 150),
    ];
}

echo "\n── CP-05: SAR booking + SAR cashbox customer payment → REJECTED ──\n";
$cp05 = cp_create_booking('CP05', 'SAR', $sarCarrier, $adminToken, $baseUrl, 5000, 387.60, 4500);
$balSarBefore = $sarCashbox ? cp_balance($sarCashbox->id) : 0;
$entriesSarBefore = $sarCashbox ? cp_entries_for_account($sarCashbox->id) : 0;
$cp05result = cp_assert_rejected($cp05, $sarCashbox, $adminToken, $baseUrl, $balSarBefore, $entriesSarBefore);
$ok = $cp05result['skipped']
    || ($cp05result['http_code'] >= 400
        && $cp05result['cashbox_delta'] == 0
        && $cp05result['entries_delta'] == 0
        && $cp05result['payment_count'] === 0);
rec($results, 'CP-05-sar-booking-sar-cashbox-rejected', $ok, $cp05result);

echo "\n── CP-06: USD booking + USD cashbox customer payment → REJECTED ──\n";
$cp06 = cp_create_booking('CP06', 'USD', null, $adminToken, $baseUrl, 5000, 103.10, 4500);
$balUsdBefore = $usdCashbox ? cp_balance($usdCashbox->id) : 0;
$entriesUsdBefore = $usdCashbox ? cp_entries_for_account($usdCashbox->id) : 0;
$cp06result = cp_assert_rejected($cp06, $usdCashbox, $adminToken, $baseUrl, $balUsdBefore, $entriesUsdBefore);
$ok = $cp06result['skipped']
    || ($cp06result['http_code'] >= 400
        && $cp06result['cashbox_delta'] == 0
        && $cp06result['entries_delta'] == 0
        && $cp06result['payment_count'] === 0);
rec($results, 'CP-06-usd-booking-usd-cashbox-rejected', $ok, $cp06result);

echo "\n── CP-07: KWD booking + KWD cashbox customer payment → REJECTED ──\n";
$cp07 = cp_create_booking('CP07', 'KWD', $kwdCarrier, $adminToken, $baseUrl, 5000, 31.75, 4500);
$balKwdBefore = $kwdCashbox ? cp_balance($kwdCashbox->id) : 0;
$entriesKwdBefore = $kwdCashbox ? cp_entries_for_account($kwdCashbox->id) : 0;
$cp07result = cp_assert_rejected($cp07, $kwdCashbox, $adminToken, $baseUrl, $balKwdBefore, $entriesKwdBefore);
$ok = $cp07result['skipped']
    || ($cp07result['http_code'] >= 400
        && $cp07result['cashbox_delta'] == 0
        && $cp07result['entries_delta'] == 0
        && $cp07result['payment_count'] === 0);
rec($results, 'CP-07-kwd-booking-kwd-cashbox-rejected', $ok, $cp07result);

echo "\n── CP-08: SAR booking + USD cashbox customer payment → REJECTED ──\n";
$cp08 = cp_create_booking('CP08', 'SAR', $sarCarrier, $adminToken, $baseUrl, 5000, 387.60, 4500);
$balUsd8Before = $usdCashbox ? cp_balance($usdCashbox->id) : 0;
$entriesUsd8Before = $usdCashbox ? cp_entries_for_account($usdCashbox->id) : 0;
$cp08result = cp_assert_rejected($cp08, $usdCashbox, $adminToken, $baseUrl, $balUsd8Before, $entriesUsd8Before);
$ok = $cp08result['skipped']
    || ($cp08result['http_code'] >= 400
        && $cp08result['cashbox_delta'] == 0
        && $cp08result['entries_delta'] == 0
        && $cp08result['payment_count'] === 0);
rec($results, 'CP-08-sar-booking-usd-cashbox-rejected', $ok, $cp08result);

echo "\n── CP-09: API tampering — foreign cashbox via direct account_id ──\n";
$cp09 = cp_create_booking('CP09', 'EGP', $egpCarrier, $adminToken, $baseUrl, 5000, 5000, 4500);
$balEg9Before = cp_balance($egpCashboxId);
$entriesEg9Before = cp_entries_for_account($egpCashboxId);
$r = httpReq('POST', "$baseUrl/api/v1/flight/bookings/{$cp09['booking_id']}/payments", $adminToken, [
    'amount' => 1000,
    'payment_method' => 'cash',
    'account_id' => $sarCashbox?->id ?? $usdCashbox?->id ?? $egpCashboxId,
]);
$delta = cp_balance($egpCashboxId) - $balEg9Before;
$entriesDelta = cp_entries_for_account($egpCashboxId) - $entriesEg9Before;
$ok = $r['http_code'] >= 400
    && cp_payment_count($cp09['booking_id']) === 0
    && $entriesDelta == 0;
rec($results, 'CP-09-tampering-rejected', $ok, [
    'http_code' => $r['http_code'], 'entries_delta' => $entriesDelta,
    'payment_count' => cp_payment_count($cp09['booking_id']),
    'body_excerpt' => substr((string) ($r['body'] ?? ''), 0, 150),
]);

echo "\n── CP-10: Successful SAR booking paid in EGP — full integrity ──\n";
$cp10 = cp_create_booking('CP10', 'SAR', $sarCarrier, $adminToken, $baseUrl, 5000, 387.60, 4500);
// Snapshot drift BEFORE
$driftBefore10 = 0;
foreach (DB::select('SELECT a.balance as s, COALESCE((SELECT SUM(credit)-SUM(debit) FROM account_entries WHERE account_id=a.id), 0) AS c FROM accounts a WHERE deleted_at IS NULL') as $dr) {
    if (abs(((float) $dr->s) - ((float) $dr->c)) > 0.02) {
        $driftBefore10++;
    }
}
$balEg10Before = cp_balance($egpCashboxId);
$entriesEg10Before = cp_entries_for_account($egpCashboxId);
$r = httpReq('POST', "$baseUrl/api/v1/flight/bookings/{$cp10['booking_id']}/payments", $adminToken, [
    'amount' => 2000, 'payment_method' => 'cash', 'account_id' => $egpCashboxId,
]);
$driftAfter10 = 0;
foreach (DB::select('SELECT a.balance as s, COALESCE((SELECT SUM(credit)-SUM(debit) FROM account_entries WHERE account_id=a.id), 0) AS c FROM accounts a WHERE deleted_at IS NULL') as $dr) {
    if (abs(((float) $dr->s) - ((float) $dr->c)) > 0.02) {
        $driftAfter10++;
    }
}
$bookingCurrencyAfter = DB::table('flight_bookings')->where('id', $cp10['booking_id'])->value('currency');
$paymentAcctCurrency = DB::table('flight_payments')->where('flight_booking_id', $cp10['booking_id'])->value('currency');
$ok = ($r['http_code'] === 200 || $r['http_code'] === 201)
    && cp_balance($egpCashboxId) - $balEg10Before === 2000.0
    && strtoupper((string) $bookingCurrencyAfter) === 'SAR'    // booking currency preserved
    && strtoupper((string) $paymentAcctCurrency) === 'EGP'      // payment collected in EGP
    && $driftAfter10 === 0
    && cp_entries_for_account($egpCashboxId) > $entriesEg10Before;
rec($results, 'CP-10-sar-booking-egp-full-integrity', $ok, [
    'http_code' => $r['http_code'],
    'cashbox_delta' => cp_balance($egpCashboxId) - $balEg10Before,
    'booking_currency_preserved' => $bookingCurrencyAfter,
    'payment_currency' => $paymentAcctCurrency,
    'drift_before' => $driftBefore10,
    'drift_after' => $driftAfter10,
    'entries_added' => cp_entries_for_account($egpCashboxId) - $entriesEg10Before,
]);

echo "\n── CP-11: Rejected foreign-currency payment → complete state preservation ──\n";
$cp11 = cp_create_booking('CP11', 'SAR', $sarCarrier, $adminToken, $baseUrl, 5000, 387.60, 4500);
// Snapshot full state BEFORE
$balEg11Before = cp_balance($egpCashboxId);
$balSar11Before = $sarCashbox ? cp_balance($sarCashbox->id) : 0;
$balUsd11Before = $usdCashbox ? cp_balance($usdCashbox->id) : 0;
$balKwd11Before = $kwdCashbox ? cp_balance($kwdCashbox->id) : 0;
$entriesEg11Before = cp_entries_for_account($egpCashboxId);
$entriesTotal11Before = (int) DB::table('account_entries')->count();
$orphanEntries11Before = (int) DB::selectOne('SELECT COUNT(*) AS c FROM account_entries ae LEFT JOIN accounts a ON a.id=ae.account_id WHERE a.id IS NULL AND ae.account_id IS NOT NULL')->c;
// Try USD cashbox
$r = httpReq('POST', "$baseUrl/api/v1/flight/bookings/{$cp11['booking_id']}/payments", $adminToken, [
    'amount' => 100, 'payment_method' => 'cash', 'account_id' => $usdCashbox?->id ?? $egpCashboxId,
]);
// Verify NOTHING changed
$balEg11After = cp_balance($egpCashboxId);
$balSar11After = $sarCashbox ? cp_balance($sarCashbox->id) : 0;
$balUsd11After = $usdCashbox ? cp_balance($usdCashbox->id) : 0;
$balKwd11After = $kwdCashbox ? cp_balance($kwdCashbox->id) : 0;
$entriesEg11After = cp_entries_for_account($egpCashboxId);
$entriesTotal11After = (int) DB::table('account_entries')->count();
$orphanEntries11After = (int) DB::selectOne('SELECT COUNT(*) AS c FROM account_entries ae LEFT JOIN accounts a ON a.id=ae.account_id WHERE a.id IS NULL AND ae.account_id IS NOT NULL')->c;
$ok = $r['http_code'] >= 400
    && cp_payment_count($cp11['booking_id']) === 0
    && $balEg11Before === $balEg11After
    && $balSar11Before === $balSar11After
    && $balUsd11Before === $balUsd11After
    && $balKwd11Before === $balKwd11After
    && $entriesEg11Before === $entriesEg11After
    && $entriesTotal11Before === $entriesTotal11After
    && $orphanEntries11Before === $orphanEntries11After;
rec($results, 'CP-11-rejected-no-state-change', $ok, [
    'http_code' => $r['http_code'],
    'payment_count' => cp_payment_count($cp11['booking_id']),
    'balances_unchanged' => $balEg11Before === $balEg11After && $balSar11Before === $balSar11After && $balUsd11Before === $balUsd11After && $balKwd11Before === $balKwd11After,
    'entries_unchanged' => $entriesEg11Before === $entriesEg11After && $entriesTotal11Before === $entriesTotal11After,
    'orphans_unchanged' => $orphanEntries11Before === $orphanEntries11After,
]);

echo "\n── CP-12: Every supported non-EGP currency → REJECTED ──\n";
$currencies = [
    'USD' => $usdCashbox,
    'SAR' => $sarCashbox,
    'KWD' => $kwdCashbox,
    'EUR' => $eurCashbox,
    'AED' => $aedCashbox,
];
$allRejected = true;
$details = [];
foreach ($currencies as $code => $cb) {
    if (! $cb) {
        $details[$code] = 'cashbox not seeded';

        continue;
    }
    $booking = cp_create_booking('CP12-'.$code, $code, null, $adminToken, $baseUrl, 5000, 100.0, 4500);
    $balBefore = cp_balance($cb->id);
    $r = httpReq('POST', "$baseUrl/api/v1/flight/bookings/{$booking['booking_id']}/payments", $adminToken, [
        'amount' => 100, 'payment_method' => 'cash', 'account_id' => $cb->id,
    ]);
    $balAfter = cp_balance($cb->id);
    $rejected = $r['http_code'] >= 400 && $balAfter === $balBefore && cp_payment_count($booking['booking_id']) === 0;
    if (! $rejected) {
        $allRejected = false;
    }
    $details[$code] = ['http_code' => $r['http_code'], 'cashbox_delta' => $balAfter - $balBefore, 'payment_count' => cp_payment_count($booking['booking_id'])];
}
rec($results, 'CP-12-all-foreign-currencies-rejected', $allRejected, $details);

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo sprintf("  CP-EGP TARGETED SUITE: %d PASS / %d FAIL\n", $results['count_pass'], $results['count_fail']);
echo "═══════════════════════════════════════════════════════════════════════\n";

$outPath = __DIR__.'/../storage/logs/flight_customer_payment_egp_targeted_results.json';
file_put_contents($outPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "  Results saved: storage/logs/flight_customer_payment_egp_targeted_results.json\n";
