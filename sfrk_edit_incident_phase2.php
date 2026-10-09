<?php
/**
 * PHASE 2 — Multi-scenario edit-after-save defect verification
 * Date: 2026-08-18
 * Target DB: sfrk_edit_incident_20260818 (isolated)
 * Server:   http://127.0.0.1:8123 (artisan serve, isolated DB)
 *
 * Scenarios:
 *   A. Edit 50→30→50       (reverse direction)
 *   B. Edit 50→50          (no-change)
 *   C. Edit 50→30→40→25→25 (multi-edit drift)
 *   D. Edit before partial payment (create 50, pay 20, edit 50→30)
 *   E. Edit after  full payment (create 50, pay 50, edit 50→30)
 *   F. Create→edit→cancel  (create 50, edit 50→30, then cancel)
 *
 * For every scenario the script:
 *   1. Wipes fixtures.
 *   2. Re-creates fresh fixtures.
 *   3. Authenticates (POST /api/v1/auth/login) to get token.
 *   4. Drives the flow through real HTTP PUT /api/v1/flight/bookings/{id}.
 *   5. Snapshots AR balance, carrier.balance, prepaid_pool.balance, tx counts.
 *   6. Prints expected-vs-actual verdict.
 */

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\FlightSystemType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use Illuminate\Support\Facades\DB;

// ============================================================================
// BOOT LARAVEL
// ============================================================================
$_ENV['DB_DATABASE'] = 'sfrk_edit_incident_20260818';
$_SERVER['DB_DATABASE'] = 'sfrk_edit_incident_20260818';
putenv('DB_DATABASE=sfrk_edit_incident_20260818');

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['database.connections.mysql.database' => 'sfrk_edit_incident_20260818']);
config(['app.url' => 'http://127.0.0.1:8123']);
DB::purge('mysql');
DB::reconnect('mysql');

// ============================================================================
// HELPERS
// ============================================================================
function h1(string $s): void { echo "\n\e[1;36m=== ".strtoupper($s)." ===\e[0m\n"; }
function h2(string $s): void { echo "\n\e[1;33m── $s ──\e[0m\n"; }
function row(string $k, $v): void {
    $v = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
    echo str_pad($k, 38)." : $v\n";
}
function call(string $method, string $url, array $payload, ?string $token = null): array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) $headers[] = "Authorization: Bearer $token";
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => json_decode($resp, true) ?? $resp, 'raw' => $resp];
}

function wipe(): void {
    DB::table('account_entries')->delete();
    DB::table('transactions')->delete();
    DB::table('flight_payments')->delete();
    DB::table('flight_refunds')->delete();
    DB::table('flight_tickets')->delete();
    DB::table('flight_segments')->delete();
    DB::table('passengers')->delete();
    DB::table('flight_bookings')->delete();
    DB::table('flight_carriers')->delete();
    DB::table('flight_systems')->delete();
    DB::table('flight_groups')->delete();
    DB::table('flight_group_transactions')->delete();
    DB::table('flight_system_transactions')->delete();
    DB::table('audit_logs')->delete();
    DB::table('personal_access_tokens')->where('name', 'p2-token')->delete();
    DB::table('accounts')->whereIn('owner_type', ['owner', 'office', 'customer'])->delete();
    DB::table('customers')->delete();
    // Admin user is intentionally kept so the Sanctum token stays valid
    // across scenarios (avoids the throttle:auth 5/min limit).
}

function fixtures(): array {
    $admin = User::firstOrCreate(
        ['email' => 'p2-admin@test.com'],
        ['name' => 'Phase2 Admin', 'password' => bcrypt('p2-password'),
         'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()],
    );
    $cashbox = Account::create([
        'name' => 'P2 Cashbox', 'type' => AccountType::Cashbox->value,
        'currency' => 'EGP', 'balance' => 100000, 'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER, 'module_type' => 'office',
        'created_by' => $admin->id,
    ]);
    Account::create([
        'name' => 'إقفال تكاليف الطيران',
        'type' => AccountType::Cashbox->value,
        'currency' => 'EGP', 'balance' => 0,
        'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'tourism', 'created_by' => $admin->id,
    ]);
    $prepaid = Account::create([
        'name' => 'رصيد مسبق — ناقلو الطيران',
        'type' => AccountType::Cashbox->value,
        'currency' => 'EGP', 'balance' => 100000,
        'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'office', 'created_by' => $admin->id,
    ]);
    $customer = Customer::create([
        'full_name' => 'P2 Customer ' . substr(uniqid(), -5),
        'phone' => 'P2' . substr(uniqid(), -8),
        'national_id' => '2'.substr(uniqid(), -9), 'type' => 'individual', 'is_active' => true,
        'created_by' => $admin->id,
    ]);
    $uniq = substr(uniqid(), -6);
    $carrier = FlightCarrier::create([
        'name' => 'P2 Carrier', 'code' => 'P2C-'.$uniq,
        'currency' => 'EGP', 'available_balance' => 10000,
        'is_active' => true, 'created_by' => $admin->id,
    ]);
    DB::table('flight_carriers')->where('id', $carrier->id)->update(['balance' => 10000]);
    $carrier->refresh();
    $system = FlightSystem::create([
        'name' => 'P2 System', 'code' => 'P2S-'.$uniq,
        'currency' => 'EGP', 'available_balance' => 10000,
        'is_active' => true, 'created_by' => $admin->id,
    ]);
    DB::table('flight_systems')->where('id', $system->id)->update(['balance' => 10000]);
    $system->refresh();
    return compact('admin', 'cashbox', 'prepaid', 'customer', 'carrier', 'system');
}

function login(): string {
    $r = call('POST', 'http://127.0.0.1:8123/api/v1/auth/login', [
        'email' => 'p2-admin@test.com', 'password' => 'p2-password',
    ]);
    if ($r['status'] !== 200) throw new RuntimeException('login failed: '.$r['raw']);
    return $r['body']['data']['token'] ?? $r['body']['token'];
}

function createBooking(array $f, int $selling, int $cost): FlightBooking {
    \Illuminate\Support\Facades\Auth::login($f['admin']);
    $data = [
        'customer_id' => $f['customer']->id,
        'booking_channel_type' => 'SIGN',
        'booking_channel_provider' => 'Office',
        'system_type' => FlightSystemType::Manual->value,
        'airline_name' => 'EgyptAir',
        'pnr' => 'P2PNR',
        'from_airport' => 'CAI',
        'to_airport' => 'AMM',
        'departure_date' => now()->addDays(7)->toDateString(),
        'departure_time' => '10:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'passengers_count' => 1,
        'passengers' => [['first_name' => 'Test', 'last_name' => 'P2Traveler', 'type' => 'adult']],
        'selling_price' => $selling,
        'purchase_price' => $cost,
        'currency' => 'EGP',
        'flight_carrier_id' => $f['carrier']->id,
        'flight_system_id' => $f['system']->id,
        'purchase_balance_source' => 'carrier',
        'account_id' => $f['cashbox']->id,
        'agent_name' => 'P2 Test',
        'notes' => 'P2_REPRO',
        'baggage_allowance_kg' => 0,
    ];
    $b = app(FlightBookingService::class)->createBooking($data);
    \Illuminate\Support\Facades\Auth::logout();
    return $b;
}

function editBooking(string $token, int $bookingId, array $f, int $newSelling, int $newCost): array {
    $payload = [
        'customer_id' => $f['customer']->id,
        'selling_price' => $newSelling,
        'purchase_price' => $newCost,
        'currency' => 'EGP',
        'flight_carrier_id' => $f['carrier']->id,
        'flight_system_id' => $f['system']->id,
        'purchase_balance_source' => 'carrier',
        'account_id' => $f['cashbox']->id,
        'airline_name' => 'EgyptAir',
        'pnr' => 'P2PNR',
        'from_airport' => 'CAI',
        'to_airport' => 'AMM',
        'departure_date' => now()->addDays(7)->toDateString(),
        'departure_time' => '10:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'passengers_count' => 1,
        'baggage_allowance_kg' => 0,
        'passengers' => [['first_name' => 'Test', 'last_name' => 'P2Traveler', 'type' => 'adult']],
        'notes' => 'P2_EDIT',
        'agent_name' => 'P2 Test',
    ];
    return call('PUT', "http://127.0.0.1:8123/api/v1/flight/bookings/{$bookingId}", $payload, $token);
}

function snapshot(int $bookingId): array {
    $b = FlightBooking::with('customer.ledgerAccount')->find($bookingId);
    $custAcct = $b->customer?->ledgerAccount?->id;
    $custBal = 0.0;
    if ($custAcct) {
        $c = (float) AccountEntry::where('account_id', $custAcct)->sum('credit');
        $d = (float) AccountEntry::where('account_id', $custAcct)->sum('debit');
        $custBal = $c - $d;
    }
    $prepaid = Account::where('name', 'رصيد مسبق — ناقلو الطيران')->first();
    $prepaidBal = 0.0;
    if ($prepaid) {
        $c = (float) AccountEntry::where('account_id', $prepaid->id)->sum('credit');
        $d = (float) AccountEntry::where('account_id', $prepaid->id)->sum('debit');
        $prepaidBal = $c - $d;
    }
    $txCount = (int) Transaction::where('related_type', FlightBooking::class)
        ->where('related_id', $bookingId)->count();
    return [
        'selling' => (float) $b->selling_price,
        'purchase' => (float) $b->purchase_price,
        'profit' => (float) $b->profit,
        'customer_gl' => $custBal,
        'carrier_balance' => (float) FlightCarrier::find($b->flight_carrier_id)?->balance,
        'prepaid_gl' => $prepaidBal,
        'tx_count' => $txCount,
    ];
}

function snapRow(array $s): void {
    row('selling_price (col)',    $s['selling']);
    row('purchase_price (col)',   $s['purchase']);
    row('profit (col)',           $s['profit']);
    row('customer.balance_gl',    $s['customer_gl']);
    row('carrier.balance',        $s['carrier_balance']);
    row('prepaid_pool.balance_gl',$s['prepaid_gl']);
    row('tx_count (FlightBooking-related)', $s['tx_count']);
}

// ============================================================================
// LOGIN ONCE — reuse token across all scenarios (avoid throttle:auth 5/min)
// ============================================================================
h1('LOGIN ONCE — get a single token reused across all scenarios');
wipe();
$f = fixtures();
$token = login();
echo "  token captured\n";

// ============================================================================
// SCENARIO A: Reverse direction 30→50
// ============================================================================
h1('SCENARIO A — Reverse direction 50→30→50');
wipe();
$f = fixtures();
$b = createBooking($f, 50, 40);
echo "  [STEP A1] Created booking #{$b->id} (50/40)\n";
$s = snapshot($b->id);
echo "    before any edit:\n"; snapRow($s);
$resp = editBooking($token, $b->id, $f, 30, 40);
echo "  [STEP A2] PUT 50→30 → HTTP {$resp['status']}\n";
$s = snapshot($b->id);
echo "    after 50→30:\n"; snapRow($s);
$resp = editBooking($token, $b->id, $f, 50, 40);
echo "  [STEP A3] PUT 30→50 → HTTP {$resp['status']}\n";
$s = snapshot($b->id);
echo "    after 30→50:\n"; snapRow($s);
row('expected.selling', 50);
row('expected.customer_gl', 50);
row('actual.selling (col)', $s['selling']);
row('actual.customer_gl',   $s['customer_gl']);
row('verdict', (abs($s['selling']-50) < 0.001 && abs($s['customer_gl']-50) < 0.001)
    ? "\e[1;32mcolumn matches but GL is the original — silent drift masked\e[0m"
    : "\e[1;31mDIVERGED\e[0m");

// ============================================================================
// SCENARIO B: No-change 50→50
// ============================================================================
h1('SCENARIO B — No-change 50→50');
wipe();
$f = fixtures();
$b = createBooking($f, 50, 40);
echo "  [STEP B1] Created booking #{$b->id} (50/40)\n";
$s0 = snapshot($b->id);
echo "    baseline:\n"; snapRow($s0);
$resp = editBooking($token, $b->id, $f, 50, 40);
echo "  [STEP B2] PUT 50→50 → HTTP {$resp['status']}\n";
$s1 = snapshot($b->id);
echo "    after no-change edit:\n"; snapRow($s1);
row('verdict', $s0 == $s1 ? "\e[1;32mNO-OP (good)\e[0m" : "\e[1;31mCHANGED on no-op\e[0m");

// ============================================================================
// SCENARIO C: Multi-edit sequence 50→30→40→25→25
// ============================================================================
h1('SCENARIO C — Multi-edit 50→30→40→25→25');
wipe();
$f = fixtures();
$b = createBooking($f, 50, 40);
echo "  [STEP C1] Created booking #{$b->id} (50/40)\n";
$s0 = snapshot($b->id);
echo "    baseline:\n"; snapRow($s0);
$seq = [30, 40, 25, 25];
foreach ($seq as $i => $newSell) {
    $resp = editBooking($token, $b->id, $f, $newSell, 40);
    $s = snapshot($b->id);
    echo "  [STEP C".($i+2)."] PUT → selling={$newSell} HTTP={$resp['status']}\n";
    echo "    state:\n"; snapRow($s);
    row('drift.selling vs GL', "col={$s['selling']}, GL={$s['customer_gl']}, delta=".($s['selling']-$s0['customer_gl']));
}
row('final.verdict', (abs($s['selling']-25) < 0.001 && abs($s['customer_gl']-50) < 0.001)
    ? "\e[1;31mClass-A drift: column=25, GL=50 (silent)\e[0m"
    : "see delta above");

// ============================================================================
// SCENARIO D: Edit before partial payment (pay 20, then edit 50→30)
// ============================================================================
h1('SCENARIO D — Edit before partial payment (pay 20, then 50→30)');
wipe();
$f = fixtures();
$b = createBooking($f, 50, 40);
echo "  [STEP D1] Created booking #{$b->id} (50/40)\n";
$s = snapshot($b->id);
echo "    baseline:\n"; snapRow($s);
$payResp = call('POST', "http://127.0.0.1:8123/api/v1/flight/bookings/{$b->id}/payments",
    ['amount' => 20, 'payment_method' => 'cash', 'account_id' => $f['cashbox']->id], $token);
echo "  [STEP D2] Payment 20 → HTTP {$payResp['status']}\n";
if ($payResp['status'] !== 201 && $payResp['status'] !== 200) {
    echo "  payment body: ".substr($payResp['raw'], 0, 500)."\n";
}
$s = snapshot($b->id);
echo "    after 20 paid:\n"; snapRow($s);
$resp = editBooking($token, $b->id, $f, 30, 40);
echo "  [STEP D3] PUT 50→30 → HTTP {$resp['status']}\n";
$s = snapshot($b->id);
echo "    after edit:\n"; snapRow($s);
row('expected.customer_gl', '30 (after reversal+resale at 30, with -20 already paid)');
row('actual.customer_gl',   $s['customer_gl']);
row('actual.selling(col)',  $s['selling']);
row('verdict', "col={$s['selling']} vs GL={$s['customer_gl']} — payment was applied to ORIGINAL sale, edit column-only");

// ============================================================================
// SCENARIO E: Edit after full payment (pay 50, then edit 50→30)
// ============================================================================
h1('SCENARIO E — Edit after full payment (pay 50, then 50→30)');
wipe();
$f = fixtures();
$b = createBooking($f, 50, 40);
echo "  [STEP E1] Created booking #{$b->id} (50/40)\n";
$s = snapshot($b->id);
echo "    baseline:\n"; snapRow($s);
$payResp = call('POST', "http://127.0.0.1:8123/api/v1/flight/bookings/{$b->id}/payments",
    ['amount' => 50, 'payment_method' => 'cash', 'account_id' => $f['cashbox']->id], $token);
echo "  [STEP E2] Payment 50 → HTTP {$payResp['status']}\n";
if ($payResp['status'] !== 201 && $payResp['status'] !== 200) {
    echo "  payment body: ".substr($payResp['raw'], 0, 500)."\n";
}
$s = snapshot($b->id);
echo "    after full paid:\n"; snapRow($s);
$resp = editBooking($token, $b->id, $f, 30, 40);
echo "  [STEP E3] PUT 50→30 → HTTP {$resp['status']}\n";
$s = snapshot($b->id);
echo "    after edit:\n"; snapRow($s);
row('expected.customer_gl', '-20 (customer has 20 credit with us)');
row('actual.customer_gl',   $s['customer_gl']);
row('actual.selling(col)',  $s['selling']);
row('verdict', (abs($s['selling']-30) < 0.001 && abs($s['customer_gl']) < 0.001)
    ? "\e[1;31mDANGEROUS: column=30, GL=0 (no reversal, customer overpaid)\e[0m"
    : "see values above");

// ============================================================================
// SCENARIO F: Create→edit→cancel
// ============================================================================
h1('SCENARIO F — Create 50, edit 50→30, cancel');
wipe();
$f = fixtures();
$b = createBooking($f, 50, 40);
echo "  [STEP F1] Created booking #{$b->id} (50/40)\n";
$s = snapshot($b->id);
echo "    baseline:\n"; snapRow($s);
$resp = editBooking($token, $b->id, $f, 30, 40);
echo "  [STEP F2] PUT 50→30 → HTTP {$resp['status']}\n";
$s = snapshot($b->id);
echo "    after edit:\n"; snapRow($s);
// Look at the cancel request schema to know the correct payload.
$cancelResp = call('POST', "http://127.0.0.1:8123/api/v1/flight/bookings/{$b->id}/cancel",
    ['airline_penalty' => 0, 'office_penalty' => 0, 'account_id' => $f['cashbox']->id,
     'notes' => 'P2 cancel test'], $token);
echo "  [STEP F3] Cancel (penalty=0) → HTTP {$cancelResp['status']}\n";
if ($cancelResp['status'] !== 200 && $cancelResp['status'] !== 201) {
    echo "  cancel body: ".substr($cancelResp['raw'], 0, 500)."\n";
}
$b->refresh();
$s = snapshot($b->id);
echo "    after cancel:\n"; snapRow($s);
row('booking.status',  $b->status?->value ?? (string) $b->status);
row('expected',         'cancel reverses the ORIGINAL 50 → refund 50, customer_gl=0');
row('actual.customer_gl', $s['customer_gl']);
row('actual.tx_count',    $s['tx_count']);

h1('PHASE 2 complete');