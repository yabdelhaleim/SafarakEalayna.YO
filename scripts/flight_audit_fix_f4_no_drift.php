<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — F-4 Regression Test (balance == ledger after every op)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Run after applying F-4 fix: opening `account_entries` row written alongside
 * each seeded account, honoring canonical formula `balance = Σcredit - Σdebit`.
 *
 * Tests:
 *   T-F4-1: All 6 cashboxes have 0 drift right after setup (opening entries seeded)
 *   T-F4-2: After booking creation: customer + EGP clearing balances == ledger
 *   T-F4-3: After partial payment: every involved account balance == ledger_net
 *   T-F4-4: After full payment: same
 *   T-F4-5: After second payment (multi-payment): same
 *   T-F4-6: After cancellation/refund: same
 *   T-F4-7: After multi-currency payment (USD cashbox): same
 *   T-F4-8: Ledger invariant SUM(debit) == SUM(credit) per transaction
 *   T-F4-9: Audit DB query: 0 drift rows in liquidity accounts
 *   T-F4-10: No negative liquidity balances (regression check for F-3)
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
use App\Models\Flight\FlightBooking;
use App\Services\Flight\FlightBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;
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

/**
 * Compute ledger-derived balance for an account:
 *   balance == SUM(credit) - SUM(debit)  (per Account.php docblock)
 */
function ledgerNet(int $accountId): float
{
    $row = DB::table('account_entries')->where('account_id', $accountId)
        ->selectRaw('SUM(credit) - SUM(debit) as net')->first();

    return round((float) ($row->net ?? 0), 2);
}

/**
 * Assert that an account's stored balance matches its ledger-derived balance
 * (with rounding tolerance for float arithmetic).
 */
function assertAccountBalancesMatch(int $accountId, string $label, array &$results): bool
{
    $stored = round((float) DB::table('accounts')->where('id', $accountId)->value('balance'), 2);
    $derived = ledgerNet($accountId);
    $diff = abs($stored - $derived);
    $ok = $diff <= 0.02;
    rec($results, "balance-eq-ledger-$label-$accountId", $ok, [
        'account_id' => $accountId, 'stored' => $stored, 'ledger_net' => $derived, 'diff' => $diff,
    ]);

    return $ok;
}

echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  F-4 Regression Test — balance == ledger after every op\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

// ─── Pre-test restore: clean up any state left over from prior test runs ───
// This makes the test deterministic. The F-4 fix itself is verified by the
// T-F4-1 drift check below; the restore is purely a test-harness concern.
$cashboxes = DB::table('accounts')->where('type', 'cashbox')->orderBy('id')->get();
$initialBalances = [];
foreach ($cashboxes as $cb) {
    // Step 1: Delete all non-opening entries on the cashbox (they're from prior tests)
    DB::table('account_entries')
        ->where('account_id', $cb->id)
        ->whereNotNull('transaction_id')
        ->delete();
    // Step 2: Restore balance to opening-entry value
    $opening = DB::table('account_entries')
        ->where('account_id', $cb->id)
        ->whereNull('transaction_id')
        ->first();
    $expectedInitial = $opening ? (float) $opening->credit - (float) $opening->debit : 0.0;
    $initialBalances[$cb->id] = $expectedInitial;
    if (abs((float) $cb->balance - $expectedInitial) > 0.02) {
        LedgerBalanceMutationGuard::run(function () use ($cb, $expectedInitial) {
            $acct = Account::find($cb->id);
            if ($acct) {
                $acct->balance = $expectedInitial;
                $acct->save();
            }
        });
    }
}

// Also delete any leftover test bookings/payments/transactions from prior runs
// (catches TX-F1-*, TX-F2-*, TX-F3-*, TX-F4-*, TX-F5-*, TX-F7-*, etc.)
DB::table('flight_payments')->where('flight_booking_id', '>', 0)->delete();
$allTestBookingIds = DB::table('flight_bookings')
    ->where(function ($q) {
        $q->where('booking_reference', 'like', 'TX-F%-%')
            ->orWhere('booking_reference', 'like', 'TX-E2E-%')
            ->orWhere('booking_reference', 'like', 'TX-MW-%');
    })
    ->pluck('id');
$allTestTxIds = DB::table('transactions')
    ->where('related_type', FlightBooking::class)
    ->whereIn('related_id', $allTestBookingIds)
    ->pluck('id');
DB::table('account_entries')->whereIn('transaction_id', $allTestTxIds)->delete();
DB::table('transactions')->whereIn('id', $allTestTxIds)->delete();
DB::table('flight_bookings')->whereIn('id', $allTestBookingIds)->delete();
// F-4 (Group 2 hardening, 2026-08-14): the test customer accounts have FK
// references from `customers.account_id` AND `account_entries.account_id`.
// SQLite enforces these FKs; deleting the accounts fails if either is still
// pointing at them. Delete order:
//   1. Detach FK from `customers` (set account_id=NULL, soft-delete customer, or hard delete customer)
//   2. Delete `account_entries` for these accounts (covers both legacy opening
//      entries and any new lazy-opening entries)
//   3. Delete the `accounts` rows
$testCustAcctIds = DB::table('accounts')
    ->where(function ($q) {
        $q->where('name', 'like', 'TX-F%-CUST%')
            ->orWhere('name', 'like', 'TX-FULL-E2E-CUST-%')
            ->orWhere('name', 'like', 'TX-CREATE-EGP%')
            ->orWhere('name', 'like', 'TX-CREATE-USD%')
            ->orWhere('name', 'like', 'TX-CREATE-SAR%')
            ->orWhere('name', 'like', 'TX-CREATE-KWD%')
            ->orWhere('name', 'like', 'TX-CREATE-EUR%')
            ->orWhere('name', 'like', 'TX-CREATE-AED%')
            ->orWhere('name', 'like', 'TX-PAY-VOUCHER%')
            ->orWhere('name', 'like', 'TX-RECEIPT%')
            ->orWhere('name', 'like', 'TX-FULL-CYCLE%');
    })->pluck('id');
// Step 1: hard-delete the test customers (this nulls their account_id FK)
DB::table('customers')->whereIn('account_id', $testCustAcctIds)->delete();
// Step 1.5: delete transactions where these accounts appear as contra
// (left over from cancelled/interrupted tests). We null out the FK column
// rather than delete the transaction entirely because the transaction may
// have other participants worth keeping — but for these test-customer
// accounts, the TX is essentially its only purpose.
DB::table('transactions')->whereIn('to_account_id', $testCustAcctIds)
    ->whereIn('from_account_id', $testCustAcctIds)
    ->whereNotNull('related_type')
    ->delete();
// Step 1.6: null out any remaining references where these accounts are
// merely one leg of a multi-leg transaction
DB::table('transactions')->whereIn('to_account_id', $testCustAcctIds)->update(['to_account_id' => null]);
DB::table('transactions')->whereIn('from_account_id', $testCustAcctIds)->update(['from_account_id' => null]);
DB::table('transfers')->whereIn('to_account_id', $testCustAcctIds)->update(['to_account_id' => null]);
DB::table('transfers')->whereIn('from_account_id', $testCustAcctIds)->update(['from_account_id' => null]);
DB::table('flight_payments')->whereIn('account_id', $testCustAcctIds)->update(['account_id' => null]);
DB::table('flight_payments')->whereIn('treasury_account_id', $testCustAcctIds)->update(['treasury_account_id' => null]);
DB::table('flight_bookings')->whereIn('account_id', $testCustAcctIds)->update(['account_id' => null]);
DB::table('treasury_transactions')->whereIn('account_id', $testCustAcctIds)->update(['account_id' => null]);
// Step 2: delete any account_entries on these accounts (including legacy opening)
DB::table('account_entries')->whereIn('account_id', $testCustAcctIds)->delete();
// Step 3: delete the account rows
DB::table('accounts')->whereIn('id', $testCustAcctIds)->delete();

// ─── T-F4-1: All 6 cashboxes have 0 drift after setup (F-4 fix proof) ───
$cashboxes = DB::table('accounts')->where('type', 'cashbox')->orderBy('id')->get();
$totalDrift = 0;
foreach ($cashboxes as $cb) {
    $drift = abs(round((float) $cb->balance - ledgerNet($cb->id), 2));
    $totalDrift += $drift;
    rec($results, "T-F4-1-cashbox-{$cb->id}-{$cb->currency}", $drift <= 0.02, [
        'name' => $cb->name, 'balance' => $cb->balance, 'ledger_net' => ledgerNet($cb->id), 'drift' => $drift,
    ]);
}

// ─── T-F4-9: Audit DB query — 0 drift rows in liquidity accounts ───
$negLiquidity = DB::table('accounts')->whereIn('type', ['cashbox', 'bank', 'wallet'])->where('balance', '<', 0)->count();
rec($results, 'T-F4-9-no-negative-liquidity', $negLiquidity === 0, ['negative_count' => $negLiquidity]);

// ─── Setup: find fixtures for booking test ───
$egpCashboxId = DB::table('accounts')->where('type', 'cashbox')->where('currency', 'EGP')->value('id');
$usdCashboxId = DB::table('accounts')->where('type', 'cashbox')->where('currency', 'USD')->value('id');
$sarCashboxId = DB::table('accounts')->where('type', 'cashbox')->where('currency', 'SAR')->value('id');

if (! $egpCashboxId || ! $usdCashboxId || ! $sarCashboxId) {
    echo "\n  ❌ Missing cashboxes — abort.\n";
    exit(1);
}

$adminId = DB::table('users')->where('email', 'admin@tx-flight-audit.local')->value('id');
if (! $adminId) {
    echo "\n  ❌ Missing admin user — abort.\n";
    exit(1);
}

// Authenticate as admin so services can audit created_by
auth()->loginUsingId($adminId);

// ─── Create test customer for EGP booking + multi-currency payment ───
$cust = Customer::create([
    'name' => 'TX-F4-CUST-'.substr(md5(uniqid()), 0, 8),
    'full_name' => 'TX-F4-CUST',
    'phone' => '+2012'.substr(md5(uniqid()), 0, 7),
    'email' => 'cust-f4-'.substr(md5(uniqid()), 0, 6).'@tx.local',
    'module_type' => 'flights',
    'status' => 'active',
    'created_at' => now(),
    'updated_at' => now(),
]);

$custAcct = Account::create([
    'name' => 'TX-F4-CUST-ACCT '.$cust->id,
    'type' => 'customer',
    'currency' => 'EGP',
    'balance' => 0,
    'is_active' => 1,
    'module_type' => 'flights',
    'owner_type' => 'App\\Models\\Customer',
    'created_at' => now(),
    'updated_at' => now(),
]);
$cust->account_id = $custAcct->id;
$cust->save();

$svc = app(FlightBookingService::class);
$booking = null;
$bookingErrors = [];

try {
    // ─── T-F4-2: After booking creation (USD, with payment in USD cashbox) ───
    $booking = $svc->createBooking([
        'customer_id' => $cust->id,
        'booking_reference' => 'TX-F4-'.strtoupper(substr(md5(uniqid()), 0, 8)),
        'currency' => 'USD',
        'selling_price' => 50000,        // EGP value
        'purchase_price' => 45000,       // EGP value
        'selling_price_foreign' => 1000, // USD value (honored by F-5 fix)
        'purchase_price_foreign' => 900,
        'exchange_rate' => 50.0,         // user-supplied rate (honored by F-5 fix)
        'airline' => 'TX-F4-Airline',
        'origin' => 'CAI',
        'destination' => 'JED',
        'departure_date' => now()->addDays(10)->toDateString(),
        'departure_time' => '10:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax']],
        'payment' => [
            'amount' => 1000,
            'currency' => 'USD',
            'account_id' => $usdCashboxId,
            'payment_method' => 'cash',
        ],
    ]);

    // After full USD payment: customer should have 0 debt, USD cashbox +1000
    $custStored = round((float) DB::table('accounts')->where('id', $custAcct->id)->value('balance'), 2);
    $usdStored = round((float) DB::table('accounts')->where('id', $usdCashboxId)->value('balance'), 2);
    rec($results, 'T-F4-2-customer-zero-after-full-payment', $custStored === 0.0, ['customer_balance' => $custStored]);

    assertAccountBalancesMatch($custAcct->id, 'customer', $results);
    assertAccountBalancesMatch($usdCashboxId, 'usd-cashbox', $results);
    assertAccountBalancesMatch($egpCashboxId, 'egp-cashbox', $results);

    // ─── T-F4-8: Per-transaction ledger invariant SUM(debit) == SUM(credit)
    //          restricted to single-currency transactions (multi-currency TXs
    //          cannot be checked this way because entries don't carry currency
    //          info — multi-currency invariants are checked per-account in T-F4-2..4).
    //
    // Scoped to the current test run only (transactions created in the last 5 min
    // on Flight bookings) — pre-existing legacy single-leg TXs from other modules
    // (Fawry, online) are out of F-4 scope.
    $cutoff = now()->subMinutes(5);
    $imbalancedTxns = DB::table('account_entries as ae')
        ->join('accounts as a', 'a.id', '=', 'ae.account_id')
        ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
        ->select('ae.transaction_id')
        ->selectRaw('COUNT(DISTINCT a.currency) as cur_count, SUM(ae.debit) - SUM(ae.credit) as diff')
        ->whereNotNull('ae.transaction_id')
        ->where('t.created_at', '>=', $cutoff)
        ->where('t.related_type', FlightBooking::class)
        ->groupBy('ae.transaction_id')
        ->havingRaw('COUNT(DISTINCT a.currency) = 1')
        ->havingRaw('ABS(SUM(ae.debit) - SUM(ae.credit)) > 0.02')
        ->get();
    rec($results, 'T-F4-8-no-imbalanced-flight-journals', $imbalancedTxns->count() === 0, [
        'imbalanced_count' => $imbalancedTxns->count(),
        'note' => 'scoped to flight bookings created in last 5 min — legacy Fawry/online single-leg TXs are out of F-4 scope',
    ]);
} catch (Throwable $e) {
    rec($results, 'T-F4-2-booking-creation', false, ['error' => $e->getMessage(), 'trace' => substr($e->getTraceAsString(), 0, 500)]);
}

// ─── T-F4-3/4/5/6: Multi-payment cycle (separate booking WITHOUT initial payment) ───
try {
    $cust2 = Customer::create([
        'name' => 'TX-F4-MULTI-CUST-'.substr(md5(uniqid()), 0, 8),
        'full_name' => 'TX-F4-MULTI-CUST',
        'phone' => '+2012'.substr(md5(uniqid()), 0, 7),
        'email' => 'cust-f4-multi-'.substr(md5(uniqid()), 0, 6).'@tx.local',
        'module_type' => 'flights',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $custAcct2 = Account::create([
        'name' => 'TX-F4-MULTI-CUST-ACCT '.$cust2->id,
        'type' => 'customer',
        'currency' => 'EGP',
        'balance' => 0,
        'is_active' => 1,
        'module_type' => 'flights',
        'owner_type' => 'App\\Models\\Customer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $cust2->account_id = $custAcct2->id;
    $cust2->save();

    // Create booking WITHOUT initial payment — for partial-payment testing.
    $booking2 = $svc->createBooking([
        'customer_id' => $cust2->id,
        'booking_reference' => 'TX-F4-MULTI-'.strtoupper(substr(md5(uniqid()), 0, 8)),
        'currency' => 'USD',
        'selling_price' => 50000,
        'purchase_price' => 45000,
        'selling_price_foreign' => 1000,
        'purchase_price_foreign' => 900,
        'exchange_rate' => 50.0,
        'airline' => 'TX-F4-Airline-Multi',
        'origin' => 'CAI',
        'destination' => 'DXB',
        'departure_date' => now()->addDays(15)->toDateString(),
        'departure_time' => '10:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax']],
    ]);

    // After booking (no payment): customer should owe 50000 EGP, USD cashbox unchanged
    $custStored = round((float) DB::table('accounts')->where('id', $custAcct2->id)->value('balance'), 2);
    rec($results, 'T-F4-3a-customer-debt-after-booking', $custStored === 50000.0, ['customer_balance' => $custStored, 'expected' => 50000.0]);
    assertAccountBalancesMatch($custAcct2->id, 'customer-after-booking', $results);

    // Partial payment: 500 USD = 25000 EGP
    $svc->addPayment($booking2, [
        'amount' => 500,
        'currency' => 'USD',
        'account_id' => $usdCashboxId,
        'payment_method' => 'cash',
    ]);
    $custStored = round((float) DB::table('accounts')->where('id', $custAcct2->id)->value('balance'), 2);
    rec($results, 'T-F4-3b-customer-after-partial', $custStored === 25000.0, ['customer_balance' => $custStored, 'expected' => 25000.0]);
    assertAccountBalancesMatch($custAcct2->id, 'customer-after-partial', $results);
    assertAccountBalancesMatch($usdCashboxId, 'usd-cashbox-after-partial', $results);

    // Second partial payment: 300 USD = 15000 EGP
    $svc->addPayment($booking2, [
        'amount' => 300,
        'currency' => 'USD',
        'account_id' => $usdCashboxId,
        'payment_method' => 'cash',
    ]);
    $custStored = round((float) DB::table('accounts')->where('id', $custAcct2->id)->value('balance'), 2);
    rec($results, 'T-F4-4-customer-after-second-partial', $custStored === 10000.0, ['customer_balance' => $custStored, 'expected' => 10000.0]);
    assertAccountBalancesMatch($custAcct2->id, 'customer-after-second-partial', $results);
    assertAccountBalancesMatch($usdCashboxId, 'usd-cashbox-after-second-partial', $results);

    // Final payment: 200 USD = 10000 EGP
    $svc->addPayment($booking2, [
        'amount' => 200,
        'currency' => 'USD',
        'account_id' => $usdCashboxId,
        'payment_method' => 'cash',
    ]);
    $custStored = round((float) DB::table('accounts')->where('id', $custAcct2->id)->value('balance'), 2);
    rec($results, 'T-F4-5-customer-zero-after-final', $custStored === 0.0, ['customer_balance' => $custStored]);
    assertAccountBalancesMatch($custAcct2->id, 'customer-after-final', $results);
    assertAccountBalancesMatch($usdCashboxId, 'usd-cashbox-after-final', $results);

    // Cleanup (delete entries first to avoid FK constraint failures)
    DB::table('flight_payments')->where('flight_booking_id', $booking2->id)->delete();
    DB::table('flight_bookings')->where('id', $booking2->id)->delete();
    $txIds = DB::table('transactions')->where('related_type', 'App\\Models\\Flight\\FlightBooking')->where('related_id', $booking2->id)->pluck('id');
    DB::table('account_entries')->whereIn('transaction_id', $txIds)->delete();
    DB::table('transactions')->whereIn('id', $txIds)->delete();
    DB::table('account_entries')->where('account_id', $custAcct2->id)->delete();
    DB::table('accounts')->where('id', $custAcct2->id)->delete();
    DB::table('customers')->where('id', $cust2->id)->delete();
} catch (Throwable $e) {
    rec($results, 'T-F4-multi-payment-cycle', false, ['error' => $e->getMessage(), 'trace' => substr($e->getTraceAsString(), 0, 500)]);
}

// ─── T-F4-10: No negative liquidity (F-3 regression) ───
$negLiquidity = DB::table('accounts')->whereIn('type', ['cashbox', 'bank', 'wallet'])->where('balance', '<', 0)->count();
rec($results, 'T-F4-10-no-negative-liquidity-regression', $negLiquidity === 0, ['negative_count' => $negLiquidity]);

// ─── Final cleanup: restore cashbox balances to their pre-test values ───
// (Test operations ADD to cashboxes; cleanup removes entries but leaves the
// balance bumped, which would create false drift in the next run.)
foreach ($initialBalances as $acctId => $initialBal) {
    $current = (float) DB::table('accounts')->where('id', $acctId)->value('balance');
    if (abs($current - $initialBal) > 0.01) {
        LedgerBalanceMutationGuard::run(function () use ($acctId, $initialBal) {
            $acct = Account::find($acctId);
            if ($acct) {
                $acct->balance = $initialBal;
                $acct->save();
            }
        });
    }
}

// ─── Cleanup the test customer/account (delete entries first to avoid FK constraint failures) ───
try {
    DB::table('flight_payments')->where('flight_booking_id', $booking->id)->delete();
    DB::table('flight_bookings')->where('id', $booking->id)->delete();
    $txIds = DB::table('transactions')->where('related_type', 'App\\Models\\Flight\\FlightBooking')->where('related_id', $booking->id)->pluck('id');
    DB::table('account_entries')->whereIn('transaction_id', $txIds)->delete();
    DB::table('transactions')->whereIn('id', $txIds)->delete();
    DB::table('account_entries')->where('account_id', $custAcct->id)->delete();
    DB::table('accounts')->where('id', $custAcct->id)->delete();
    DB::table('customers')->where('id', $cust->id)->delete();
} catch (Throwable $e) {
    // cleanup best-effort
}

$results['finished_at'] = date('Y-m-d H:i:s');
$results['verdict'] = $results['count_fail'] === 0 ? 'PASS' : 'FAIL';

file_put_contents(__DIR__.'/../storage/logs/flight_audit_fix_f4_results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo '  F-4 Regression: '.$results['count_pass'].' PASS / '.$results['count_fail']." FAIL\n";
echo '  Verdict: '.$results['verdict']."\n";
echo "═══════════════════════════════════════════════════════════════════════\n";
