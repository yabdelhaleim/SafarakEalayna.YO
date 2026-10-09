<?php
/**
 * Tourism Full System + Financial Integrity Audit — Local Runner
 * Date: 2026-08-17
 *
 * Authorized by user directive to use the local MySQL database (safarakealayna)
 * with APP_ENV=local. No migrate:fresh, no DROP, no production writes.
 *
 * All created fixtures are prefixed with "AUDIT_20260817_" so they can be
 * identified and removed at the end.
 *
 * Run:  php tests/reports/audit_runner.php
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

// ─────────────────────────────────────────────────────────────────────────────
// SAFETY GATE
// ─────────────────────────────────────────────────────────────────────────────

$dbName = DB::selectOne('SELECT DATABASE() AS db')->db;
$env    = config('app.env');
$conn   = config('database.default');
$host   = config("database.connections.$conn.host");

if ($dbName !== 'safarakealayna') {
    fwrite(STDERR, "BLOCKED: DB is '$dbName' not 'safarakealayna'\n");
    exit(2);
}
if ($env !== 'local') {
    fwrite(STDERR, "BLOCKED: APP_ENV is '$env' not 'local'\n");
    exit(2);
}

$report = [
    'audit'         => 'TOURISM_FULL_SYSTEM_FINANCIAL_AUDIT',
    'date'          => '2026-08-17',
    'database'      => $dbName,
    'host'          => $host,
    'env'           => $env,
    'setup'         => [],
    'phases'        => [],
    'defects'       => [],
    'audit_ids'     => [],
    'verdict'       => null,
    'verdict_basis' => [],
];

$log = function (string $phase, string $msg, $extra = null) use (&$report) {
    $report['phases'][] = ['phase' => $phase, 'msg' => $msg, 'extra' => $extra, 't' => now()->toIso8601String()];
};

// ─────────────────────────────────────────────────────────────────────────────
// SETUP — capture baseline + create audit-prefixed fixtures
// ─────────────────────────────────────────────────────────────────────────────

$prefix = 'AUDIT_20260817_';

$log('SETUP', 'Capturing baseline');
$baseline = [
    'accounts'        => (int) DB::table('accounts')->count(),
    'account_entries' => (int) DB::table('account_entries')->count(),
    'transactions'    => (int) DB::table('transactions')->count(),
    'customers'       => (int) DB::table('customers')->count(),
    'flight_bookings' => (int) DB::table('flight_bookings')->count(),
    'hajj_umra_bookings' => (int) DB::table('hajj_umra_bookings')->count(),
    'visa_bookings'   => (int) DB::table('visa_bookings')->count(),
    'bus_bookings'    => (int) DB::table('bus_bookings')->count(),
    'online_transactions' => (int) DB::table('online_transactions')->count(),
    'wallet_transactions' => (int) DB::table('wallet_transactions')->count(),
    'fawry_transactions' => (int) DB::table('fawry_transactions')->count(),
    'flight_payments'   => (int) DB::table('flight_payments')->count(),
    'hajj_umra_payments'=> (int) DB::table('hajj_umra_payments')->count(),
    'visa_payments'     => (int) DB::table('visa_payments')->count(),
];
$report['setup']['baseline'] = $baseline;

$log('SETUP', 'Locating admin user + treasury accounts');
$admin = DB::table('users')->where('role', 'admin')->orderBy('id')->first();
if (!$admin) {
    fwrite(STDERR, "BLOCKED: no admin user found\n");
    exit(2);
}
$report['setup']['admin_id'] = (int) $admin->id;
Auth::loginUsingId($admin->id);

// Find a treasury bank Account (EGP)
$egpTreasury = DB::table('accounts')->where('type', 'bank')->where('currency', 'EGP')->orderBy('id')->first();
if (!$egpTreasury) {
    fwrite(STDERR, "BLOCKED: no EGP bank account for treasury\n");
    exit(2);
}
$report['setup']['egp_treasury_id'] = (int) $egpTreasury->id;
$report['setup']['egp_treasury_balance_before'] = (float) $egpTreasury->balance;

// Ensure currencies seeded (needed by FlightBookingService)
$ars = DB::table('currencies')->where('code', 'ARS')->first();
if (!$ars) {
    DB::table('currencies')->insert([
        'code' => 'ARS', 'name_ar' => 'جنيه مصري', 'name_en' => 'Egyptian Pound',
        'symbol' => 'EGP', 'exchange_rate' => 1.0, 'is_active' => 1, 'order' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
$usd = DB::table('currencies')->where('code', 'USD')->first();
if (!$usd) {
    DB::table('currencies')->insert([
        'code' => 'USD', 'name_ar' => 'دولار أمريكي', 'name_en' => 'US Dollar',
        'symbol' => '$', 'exchange_rate' => 50.0, 'is_active' => 1, 'order' => 10,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

// Create audit customer
$customer = DB::table('customers')->insertGetId([
    'full_name'      => "{$prefix}Multi Module Customer",
    'phone'          => '01000000001',
    'email'          => strtolower($prefix) . 'cust@example.com',
    'module_type'    => 'flights',
    'customer_tier'  => 'regular',
    'type'           => 'individual',
    'status'         => 'active',
    'created_by'     => $admin->id,
    'created_at'     => now(),
    'updated_at'     => now(),
]);
$report['audit_ids']['customer_id'] = $customer;
$report['setup']['customer_account_id'] = null;

// Ensure customer has an account (CustomerLedgerObserver normally creates it)
$existingCustAcct = DB::table('customers')->where('id', $customer)->value('account_id');
if ($existingCustAcct) {
    $existingCustAcct = DB::table('accounts')->where('id', $existingCustAcct)->first();
}
if (!$existingCustAcct) {
    // Create manually (canonical safe path)
    $acctId = DB::table('accounts')->insertGetId([
        'name'         => "{$prefix}CustomerAR",
        'type'         => 'customer',
        'currency'     => 'EGP',
        'balance'      => 0,
        'is_active'    => 1,
        'module_type'  => 'flights',
        'module'       => 'flights',
        'created_by'   => $admin->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    DB::table('customers')->where('id', $customer)->update(['account_id' => $acctId]);
    $report['setup']['customer_account_id'] = $acctId;
} else {
    $report['setup']['customer_account_id'] = $existingCustAcct->id;
}

// Create audit employee (needed by some flows)
$emp = DB::table('employees')->insertGetId([
    'user_id' => $admin->id,
    'first_name' => 'AUDIT', 'last_name' => 'EMPLOYEE',
    'full_name'  => "{$prefix}Employee",
    'status'     => 'active',
    'created_at' => now(), 'updated_at' => now(),
]);
$report['audit_ids']['employee_id'] = $emp;

// Save carry forward
$report['setup']['customer_id'] = $customer;
$report['setup']['employee_id'] = $emp;

$report['phases'][] = ['phase' => 'SETUP', 'msg' => 'fixtures created', 'extra' => $report['setup'], 't' => now()->toIso8601String()];

// ─────────────────────────────────────────────────────────────────────────────
// GLOBAL INVARIANT — pre-audit snapshot
// ─────────────────────────────────────────────────────────────────────────────

$globalPre = DB::table('account_entries')
    ->selectRaw('COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c')
    ->first();
$balancePre = DB::table('accounts')->selectRaw('COALESCE(SUM(balance),0) AS b')->first();
$report['setup']['global_pre'] = [
    'debits' => (float) $globalPre->d,
    'credits' => (float) $globalPre->c,
    'balance_sum' => (float) $balancePre->b,
    'diff_debits_credits' => round((float) $globalPre->d - (float) $globalPre->c, 2),
];

// ─────────────────────────────────────────────────────────────────────────────
// PHASE A — DATABASE INTEGRITY INVARIANTS (read-only)
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_A', 'Database integrity invariants');

$invariants = [];

// A.1: every account.balance == SUM(credits) - SUM(debits)
$balanceVariance = DB::table('accounts as a')
    ->leftJoin('account_entries as ae', 'ae.account_id', '=', 'a.id')
    ->groupBy('a.id', 'a.balance')
    ->selectRaw('a.id, a.balance, COALESCE(SUM(ae.credit),0) - COALESCE(SUM(ae.debit),0) AS ledger_balance')
    ->get()
    ->filter(fn ($r) => abs((float) $r->balance - (float) $r->ledger_balance) > 0.005);
$invariants['balance_variance_accounts'] = $balanceVariance->count();
$invariants['max_variance']              = $balanceVariance->max(fn ($r) => abs((float) $r->balance - (float) $r->ledger_balance)) ?? 0;

// A.2: every transaction has balanced debit/credit
$unbalanced = DB::table('transactions as t')
    ->leftJoin('account_entries as ae', 'ae.transaction_id', '=', 't.id')
    ->groupBy('t.id')
    ->selectRaw('t.id, COALESCE(SUM(ae.debit),0) AS d, COALESCE(SUM(ae.credit),0) AS c, COUNT(ae.id) AS n')
    ->get()
    ->filter(fn ($r) => abs((float) $r->d - (float) $r->c) > 0.005 || (int) $r->n === 0);
$invariants['unbalanced_transactions'] = $unbalanced->count();

// A.3: orphan AccountEntry (entry without transaction)
$orphans = DB::table('account_entries as ae')
    ->leftJoin('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->whereNull('t.id')
    ->count();
$invariants['orphan_account_entries'] = $orphans;

// A.4: orphan transaction (transaction without account entries)
$orphanTx = DB::table('transactions as t')
    ->leftJoin('account_entries as ae', 'ae.transaction_id', '=', 't.id')
    ->whereNull('ae.id')
    ->distinct()
    ->count('t.id');
$invariants['orphan_transactions'] = $orphanTx;

// A.5: debit/credit must be non-negative in account_entries
$negAmountRows = DB::table('account_entries')->where('debit', '<', 0)->orWhere('credit', '<', 0)->count();
$invariants['negative_amount_entries'] = $negAmountRows;

// A.6: negative account balances (where category is "subject" / customer / supplier — these MUST allow negative for AR/AP)
$negBalanceAccounts = DB::table('accounts')->where('balance', '<', 0)->count();
$invariants['negative_balance_accounts'] = $negBalanceAccounts;

// A.7: trial balance delta (sum debits - sum credits globally)
$invariants['global_debit_credit_diff'] = round((float) $globalPre->d - (float) $globalPre->c, 2);

$report['phases'][] = ['phase' => 'PHASE_A', 'msg' => 'invariants', 'extra' => $invariants, 't' => now()->toIso8601String()];

// Each variance > 0 becomes a class-A defect
foreach ($invariants as $k => $v) {
    if ($k === 'max_variance') {
        if ($v > 0.005) $report['defects'][] = ['class' => 'A', 'where' => 'invariant', 'key' => $k, 'value' => $v];
    } elseif ($k === 'global_debit_credit_diff') {
        if (abs($v) > 0.005) $report['defects'][] = ['class' => 'A', 'where' => 'invariant', 'key' => $k, 'value' => $v];
    } else {
        if ($v > 0) $report['defects'][] = ['class' => 'A', 'where' => 'invariant', 'key' => $k, 'value' => $v];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PHASE B — DIRECT SERVICE TESTS (Flight, Hajj/Umra, Visa)
// ─────────────────────────────────────────────────────────────────────────────

$results = ['flight' => [], 'hajj' => [], 'visa' => []];

// ── B.1 VISA BOOKING LIFECYCLE ────────────────────────────────────────────────

$log('PHASE_B', 'Visa booking lifecycle');

$visaSvc = app(\App\Services\Visa\VisaBookingService::class);
$visaAgent = DB::table('visa_agents')->first();
$visaDuration = DB::table('visa_durations')->first();
$visaresults = [];

// Use existing flight_carrier / flight_system as fallback vault account for visa
$visaVault = DB::table('accounts')
    ->whereIn('module', ['visa', 'visas'])
    ->where('is_active', 1)
    ->orderBy('id')->first();
if (!$visaVault) {
    $visaVault = DB::table('accounts')->where('is_active', 1)->orderBy('id')->first();
}
$report['setup']['visa_vault_id'] = $visaVault->id ?? null;

try {
    $visaTx = $visaSvc->create([
        'customer_id'         => $customer,
        'visa_agent_id'       => $visaAgent?->id,
        'visa_duration_id'    => $visaDuration?->id,
        'purchase_price'      => 2000,
        'selling_price'       => 3000,
        'service_fee'         => 0,
        'currency'            => 'EGP',
        'notes'               => "{$prefix}initial booking",
        'created_by'          => $admin->id,
        'account_id'          => $report['setup']['customer_account_id'],
        'from_account_id'     => $visaVault->id,
        'payment'             => ['amount' => 1000, 'method' => 'bank', 'account_id' => $egpTreasury->id],
        'with_service_fee'    => false,
    ]);
    $visaresults['created_booking_id'] = $visaTx->id;
    $visaresults['status']              = $visaTx->status?->value ?? (string) $visaTx->status;
    $visaresults['selling']             = (float) $visaTx->selling_price;
    $visaresults['purchase']            = (float) $visaTx->purchase_price;
    $visaresults['profit']              = (float) $visaTx->profit;
    $report['audit_ids']['visa_booking_id'] = $visaTx->id;

    // Verify ledger entries
    $acctEntry = DB::table('account_entries')
        ->where('related_type', 'visa_booking')
        ->where('related_id', $visaTx->id)
        ->get();
    $visaresults['account_entries_count'] = $acctEntry->count();
    $visaresults['account_entries_total'] = round((float) $acctEntry->sum('debit'), 2);

    $report['phases'][] = ['phase' => 'PHASE_B.visa.create', 'msg' => 'PASS', 'extra' => $visaresults, 't' => now()->toIso8601String()];
    $results['visa'][] = ['test' => 'create', 'status' => 'PASS', 'details' => $visaresults];

    // B.1.2 — addPayment idempotency same key
    $idemKey = 'audit-idem-'.uniqid('', true);
    $idempotencyResult = ['first' => null, 'second' => null];
    try {
        $first = $visaSvc->addPayment($visaTx->id, [
            'amount'           => 500,
            'method'           => 'bank',
            'account_id'       => $egpTreasury->id,
            'idempotency_key'  => $idemKey,
        ]);
        $idempotencyResult['first'] = $first->id ?? 'created';
        $second = $visaSvc->addPayment($visaTx->id, [
            'amount'           => 500,
            'method'           => 'bank',
            'account_id'       => $egpTreasury->id,
            'idempotency_key'  => $idemKey, // same key
        ]);
        $idempotencyResult['second'] = $second->id ?? 'second-call:'.(string)($second['error'] ?? 'unknown');
        $idempotencyResult['status'] = $first->id && $first->id === ($second->id ?? null) ? 'IDEMPOTENT' : ($second->id === null ? 'IDEMPOTENT' : 'NOT_IDEMPOTENT');
    } catch (\Throwable $e) {
        $idempotencyResult['exception'] = $e->getMessage();
        $idempotencyResult['status'] = str_contains($e->getMessage(), 'idempot') ? 'IDEMPOTENT_BLOCKED' : 'EXCEPTION';
    }
    $visaresults['idempotency'] = $idempotencyResult;
    $results['visa'][] = ['test' => 'idempotency_same_key', 'status' => $idempotencyResult['status'], 'details' => $idempotencyResult];

    // B.1.3 — full refund flow
    $refundSvc = app(\App\Services\Visa\VisaRefundService::class);
    try {
        $refundSvc->refund($visaTx, "{$prefix} full refund");
        $visaresults['refund_status'] = 'PASS';
    } catch (\Throwable $e) {
        $visaresults['refund_status'] = 'EXCEPTION: '.$e->getMessage();
    }
    $visaresults['refund_attempt_count']   = DB::table('account_entries')->where('related_type', 'visa_booking')->where('related_id', $visaTx->id)->count();
    $visaresults['final_status']           = DB::table('visa_bookings')->where('id', $visaTx->id)->value('status');
    $results['visa'][] = ['test' => 'refund', 'status' => $visaresults['refund_status'], 'details' => $visaresults];

    // B.1.4 — second refund must be rejected
    try {
        $refundSvc->refund($visaTx, "{$prefix} second refund");
        $results['visa'][] = ['test' => 'double_refund', 'status' => 'NOT_REJECTED', 'details' => ['msg' => 'second refund accepted unexpectedly']];
        $report['defects'][] = ['class' => 'A', 'where' => 'visa', 'key' => 'double_refund', 'value' => 'not rejected'];
    } catch (\Throwable $e) {
        $results['visa'][] = ['test' => 'double_refund', 'status' => 'REJECTED', 'details' => ['msg' => $e->getMessage()]];
    }
} catch (\Throwable $e) {
    $visaresults['exception'] = $e->getMessage();
    $report['phases'][] = ['phase' => 'PHASE_B.visa', 'msg' => 'EXCEPTION', 'extra' => $visaresults, 't' => now()->toIso8601String()];
    $report['defects'][] = ['class' => 'A', 'where' => 'visa', 'key' => 'create_or_lifecycle', 'value' => $e->getMessage()];
    $results['visa'][] = ['test' => 'create', 'status' => 'EXCEPTION', 'details' => ['msg' => $e->getMessage()]];
}
$report['phases'][] = ['phase' => 'PHASE_B.visa.summary', 'msg' => 'visa done', 'extra' => $results['visa'], 't' => now()->toIso8601String()];

// ── B.2 HAJJ/UMRAH BOOKING LIFECYCLE ──────────────────────────────────────────

$log('PHASE_B', 'Hajj/Umra booking lifecycle');

$hajjSvc = app(\App\Services\HajjUmra\HajjUmraBookingService::class);
$program = DB::table('programs')->first();
$umrahSup = DB::table('umrah_suppliers')->first();
$execCo = DB::table('hajj_umra_executing_companies')->first();
$hajjresults = [];

$hajjVault = DB::table('accounts')->whereIn('module', ['hajj_umra'])->where('is_active', 1)->orderBy('id')->first();
if (!$hajjVault) {
    $hajjVault = DB::table('accounts')->where('type', 'bank')->where('module_type', 'tourism')->where('is_active', 1)->orderBy('id')->first();
}
if (!$hajjVault) {
    $hajjVault = DB::table('accounts')->where('is_active', 1)->orderBy('id')->first();
}
$report['setup']['hajj_vault_id'] = $hajjVault->id ?? null;

if ($program) {
    try {
        $hajjTx = $hajjSvc->create([
            'customer_id'      => $customer,
            'program_id'       => $program->id,
            'supplier_id'      => $umrahSup?->id,
            'executing_company_id' => $execCo?->id,
            'purchase_price'   => 5000,
            'selling_price'    => 7000,
            'currency'         => 'EGP',
            'notes'            => "{$prefix}initial booking",
            'created_by'       => $admin->id,
            'from_account_id'  => $hajjVault->id,
            'account_id'       => $report['setup']['customer_account_id'],
            'payment'          => ['amount' => 2000, 'method' => 'bank', 'account_id' => $egpTreasury->id],
        ]);
        $hajjresults['created_booking_id'] = $hajjTx->id;
        $hajjresults['status']              = $hajjTx->status?->value ?? (string) $hajjTx->status;
        $hajjresults['selling']             = (float) $hajjTx->selling_price;
        $hajjresults['purchase']            = (float) $hajjTx->purchase_price;
        $hajjresults['profit']              = (float) $hajjTx->profit;
        $report['audit_ids']['hajj_booking_id'] = $hajjTx->id;

        $acctEntry = DB::table('account_entries')
            ->where('related_type', 'hajj_umra_booking')
            ->where('related_id', $hajjTx->id)
            ->get();
        $hajjresults['account_entries_count'] = $acctEntry->count();
        $report['phases'][] = ['phase' => 'PHASE_B.hajj.create', 'msg' => 'PASS', 'extra' => $hajjresults, 't' => now()->toIso8601String()];
        $results['hajj'][] = ['test' => 'create', 'status' => 'PASS', 'details' => $hajjresults];

        // Idempotency
        $idemKey = 'audit-hajj-'.uniqid('', true);
        $idempotencyResult = ['first' => null, 'second' => null];
        try {
            $first = $hajjSvc->addPayment($hajjTx->id, [
                'amount'           => 500,
                'method'           => 'bank',
                'account_id'       => $egpTreasury->id,
                'idempotency_key'  => $idemKey,
            ]);
            $idempotencyResult['first'] = $first->id ?? 'created';
            $second = $hajjSvc->addPayment($hajjTx->id, [
                'amount'           => 500,
                'method'           => 'bank',
                'account_id'       => $egpTreasury->id,
                'idempotency_key'  => $idemKey,
            ]);
            $idempotencyResult['second'] = $second->id ?? null;
            $idempotencyResult['status'] = ($first->id ?? null) === ($second->id ?? null) ? 'IDEMPOTENT' : 'NOT_IDEMPOTENT';
        } catch (\Throwable $e) {
            $idempotencyResult['status'] = str_contains($e->getMessage(), 'idempot') || str_contains($e->getMessage(), 'مكرر') ? 'IDEMPOTENT_BLOCKED' : 'EXCEPTION';
            $idempotencyResult['exception'] = $e->getMessage();
        }
        $hajjresults['idempotency'] = $idempotencyResult;
        $results['hajj'][] = ['test' => 'idempotency_same_key', 'status' => $idempotencyResult['status'], 'details' => $idempotencyResult];

        // Cancellation
        try {
            $hajjSvc->cancel($hajjTx, "{$prefix} cancel");
            $hajjresults['cancel_status'] = 'PASS';
        } catch (\Throwable $e) {
            $hajjresults['cancel_status'] = 'EXCEPTION: '.$e->getMessage();
        }
        $hajjresults['final_status'] = DB::table('hajj_umra_bookings')->where('id', $hajjTx->id)->value('status');
        $results['hajj'][] = ['test' => 'cancel', 'status' => $hajjresults['cancel_status'], 'details' => $hajjresults];

        // Double cancel must be rejected
        try {
            $hajjSvc->cancel($hajjTx, "{$prefix} double cancel");
            $results['hajj'][] = ['test' => 'double_cancel', 'status' => 'NOT_REJECTED', 'details' => ['msg' => 'second cancel accepted unexpectedly']];
            $report['defects'][] = ['class' => 'A', 'where' => 'hajj', 'key' => 'double_cancel', 'value' => 'not rejected'];
        } catch (\Throwable $e) {
            $results['hajj'][] = ['test' => 'double_cancel', 'status' => 'REJECTED', 'details' => ['msg' => $e->getMessage()]];
        }
    } catch (\Throwable $e) {
        $report['phases'][] = ['phase' => 'PHASE_B.hajj', 'msg' => 'EXCEPTION', 'extra' => ['msg' => $e->getMessage()], 't' => now()->toIso8601String()];
        $report['defects'][] = ['class' => 'A', 'where' => 'hajj', 'key' => 'create_or_lifecycle', 'value' => $e->getMessage()];
        $results['hajj'][] = ['test' => 'create', 'status' => 'EXCEPTION', 'details' => ['msg' => $e->getMessage()]];
    }
} else {
    $results['hajj'][] = ['test' => 'skip', 'status' => 'BLOCKED', 'details' => ['reason' => 'no program found']];
    $report['phases'][] = ['phase' => 'PHASE_B.hajj', 'msg' => 'BLOCKED', 'extra' => ['reason' => 'no program'], 't' => now()->toIso8601String()];
}
$report['phases'][] = ['phase' => 'PHASE_B.hajj.summary', 'msg' => 'hajj done', 'extra' => $results['hajj'], 't' => now()->toIso8601String()];

// ── B.3 FLIGHT BOOKING LIFECYCLE ──────────────────────────────────────────────

$log('PHASE_B', 'Flight booking lifecycle');

$flightSvc = app(\App\Services\Flight\FlightBookingService::class);
$flightresults = [];

// Pick a flight system that exists with a balance
$flightSystem = DB::table('flight_systems')->orderBy('id')->first();
$flightCarrier = DB::table('flight_carriers')->orderBy('id')->first();
$flightVault = DB::table('accounts')->where('type', 'bank')->where('currency', 'EGP')->orderBy('id')->first();
$airlineAcct = DB::table('airline_accounts')->orderBy('id')->first();

$report['setup']['flight_system_id'] = $flightSystem->id ?? null;
$report['setup']['flight_carrier_id'] = $flightCarrier->id ?? null;
$report['setup']['airline_account_id'] = $airlineAcct->id ?? null;

if ($flightSystem) {
    try {
        $flightTx = $flightSvc->createBooking([
            'customer_id'        => $customer,
            'employee_id'        => $emp,
            'booking_channel_type' => 'sign',
            'system_type'        => 'manual',
            'airline'            => 'AUDIT',
            'airline_name'       => 'AUDIT',
            'origin'             => 'CAI',
            'destination'        => 'JED',
            'from_airport'       => 'CAI',
            'to_airport'         => 'JED',
            'departure_date'     => now()->addDays(30)->toDateString(),
            'departure_time'     => '12:00',
            'passenger_count'    => 1,
            'passengers_count'   => 1,
            'passengers'         => [
                ['full_name' => 'AUDIT Passenger', 'passport_number' => 'A12345', 'passenger_type' => 'adult'],
            ],
            'purchase_price'     => 4000,
            'selling_price'      => 6000,
            'currency'           => 'EGP',
            'pnr'                => 'AUDIT1',
            'status'             => 'confirmed',
            'flight_system_id'   => $flightSystem->id,
            'flight_carrier_id'  => $flightCarrier?->id,
            'airline_account_id' => $airlineAcct?->id,
            'account_id'         => $report['setup']['customer_account_id'],
            'payment'            => ['amount' => 2000, 'method' => 'bank', 'account_id' => $egpTreasury->id],
            'notes'              => "{$prefix}initial booking",
        ]);
        $flightresults['created_booking_id'] = $flightTx->id;
        $flightresults['status']              = $flightTx->status?->value ?? (string) $flightTx->status;
        $flightresults['selling']             = (float) $flightTx->selling_price;
        $flightresults['purchase']            = (float) $flightTx->purchase_price;
        $flightresults['profit']              = (float) $flightTx->profit;
        $report['audit_ids']['flight_booking_id'] = $flightTx->id;

        $acctEntry = DB::table('account_entries')
            ->where('related_type', 'flight_booking')
            ->where('related_id', $flightTx->id)
            ->get();
        $flightresults['account_entries_count'] = $acctEntry->count();
        $report['phases'][] = ['phase' => 'PHASE_B.flight.create', 'msg' => 'PASS', 'extra' => $flightresults, 't' => now()->toIso8601String()];
        $results['flight'][] = ['test' => 'create', 'status' => 'PASS', 'details' => $flightresults];

        // Idempotency check
        $idemKey = 'audit-flight-'.uniqid('', true);
        $idempotencyResult = ['first' => null, 'second' => null];
        try {
            $first = $flightSvc->addPayment($flightTx->id, [
                'amount'           => 500,
                'method'           => 'bank',
                'account_id'       => $egpTreasury->id,
                'idempotency_key'  => $idemKey,
            ]);
            $idempotencyResult['first'] = $first->id ?? 'created';
            $second = $flightSvc->addPayment($flightTx->id, [
                'amount'           => 500,
                'method'           => 'bank',
                'account_id'       => $egpTreasury->id,
                'idempotency_key'  => $idemKey,
            ]);
            $idempotencyResult['second'] = $second->id ?? null;
            $idempotencyResult['status'] = ($first->id ?? null) === ($second->id ?? null) ? 'IDEMPOTENT' : 'NOT_IDEMPOTENT';
        } catch (\Throwable $e) {
            $idempotencyResult['status'] = str_contains($e->getMessage(), 'idempot') || str_contains($e->getMessage(), 'مكرر') ? 'IDEMPOTENT_BLOCKED' : 'EXCEPTION';
            $idempotencyResult['exception'] = $e->getMessage();
        }
        $flightresults['idempotency'] = $idempotencyResult;
        $results['flight'][] = ['test' => 'idempotency_same_key', 'status' => $idempotencyResult['status'], 'details' => $idempotencyResult];
    } catch (\Throwable $e) {
        $report['phases'][] = ['phase' => 'PHASE_B.flight', 'msg' => 'EXCEPTION', 'extra' => ['msg' => $e->getMessage()], 't' => now()->toIso8601String()];
        $report['defects'][] = ['class' => 'A', 'where' => 'flight', 'key' => 'create_or_lifecycle', 'value' => $e->getMessage()];
        $results['flight'][] = ['test' => 'create', 'status' => 'EXCEPTION', 'details' => ['msg' => $e->getMessage()]];
    }
} else {
    $results['flight'][] = ['test' => 'skip', 'status' => 'BLOCKED', 'details' => ['reason' => 'no flight_system']];
    $report['phases'][] = ['phase' => 'PHASE_B.flight', 'msg' => 'BLOCKED', 'extra' => ['reason' => 'no flight_system'], 't' => now()->toIso8601String()];
}
$report['phases'][] = ['phase' => 'PHASE_B.flight.summary', 'msg' => 'flight done', 'extra' => $results['flight'], 't' => now()->toIso8601String()];

$report['results'] = $results;

// ─────────────────────────────────────────────────────────────────────────────
// PHASE C — REPORT CROSS-CHECKS
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_C', 'Report cross-checks');

$reportChecks = [];

try {
    $profit = app(\App\Services\Reports\ProfitLossReportService::class);
    $noF = $profit->moduleBreakdown(null, null);
    $reportChecks['pl_modulebreakdown_summary'] = array_map(fn ($r) => [
        'module' => $r->module ?? 'unknown',
        'revenue' => (float) ($r->total_income ?? 0),
        'expense' => (float) ($r->total_expense ?? 0),
        'profit' => (float) ($r->profit ?? 0),
    ], (array) $noF);
} catch (\Throwable $e) {
    $reportChecks['pl_error'] = $e->getMessage();
}

try {
    $fin = app(\App\Services\Reports\FinancialReportService::class);
    $global = $fin->getFinancialSummary(null, null);
    $reportChecks['fin_summary_keys'] = is_array($global) ? array_keys($global) : (is_object($global) ? array_keys((array) $global) : 'scalar');
} catch (\Throwable $e) {
    $reportChecks['fin_error'] = $e->getMessage();
}

// Independently compute totals from underlying tables
$indep = DB::table('account_entries as ae')
    ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->where('t.type', 'income')
    ->selectRaw('COALESCE(SUM(ae.credit),0) AS income, COALESCE(SUM(ae.debit),0) AS income_debit')
    ->first();
$reportChecks['independent_income_credit'] = (float) $indep->income;

$indepExp = DB::table('account_entries as ae')
    ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->where('t.type', 'expense')
    ->selectRaw('COALESCE(SUM(ae.debit),0) AS expense, COALESCE(SUM(ae.credit),0) AS expense_credit')
    ->first();
$reportChecks['independent_expense_debit'] = (float) $indepExp->expense;

$report['phases'][] = ['phase' => 'PHASE_C', 'msg' => 'report checks', 'extra' => $reportChecks, 't' => now()->toIso8601String()];

// ─────────────────────────────────────────────────────────────────────────────
// PHASE D — CUSTOMER DEBT INTEGRITY
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_D', 'Customer debt integrity');

$accts = DB::table('accounts')->where('id', $report['setup']['customer_account_id'])->get();
$totalCustomerBalance = (float) $accts->sum('balance');
$report['phases'][] = ['phase' => 'PHASE_D', 'msg' => 'customer audit-customer balances', 'extra' => [
    'accounts_count' => $accts->count(),
    'total_balance'  => $totalCustomerBalance,
    'accounts'       => $accts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'module' => $a->module, 'balance' => (float) $a->balance])->values(),
], 't' => now()->toIso8601String()];

// ─────────────────────────────────────────────────────────────────────────────
// PHASE E — POST-RUN INVARIANTS
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_E', 'Post-run invariants');

$globalPost = DB::table('account_entries')
    ->selectRaw('COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c')
    ->first();
$balancePost = DB::table('accounts')->selectRaw('COALESCE(SUM(balance),0) AS b')->first();
$report['post'] = [
    'debits' => (float) $globalPost->d,
    'credits' => (float) $globalPost->c,
    'balance_sum' => (float) $balancePost->b,
    'diff_debits_credits' => round((float) $globalPost->d - (float) $globalPost->c, 2),
    'diff_pre_post_debits' => round((float) $globalPost->d - (float) $globalPre->d, 2),
    'diff_pre_post_credits' => round((float) $globalPost->c - (float) $globalPre->c, 2),
];

$postInv = [];
$balanceVariancePost = DB::table('accounts as a')
    ->leftJoin('account_entries as ae', 'ae.account_id', '=', 'a.id')
    ->groupBy('a.id', 'a.balance')
    ->selectRaw('a.id, a.balance, COALESCE(SUM(ae.credit),0) - COALESCE(SUM(ae.debit),0) AS ledger_balance')
    ->get()
    ->filter(fn ($r) => abs((float) $r->balance - (float) $r->ledger_balance) > 0.005);
$postInv['balance_variance_accounts'] = $balanceVariancePost->count();

$unbalancedPost = DB::table('transactions as t')
    ->leftJoin('account_entries as ae', 'ae.transaction_id', '=', 't.id')
    ->groupBy('t.id')
    ->selectRaw('t.id, COALESCE(SUM(ae.debit),0) AS d, COALESCE(SUM(ae.credit),0) AS c, COUNT(ae.id) AS n')
    ->get()
    ->filter(fn ($r) => abs((float) $r->d - (float) $r->c) > 0.005 || (int) $r->n === 0);
$postInv['unbalanced_transactions'] = $unbalancedPost->count();

$report['phases'][] = ['phase' => 'PHASE_E', 'msg' => 'post-run invariants', 'extra' => $postInv, 't' => now()->toIso8601String()];

foreach ($postInv as $k => $v) {
    if ($v > 0) $report['defects'][] = ['class' => 'A', 'where' => 'post_invariant', 'key' => $k, 'value' => $v];
}

// ─────────────────────────────────────────────────────────────────────────────
// VERDICT
// ─────────────────────────────────────────────────────────────────────────────

$classA = collect($report['defects'])->where('class', 'A')->count();
$classB = collect($report['defects'])->where('class', 'B')->count();
$hasVariance = abs($report['post']['diff_debits_credits']) > 0.005;
$hasBalanceVariance = $postInv['balance_variance_accounts'] > 0 || $postInv['unbalanced_transactions'] > 0;

$report['verdict_basis'] = [
    'class_a' => $classA,
    'class_b' => $classB,
    'has_variance' => $hasVariance,
    'has_balance_variance' => $hasBalanceVariance,
    'global_post_diff' => $report['post']['diff_debits_credits'],
];

$noGo = $classA > 0 || $hasVariance || $hasBalanceVariance;
$report['verdict'] = $noGo ? 'NO-GO' : 'GO';

$report['phases'][] = ['phase' => 'VERDICT', 'msg' => $report['verdict'], 'extra' => $report['verdict_basis'], 't' => now()->toIso8601String()];

// Write report
$reportsDir = __DIR__;
if (!is_dir($reportsDir)) {
    mkdir($reportsDir, 0755, true);
}
file_put_contents($reportsDir . '/TOURISM_AUDIT_RUN_20260817.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode([
    'verdict' => $report['verdict'],
    'class_a' => $classA,
    'class_b' => $classB,
    'global_post_diff' => $report['post']['diff_debits_credits'],
    'balance_variance_post' => $postInv['balance_variance_accounts'],
    'unbalanced_tx_post' => $postInv['unbalanced_transactions'],
    'audit_ids' => $report['audit_ids'],
    'report_path' => $reportsDir . '/TOURISM_AUDIT_RUN_20260817.json',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
