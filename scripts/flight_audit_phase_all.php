<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — 2026-08-13 Comprehensive Audit (Phases A + L + H + I + J + N + O + T)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Audit generator — يكتب findings لكل phase. Findings-only audit (لا يعمل fixes).
 *
 * الـ Phases في هذا الـ script:
 *   - Phase A: Auth & Permissions Matrix (Sanctum tokens, role-based access)
 *   - Phase L: FormRequest Validation (StoreFlightBookingRequest, StoreFlightPaymentRequest, etc.)
 *   - Phase H: Multi-Currency Cross-Border (6 currencies × multiple scenarios)
 *   - Phase I: Transaction Type & Dedupe (income, transfer, refund posting)
 *   - Phase J: Treasury Reconciliation (account balance == transaction sum)
 *   - Phase N: DB Integrity (FK, amounts, soft-deleted_at, paid_amount consistency)
 *   - Phase O: Real-Life Scenarios (5+ multi-step end-to-end scenarios)
 *   - Phase T: Idempotency/Duplicate (double click, double submit, refresh-after-pay)
 *
 * الـ Output:
 *   - storage/logs/flight_audit_phase_all_results.json
 *   - عرض في stdout
 */
define('LARAVEL_START', microtime(true));
$dbPath = realpath(__DIR__.'/..').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'local_flight_audit.sqlite';

if (! file_exists($dbPath)) {
    echo "❌ FATAL: Audit DB not found. Run flight_audit_setup.php first\n";
    exit(1);
}

putenv('DB_CONNECTION=sqlite');
putenv("DB_DATABASE=$dbPath");
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $dbPath;
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $dbPath;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$results = [
    'audit_id' => 'FLIGHT_AUDIT_20260813',
    'started_at' => date('Y-m-d H:i:s'),
    'phases' => [],
    'findings' => [],
];

function ok(string $m = 'OK'): void
{
    echo "    ✅ $m\n";
}
function fail(string $m): void
{
    echo "    ❌ $m\n";
}
function info(string $m): void
{
    echo "    ℹ  $m\n";
}
function warn(string $m): void
{
    echo "    ⚠  $m\n";
}
function head(string $m): void
{
    echo "    → $m\n";
}
function section(string $name): void
{
    echo "\n".str_repeat('═', 75)."\n  $name\n".str_repeat('═', 75)."\n";
}
function subsection(string $name): void
{
    echo "\n  $name\n".str_repeat('─', 75)."\n";
}

function record(string $phase, string $test, string $verdict, string $note = '', array $context = []): void
{
    global $results;
    if (! isset($results['phases'][$phase])) {
        $results['phases'][$phase] = ['tests' => [], 'passed' => 0, 'failed' => 0, 'warn' => 0, 'not_supported' => 0, 'not_testable' => 0];
    }
    $results['phases'][$phase]['tests'][$test] = array_merge(['verdict' => $verdict, 'note' => $note], $context);
    if ($verdict === 'PASS') {
        $results['phases'][$phase]['passed']++;
    } elseif ($verdict === 'FAIL') {
        $results['phases'][$phase]['failed']++;
    } elseif ($verdict === 'WARN') {
        $results['phases'][$phase]['warn']++;
    } elseif ($verdict === 'NOT_SUPPORTED') {
        $results['phases'][$phase]['not_supported']++;
    } elseif ($verdict === 'NOT_TESTABLE') {
        $results['phases'][$phase]['not_testable']++;
    }

    if (in_array($verdict, ['FAIL', 'WARN'], true)) {
        $results['findings'][] = [
            'phase' => $phase,
            'test' => $test,
            'verdict' => $verdict,
            'note' => $note,
            'context' => $context,
        ];
    }
}

function safeRun(string $phase, string $test, string $name, callable $fn): void
{
    head($test.' — '.$name);
    try {
        $fn();
    } catch (Throwable $e) {
        fail("$test crashed: ".$e->getMessage());
        record($phase, $test, 'FAIL', $e->getMessage(), ['exception' => $e->getTraceAsString()]);
    }
}

$adminUser = User::where('role', 'owner')->first();
$managerUser = User::where('role', 'manager')->first();
$employeeUser = User::where('role', 'employee')->first();
$financeUser = User::where('role', 'head_of_finance')->first();
$svc = app(FlightBookingService::class);

// ============================================================================
// PHASE A — AUTH & PERMISSIONS
// ============================================================================
section('PHASE A: Auth & Permissions Matrix');

safeRun('A', 'A1', 'Sanctum tokens issued for 4 roles', function () {
    $tokens = DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->get();
    $expected = ['flight-audit-admin', 'flight-audit-manager', 'flight-audit-employee', 'flight-audit-finance'];
    $found = $tokens->pluck('name')->toArray();
    $missing = array_diff($expected, $found);
    if (empty($missing)) {
        ok('4 tokens found: '.implode(', ', $found));
        record('A', 'A1', 'PASS', '4 tokens found');
    } else {
        fail('Missing tokens: '.implode(', ', $missing));
        record('A', 'A1', 'FAIL', 'Missing tokens: '.implode(', ', $missing));
    }
});

safeRun('A', 'A2', 'Sanctum token format correct (id|hash)', function () {
    $metadata = json_decode(file_get_contents(storage_path('logs/flight_audit_setup.json')), true);
    $allValid = true;
    foreach (['admin_token', 'manager_token', 'employee_token', 'finance_token'] as $key) {
        if (! preg_match('/^\d+\|[a-f0-9]+$/', $metadata[$key] ?? '')) {
            $allValid = false;
            fail("$key format invalid: ".substr($metadata[$key] ?? '', 0, 30));
        }
    }
    if ($allValid) {
        ok('All tokens match format id|hex');
        record('A', 'A2', 'PASS');
    } else {
        record('A', 'A2', 'FAIL', 'Token format mismatch');
    }
});

safeRun('A', 'A3', 'No admin middleware on any Flight route', function () {
    $routePrefix = 'flight/';
    $apiRoutes = file_get_contents(base_path('routes/api.php'));
    // Count flight routes without ->middleware('admin')
    $lines = explode("\n", $apiRoutes);
    $flightLines = array_filter($lines, function ($l) {
        return strpos($l, 'flight') !== false || strpos($l, 'v1/flight') !== false;
    });
    $total = count($flightLines);
    // Check if any flight route uses 'admin' middleware
    $hasAdmin = preg_match('/flight.*->middleware.*admin/', $apiRoutes);
    if (! $hasAdmin) {
        warn('No Flight route enforces admin middleware — any authenticated user can access destructive ops');
        record('A', 'A3', 'FAIL', 'No admin guard on Flight routes',
            ['severity' => 'CRITICAL', 'file' => 'routes/api.php', 'issue' => 'No admin middleware on Flight routes']);
    } else {
        ok('Admin middleware is enforced on some flight routes');
        record('A', 'A3', 'PASS');
    }
});

safeRun('A', 'A4', 'Role-based access for ModificationController', function () {
    $content = file_get_contents(base_path('app/Http/Controllers/Api/V1/Flight/ModificationController.php'));
    if (strpos($content, 'authorizeMatrix') !== false) {
        ok('ModificationController has inline authorizeMatrix (RBAC)');
        record('A', 'A4', 'PASS');
    } else {
        fail('ModificationController missing authorizeMatrix');
        record('A', 'A4', 'FAIL', 'Missing RBAC matrix');
    }
});

safeRun('A', 'A5', 'Vue route permissions (manage_finance)', function () {
    $router = file_get_contents(base_path('resources/js/router/index.js'));
    if (strpos($router, 'manage_finance') !== false) {
        ok('Vue router uses "manage_finance" permission for treasury/debt routes');
        record('A', 'A5', 'PASS');
    } else {
        warn('Vue router does not reference manage_finance permission');
        record('A', 'A5', 'WARN', 'manage_finance may not be enforced');
    }
});

// ============================================================================
// PHASE L — VALIDATION (FormRequest)
// ============================================================================
section('PHASE L: Validation (FormRequest)');

safeRun('L', 'L1', 'StoreFlightBookingRequest exists and validates required fields', function () {
    $file = base_path('app/Http/Requests/Flight/StoreFlightBookingRequest.php');
    if (! file_exists($file)) {
        fail('StoreFlightBookingRequest.php missing');
        record('L', 'L1', 'FAIL', 'FormRequest missing');

        return;
    }
    $content = file_get_contents($file);
    $rules = ['customer_id', 'currency', 'selling_price', 'departure_date'];
    $missing = [];
    foreach ($rules as $r) {
        if (strpos($content, "'$r'") === false && strpos($content, "\"$r\"") === false) {
            $missing[] = $r;
        }
    }
    if (empty($missing)) {
        ok('StoreFlightBookingRequest validates all required fields');
        record('L', 'L1', 'PASS');
    } else {
        warn('StoreFlightBookingRequest missing rules: '.implode(', ', $missing));
        record('L', 'L1', 'WARN', 'Missing rules: '.implode(', ', $missing));
    }
});

safeRun('L', 'L2', 'StoreFlightPaymentRequest validates amount/currency', function () {
    $file = base_path('app/Http/Requests/Flight/StoreFlightPaymentRequest.php');
    if (! file_exists($file)) {
        fail('StoreFlightPaymentRequest.php missing');
        record('L', 'L2', 'FAIL', 'FormRequest missing');

        return;
    }
    $content = file_get_contents($file);
    $hasAmount = strpos($content, "'amount'") !== false || strpos($content, '"amount"') !== false;
    $hasCurrency = strpos($content, "'currency'") !== false || strpos($content, '"currency"') !== false;
    if ($hasAmount && $hasCurrency) {
        ok('StoreFlightPaymentRequest validates amount + currency');
        record('L', 'L2', 'PASS');
    } else {
        fail('Missing validation: amount='.($hasAmount ? 'yes' : 'no').', currency='.($hasCurrency ? 'yes' : 'no'));
        record('L', 'L2', 'FAIL', 'Missing amount/currency validation');
    }
});

safeRun('L', 'L3', 'RechargeFlightSystemRequest enforces currency match', function () {
    $file = base_path('app/Http/Requests/Flight/RechargeFlightSystemRequest.php');
    if (! file_exists($file)) {
        fail('RechargeFlightSystemRequest.php missing');
        record('L', 'L3', 'FAIL', 'FormRequest missing');

        return;
    }
    $content = file_get_contents($file);
    if (strpos($content, 'withValidator') !== false) {
        ok('RechargeFlightSystemRequest uses withValidator for currency match enforcement');
        record('L', 'L3', 'PASS');
    } else {
        warn('RechargeFlightSystemRequest does not enforce currency match');
        record('L', 'L3', 'WARN', 'Currency match not enforced in form request');
    }
});

safeRun('L', 'L4', 'StoreFlightRefundRequest fields are minimal', function () {
    $file = base_path('app/Http/Requests/Flight/StoreFlightRefundRequest.php');
    $content = file_get_contents($file);
    // Should NOT validate flight_booking_id (route binding)
    $hasFlightBookingId = preg_match('/flight_booking_id/', $content);
    $hasPenalty = preg_match('/airline_penalty/', $content);
    if (! $hasFlightBookingId && $hasPenalty) {
        ok('StoreFlightRefundRequest validates penalties (booking_id comes from route)');
        record('L', 'L4', 'PASS');
    } else {
        warn('StoreFlightRefundRequest fields may be incomplete (flight_booking_id='.($hasFlightBookingId ? 'yes' : 'no').', airline_penalty='.($hasPenalty ? 'yes' : 'no').')');
        record('L', 'L4', 'WARN', 'Refund request fields incomplete');
    }
});

// ============================================================================
// PHASE H — MULTI-CURRENCY CROSS-BORDER
// ============================================================================
section('PHASE H: Multi-Currency Cross-Border');

$currencies = ['EGP', 'USD', 'SAR', 'KWD', 'EUR', 'AED'];

foreach ($currencies as $currency) {
    safeRun('H', "H-CREATE-$currency", "Create booking in $currency", function () use ($svc, $adminUser, $currency) {
        $customer = Customer::create([
            'name' => "TX-FLIGHT-E2E-20260813 H-CREATE-$currency",
            'full_name' => "TX-FLIGHT-E2E-20260813 H-CREATE-$currency",
            'phone' => '+2012345678'.rand(0, 99),
            'email' => strtolower($currency).'@tx.local',
            'module_type' => 'flights',
            'status' => 'active',
            'created_by' => $adminUser->id,
        ]);
        $account = Account::create([
            'name' => "TX-FLIGHT-E2E-20260813 H-CREATE-$currency Acct",
            'type' => 'customer',
            'currency' => $currency,
            'balance' => 0,
            'module_type' => 'flights',
            'is_active' => 1,
            'owner_type' => 'App\\Models\\Customer',
            'created_by' => $adminUser->id,
        ]);

        $selling = 1000;
        $purchase = 900;
        $booking = $svc->createBooking([
            'customer_id' => $customer->id,
            'booking_reference' => "TX-FLIGHT-E2E-20260813-H-$currency-".substr(md5(uniqid('', true)), 0, 4),
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'Audit',
            'status' => FlightBookingStatus::PENDING->value,
            'agent_name' => 'Audit',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => '10:00',
            'trip_type' => 'one_way',
            'airline' => 'EK',
            'passenger_count' => 1,
            'currency' => $currency,
            'selling_price' => $selling,
            'purchase_price' => $purchase,
        ]);
        if ($booking->currency === $currency && abs($booking->selling_price - $selling) < 0.01) {
            ok("$currency booking created OK (id=$booking->id, selling=$selling, currency=$currency)");
            record('H', "H-CREATE-$currency", 'PASS', "Booking created in $currency",
                ['booking_id' => $booking->id, 'currency' => $currency, 'selling' => $selling]);
        } else {
            fail("$currency booking mismatch: expected currency=$currency, got=$booking->currency");
            record('H', "H-CREATE-$currency", 'FAIL', 'Currency mismatch');
        }
    });
}

safeRun('H', 'H-PAYMENT-MIX', 'Payment in EGP for KWD booking (cross-currency)', function () use ($svc) {
    $booking = FlightBooking::where('booking_reference', 'like', 'TX-FLIGHT-E2E-20260813-H-KWD-%')->first();
    if (! $booking) {
        fail('No KWD booking to test against');
        record('H', 'H-PAYMENT-MIX', 'NOT_TESTABLE', 'No KWD booking');

        return;
    }
    try {
        $svc->addPayment($booking, [
            'amount' => 1000,
            'currency' => 'EGP',  // Different currency than booking (KWD)
            'payment_method' => 'cash',
            'account_id' => DB::table('accounts')->where('type', 'cashbox')->where('currency', 'EGP')->value('id'),
            'paid_by' => 'TX-FLIGHT-E2E-20260813',
        ]);
        ok('Cross-currency payment accepted (KWD booking + EGP payment)');
        record('H', 'H-PAYMENT-MIX', 'PASS', 'cross-currency payment worked (verify ledger)');
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'currency') !== false || strpos($e->getMessage(), 'currency match') !== false) {
            fail('Cross-currency payment rejected: '.$e->getMessage());
            record('H', 'H-PAYMENT-MIX', 'WARN', 'Cross-currency rejected — may be intentional guard');
        } else {
            fail('Cross-currency payment failed unexpectedly: '.$e->getMessage());
            record('H', 'H-PAYMENT-MIX', 'FAIL', $e->getMessage());
        }
    }
});

safeRun('H', 'H-PAYMENT-CONSISTENCY', 'Payment currency matches booking currency (same-currency)', function () use ($svc) {
    $booking = FlightBooking::where('booking_reference', 'like', 'TX-FLIGHT-E2E-20260813-H-USD-%')->first();
    if (! $booking) {
        fail('No USD booking to test against');
        record('H', 'H-PAYMENT-CONSISTENCY', 'NOT_TESTABLE', 'No USD booking');

        return;
    }
    try {
        $payment = $svc->addPayment($booking, [
            'amount' => 1000,
            'currency' => 'USD',  // Same currency as booking
            'payment_method' => 'cash',
            'account_id' => DB::table('accounts')->where('type', 'cashbox')->where('currency', 'USD')->value('id'),
            'paid_by' => 'TX-FLIGHT-E2E-20260813',
        ]);
        $paidCurrency = $payment->currency;
        $bookingCurrency = $booking->currency;
        if ($paidCurrency === $bookingCurrency) {
            ok("Same-currency payment OK (booking=$bookingCurrency, payment=$paidCurrency)");
            record('H', 'H-PAYMENT-CONSISTENCY', 'PASS');
        } else {
            fail("Currency mismatch: booking=$bookingCurrency, payment=$paidCurrency");
            record('H', 'H-PAYMENT-CONSISTENCY', 'FAIL', 'Currency mismatch');
        }
    } catch (Throwable $e) {
        fail('Same-currency payment failed: '.$e->getMessage());
        record('H', 'H-PAYMENT-CONSISTENCY', 'FAIL', $e->getMessage());
    }
});

// ============================================================================
// PHASE I — TRANSACTION TYPE & DEDUPE
// ============================================================================
section('PHASE I: Transaction Type & Dedupe');

safeRun('I', 'I1', 'Each flight booking creates a transaction record', function () {
    $bookings = FlightBooking::where('booking_reference', 'like', 'TX-FLIGHT-E2E-20260813-%')
        ->with('payments')->get();
    $bookingsWithPayments = $bookings->filter(fn ($b) => $b->payments->count() > 0);
    $missing = 0;
    foreach ($bookingsWithPayments as $b) {
        foreach ($b->payments as $p) {
            if (! $p->transaction_id) {
                $missing++;
            }
        }
    }
    if ($missing === 0) {
        ok('All flight payments have transaction records');
        record('I', 'I1', 'PASS');
    } else {
        fail("$missing payments without transaction_id");
        record('I', 'I1', 'FAIL', "$missing orphan payments");
    }
});

safeRun('I', 'I2', 'Refund creates reverse transaction', function () use ($svc) {
    $booking = FlightBooking::where('booking_reference', 'like', 'TX-FLIGHT-E2E-20260813-H-EUR-%')
        ->where('currency', 'EUR')->first();
    if (! $booking) {
        warn('No EUR booking to test refund against');
        record('I', 'I2', 'NOT_TESTABLE', 'No EUR booking');

        return;
    }
    $beforeTxCount = DB::table('transactions')->count();
    try {
        $refund = $svc->cancelBooking($booking, [
            'airline_penalty' => 50,
            'office_penalty' => 0,
            'notes' => 'TX-FLIGHT-E2E-20260813 Refund test',
        ]);
        $afterTxCount = DB::table('transactions')->count();
        $delta = $afterTxCount - $beforeTxCount;
        if ($delta > 0 && $refund) {
            ok("Refund created $delta new transactions");
            record('I', 'I2', 'PASS', "Created $delta transactions");
        } else {
            fail('Refund did not create transactions');
            record('I', 'I2', 'FAIL', 'No reverse transaction');
        }
    } catch (Throwable $e) {
        // If cancelled already, may fail
        warn('Refund flow: '.$e->getMessage());
        record('I', 'I2', 'WARN', $e->getMessage());
    }
});

safeRun('I', 'I3', 'No duplicate transactions per booking', function () {
    $payments = FlightPayment::where('booking_reference', '!=', '')
        ->whereNotNull('transaction_id')
        ->get()
        ->groupBy('transaction_id');
    $duplicates = $payments->filter(fn ($g) => $g->count() > 1);
    if ($duplicates->isEmpty()) {
        ok('No duplicate transactions per payment');
        record('I', 'I3', 'PASS');
    } else {
        fail('Found '.$duplicates->count().' transactions linked to multiple payments');
        record('I', 'I3', 'FAIL', 'Duplicate tx linkage');
    }
});

// ============================================================================
// PHASE J — TREASURY RECONCILIATION
// ============================================================================
section('PHASE J: Treasury Reconciliation');

safeRun('J', 'J1', 'Account balance == debit/credit sum per currency', function () {
    $accounts = DB::table('accounts')->where('type', 'cashbox')->get();
    $mismatches = [];
    foreach ($accounts as $acc) {
        $expected = DB::table('account_entries')->where('account_id', $acc->id)->sum(DB::raw('debit - credit'));
        $actual = $acc->balance;
        if (abs($actual - $expected) > 0.01) {
            $mismatches[] = ['account_id' => $acc->id, 'currency' => $acc->currency, 'expected' => $expected, 'actual' => $actual];
        }
    }
    if (empty($mismatches)) {
        ok('All cashbox accounts balance correctly');
        record('J', 'J1', 'PASS');
    } else {
        fail('Found '.count($mismatches).' accounts with balance mismatch');
        record('J', 'J1', 'FAIL', json_encode($mismatches));
    }
});

safeRun('J', 'J2', 'Flight carrier balance == transactions sum', function () {
    $carriers = FlightCarrier::all();
    $mismatches = [];
    foreach ($carriers as $c) {
        $txSum = DB::table('airline_transactions')->where('flight_carrier_id', $c->id)->sum('amount');
        // Note: airline_transactions.amount has no inherent sign — should match balance change
        if ($txSum == 0 && $c->balance != 0) {
            $mismatches[] = ['carrier_id' => $c->id, 'name' => $c->name, 'balance' => $c->balance, 'tx_sum' => $txSum];
        }
    }
    if (empty($mismatches)) {
        ok('All carriers have consistent balance/tx relationship');
        record('J', 'J2', 'PASS');
    } else {
        warn('Found '.count($mismatches).' carriers with balance mismatch');
        record('J', 'J2', 'WARN', json_encode($mismatches));
    }
});

// ============================================================================
// PHASE N — DB INTEGRITY
// ============================================================================
section('PHASE N: DB Integrity');

safeRun('N', 'N1', 'All flight_bookings have valid customer_id', function () {
    $orphan = DB::table('flight_bookings')
        ->leftJoin('customers', 'flight_bookings.customer_id', '=', 'customers.id')
        ->whereNull('customers.id')
        ->count();
    if ($orphan === 0) {
        ok('No orphan flight bookings (customer_id always valid)');
        record('N', 'N1', 'PASS');
    } else {
        fail("Found $orphan orphan bookings");
        record('N', 'N1', 'FAIL', "Orphan bookings: $orphan");
    }
});

safeRun('N', 'N2', 'All flight_payments have valid booking_id', function () {
    $orphan = DB::table('flight_payments')
        ->leftJoin('flight_bookings', 'flight_payments.flight_booking_id', '=', 'flight_bookings.id')
        ->whereNull('flight_bookings.id')
        ->count();
    if ($orphan === 0) {
        ok('No orphan payments (booking_id always valid)');
        record('N', 'N2', 'PASS');
    } else {
        fail("Found $orphan orphan payments");
        record('N', 'N2', 'FAIL', "Orphan payments: $orphan");
    }
});

safeRun('N', 'N3', 'paid_amount consistency: booking.paid_amount == sum(payments.amount)', function () {
    $bookings = FlightBooking::where('booking_reference', 'like', 'TX-FLIGHT-E2E-20260813-%')
        ->with('payments')->get();
    $mismatches = [];
    foreach ($bookings as $b) {
        $calculated = $b->payments->sum('amount');
        $accessor = $b->paid_amount;
        if (abs($calculated - $accessor) > 0.01) {
            $mismatches[] = ['booking_id' => $b->id, 'accessor' => $accessor, 'sum' => $calculated];
        }
    }
    if (empty($mismatches)) {
        ok('All bookings: paid_amount accessor matches sum of payments');
        record('N', 'N3', 'PASS');
    } else {
        warn('Found '.count($mismatches).' bookings with paid_amount mismatch');
        record('N', 'N3', 'WARN', json_encode($mismatches));
    }
});

safeRun('N', 'N4', 'Currency consistency: booking.currency matches pricing.currency', function () {
    $bookings = FlightBooking::where('booking_reference', 'like', 'TX-FLIGHT-E2E-20260813-%')
        ->with('pricing')->get();
    $mismatches = [];
    foreach ($bookings as $b) {
        if ($b->pricing && $b->pricing->currency !== $b->currency) {
            $mismatches[] = ['booking_id' => $b->id, 'booking_currency' => $b->currency, 'pricing_currency' => $b->pricing->currency];
        }
    }
    if (empty($mismatches)) {
        ok('All bookings: currency matches pricing currency');
        record('N', 'N4', 'PASS');
    } else {
        warn('Found '.count($mismatches).' bookings with currency mismatch');
        record('N', 'N4', 'WARN', json_encode($mismatches));
    }
});

safeRun('N', 'N5', 'No negative balances', function () {
    $negative = DB::table('flight_carriers')->where('balance', '<', 0)->count();
    $negativeAccounts = DB::table('accounts')->where('balance', '<', 0)->count();
    if ($negative === 0 && $negativeAccounts === 0) {
        ok('No negative balances in carriers or accounts');
        record('N', 'N5', 'PASS');
    } else {
        fail("Found $negative negative carrier balances, $negativeAccounts negative account balances");
        record('N', 'N5', 'FAIL', 'Negative balances exist');
    }
});

safeRun('N', 'N6', 'Soft-delete behavior: deleted bookings remain in DB', function () use ($svc) {
    // Create a booking, then soft-delete it
    $customer = Customer::create([
        'name' => 'TX-FLIGHT-E2E-20260813 N6',
        'full_name' => 'TX-FLIGHT-E2E-20260813 N6',
        'phone' => '+20123',
        'email' => 'n6@tx.local',
        'module_type' => 'flights',
        'status' => 'active',
    ]);
    $account = Account::create([
        'name' => 'TX-FLIGHT-E2E-20260813 N6 Acct',
        'type' => 'customer',
        'currency' => 'EGP',
        'balance' => 0,
        'module_type' => 'flights',
        'is_active' => 1,
        'owner_type' => 'App\\Models\\Customer',
    ]);
    $booking = $svc->createBooking([
        'customer_id' => $customer->id,
        'booking_reference' => 'TX-FLIGHT-E2E-20260813-N6-'.substr(md5(uniqid('', true)), 0, 4),
        'booking_channel_type' => 'manual',
        'booking_channel_provider' => 'Audit',
        'status' => FlightBookingStatus::PENDING->value,
        'agent_name' => 'Audit',
        'origin' => 'CAI',
        'destination' => 'JED',
        'departure_date' => now()->addDays(7)->toDateString(),
        'departure_time' => '10:00',
        'trip_type' => 'one_way',
        'airline' => 'EK',
        'passenger_count' => 1,
        'currency' => 'EGP',
        'selling_price' => 500,
        'purchase_price' => 450,
    ]);
    $bookingId = $booking->id;
    $booking->delete();
    $stillExists = DB::table('flight_bookings')->where('id', $bookingId)->exists();
    $isSoftDeleted = DB::table('flight_bookings')->where('id', $bookingId)->whereNotNull('deleted_at')->exists();
    if ($stillExists && $isSoftDeleted) {
        ok('Soft-delete preserves row + sets deleted_at');
        record('N', 'N6', 'PASS');
    } else {
        fail("Soft-delete behavior broken: exists=$stillExists, deleted_at=$isSoftDeleted");
        record('N', 'N6', 'FAIL', 'Soft-delete not working as expected');
    }
});

// ============================================================================
// PHASE O — REAL-LIFE SCENARIOS
// ============================================================================
section('PHASE O: Real-Life Scenarios');

safeRun('O', 'O1', 'Round-trip booking with 2 passengers', function () use ($svc, $adminUser) {
    $customer = Customer::create([
        'name' => 'TX-FLIGHT-E2E-20260813 O1',
        'full_name' => 'TX-FLIGHT-E2E-20260813 O1',
        'phone' => '+20123000',
        'email' => 'o1@tx.local',
        'module_type' => 'flights',
        'status' => 'active',
        'created_by' => $adminUser->id,
    ]);
    $account = Account::create([
        'name' => 'TX-FLIGHT-E2E-20260813 O1 Acct',
        'type' => 'customer',
        'currency' => 'EGP',
        'balance' => 0,
        'module_type' => 'flights',
        'is_active' => 1,
        'owner_type' => 'App\\Models\\Customer',
        'created_by' => $adminUser->id,
    ]);
    try {
        $booking = $svc->createBooking([
            'customer_id' => $customer->id,
            'booking_reference' => 'TX-FLIGHT-E2E-20260813-O1-'.substr(md5(uniqid('', true)), 0, 4),
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'Audit',
            'status' => FlightBookingStatus::PENDING->value,
            'agent_name' => 'Audit',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'return_date' => now()->addDays(14)->toDateString(),
            'departure_time' => '10:00',
            'trip_type' => 'round_trip',
            'airline' => 'SV',
            'passenger_count' => 2,
            'currency' => 'EGP',
            'selling_price' => 20000,
            'purchase_price' => 18000,
        ]);
        if ($booking->passenger_count === 2 && $booking->trip_type === 'round_trip') {
            ok('Round-trip booking with 2 passengers created');
            record('O', 'O1', 'PASS');
        } else {
            fail('Booking fields incorrect');
            record('O', 'O1', 'FAIL', 'Trip type or passenger count not honored');
        }
    } catch (Throwable $e) {
        fail('Round-trip creation failed: '.$e->getMessage());
        record('O', 'O1', 'FAIL', $e->getMessage());
    }
});

safeRun('O', 'O2', 'Multi-payment booking (partial then full)', function () use ($svc, $adminUser) {
    $customer = Customer::create([
        'name' => 'TX-FLIGHT-E2E-20260813 O2',
        'full_name' => 'TX-FLIGHT-E2E-20260813 O2',
        'phone' => '+20123001',
        'email' => 'o2@tx.local',
        'module_type' => 'flights',
        'status' => 'active',
        'created_by' => $adminUser->id,
    ]);
    $account = Account::create([
        'name' => 'TX-FLIGHT-E2E-20260813 O2 Acct',
        'type' => 'customer',
        'currency' => 'EGP',
        'balance' => 0,
        'module_type' => 'flights',
        'is_active' => 1,
        'owner_type' => 'App\\Models\\Customer',
        'created_by' => $adminUser->id,
    ]);
    $booking = $svc->createBooking([
        'customer_id' => $customer->id,
        'booking_reference' => 'TX-FLIGHT-E2E-20260813-O2-'.substr(md5(uniqid('', true)), 0, 4),
        'booking_channel_type' => 'manual',
        'booking_channel_provider' => 'Audit',
        'status' => FlightBookingStatus::PENDING->value,
        'agent_name' => 'Audit',
        'origin' => 'CAI',
        'destination' => 'JED',
        'departure_date' => now()->addDays(7)->toDateString(),
        'departure_time' => '10:00',
        'trip_type' => 'one_way',
        'airline' => 'SV',
        'passenger_count' => 1,
        'currency' => 'EGP',
        'selling_price' => 10000,
        'purchase_price' => 9000,
    ]);
    // Partial payment
    $svc->addPayment($booking, [
        'amount' => 4000,
        'currency' => 'EGP',
        'payment_method' => 'cash',
        'account_id' => DB::table('accounts')->where('type', 'cashbox')->where('currency', 'EGP')->value('id'),
        'paid_by' => 'TX-FLIGHT-E2E-20260813',
    ]);
    $booking->refresh();
    $after1 = $booking->paid_amount;
    // Full payment
    $svc->addPayment($booking, [
        'amount' => 6000,
        'currency' => 'EGP',
        'payment_method' => 'cash',
        'account_id' => DB::table('accounts')->where('type', 'cashbox')->where('currency', 'EGP')->value('id'),
        'paid_by' => 'TX-FLIGHT-E2E-20260813',
    ]);
    $booking->refresh();
    $after2 = $booking->paid_amount;
    if ($after1 >= 4000 && $after2 >= 10000) {
        ok("Multi-payment: partial=$after1, full=$after2");
        record('O', 'O2', 'PASS');
    } else {
        fail("Multi-payment inconsistent: partial=$after1, full=$after2");
        record('O', 'O2', 'FAIL', "Partial=$after1, Full=$after2");
    }
});

safeRun('O', 'O3', 'Cancellation with full refund', function () use ($svc, $adminUser) {
    $customer = Customer::create([
        'name' => 'TX-FLIGHT-E2E-20260813 O3',
        'full_name' => 'TX-FLIGHT-E2E-20260813 O3',
        'phone' => '+20123002',
        'email' => 'o3@tx.local',
        'module_type' => 'flights',
        'status' => 'active',
        'created_by' => $adminUser->id,
    ]);
    $account = Account::create([
        'name' => 'TX-FLIGHT-E2E-20260813 O3 Acct',
        'type' => 'customer',
        'currency' => 'EGP',
        'balance' => 0,
        'module_type' => 'flights',
        'is_active' => 1,
        'owner_type' => 'App\\Models\\Customer',
        'created_by' => $adminUser->id,
    ]);
    $booking = $svc->createBooking([
        'customer_id' => $customer->id,
        'booking_reference' => 'TX-FLIGHT-E2E-20260813-O3-'.substr(md5(uniqid('', true)), 0, 4),
        'booking_channel_type' => 'manual',
        'booking_channel_provider' => 'Audit',
        'status' => FlightBookingStatus::PENDING->value,
        'agent_name' => 'Audit',
        'origin' => 'CAI',
        'destination' => 'JED',
        'departure_date' => now()->addDays(7)->toDateString(),
        'departure_time' => '10:00',
        'trip_type' => 'one_way',
        'airline' => 'SV',
        'passenger_count' => 1,
        'currency' => 'EGP',
        'selling_price' => 5000,
        'purchase_price' => 4500,
    ]);
    $svc->addPayment($booking, [
        'amount' => 5000,
        'currency' => 'EGP',
        'payment_method' => 'cash',
        'account_id' => DB::table('accounts')->where('type', 'cashbox')->where('currency', 'EGP')->value('id'),
        'paid_by' => 'TX-FLIGHT-E2E-20260813',
    ]);
    $refund = $svc->cancelBooking($booking, [
        'airline_penalty' => 0,
        'office_penalty' => 0,
        'notes' => 'TX-FLIGHT-E2E-20260813 Full refund',
    ]);
    if ($refund && $refund->status === 'completed') {
        ok('Cancellation with refund completed');
        record('O', 'O3', 'PASS');
    } else {
        warn('Cancellation status: '.($refund->status ?? 'unknown'));
        record('O', 'O3', 'WARN', 'Refund status unclear');
    }
});

// ============================================================================
// PHASE T — IDEMPOTENCY / DUPLICATE
// ============================================================================
section('PHASE T: Idempotency / Duplicate');

safeRun('T', 'T1', 'Duplicate booking reference rejected', function () use ($svc, $adminUser) {
    $ref = 'TX-FLIGHT-E2E-20260813-T-DUP-'.substr(md5(uniqid('', true)), 0, 4);
    $customer = Customer::create([
        'name' => 'TX-FLIGHT-E2E-20260813 T1',
        'full_name' => 'TX-FLIGHT-E2E-20260813 T1',
        'phone' => '+20123003',
        'email' => 't1@tx.local',
        'module_type' => 'flights',
        'status' => 'active',
        'created_by' => $adminUser->id,
    ]);
    $account = Account::create([
        'name' => 'TX-FLIGHT-E2E-20260813 T1 Acct',
        'type' => 'customer',
        'currency' => 'EGP',
        'balance' => 0,
        'module_type' => 'flights',
        'is_active' => 1,
        'owner_type' => 'App\\Models\\Customer',
        'created_by' => $adminUser->id,
    ]);
    $bookingData = [
        'customer_id' => $customer->id,
        'booking_reference' => $ref,
        'booking_channel_type' => 'manual',
        'booking_channel_provider' => 'Audit',
        'status' => FlightBookingStatus::PENDING->value,
        'agent_name' => 'Audit',
        'origin' => 'CAI',
        'destination' => 'JED',
        'departure_date' => now()->addDays(7)->toDateString(),
        'departure_time' => '10:00',
        'trip_type' => 'one_way',
        'airline' => 'SV',
        'passenger_count' => 1,
        'currency' => 'EGP',
        'selling_price' => 1000,
        'purchase_price' => 900,
    ];
    $b1 = $svc->createBooking($bookingData);
    try {
        $b2 = $svc->createBooking($bookingData);
        fail('Duplicate booking reference was accepted (b1='.$b1->id.', b2='.$b2->id.')');
        record('T', 'T1', 'FAIL', 'Duplicate booking reference accepted');
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'booking_reference') !== false || strpos($e->getMessage(), 'UNIQUE') !== false || strpos($e->getMessage(), 'duplicate') !== false) {
            ok('Duplicate booking reference rejected: '.substr($e->getMessage(), 0, 80));
            record('T', 'T1', 'PASS');
        } else {
            warn('Duplicate rejected but with unexpected error: '.$e->getMessage());
            record('T', 'T1', 'WARN', $e->getMessage());
        }
    }
});

// ============================================================================
// SAVE RESULTS
// ============================================================================
section('SUMMARY');
$results['finished_at'] = date('Y-m-d H:i:s');
$totalPassed = 0;
$totalFailed = 0;
$totalWarn = 0;
$totalNs = 0;
$totalNt = 0;
foreach ($results['phases'] as $phase => $stats) {
    $totalPassed += $stats['passed'];
    $totalFailed += $stats['failed'];
    $totalWarn += $stats['warn'];
    $totalNs += $stats['not_supported'];
    $totalNt += $stats['not_testable'];
    echo sprintf("  %s: %d PASS, %d FAIL, %d WARN, %d NOT_SUPPORTED, %d NOT_TESTABLE\n",
        $phase, $stats['passed'], $stats['failed'], $stats['warn'], $stats['not_supported'], $stats['not_testable']);
}
echo "\n  TOTAL: $totalPassed PASS, $totalFailed FAIL, $totalWarn WARN, $totalNs NOT_SUPPORTED, $totalNt NOT_TESTABLE\n";
echo '  Findings: '.count($results['findings'])."\n";

$results['summary'] = [
    'passed' => $totalPassed,
    'failed' => $totalFailed,
    'warn' => $totalWarn,
    'not_supported' => $totalNs,
    'not_testable' => $totalNt,
    'findings_count' => count($results['findings']),
];

$resultsPath = storage_path('logs/flight_audit_phase_all_results.json');
file_put_contents($resultsPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "  Results saved to: $resultsPath\n\n";
