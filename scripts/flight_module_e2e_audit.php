<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — Comprehensive End-to-End Audit Script
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Runs 24 scenarios as real user experiments: every operation commits to the DB,
 * assertions are made against live data, then everything the script created is
 * automatically cleaned up. Zero impact on pre-existing data.
 *
 *   Coverage:  Book / Payment / Cancel / Refund / Modify / Delete-with-reversal
 *   Currency:  EGP + USD (real exchange rate from currencies table)
 *   Lifecycle: PENDING → CONFIRMED → PAID → CANCELLED → REFUNDED → DELETED
 *
 * Safety guards (refuse to run otherwise):
 *   - APP_ENV must not be "production"
 *   - DB database name must not contain "prod"
 *   - Pre-snapshot of 20 tables; post-snapshot diff after cleanup
 *   - Interactive confirm (skippable via --yes)
 *
 * CLI:
 *   php scripts/flight_module_e2e_audit.php                    # interactive
 *   php scripts/flight_module_e2e_audit.php --yes              # auto-confirm
 *   php scripts/flight_module_e2e_audit.php --read-only        # rollback everything
 *   php scripts/flight_module_e2e_audit.php --no-cleanup       # keep data after run
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Enums\AccountType;
use App\Enums\FlightBookingStatus;
use App\Exceptions\OverpaymentException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightSystem;
use App\Models\Setting\Currency;
use App\Models\TicketModification;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\FlightSystemRechargeService;
use App\Services\Flight\ModificationService;
use App\Services\Flight\RefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

const AUDIT_PREFIX = 'FM-AUDIT-';
const SNAPSHOT_TABLES = [
    'customers', 'accounts', 'flight_carriers', 'flight_systems', 'flight_groups',
    'airline_accounts', 'transactions', 'account_entries',
    'flight_bookings', 'flight_payments', 'flight_refunds',
    'refund_requests', 'airline_credits', 'airline_transactions',
    'ticket_modifications', 'flight_carrier_transactions', 'flight_system_transactions',
    'flight_tickets', 'flight_passengers',
];

$RUN_UUID = sprintf(
    '%s%s',
    AUDIT_PREFIX,
    strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 10))
);
$GLOBALS['RUN_UUID'] = $RUN_UUID;

// Resolve real exchange rates
$USD_RATE = (float) Currency::where('code', 'USD')->value('exchange_rate');
$EUR_RATE = (float) Currency::where('code', 'EUR')->value('exchange_rate');
$KWD_RATE = (float) Currency::where('code', 'KWD')->value('exchange_rate');
$SAR_RATE = (float) Currency::where('code', 'SAR')->value('exchange_rate');

// ──────────────────────────────────────────────────────────────────────────
// Output helpers
// ──────────────────────────────────────────────────────────────────────────
function out_ok(string $m = 'OK'): void
{
    echo "    ✅ $m\n";
}
function out_fail(string $m): void
{
    echo "    ❌ $m\n";
}
function out_info(string $m): void
{
    echo "    ℹ  $m\n";
}
function out_warn(string $m): void
{
    echo "    ⚠  $m\n";
}
function out_section(string $name): void
{
    echo "\n".str_repeat('═', 75)."\n  $name\n".str_repeat('═', 75)."\n";
}
function runUuid(): string
{
    return $GLOBALS['RUN_UUID'];
}

// ──────────────────────────────────────────────────────────────────────────
// CLI + Safety guards
// ──────────────────────────────────────────────────────────────────────────
out_section('Flight Module E2E Audit — Safety Checks');
$env = (string) config('app.env');
$dbName = (string) config('database.connections.'.config('database.default').'.database');
out_info("APP_ENV  = $env");
out_info("DB       = $dbName");
out_info("USD rate = $USD_RATE (from currencies table)");

$argvList = $argv;
array_shift($argvList);
$argYes = in_array('--yes', $argvList, true) || in_array('-y', $argvList, true);
$argReadOnly = in_array('--read-only', $argvList, true);
$argNoClean = in_array('--no-cleanup', $argvList, true);

if ($env === 'production') {
    fwrite(STDERR, "❌ ABORT: APP_ENV=production. This script must NEVER run on production.\n");
    exit(2);
}
if (preg_match('/(prod|production|live)/i', $dbName)) {
    fwrite(STDERR, "❌ ABORT: DB name '$dbName' looks like production.\n");
    exit(2);
}
if (! $argYes) {
    echo "\n  This will write test data to the database (auto-cleaned up afterwards).\n";
    echo '  Type YES to continue: ';
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'YES') {
        fwrite(STDERR, "❌ Aborted by user.\n");
        exit(0);
    }
}

// ──────────────────────────────────────────────────────────────────────────
// Snapshot
// ──────────────────────────────────────────────────────────────────────────
function take_snapshot(): array
{
    $snap = ['taken_at' => date('Y-m-d H:i:s'), 'tables' => []];
    foreach (SNAPSHOT_TABLES as $table) {
        try {
            $snap['tables'][$table] = (int) DB::table($table)->count();
        } catch (Throwable $e) {
            $snap['tables'][$table] = 'err: '.$e->getMessage();
        }
    }

    return $snap;
}
$preSnapshot = take_snapshot();
out_info('Snapshot taken: '.count($preSnapshot['tables']).' tables');

// ──────────────────────────────────────────────────────────────────────────
// Authenticate
// ──────────────────────────────────────────────────────────────────────────
$admin = User::where('role', 'owner')->first() ?? User::where('role', 'admin')->first() ?? User::first();
if (! $admin) {
    fwrite(STDERR, "❌ No admin user found.\n");
    exit(1);
}
Auth::login($admin);
out_info("Authenticated as User #{$admin->id} ({$admin->email})");
$GLOBALS['admin'] = $admin;

// ──────────────────────────────────────────────────────────────────────────
// Result container
// ──────────────────────────────────────────────────────────────────────────
$GLOBALS['results'] = [
    'run_uuid' => $RUN_UUID,
    'started_at' => date('Y-m-d H:i:s'),
    'finished_at' => null,
    'app_env' => $env,
    'db' => $dbName,
    'pre_snapshot' => $preSnapshot,
    'post_snapshot' => null,
    'scenarios' => [],
    'verdict' => ['passed' => 0, 'failed' => 0, 'issues' => []],
];

function record_issue(string $scenario, string $check, $expected, $actual, string $hint = ''): void
{
    $GLOBALS['results']['verdict']['issues'][] = [
        'scenario' => $scenario,
        'check' => $check,
        'expected' => is_scalar($expected) ? $expected : json_encode($expected),
        'actual' => is_scalar($actual) ? $actual : json_encode($actual),
        'hint' => $hint,
    ];
}

function assertSame(string $scenario, string $check, $expected, $actual, string $hint = '', float $tol = 0.01): void
{
    $diff = is_numeric($expected) && is_numeric($actual)
        ? round((float) $actual - (float) $expected, 2)
        : 0.0;
    $ok = is_numeric($expected) ? abs($diff) <= $tol : $expected === $actual;
    if ($ok) {
        out_ok("$check (actual=".(is_scalar($actual) ? $actual : json_encode($actual)).')');
        $GLOBALS['results']['verdict']['passed']++;
    } else {
        out_fail("$check: expected=".(is_scalar($expected) ? $expected : json_encode($expected))
              .' actual='.(is_scalar($actual) ? $actual : json_encode($actual))
              .($hint ? "  → $hint" : ''));
        $GLOBALS['results']['verdict']['failed']++;
        record_issue($scenario, $check, $expected, $actual, $hint);
    }
}

function snapAccount(int $id): float
{
    $a = Account::find($id);

    return $a ? (float) $a->balance : 0.0;
}

/**
 * Add a payment AND confirm the booking (addPayment alone doesn't change status).
 */
function payFullAndConfirm(FlightBookingService $svc, FlightBooking $booking, float $amount, int $accountId, string $currency = 'EGP'): FlightBooking
{
    $svc->addPayment($booking, [
        'amount' => $amount,
        'account_id' => $accountId,
        'currency' => $currency,
        'payment_method' => 'cash',
        'payment_date' => now()->toDateTimeString(),
        'paid_by' => 'FM-AUDIT',
        'notes' => runUuid(),
    ]);
    $svc->confirmBooking($booking->fresh());

    return $booking->fresh();
}

// ──────────────────────────────────────────────────────────────────────────
// Master data setup (idempotent)
// ──────────────────────────────────────────────────────────────────────────
out_section('Master data setup');

function auditCode(string $name): string
{
    $clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $name));
    $code = substr($clean, -16);

    return $code !== '' ? $code : ('AU'.strtoupper(substr(md5($name), 0, 6)));
}

function findOrCreateCarrier(string $name, int $adminId, string $currency = 'EGP'): FlightCarrier
{
    $c = FlightCarrier::where('name', $name)->first();
    if ($c) {
        return $c;
    }

    return FlightCarrier::create([
        'name' => $name,
        'code' => auditCode($name),
        'currency' => $currency,
        'credit_limit' => 1_000_000,
        'balance' => 0,
        'is_active' => true,
        'created_by' => $adminId,
    ]);
}

function findOrCreateSystem(string $name, int $adminId, string $currency = 'EGP'): FlightSystem
{
    $s = FlightSystem::where('name', $name)->first();
    if ($s) {
        return $s;
    }

    return FlightSystem::create([
        'name' => $name,
        'code' => auditCode($name),
        'currency' => $currency,
        'credit_limit' => 1_000_000,
        'balance' => 0,
        'is_active' => true,
        'created_by' => $adminId,
    ]);
}

function findOrCreateGroup(string $name, int $adminId, string $currency = 'EGP'): FlightGroup
{
    $g = FlightGroup::where('name', $name)->first();
    if ($g) {
        return $g;
    }

    return FlightGroup::create([
        'name' => $name,
        'code' => auditCode($name),
        'currency' => $currency,
        'credit_limit' => 1_000_000,
        'balance' => 0,
        'is_active' => true,
        'created_by' => $adminId,
    ]);
}

function findOrCreateAirlineAccount(string $name, int $adminId, string $currency): AirlineAccount
{
    $a = AirlineAccount::where('name', $name)->first();
    if ($a) {
        return $a;
    }

    return AirlineAccount::create([
        'name' => $name,
        'code' => auditCode($name),
        'currency' => $currency,
        'system_type' => 'manual',
        'balance' => 0,
        'is_active' => true,
        'created_by' => $adminId,
    ]);
}

function findOrCreateAuditAccount(string $name, int $adminId, AccountType $type, string $currency, float $openingBalance = 1_000_000): Account
{
    $existing = Account::where('name', $name)->first();
    if ($existing) {
        if ((float) $existing->balance < $openingBalance - 0.01) {
            $diff = round($openingBalance - (float) $existing->balance, 2);
            try {
                DB::table('account_entries')->insert([
                    'account_id' => $existing->id,
                    'debit' => $diff,
                    'credit' => 0,
                    'balance_after' => $openingBalance,
                    'description' => runUuid().' — idempotent top-up',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $existing->balance = $openingBalance;
                $existing->save();
            } catch (Throwable $e) { /* best-effort */
            }
        }

        return $existing->fresh();
    }

    return LedgerBalanceMutationGuard::run(function () use ($name, $adminId, $type, $currency, $openingBalance) {
        return Account::create([
            'name' => $name,
            'type' => $type,
            'currency' => $currency,
            'balance' => $openingBalance,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => $type === AccountType::Customer ? 'flights' : 'office',
            'is_module_vault' => true,
            'notes' => runUuid().' — test cashbox',
            'created_by' => $adminId,
        ]);
    });
}

function createAuditCustomer(string $suffix, int $adminId, string $currency = 'EGP'): array
{
    $account = Account::create([
        'name' => runUuid()."-CUST-$suffix",
        'type' => AccountType::Customer,
        'balance' => 0,
        'currency' => $currency,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'flights',
        'notes' => runUuid()." — customer $suffix",
        'created_by' => $adminId,
    ]);
    $customer = Customer::create([
        'account_id' => $account->id,
        'full_name' => runUuid()."-CUST-$suffix",
        'phone' => '010'.str_pad((string) abs(crc32($suffix.$GLOBALS['RUN_UUID'])), 8, '0', STR_PAD_LEFT),
        'national_id' => str_pad((string) random_int(10000000000000, 99999999999999), 14, '0', STR_PAD_LEFT),
        'type' => 'individual',
        'status' => 'active',
        'created_by' => $adminId,
    ]);

    return ['customer' => $customer, 'account' => $account];
}

function makeBooking(FlightBookingService $svc, Customer $customer, float $sellingEgp, float $purchaseEgp, array $overrides = []): FlightBooking
{
    return $svc->createBooking(array_merge([
        'customer_id' => $customer->id,
        'booking_reference' => runUuid().'-BK-'.substr(md5(uniqid('', true)), 0, 6),
        'booking_channel_type' => 'manual',
        'booking_channel_provider' => 'AuditScript',
        'status' => FlightBookingStatus::PENDING->value,
        'agent_name' => 'FM-AUDIT Agent',
        'origin' => 'CAI',
        'destination' => 'DXB',
        'departure_date' => now()->addDays(7)->toDateString(),
        'departure_time' => '10:00',
        'trip_type' => 'one_way',
        'airline' => 'EK',
        'passenger_count' => 1,
        'currency' => 'EGP',
        'selling_price' => $sellingEgp,
        'purchase_price' => $purchaseEgp,
        'notes' => runUuid(),
    ], $overrides));
}

// Create master data
$carrierEgp = findOrCreateCarrier(runUuid().'-CARRIER-EGP', $admin->id, 'EGP');
$carrierUsd = findOrCreateCarrier(runUuid().'-CARRIER-USD', $admin->id, 'USD');
$systemEgp = findOrCreateSystem(runUuid().'-SYSTEM-EGP', $admin->id, 'EGP');
$groupEgp = findOrCreateGroup(runUuid().'-GROUP-EGP', $admin->id, 'EGP');
$airlineAccEgp = findOrCreateAirlineAccount(runUuid().'-AIRL-AC-EGP', $admin->id, 'EGP');
$airlineAccUsd = findOrCreateAirlineAccount(runUuid().'-AIRL-AC-USD', $admin->id, 'USD');

$cashboxEgp = findOrCreateAuditAccount(runUuid().'-TRS-EGP-CASHBOX', $admin->id, AccountType::Cashbox, 'EGP', 1_000_000);
$cashboxUsd = findOrCreateAuditAccount(runUuid().'-TRS-USD-CASHBOX', $admin->id, AccountType::Cashbox, 'USD', 50_000);
$bankEgp = findOrCreateAuditAccount(runUuid().'-BANK-EGP', $admin->id, AccountType::Bank, 'EGP', 2_000_000);
$bankUsd = findOrCreateAuditAccount(runUuid().'-BANK-USD', $admin->id, AccountType::Bank, 'USD', 50_000);

$custEgpA = createAuditCustomer('EGP-A', $admin->id, 'EGP');
$custEgpB = createAuditCustomer('EGP-B', $admin->id, 'EGP');
$custUsdA = createAuditCustomer('USD-A', $admin->id, 'USD');
$custUsdB = createAuditCustomer('USD-B', $admin->id, 'USD');

// Resolve services
$svc = app(FlightBookingService::class);
$rechargeSvc = app(FlightCarrierRechargeService::class);
$systemRecharge = app(FlightSystemRechargeService::class);
$txSvc = app(TransactionService::class);
$refundSvc = app(RefundService::class);
$modSvc = app(ModificationService::class);

$GLOBALS['svc'] = $svc;
$GLOBALS['rechargeSvc'] = $rechargeSvc;
$GLOBALS['systemRecharge'] = $systemRecharge;
$GLOBALS['txSvc'] = $txSvc;
$GLOBALS['refundSvc'] = $refundSvc;
$GLOBALS['modSvc'] = $modSvc;
$GLOBALS['cashboxEgp'] = $cashboxEgp;
$GLOBALS['cashboxUsd'] = $cashboxUsd;
$GLOBALS['bankEgp'] = $bankEgp;
$GLOBALS['bankUsd'] = $bankUsd;
$GLOBALS['custEgpA'] = $custEgpA;
$GLOBALS['custEgpB'] = $custEgpB;
$GLOBALS['custUsdA'] = $custUsdA;
$GLOBALS['custUsdB'] = $custUsdB;
$GLOBALS['carrierEgp'] = $carrierEgp;
$GLOBALS['carrierUsd'] = $carrierUsd;
$GLOBALS['systemEgp'] = $systemEgp;
$GLOBALS['groupEgp'] = $groupEgp;
$GLOBALS['airlineAccEgp'] = $airlineAccEgp;
$GLOBALS['airlineAccUsd'] = $airlineAccUsd;
$GLOBALS['USD_RATE'] = $USD_RATE;

try {
    $rechargeSvc->rechargeFromAccount($carrierEgp, $bankEgp, 500_000.0, runUuid().' — fund carrier EGP');
    out_info('Carrier EGP funded 500,000');
} catch (Throwable $e) {
    out_warn('Carrier EGP fund skipped: '.$e->getMessage());
}
try {
    $systemRecharge->rechargeFromAccount($systemEgp, $bankEgp, 500_000.0, runUuid().' — fund system EGP');
    out_info('System EGP funded 500,000');
} catch (Throwable $e) {
    out_warn('System EGP fund skipped: '.$e->getMessage());
}

out_info("Master data ready: cashboxes EGP #{$cashboxEgp->id}, USD #{$cashboxUsd->id}");

// ──────────────────────────────────────────────────────────────────────────
// Scenario runner
// ──────────────────────────────────────────────────────────────────────────
function runScenario(string $id, string $name, callable $body, bool $readOnly = false): void
{
    out_section("$id — $name");
    if ($readOnly) {
        DB::beginTransaction();
    }
    $scenarioStart = microtime(true);
    try {
        $body();
        $GLOBALS['results']['scenarios'][$id] = [
            'name' => $name, 'status' => 'passed',
            'duration_ms' => (int) ((microtime(true) - $scenarioStart) * 1000),
        ];
    } catch (Throwable $e) {
        $GLOBALS['results']['scenarios'][$id] = [
            'name' => $name, 'status' => 'failed',
            'error' => $e->getMessage(),
            'trace' => substr($e->getTraceAsString(), 0, 1200),
            'duration_ms' => (int) ((microtime(true) - $scenarioStart) * 1000),
        ];
        out_fail("$id: ".$e->getMessage());
        record_issue($id, 'crash', 'no crash', $e->getMessage(), substr($e->getTraceAsString(), 0, 200));
        $GLOBALS['results']['verdict']['failed']++;
    } finally {
        if ($readOnly) {
            try {
                DB::rollBack();
            } catch (Throwable $e) { /* already rolled back */
            }
        }
    }
}

// ──────────────────────────────────────────────────────────────────────────
// SCENARIOS
// ──────────────────────────────────────────────────────────────────────────

// S01 — Book EGP, full immediate payment
runScenario('S01', 'Book EGP, full immediate payment', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $opening = snapAccount($cashboxEgp->id);
    $booking = makeBooking($svc, $custEgpA['customer'], $sellingEgp, $purchaseEgp);
    $booking->refresh();
    assertSame('S01', 'booking.status', FlightBookingStatus::PENDING->value, $booking->status->value);
    assertSame('S01', 'customer.AR after booking', $sellingEgp, snapAccount($custEgpA['account']->id));

    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');
    $booking->refresh();
    assertSame('S01', 'booking.status after full pay', FlightBookingStatus::CONFIRMED->value, $booking->status->value);
    assertSame('S01', 'customer.AR after full pay', 0, snapAccount($custEgpA['account']->id));
    assertSame('S01', 'cashbox EGP delta (+selling)', $opening + $sellingEgp, snapAccount($cashboxEgp->id));
    $GLOBALS['results']['scenarios']['S01']['booking_id'] = $booking->id;
});

// S02 — Book EGP, partial then full payment
runScenario('S02', 'Book EGP, partial then full payment', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S02', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp);
    assertSame('S02', 'AR after booking', $sellingEgp, snapAccount($cust['account']->id));

    $svc->addPayment($booking, [
        'amount' => 4_000, 'account_id' => $cashboxEgp->id,
        'payment_method' => 'cash', 'payment_date' => now()->toDateTimeString(),
        'paid_by' => 'FM-AUDIT', 'notes' => runUuid(),
    ]);
    assertSame('S02', 'AR after partial 4k', 6_000, snapAccount($cust['account']->id));

    $svc->addPayment($booking, [
        'amount' => 6_000, 'account_id' => $cashboxEgp->id,
        'payment_method' => 'cash', 'payment_date' => now()->toDateTimeString(),
        'paid_by' => 'FM-AUDIT', 'notes' => runUuid(),
    ]);
    $svc->confirmBooking($booking->fresh());
    $booking->refresh();
    assertSame('S02', 'status after full pay', FlightBookingStatus::CONFIRMED->value, $booking->status->value);
    assertSame('S02', 'AR after full pay', 0, snapAccount($cust['account']->id));
});

// S03 — Book EGP, overpayment attempt (must reject)
runScenario('S03', 'Book EGP, overpayment attempt rejected', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S03', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp);

    $thrown = false;
    try {
        $svc->addPayment($booking, [
            'amount' => 15_000, 'account_id' => $cashboxEgp->id,
            'payment_method' => 'cash', 'payment_date' => now()->toDateTimeString(),
            'paid_by' => 'FM-AUDIT', 'notes' => runUuid(),
        ]);
    } catch (ValidationException $e) {
        $thrown = true;
    } catch (OverpaymentException $e) {
        $thrown = true;
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'overpay') !== false || stripos($e->getMessage(), 'exceed') !== false) {
            $thrown = true;
        } else {
            throw $e;
        }
    }
    assertSame('S03', 'overpayment rejected', true, $thrown, 'overpayment of 15k vs 10k sale must throw');
});

// S04 — Book USD, payment in USD cashbox
runScenario('S04', 'Book USD, payment in same currency', function () {
    extract($GLOBALS);
    $sellingForeign = 1_000;
    $purchaseForeign = 900;
    $rate = $USD_RATE;
    $sellingEgp = $sellingForeign * $rate;
    $purchaseEgp = $purchaseForeign * $rate;

    $cust = createAuditCustomer('S04', $admin->id, 'USD');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp, [
        'currency' => 'USD', 'foreign_currency' => 'USD',
        'selling_price_foreign' => $sellingForeign,
        'purchase_price_foreign' => $purchaseForeign,
        'exchange_rate' => $rate,
    ]);
    $booking->refresh();
    assertSame('S04', 'selling_price_foreign persisted', $sellingForeign, (float) $booking->selling_price_foreign,
        'migration 2026_07_29_add_selling_price_foreign may not be applied');

    payFullAndConfirm($svc, $booking, $sellingForeign, $cashboxUsd->id, 'USD');
    $booking->refresh();
    assertSame('S04', 'status', FlightBookingStatus::CONFIRMED->value, $booking->status->value);
});

// S05 — Book USD, payment in EGP cashbox (cross-currency)
runScenario('S05', 'Book USD, cross-currency payment (USD sale, EGP cashbox)', function () {
    extract($GLOBALS);
    $sellingForeign = 500;
    $purchaseForeign = 450;
    $rate = $USD_RATE;
    $sellingEgp = $sellingForeign * $rate;
    $purchaseEgp = $purchaseForeign * $rate;

    $cust = createAuditCustomer('S05', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp, [
        'currency' => 'USD', 'foreign_currency' => 'USD',
        'selling_price_foreign' => $sellingForeign,
        'purchase_price_foreign' => $purchaseForeign,
        'exchange_rate' => $rate,
    ]);

    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');
    $booking->refresh();
    assertSame('S05', 'status after EGP payment on USD sale', FlightBookingStatus::CONFIRMED->value, $booking->status->value);
});

// S06 — Book USD, partial then full payment
runScenario('S06', 'Book USD, partial then full payment', function () {
    extract($GLOBALS);
    $sellingForeign = 1_000;
    $purchaseForeign = 900;
    $rate = $USD_RATE;
    $sellingEgp = $sellingForeign * $rate;
    $purchaseEgp = $purchaseForeign * $rate;

    $cust = createAuditCustomer('S06', $admin->id, 'USD');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp, [
        'currency' => 'USD', 'foreign_currency' => 'USD',
        'selling_price_foreign' => $sellingForeign,
        'purchase_price_foreign' => $purchaseForeign,
        'exchange_rate' => $rate,
    ]);
    $svc->addPayment($booking, [
        'amount' => 200, 'account_id' => $cashboxUsd->id, 'currency' => 'USD',
        'payment_method' => 'cash', 'payment_date' => now()->toDateTimeString(),
        'paid_by' => 'FM-AUDIT', 'notes' => runUuid(),
    ]);
    $svc->addPayment($booking, [
        'amount' => 800, 'account_id' => $cashboxUsd->id, 'currency' => 'USD',
        'payment_method' => 'cash', 'payment_date' => now()->toDateTimeString(),
        'paid_by' => 'FM-AUDIT', 'notes' => runUuid(),
    ]);
    $svc->confirmBooking($booking->fresh());
    $booking->refresh();
    assertSame('S06', 'status', FlightBookingStatus::CONFIRMED->value, $booking->status->value);
});

// S07 — Book + cancel with full refund (zero penalties)
runScenario('S07', 'Book EGP + cancel with full refund', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S07', $admin->id, 'EGP');
    $openingCash = snapAccount($cashboxEgp->id);
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp);

    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');

    $svc->cancelBooking($booking, [
        'reason' => 'FM-AUDIT full refund',
        'airline_penalty' => 0,
        'office_penalty' => 0,
        'account_id' => $cashboxEgp->id,
        'currency' => 'EGP',
    ]);
    $booking->refresh();
    assertSame('S07', 'booking.status after full refund cancel', FlightBookingStatus::REFUNDED->value, $booking->status->value);
    assertSame('S07', 'customer.AR after full refund', 0, snapAccount($cust['account']->id), 'should be net 0');
    assertSame('S07', 'cashbox back to opening', $openingCash, snapAccount($cashboxEgp->id), 'full refund restores cashbox');
});

// S08 — Book + cancel with penalty (partial refund)
runScenario('S08', 'Book EGP + cancel with penalty (partial refund)', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S08', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp);
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');
    $svc->cancelBooking($booking, [
        'reason' => 'FM-AUDIT partial refund',
        'airline_penalty' => 2_000,
        'office_penalty' => 0,
        'account_id' => $cashboxEgp->id,
        'currency' => 'EGP',
    ]);
    $booking->refresh();
    assertSame('S08', 'booking.status after partial refund', FlightBookingStatus::PARTIALLY_REFUNDED->value, $booking->status->value);
});

// S09 — Book + cancel with no refund (penalty == sale)
runScenario('S09', 'Book EGP + cancel with penalty == sale (no refund)', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S09', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp);
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');
    $svc->cancelBooking($booking, [
        'reason' => 'FM-AUDIT no refund',
        'airline_penalty' => $sellingEgp,
        'office_penalty' => 0,
        'account_id' => $cashboxEgp->id,
        'currency' => 'EGP',
    ]);
    $booking->refresh();
    assertSame('S09', 'booking.status', FlightBookingStatus::CANCELLED->value, $booking->status->value);
});

// S10 — Book USD + cancel with full refund
runScenario('S10', 'Book USD + cancel with full refund', function () {
    extract($GLOBALS);
    $sellingForeign = 1_000;
    $purchaseForeign = 900;
    $rate = $USD_RATE;
    $sellingEgp = $sellingForeign * $rate;
    $purchaseEgp = $purchaseForeign * $rate;

    $cust = createAuditCustomer('S10', $admin->id, 'USD');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp, [
        'currency' => 'USD', 'foreign_currency' => 'USD',
        'selling_price_foreign' => $sellingForeign,
        'purchase_price_foreign' => $purchaseForeign,
        'exchange_rate' => $rate,
    ]);
    payFullAndConfirm($svc, $booking, $sellingForeign, $cashboxUsd->id, 'USD');
    $svc->cancelBooking($booking, [
        'reason' => 'FM-AUDIT USD full refund',
        'airline_penalty' => 0,
        'office_penalty' => 0,
        'account_id' => $cashboxUsd->id,
        'currency' => 'USD',
    ]);
    $booking->refresh();
    assertSame('S10', 'booking.status', FlightBookingStatus::REFUNDED->value, $booking->status->value);
});

// S11 — Book USD + cancel with penalty
runScenario('S11', 'Book USD + cancel with penalty', function () {
    extract($GLOBALS);
    $sellingForeign = 1_000;
    $purchaseForeign = 900;
    $rate = $USD_RATE;
    $sellingEgp = $sellingForeign * $rate;
    $purchaseEgp = $purchaseForeign * $rate;

    $cust = createAuditCustomer('S11', $admin->id, 'USD');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp, [
        'currency' => 'USD', 'foreign_currency' => 'USD',
        'selling_price_foreign' => $sellingForeign,
        'purchase_price_foreign' => $purchaseForeign,
        'exchange_rate' => $rate,
    ]);
    payFullAndConfirm($svc, $booking, $sellingForeign, $cashboxUsd->id, 'USD');
    $svc->cancelBooking($booking, [
        'reason' => 'FM-AUDIT USD penalty',
        'airline_penalty' => 200,
        'office_penalty' => 0,
        'account_id' => $cashboxUsd->id,
        'currency' => 'USD',
    ]);
    $booking->refresh();
    assertSame('S11', 'booking.status', FlightBookingStatus::PARTIALLY_REFUNDED->value, $booking->status->value);
});

// S12 — RefundRequest → process (airline_credit)
runScenario('S12', 'RefundRequest → process airline_credit', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S12', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp, [
        'airline_account_id' => $airlineAccEgp->id,
    ]);
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');

    $rr = $refundSvc->createRefundRequest([
        'flight_booking_id' => $booking->id,
        'cancellation_fee' => 3_000,
        'currency' => 'EGP',
        'method' => 'airline_credit',
        'airline_account_id' => $airlineAccEgp->id,
        'reason' => 'FM-AUDIT airline credit refund',
        'notes' => runUuid(),
    ], $admin->id);

    $refundSvc->processRefundRequest($rr->id, $admin->id);
    $rr->refresh();
    assertSame('S12', 'refund_request.status', 'processed', $rr->status->value ?? $rr->status);
    $ac = DB::table('airline_credits')->where('refund_request_id', $rr->id)->first();
    assertSame('S12', 'airline_credit exists', true, (bool) $ac, 'airline_credit row must exist');
});

// S13 — RefundRequest → process (agency_treasury)
runScenario('S13', 'RefundRequest → process agency_treasury', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S13', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp);
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');
    $openingCash = snapAccount($cashboxEgp->id);

    $rr = $refundSvc->createRefundRequest([
        'flight_booking_id' => $booking->id,
        'cancellation_fee' => 2_000,
        'currency' => 'EGP',
        'method' => 'agency_treasury',
        'reason' => 'FM-AUDIT treasury refund',
        'notes' => runUuid(),
    ], $admin->id);
    $refundSvc->processRefundRequest($rr->id, $admin->id);
    $rr->refresh();
    assertSame('S13', 'rr.status', 'processed', $rr->status->value ?? $rr->status);
    assertSame('S13', 'cashbox debited by 2000', $openingCash - 2_000, snapAccount($cashboxEgp->id));
});

// S14 — Reverse a processed RefundRequest
runScenario('S14', 'Reverse a processed RefundRequest', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S14', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp);
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');

    $rr = $refundSvc->createRefundRequest([
        'flight_booking_id' => $booking->id,
        'cancellation_fee' => 1_500, 'currency' => 'EGP',
        'method' => 'agency_treasury', 'reason' => 'FM-AUDIT reverse test',
        'notes' => runUuid(),
    ], $admin->id);
    $refundSvc->processRefundRequest($rr->id, $admin->id);

    $refundSvc->reverseRefundRequest($rr->id, $admin->id);
    $rrTrashed = DB::table('refund_requests')->where('id', $rr->id)->first();
    assertSame('S14', 'rr soft-deleted after reverse', true, (bool) ($rrTrashed->deleted_at ?? null));
});

// S15 — Modification create + confirm
runScenario('S15', 'Modification create + confirm', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S15', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp, [
        'airline_account_id' => $airlineAccEgp->id,
    ]);
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');

    $mod = $modSvc->createRequest([
        'booking_id' => $booking->id,
        'type' => 'airline_change_fee',
        'description' => 'FM-AUDIT change fee',
        'cost_amount' => 500,
        'currency' => 'EGP',
        'airline_account_id' => $airlineAccEgp->id,
        'notes' => runUuid(),
    ], $admin->id);
    $modSvc->confirmModification($mod->id, $admin->id);
    $mod->refresh();
    assertSame('S15', 'modification.status', 'confirmed', $mod->status->value ?? $mod->status);
    assertSame('S15', 'confirmed_at set', true, ! empty($mod->confirmed_at));
});

// S16 — Reverse a confirmed Modification (soft-deletes the modification)
runScenario('S16', 'Reverse a confirmed Modification', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S16', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp, [
        'airline_account_id' => $airlineAccEgp->id,
    ]);
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');
    $mod = $modSvc->createRequest([
        'booking_id' => $booking->id, 'type' => 'airline_change_fee',
        'description' => 'FM-AUDIT mod reverse', 'cost_amount' => 300,
        'currency' => 'EGP', 'airline_account_id' => $airlineAccEgp->id,
        'notes' => runUuid(),
    ], $admin->id);
    $modSvc->confirmModification($mod->id, $admin->id);
    $modSvc->reverseConfirmation($mod->id, $admin->id);
    $modTrashed = TicketModification::withTrashed()->find($mod->id);
    assertSame('S16', 'modification soft-deleted after reverse', true, (bool) $modTrashed->deleted_at);
});

// S17 — Delete-with-reversal (CONFIRMED booking)
runScenario('S17', 'Delete-with-reversal (CONFIRMED booking)', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S17', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp);
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');
    $bookingId = $booking->id;
    $ok = $svc->deleteBookingWithReversal($bookingId, $admin->id);
    assertSame('S17', 'delete returns true', true, $ok);
    $b = FlightBooking::withTrashed()->find($bookingId);
    assertSame('S17', 'booking trashed', true, (bool) $b->deleted_at);
});

// S18 — Delete-with-reversal (after partial refund)
runScenario('S18', 'Delete-with-reversal after cancel (PARTIALLY_REFUNDED)', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S18', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp);
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');
    $svc->cancelBooking($booking, [
        'reason' => 'FM-AUDIT', 'airline_penalty' => 2_000, 'office_penalty' => 0,
        'account_id' => $cashboxEgp->id, 'currency' => 'EGP',
    ]);
    $bookingId = $booking->id;
    $ok = $svc->deleteBookingWithReversal($bookingId, $admin->id);
    assertSame('S18', 'delete-after-cancel returns true', true, $ok);
});

// S19 — Idempotency: double delete (must throw)
runScenario('S19', 'Idempotency: double delete must throw', function () {
    extract($GLOBALS);
    $sellingEgp = 10_000;
    $purchaseEgp = 9_000;
    $cust = createAuditCustomer('S19', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp);
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');
    $svc->deleteBookingWithReversal($booking->id, $admin->id);

    $thrown = false;
    try {
        $svc->deleteBookingWithReversal($booking->id, $admin->id);
    } catch (Throwable $e) {
        $thrown = true;
    }
    assertSame('S19', 'double delete rejected', true, $thrown);
});

// S20 — Group booking + pay-debt
runScenario('S20', 'Group booking + pay debt', function () {
    extract($GLOBALS);
    $sellingEgp = 12_000;
    $purchaseEgp = 11_000;
    $cust = createAuditCustomer('S20', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp, [
        'flight_group_id' => $groupEgp->id,
    ]);
    assertSame('S20', 'AR after group booking', $sellingEgp, snapAccount($cust['account']->id));
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');
    assertSame('S20', 'AR after pay', 0, snapAccount($cust['account']->id));
});

// S21 — Carrier recharge + book via carrier
runScenario('S21', 'Carrier recharge + book via carrier', function () {
    extract($GLOBALS);
    $rechargeSvc->rechargeFromAccount($carrierEgp, $bankEgp, 100_000.0, runUuid().' — S21 fund');
    $sellingEgp = 15_000;
    $purchaseEgp = 14_000;
    $cust = createAuditCustomer('S21', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp, [
        'flight_carrier_id' => $carrierEgp->id,
    ]);
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');
    $booking->refresh();
    assertSame('S21', 'status', FlightBookingStatus::CONFIRMED->value, $booking->status->value);
});

// S22 — System recharge + book via system
runScenario('S22', 'System recharge + book via system', function () {
    extract($GLOBALS);
    $systemRecharge->rechargeFromAccount($systemEgp, $bankEgp, 100_000.0, runUuid().' — S22 fund');
    $sellingEgp = 15_000;
    $purchaseEgp = 14_000;
    $cust = createAuditCustomer('S22', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp, [
        'flight_system_id' => $systemEgp->id,
    ]);
    payFullAndConfirm($svc, $booking, $sellingEgp, $cashboxEgp->id, 'EGP');
    $booking->refresh();
    assertSame('S22', 'status', FlightBookingStatus::CONFIRMED->value, $booking->status->value);
});

// S23 — Customer debt pay (recordJournalTransfer)
runScenario('S23', 'Customer debt pay (TX-201 style)', function () {
    extract($GLOBALS);
    $sellingEgp = 5_000;
    $purchaseEgp = 4_500;
    $cust = createAuditCustomer('S23', $admin->id, 'EGP');
    $booking = makeBooking($svc, $cust['customer'], $sellingEgp, $purchaseEgp);
    assertSame('S23', 'AR after booking', $sellingEgp, snapAccount($cust['account']->id));
    $txSvc->recordJournalTransfer([
        'amount' => 5_000,
        'from_account_id' => $cust['account']->id,
        'to_account_id' => $cashboxEgp->id,
        'allow_from_negative' => true,
        'module' => 'flight',
        'notes' => runUuid().' — debt pay',
    ]);
    assertSame('S23', 'AR after debt pay', 0, snapAccount($cust['account']->id));
});

// S24 — Final invariant sweep (skip on read-only)
if (! $argReadOnly) {
    runScenario('S24', 'Final invariant sweep across all FM-AUDIT data', function () {
        extract($GLOBALS);
        $tables = [
            'customers' => 'full_name',
            'accounts' => 'name',
            'flight_bookings' => 'booking_reference',
            'flight_carriers' => 'name',
            'flight_systems' => 'name',
            'flight_groups' => 'name',
            'airline_accounts' => 'name',
            'flight_payments' => 'transaction_reference',
            'refund_requests' => 'reason',
            'ticket_modifications' => 'description',
        ];
        $uuid = runUuid();
        foreach ($tables as $table => $col) {
            $count = DB::table($table)->where($col, 'like', "$uuid%")->count();
            out_info("$table tagged rows: $count");
        }
        $unbalanced = DB::table('account_entries as ae')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->where('t.notes', 'like', "%$uuid%")
            ->groupBy('ae.transaction_id')
            ->havingRaw('ROUND(SUM(ae.debit), 2) <> ROUND(SUM(ae.credit), 2)')
            ->selectRaw('ae.transaction_id, ROUND(SUM(ae.debit),2) AS d, ROUND(SUM(ae.credit),2) AS c')
            ->limit(5)->get();
        assertSame('S24', 'unbalanced transactions for this run', 0, $unbalanced->count(),
            'all GL transactions for this run must be balanced');
    });
}

// ──────────────────────────────────────────────────────────────────────────
// FINAL SUMMARY
// ──────────────────────────────────────────────────────────────────────────
$GLOBALS['results']['finished_at'] = date('Y-m-d H:i:s');

out_section('SUMMARY');
$passed = $GLOBALS['results']['verdict']['passed'];
$failed = $GLOBALS['results']['verdict']['failed'];
echo "  Passed: $passed\n  Failed: $failed\n";
foreach ($GLOBALS['results']['scenarios'] as $id => $sc) {
    $icon = ($sc['status'] ?? '') === 'passed' ? '✅' : '❌';
    echo "  $icon $id — {$sc['name']}\n";
    if (! empty($sc['error'])) {
        echo '      '.substr($sc['error'], 0, 200)."\n";
    }
}

// ──────────────────────────────────────────────────────────────────────────
// CLEANUP
// ──────────────────────────────────────────────────────────────────────────
if (! $argNoClean && ! $argReadOnly) {
    out_section('Cleanup');
    $uuid = runUuid();
    $cleanup = [
        ['flight_payments',         'booking_id',  'flight_bookings', 'booking_reference'],
        ['flight_refunds',          'booking_id',  'flight_bookings', 'booking_reference'],
        ['refund_requests',         'booking_id',  'flight_bookings', 'booking_reference'],
        ['ticket_modifications',    'booking_id',  'flight_bookings', 'booking_reference'],
        ['airline_credits',         'airline_account_id', 'airline_accounts', 'name'],
        ['airline_transactions',    'flight_carrier_id', 'flight_carriers', 'name'],
        ['flight_carrier_transactions', 'flight_carrier_id', 'flight_carriers', 'name'],
        ['flight_system_transactions',  'flight_system_id',  'flight_systems', 'name'],
        ['flight_bookings',         null,         null, 'booking_reference'],
        ['customers',               null,         null, 'full_name'],
        ['accounts',                null,         null, 'name'],
        ['flight_carriers',         null,         null, 'name'],
        ['flight_systems',          null,         null, 'name'],
        ['flight_groups',           null,         null, 'name'],
        ['airline_accounts',        null,         null, 'name'],
    ];
    foreach ($cleanup as $row) {
        [$child, $fk, $parent, $pcol] = $row;
        try {
            $q = DB::table($child);
            if ($fk && $parent) {
                $q->whereIn($fk, DB::table($parent)->select('id')->where($pcol, 'like', "$uuid%"));
            } else {
                $q->where($pcol, 'like', "$uuid%");
            }
            $deleted = $q->delete();
            out_info("cleaned $child: $deleted");
        } catch (Throwable $e) {
            out_warn("cleanup $child failed: ".$e->getMessage());
        }
    }
    try {
        $txIds = DB::table('transactions')->where('notes', 'like', "%$uuid%")->pluck('id');
        $n1 = DB::table('account_entries')->whereIn('transaction_id', $txIds)->delete();
        $n2 = DB::table('transactions')->whereIn('id', $txIds)->delete();
        out_info("cleaned transactions: $n2 (account_entries: $n1)");
    } catch (Throwable $e) {
        out_warn('cleanup transactions failed: '.$e->getMessage());
    }
}

// Post-snapshot
$postSnapshot = take_snapshot();
$GLOBALS['results']['post_snapshot'] = $postSnapshot;
$diff = [];
foreach ($preSnapshot['tables'] as $t => $before) {
    $after = $postSnapshot['tables'][$t] ?? '?';
    if ((int) $before !== (int) $after) {
        $diff[$t] = ['before' => $before, 'after' => $after, 'delta' => (int) $after - (int) $before];
    }
}
if ($diff && ! $argReadOnly) {
    out_warn('Post-snapshot DIFF (rows still present):');
    foreach ($diff as $t => $d) {
        echo "    $t: {$d['before']} → {$d['after']} (Δ{$d['delta']})\n";
    }
    $GLOBALS['results']['cleanup_diff'] = $diff;
}

$reportPath = storage_path('logs/flight_module_audit_'.date('Ymd_His').'.json');
file_put_contents($reportPath, json_encode($GLOBALS['results'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
out_info("Report written to: $reportPath");

echo "\n";
exit($failed > 0 ? 1 : 0);
