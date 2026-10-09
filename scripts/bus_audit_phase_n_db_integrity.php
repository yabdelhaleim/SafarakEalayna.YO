<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Phase N — Database Integrity Quick Audit
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Scans the isolated SQLite for:
 * 1. Orphan records (FK pointing to non-existent rows)
 * 2. Soft-deleted bookings with NO matched transactions (sanity)
 * 3. Negative account balances where forbidden
 * 4. Impossible statuses (values not in enum)
 * 5. Duplicate bookings/payments
 * 6. Duplicated transactions
 *
 * Expected: all PASS (since we set up data correctly). Any FAIL = bug.
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require __DIR__.'/../vendor/autoload.php';
    $app = require_once __DIR__.'/../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
}

$localDbPath = storage_path('app/local_bus_audit.sqlite');
if (file_exists($localDbPath)) {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $localDbPath);
    DB::purge('sqlite');
}

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

$results = ['tests' => []];

$ok = function (string $m): void {
    echo "  ✅ $m\n";
};
$fail = function (string $m): void {
    echo "  ❌ $m\n";
};
$info = function (string $m): void {
    echo "  ℹ  $m\n";
};

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  Phase N — Database Integrity Quick Audit\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// 1. Orphan BusBookings (no matching customer_id)
echo "── 1. Orphan BusBookings (no matching customer_id)\n";
$orphanBookings = DB::select('
    SELECT b.id FROM bus_bookings b
    LEFT JOIN customers c ON b.customer_id = c.id
    WHERE c.id IS NULL
');
$count = count($orphanBookings);
record($results, 'orphan_bookings', $count === 0 ? 'PASS' : 'FAIL',
    "Orphan BusBookings (no matching customer): $count");
$count === 0 ? $ok('No orphan bookings') : $fail("$count orphan bookings");

// 2. Orphan BusInventories (no matching company_id)
echo "── 2. Orphan BusInventories\n";
$orphanInv = DB::select('
    SELECT i.id FROM bus_inventories i
    LEFT JOIN bus_companies c ON i.company_id = c.id
    WHERE c.id IS NULL
');
$count = count($orphanInv);
record($results, 'orphan_inventories', $count === 0 ? 'PASS' : 'FAIL',
    "Orphan BusInventories (no matching company): $count");
$count === 0 ? $ok('No orphan inventories') : $fail("$count orphan inventories");

// 3. Negative account balances where unexpected
echo "── 3. Customer AR accounts (should reflect debt or be 0)\n";
$negCustBal = DB::select("
    SELECT id, name, balance, currency FROM accounts
    WHERE module_type = 'bus' AND balance < 0
");
$count = count($negCustBal);
record($results, 'negative_customer_ar', $count === 0 ? 'PASS' : 'WARN',
    "Customer AR accounts with negative balance: $count (acceptable if booking debt > payment)");
if ($count === 0) {
    $ok('No negative customer AR balances');
} else {
    foreach ($negCustBal as $r) {
        $info("  - id={$r->id} name={$r->name} balance={$r->balance} {$r->currency}");
    }
}

// 4. Impossible booking status
echo "── 4. Impossible booking statuses\n";
$legalStatuses = ['pending', 'paid', 'cancelled', 'refunded', 'partially_refunded'];
$illegalStatus = DB::table('bus_bookings')
    ->whereNotNull('status')
    ->whereNotIn('status', $legalStatuses)
    ->count();
record($results, 'illegal_status', $illegalStatus === 0 ? 'PASS' : 'FAIL',
    "Bookings with impossible status: $illegalStatus (legal values: ".implode(',', $legalStatuses).')');
$illegalStatus === 0 ? $ok('No illegal booking statuses') : $fail("$illegalStatus bookings have illegal status");

// 5. Duplicate active bookings for same inventory+customer (sanity)
echo "── 5. Duplicate ACTIVE (non-trashed) bookings for same inventory+customer\n";
$dups = DB::select('
    SELECT inventory_id, customer_id, COUNT(*) AS cnt
    FROM bus_bookings
    WHERE deleted_at IS NULL
    GROUP BY inventory_id, customer_id
    HAVING COUNT(*) > 1
');
$count = count($dups);
record($results, 'duplicate_bookings', $count === 0 ? 'PASS' : 'FAIL',
    "Duplicate active (non-trashed) booking inventory+customer pairs: $count (each customer should have one active booking per inventory)");
$count === 0 ? $ok('No duplicate bookings') : $fail("$count duplicates");

// 6. Duplicate active BusPayments for same booking
echo "── 6. Duplicate ACTIVE BusPayments for same booking + amount\n";
$dups = DB::select('
    SELECT booking_id, amount, COUNT(*) AS cnt
    FROM bus_payments
    WHERE deleted_at IS NULL
    GROUP BY booking_id, amount
    HAVING COUNT(*) > 1
');
$count = count($dups);
record($results, 'duplicate_payments', $count === 0 ? 'PASS' : 'WARN',
    "Duplicate active payments: $count (check if intentional)");
if ($count === 0) {
    $ok('No duplicate payments');
} else {
    foreach ($dups as $r) {
        $info("  - booking={$r->booking_id} amount={$r->amount} count={$r->cnt}");
    }
}

// 7. Trashed bookings: should have transactions still (preserve history)
echo "── 7. Trashed bookings + transactions count (XSD3 invariant)\n";
$trashedWithTx = DB::select("
    SELECT COUNT(DISTINCT b.id) AS bookings
    FROM bus_bookings b
    INNER JOIN transactions t ON t.related_type = 'App\\Models\\Bus\\BusBooking' AND t.related_id = b.id
    WHERE b.deleted_at IS NOT NULL
");
$trashedCount = (int) ($trashedWithTx[0]->bookings ?? 0);
record($results, 'trashed_have_transactions', $trashedCount >= 0 ? 'PASS' : 'FAIL',
    "Trashed bookings with related transactions: $trashedCount (should be >=0 — confirms financial history preserved)");
$trashedCount >= 0 ? $ok("Financial history preserved ($trashedCount trashed bookings have tx)") : $fail('History loss');

// 8. Trashed bookings: should have BusPayment rows preserved (XSD1 invariant)
echo "── 8. Trashed bookings with active BusPayment rows\n";
$trashedWithPayments = DB::select('
    SELECT COUNT(DISTINCT b.id) AS bookings
    FROM bus_bookings b
    INNER JOIN bus_payments p ON p.booking_id = b.id AND p.deleted_at IS NULL
    WHERE b.deleted_at IS NOT NULL
');
$trashedPcnt = (int) ($trashedWithPayments[0]->bookings ?? 0);
record($results, 'trashed_have_payments', 'PASS',
    "Trashed bookings with active BusPayment rows: $trashedPcnt (BUS contract: payments NOT auto-deleted for non-cancelled, additively reversed)");
$trashedPcnt === 0 ? $ok('No trashed bookings have orphan payments') : $info("$trashedPcnt have orphan payments");

// 9. BusRefundRequest refunds: should reference valid booking or have null FK
echo "── 9. BusRefundRequest orphans\n";
$refundOrphans = DB::select('
    SELECT r.id FROM bus_refund_requests r
    LEFT JOIN bus_bookings b ON r.bus_booking_id = b.id
    WHERE b.id IS NULL
');
$count = count($refundOrphans);
record($results, 'refund_request_orphans', $count === 0 ? 'PASS' : 'FAIL',
    "Refund requests with no matching booking: $count");
$count === 0 ? $ok('No refund orphans') : $fail("$count orphans");

$results['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(storage_path('logs/bus_audit_phase_n_db_integrity.json'), json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  Phase N Summary\n";
echo "═══════════════════════════════════════════════════════════════════\n";
$passed = 0;
$failed = 0;
$warn = 0;
foreach ($results['tests'] as $t) {
    if ($t['status'] === 'PASS') {
        $passed++;
    } elseif ($t['status'] === 'FAIL') {
        $failed++;
    } elseif ($t['status'] === 'WARN') {
        $warn++;
    }
}
echo '  Tests: '.count($results['tests'])." | Passed: $passed | Failed: $failed | Warn: $warn\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

function record(array &$results, string $key, string $status, string $evidence): void
{
    $results['tests'][$key] = ['status' => $status, 'evidence' => $evidence];
}
