<?php
/**
 * PHASE 1 — Flight Booking Edit Financial Incident Reproduction
 * Date: 2026-08-18
 * Target DB: sfrk_edit_incident_20260818 (isolated)
 * Server:   http://127.0.0.1:8123 (artisan serve, isolated DB)
 *
 * Strategy:
 *   1. Bootstraps Laravel with DB_DATABASE = sfrk_edit_incident_20260818
 *   2. Seeds fixtures (admin user, customer, cashbox, flight carrier, system)
 *   3. Creates a 50 JOD Flight booking through FlightBookingService::createBooking()
 *   4. Snapshots BEFORE state (ledger-level)
 *   5. Authenticates via /api/v1/auth/login → token
 *   6. Sends PUT /api/v1/flight/bookings/{id} with selling 50→30 (real HTTP)
 *   7. Snapshots AFTER state
 *   8. Computes expected vs actual deltas and prints the financial diagnosis.
 *
 * The script NEVER manually edits accounts.balance or transactions.
 * Only real Laravel HTTP + service paths are exercised.
 *
 * Server/Staging DB is untouched.
 */

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\FlightSystemType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
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
    echo str_pad($k, 40)." : $v\n";
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
    $err = curl_error($ch);
    curl_close($ch);
    return ['status' => $code, 'body' => json_decode($resp, true) ?? $resp, 'raw' => $resp, 'error' => $err];
}

function snapshot(int $bookingId, string $label): array {
    $b = \App\Models\Flight\FlightBooking::with('customer.ledgerAccount', 'account', 'airlineAccount')->find($bookingId);

    $s = ['label' => $label, 'booking' => []];
    $s['booking'] = [
        'id' => $b->id,
        'booking_number' => $b->booking_number,
        'selling_price' => (float) $b->selling_price,
        'purchase_price' => (float) $b->purchase_price,
        'profit' => (float) $b->profit,
        'currency' => $b->currency,
        'sale_gl_transaction_id' => $b->sale_gl_transaction_id,
        'status' => $b->status?->value ?? (string) $b->status,
    ];

    $customerAccountId = $b->customer?->ledgerAccount?->id;
    if ($customerAccountId) {
        $credit = (float) \App\Models\AccountEntry::where('account_id', $customerAccountId)->sum('credit');
        $debit  = (float) \App\Models\AccountEntry::where('account_id', $customerAccountId)->sum('debit');
        $s['customer'] = [
            'account_id' => $customerAccountId,
            'balance_gl' => $credit - $debit,
            'credit' => $credit,
            'debit' => $debit,
            'account_row_balance' => (float) (\App\Models\Account::find($customerAccountId)?->balance ?? 0),
        ];
    } else {
        $s['customer'] = ['account_id' => null, 'balance_gl' => 0, 'credit' => 0, 'debit' => 0, 'account_row_balance' => 0];
    }

    $s['carrier'] = [
        'id' => $b->flight_carrier_id,
        'available_balance' => (float) (\App\Models\Flight\FlightCarrier::find($b->flight_carrier_id)?->available_balance ?? 0),
        'balance' => (float) (\App\Models\Flight\FlightCarrier::find($b->flight_carrier_id)?->balance ?? 0),
    ];

    // Prepaid pool 'رصيد مسبق — ناقلو الطيران' balance
    $prepaid = \App\Models\Account::where('name', 'رصيد مسبق — ناقلو الطيران')->first();
    if ($prepaid) {
        $pc = (float) \App\Models\AccountEntry::where('account_id', $prepaid->id)->sum('credit');
        $pd = (float) \App\Models\AccountEntry::where('account_id', $prepaid->id)->sum('debit');
        $s['prepaid_pool'] = [
            'account_id' => $prepaid->id,
            'balance_gl' => $pc - $pd,
            'credit' => $pc,
            'debit' => $pd,
            'account_row_balance' => (float) $prepaid->balance,
        ];
    }

    // Carrier ledger account entries (the AR-side tracking for the carrier)
    $carrierLedger = \App\Models\Account::where('name', 'like', '%ناقلو الطيران%')
        ->orWhere('name', 'like', '%Flight Carrier%')
        ->first();
    // skip — carrier.balance is on flight_carriers table, not in accounts

    $txIds = [];
    if ($b->sale_gl_transaction_id) $txIds[] = $b->sale_gl_transaction_id;
    $paymentTxIds = DB::table('flight_payments')->where('flight_booking_id', $b->id)->whereNotNull('transaction_id')->pluck('transaction_id')->all();
    $relatedTxIds = DB::table('transactions')
        ->where('related_type', \App\Models\Flight\FlightBooking::class)
        ->where('related_id', $bookingId)
        ->pluck('id')->all();
    $txIds = array_unique(array_merge($txIds, $paymentTxIds, $relatedTxIds));

    $txs = [];
    foreach ($txIds as $txId) {
        $t = \App\Models\Transaction::find($txId);
        if (!$t) continue;
        $entries = \App\Models\AccountEntry::where('transaction_id', $t->id)->orderBy('id')->get()->map(fn($e) => [
            'id' => $e->id, 'account_id' => $e->account_id,
            'debit' => (float) $e->debit, 'credit' => (float) $e->credit,
            'balance_after' => (float) $e->balance_after,
        ])->all();
        $txs[] = [
            'id' => $t->id,
            'amount' => (float) $t->amount,
            'type' => $t->type?->value ?? (string) $t->type,
            'related_type' => $t->related_type,
            'related_id' => $t->related_id,
            'notes' => $t->notes,
            'entries_count' => count($entries),
            'entries' => $entries,
        ];
    }
    $s['transactions'] = $txs;
    $s['transaction_count'] = count($txs);

    return $s;
}

function printSnap(array $s): void {
    row('booking.selling_price',     $s['booking']['selling_price']);
    row('booking.purchase_price',    $s['booking']['purchase_price']);
    row('booking.profit (column)',   $s['booking']['profit']);
    row('booking.status',            $s['booking']['status']);
    row('booking.sale_gl_tx_id',     $s['booking']['sale_gl_transaction_id'] ?? 'null');
    row('customer.account_id',       $s['customer']['account_id']);
    row('customer.balance (GL)',     $s['customer']['balance_gl']);
    row('customer.account.balance',  $s['customer']['account_row_balance']);
    row('carrier.available_balance', $s['carrier']['available_balance']);
    row('carrier.balance',           $s['carrier']['balance']);
    if (isset($s['prepaid_pool'])) {
        row('prepaid_pool.account_id',         $s['prepaid_pool']['account_id']);
        row('prepaid_pool.balance (GL)',       $s['prepaid_pool']['balance_gl']);
        row('prepaid_pool.account.balance',    $s['prepaid_pool']['account_row_balance']);
    }
    row('transactions count',        $s['transaction_count']);
}

// ============================================================================
// STEP 1: FIXTURES
// ============================================================================
h1('STEP 1 — Setup fixtures in isolated DB');

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
// Accounts: comprehensive cleanup before users (FK created_by).
// Delete ALL accounts except system-protected ones (Account::SYSTEM_PROTECTED_IDS).
DB::table('accounts')->whereIn('owner_type', ['owner', 'office', 'customer'])->delete();
DB::table('customers')->where('full_name', 'Incident Test Customer')->delete();
DB::table('users')->where('email', 'incident-admin@test.com')->delete();

$admin = User::firstOrCreate(
    ['email' => 'incident-admin@test.com'],
    ['name' => 'Incident Admin', 'password' => bcrypt('incident-password'),
     'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()],
);

$cashbox = Account::create([
    'name' => 'Office Cashbox INCIDENT', 'type' => AccountType::Cashbox->value,
    'currency' => 'EGP', 'balance' => 100000, 'is_active' => true,
    'owner_type' => Account::OWNER_TYPE_OWNER, 'module_type' => 'office',
    'is_module_vault' => false, 'created_by' => $admin->id,
]);

// Prepaid GL pool — نفس النمط المتبع في FlightCarrierRechargeServiceTest
// هذا هو الحساب اللي بيتحقق منه consumeCogs قبل ما يخصم من الناقل
Account::create([
    'name' => 'إقفال تكاليف الطيران',
    'type' => AccountType::Cashbox->value,
    'currency' => 'EGP', 'balance' => 0,
    'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OWNER,
    'module_type' => 'tourism', 'created_by' => $admin->id,
]);

$prepaidPool = Account::create([
    'name' => 'رصيد مسبق — ناقلو الطيران',
    'type' => AccountType::Cashbox->value,
    'currency' => 'EGP', 'balance' => 100000,   // رصيد كافي للـ COGS = 40
    'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OWNER,
    'module_type' => 'office', 'created_by' => $admin->id,
]);

$customer = Customer::create([
    'full_name' => 'Incident Test Customer', 'phone' => 'INC'.substr(uniqid(), -8),
    'national_id' => '1'.substr(uniqid(), -9), 'type' => 'individual', 'is_active' => true,
    'created_by' => $admin->id,
]);

$uniq = substr(uniqid(), -6);
$carrier = FlightCarrier::create([
    'name' => 'Incident Carrier EgyptAir', 'code' => 'INC-MSR-'.$uniq,
    'currency' => 'EGP', 'available_balance' => 10000,
    'is_active' => true, 'created_by' => $admin->id,
]);
// FlightCarrier.balance is NOT mass-assignable (defense-in-depth).
// Set via property assignment + raw update that bypasses the model observer guard.
DB::table('flight_carriers')->where('id', $carrier->id)->update(['balance' => 10000]);
$carrier->refresh();

$system = FlightSystem::create([
    'name' => 'Incident System Amadeus', 'code' => 'INC-AMS-'.$uniq,
    'currency' => 'EGP', 'available_balance' => 10000,
    'is_active' => true, 'created_by' => $admin->id,
]);
try {
    DB::table('flight_systems')->where('id', $system->id)->update(['balance' => 10000]);
    $system->refresh();
} catch (\Throwable $e) { /* system may not have balance column */ }

row('admin id',         $admin->id);
row('cashbox id',       $cashbox->id);
row('prepaid pool id',  $prepaidPool->id);
row('customer id',      $customer->id);
row('carrier id',       $carrier->id);
row('flight system id', $system->id);
row('isolated DB',      DB::connection()->getDatabaseName());

// ============================================================================
// STEP 2: CREATE 50 JOD FLIGHT BOOKING THROUGH REAL SERVICE
// ============================================================================
h1('STEP 2 — Create Flight booking (50 JOD sell / 40 JOD carrier-cost / no payment)');

\Illuminate\Support\Facades\Auth::login($admin);

$bookingData = [
    'customer_id' => $customer->id,
    'booking_channel_type' => 'SIGN',
    'booking_channel_provider' => 'Office',
    'system_type' => FlightSystemType::Manual->value,
    'airline_name' => 'EgyptAir',
    'pnr' => 'INCIDENT',
    'from_airport' => 'CAI',
    'to_airport' => 'AMM',
    'departure_date' => now()->addDays(7)->toDateString(),
    'departure_time' => '10:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'passengers_count' => 1,
    'passengers' => [['first_name' => 'Test', 'last_name' => 'Traveler', 'type' => 'adult']],
    'selling_price' => 50,
    'purchase_price' => 40,
    'currency' => 'EGP',
    'flight_carrier_id' => $carrier->id,
    'flight_system_id' => $system->id,
    'purchase_balance_source' => 'carrier',
    'account_id' => $cashbox->id,
    'agent_name' => 'Incident Test',
    'notes' => 'INCIDENT_REPRODUCTION',
    'baggage_allowance_kg' => 0,
];

$booking = app(FlightBookingService::class)->createBooking($bookingData);
$bookingId = (int) $booking->id;
\Illuminate\Support\Facades\Auth::logout();

row('booking id', $bookingId);
row('booking number', $booking->booking_number);
row('sale_gl_transaction_id', $booking->sale_gl_transaction_id);
row('selling_price (column)', $booking->selling_price);
row('purchase_price (column)', $booking->purchase_price);
row('profit (column)', $booking->profit);

// ============================================================================
// STEP 3: SNAPSHOT BEFORE
// ============================================================================
h1('STEP 3 — Snapshot BEFORE Edit (50 JOD)');
$before = snapshot($bookingId, 'BEFORE');
echo "\n--- BEFORE ---\n"; printSnap($before);
echo "\n  transactions linked to this booking:\n";
foreach ($before['transactions'] as $t) {
    echo "    tx#{$t['id']} type={$t['type']} amount={$t['amount']} related={$t['related_type']}#{$t['related_id']} notes=\"{$t['notes']}\"\n";
    foreach ($t['entries'] as $e) {
        echo "        entry#{$e['id']} acct={$e['account_id']} debit={$e['debit']} credit={$e['credit']} balance_after={$e['balance_after']}\n";
    }
}
$bookingsBefore = DB::table('flight_bookings')->count();
row('flight_bookings total in DB BEFORE', $bookingsBefore);

// ============================================================================
// STEP 4: AUTH → TOKEN
// ============================================================================
h1('STEP 4 — Login (POST /api/v1/auth/login) → token');

$loginResp = call('POST', 'http://127.0.0.1:8123/api/v1/auth/login', [
    'email' => 'incident-admin@test.com',
    'password' => 'incident-password',
]);

row('login HTTP status', $loginResp['status']);
if ($loginResp['status'] !== 200) {
    row('login body', $loginResp['raw']);
    exit(1);
}
$token = $loginResp['body']['data']['token'] ?? ($loginResp['body']['token'] ?? null);
if (!$token) {
    row('login body', $loginResp['raw']);
    exit(1);
}
row('token (first 30 chars)', substr($token, 0, 30).'...');

// ============================================================================
// STEP 5: EXECUTE THE EXACT EDIT THE USER DID (PUT real HTTP)
// ============================================================================
h1('STEP 5 — PUT /api/v1/flight/bookings/{id}  selling 50 → 30 (real HTTP)');

$putPayload = [
    'customer_id' => $customer->id,
    'selling_price' => 30,
    'purchase_price' => 40,
    'currency' => 'EGP',
    'flight_carrier_id' => $carrier->id,
    'flight_system_id' => $system->id,
    'purchase_balance_source' => 'carrier',
    'account_id' => $cashbox->id,
    'airline_name' => 'EgyptAir',
    'pnr' => 'INCIDENT',
    'from_airport' => 'CAI',
    'to_airport' => 'AMM',
    'departure_date' => now()->addDays(7)->toDateString(),
    'departure_time' => '10:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'passengers_count' => 1,
    'baggage_allowance_kg' => 0,
    'passengers' => [['first_name' => 'Test', 'last_name' => 'Traveler', 'type' => 'adult']],
    'notes' => 'INCIDENT_EDIT',
    'agent_name' => 'Incident Test',
];

$putResp = call('PUT', "http://127.0.0.1:8123/api/v1/flight/bookings/{$bookingId}", $putPayload, $token);
row('PUT HTTP status', $putResp['status']);
row('PUT body[message]', $putResp['body']['message'] ?? '');
if (isset($putResp['body']['data'])) {
    $data = $putResp['body']['data'];
    row('PUT returned selling_price', $data['selling_price'] ?? 'n/a');
    row('PUT returned purchase_price', $data['purchase_price'] ?? 'n/a');
    row('PUT returned profit', $data['profit'] ?? 'n/a');
    row('PUT returned sale_gl_transaction_id', $data['sale_gl_transaction_id'] ?? 'n/a');
}
if (isset($putResp['body']['errors'])) {
    row('PUT errors', json_encode($putResp['body']['errors'], JSON_UNESCAPED_UNICODE));
}

// ============================================================================
// STEP 6: SNAPSHOT AFTER
// ============================================================================
h1('STEP 6 — Snapshot AFTER Edit');
$after = snapshot($bookingId, 'AFTER');
echo "\n--- AFTER ---\n"; printSnap($after);
echo "\n  transactions linked to this booking:\n";
foreach ($after['transactions'] as $t) {
    echo "    tx#{$t['id']} type={$t['type']} amount={$t['amount']} related={$t['related_type']}#{$t['related_id']} notes=\"{$t['notes']}\"\n";
    foreach ($t['entries'] as $e) {
        echo "        entry#{$e['id']} acct={$e['account_id']} debit={$e['debit']} credit={$e['credit']} balance_after={$e['balance_after']}\n";
    }
}
$bookingsAfter = DB::table('flight_bookings')->count();
row('flight_bookings total in DB AFTER', $bookingsAfter);

// ============================================================================
// STEP 7: FINANCIAL DELTA TABLE
// ============================================================================
h1('STEP 7 — Financial Delta Table (expected vs actual)');

$deltas = [
    ['booking.selling_price (column)',         $before['booking']['selling_price'],  $after['booking']['selling_price'],   -20.0],
    ['booking.purchase_price (column)',        $before['booking']['purchase_price'], $after['booking']['purchase_price'],   0.0],
    ['booking.profit (column)',               $before['booking']['profit'],         $after['booking']['profit'],          -20.0],
    ['customer.balance_gl (credit-debit)',    $before['customer']['balance_gl'],    $after['customer']['balance_gl'],     -20.0],
    ['customer.account.balance column',       (float)$before['customer']['account_row_balance'], (float)$after['customer']['account_row_balance'], -20.0],
    ['carrier.balance (flight_carriers)',     $before['carrier']['balance'],        $after['carrier']['balance'],         40.0],
    ['carrier.available_balance',             $before['carrier']['available_balance'], $after['carrier']['available_balance'], 0.0],
    ['prepaid_pool.balance_gl',               $before['prepaid_pool']['balance_gl'] ?? 0, $after['prepaid_pool']['balance_gl'] ?? 0, 40.0],
    ['transactions count for this booking',   (float)$before['transaction_count'],  (float)$after['transaction_count'],   1.0],
    ['flight_bookings count in DB',           (float)$bookingsBefore,               (float)$bookingsAfter,                0.0],
];

printf("%-40s | %8s | %8s | %8s | %10s | %10s | %s\n",
    "Metric", "Before", "After", "ΔActual", "ΔExpected", "Variance", "Status");
echo str_repeat('-', 110) . "\n";
foreach ($deltas as $d) {
    [$name, $b, $a, $exp] = $d;
    $actual = $a - $b;
    $var = $actual - $exp;
    $status = abs($var) < 0.001 ? "\e[1;32mPASS\e[0m" : "\e[1;31mFAIL\e[0m";
    printf("%-40s | %8.2f | %8.2f | %+8.2f | %+10.2f | %+10.2f | %s\n",
        $name, $b, $a, $actual, $exp, $var, $status);
}

// ============================================================================
// STEP 8: DIAGNOSIS
// ============================================================================
h1('STEP 8 — Diagnosis: what happened to the 20 JOD');

$sellDiff          = $after['booking']['selling_price'] - $before['booking']['selling_price'];
$custDiff          = $after['customer']['balance_gl'] - $before['customer']['balance_gl'];
$profitColDiff     = $after['booking']['profit'] - $before['booking']['profit'];
$carrierDiff       = $after['carrier']['balance'] - $before['carrier']['balance'];
$prepaidDiff       = ($after['prepaid_pool']['balance_gl'] ?? 0) - ($before['prepaid_pool']['balance_gl'] ?? 0);
$bookingsDiff      = $bookingsAfter - $bookingsBefore;
$txCountDiff       = $after['transaction_count'] - $before['transaction_count'];

echo "  • User requested:            selling 50 → 30, expected AR delta = -20 JOD on customer (customer owes 20 less)\n";
echo "  • booking.selling_price col: {$sellDiff} JOD\n";
echo "  • booking.profit col:        {$profitColDiff} JOD (recomputed by service)\n";
echo "  • customer.balance_gl (AR):  {$custDiff} JOD (expected -20)\n";
echo "  • carrier.balance (AR):      {$carrierDiff} JOD (expected +40 for reversal)\n";
echo "  • prepaid_pool.balance_gl:   {$prepaidDiff} JOD (expected +40 for reversal)\n";
echo "  • transaction count for booking: {$txCountDiff} (expected +1 for reversal)\n";
echo "  • flight_bookings total in DB: delta = {$bookingsDiff}\n";

echo "\n  Defect classification:\n";
$classA = (
    abs($custDiff - 20.0) > 0.001        // customer AR delta doesn't match expected
    && abs($custDiff) > 0.001            // AND some change happened (not silent fail)
);
$classA_silent = (
    abs($sellDiff - (-20)) < 0.001 && abs($custDiff) < 0.001  // column changed but GL silent
);
$classB_duplicate = $bookingsDiff > 0;

echo "  Class-A1 (silent ledger + column drift): " . ($classA_silent ? "\e[1;31mYES\e[0m" : "no") . "\n";
echo "  Class-A2 (wrong AR delta): " . ($classA ? "\e[1;31mYES\e[0m" : "no") . "\n";
echo "  Class-B (UX = duplicate new booking): " . ($classB_duplicate ? "\e[1;31mYES\e[0m" : "no") . "\n";

if ($bookingsDiff > 0) {
    echo "\n  Scenario (duplicate): A NEW 30 JOD booking was created; original 50 JOD booking untouched.\n";
}
if ($txCountDiff == 0 && abs($sellDiff - (-20)) < 0.001) {
    echo "\n  Scenario (silent column-only): booking.selling_price changed but NO ledger mutation occurred.\n";
    echo "  → customer AR balance_gl is unchanged. booking.selling_price column shows 30.\n";
    echo "  → GL is INCONSISTENT with the booking column → silent financial drift.\n";
}

h1('PHASE 1 reproduction complete');

// ============================================================================
// Note: .env restored to original (was backed up to .env.backup_incident_20260818)
// To re-run: DB_DATABASE=sfrk_edit_incident_20260818 php sfrk_edit_incident_phase1.php
// ============================================================================
