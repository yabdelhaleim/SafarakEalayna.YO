<?php
/**
 * DRY-RUN ONLY — Backfill `customers.module_type` from existing bookings.
 *
 * Purpose
 * -------
 * Production issue: bus-module customers were leaking into the
 * "Flight Customers and Groups" page (FlightCustomersIndex.vue). The root
 * cause is that the `/api/v1/customers` query in CustomerService only
 * filters by `customers.type` (individual/company), and the `module_type`
 * column on `customers` is NULL for ~all historical rows because:
 *
 *   - OnlineTransactionService is the ONLY place that sets
 *     customer.module_type at creation time (→ 'online').
 *   - All other booking services only re-tag the customer's AR Account
 *     (account.module_type) and never touch customer.module_type.
 *   - The CustomerLedgerObserver defaults account.module_type to 'office'
 *     when customer.module_type is NULL.
 *
 * So today, customer.module_type is:
 *   - 'online' for Online-created customers
 *   - NULL     for everyone else (flight/bus/hajj/visa historical rows)
 *
 * This script computes what `module_type` SHOULD be, based on which
 * module's bookings exist for each customer — but does NOT modify the
 * database. The operator reviews the report and approves before any
 * UPDATE happens (see scripts/backfill_customers_module_type.php).
 *
 * Scope of inspection (DRY-RUN)
 * -----------------------------
 *   1. Total non-deleted customers.
 *   2. Current distribution of `customers.module_type`.
 *   3. For every customer where module_type IS NULL:
 *        - has_flight   = EXISTS in flight_bookings
 *        - has_bus      = EXISTS in bus_bookings
 *        - has_hajj     = EXISTS in hajj_umra_bookings
 *        - has_visa     = EXISTS in visa_bookings
 *        - has_online   = EXISTS in online_transactions
 *        - has_fawry    = EXISTS in fawry_transactions (client_id FK)
 *        - has_wallet   = EXISTS in wallet_transactions
 *   4. For each NULL row, compute the proposed module:
 *        - 0 modules with bookings  → UNTOUCHED (stays NULL)
 *        - 1 module with bookings   → propose that module's name
 *        - 2+ modules with bookings → CONFLICT (stays NULL, listed for review)
 *   5. Output:
 *        - breakdown_by_proposed_module
 *        - breakdown_by_conflict_pair (e.g. "flight+bus": 12)
 *        - sample of first 20 rows per proposed module
 *        - sample of first 20 conflict rows (with all module flags)
 *
 * Output files (written to storage/app/dryrun-reports/, NOT to DB)
 * ----------------------------------------------------------------
 *   dryrun_customers_module_type_<timestamp>.json
 *   dryrun_customers_module_type_<timestamp>.txt
 *
 * Hard guarantees
 * ---------------
 *   - This script performs ZERO writes to the database.
 *   - The only side effects are files under storage/app/dryrun-reports/.
 *   - No --apply flag exists. Apply is a separate script:
 *     scripts/backfill_customers_module_type.php (requires explicit
 *     "YES-APPLY-PROD" typed confirmation + backup table creation).
 *
 * Usage
 * -----
 *   php scripts/dryrun_backfill_customers_module_type.php
 *
 * @see scripts/backfill_customers_module_type.php (apply — NOT YET CREATED)
 * @see database/migrations/2026_07_15_120000_add_module_type_to_customers_table.php
 * @see app/Services/CustomerService.php (getAllCustomers, lines 19-102)
 * @see resources/js/views/flights/FlightCustomersIndex.vue (the leaking page)
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// ── Laravel bootstrap ─────────────────────────────────────────────────────
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// ── Refuse to run anything destructive, ever ──────────────────────────────
// (Defensive: even if someone copy/pastes an --apply flag, this script
//  has no apply branch. The companion apply script has its own guards.)
if (in_array('--apply', $_SERVER['argv'], true)) {
    fwrite(STDERR, "This is the DRY-RUN script. It never writes to the DB.\n");
    fwrite(STDERR, "For the apply script, see scripts/backfill_customers_module_type.php\n");
    exit(2);
}

// ── Helpers ───────────────────────────────────────────────────────────────

/** Format a number with thousands separators (CLI-friendly). */
function fmt(int $n): string
{
    return number_format($n, 0, '.', ',');
}

/** Truncate a UTF-8 string safely to N chars. */
function trunc(?string $s, int $n = 40): string
{
    if ($s === null || $s === '') {
        return '(empty)';
    }
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s;
}

// ── Header ────────────────────────────────────────────────────────────────
echo "\n";
echo str_repeat('═', 78)."\n";
echo "  DRY-RUN — Backfill customers.module_type from existing bookings\n";
echo '  Run timestamp: '.date('Y-m-d H:i:s')."\n";
echo '  Database:       '.config('database.connections.'.config('database.default').'.database')."\n";
echo str_repeat('═', 78)."\n\n";

// ── 1. Total customers (active, non-deleted) ──────────────────────────────
$totalCustomers = (int) DB::table('customers')->whereNull('deleted_at')->count();
echo "[1] Total active customers: ".fmt($totalCustomers)."\n\n";

// ── 2. Current distribution of module_type ────────────────────────────────
$currentDistribution = DB::table('customers')
    ->whereNull('deleted_at')
    ->selectRaw("COALESCE(module_type, '__NULL__') AS mt, COUNT(*) AS cnt")
    ->groupBy('mt')
    ->orderByDesc('cnt')
    ->get();

echo "[2] Current `customers.module_type` distribution:\n";
foreach ($currentDistribution as $row) {
    $label = $row->mt === '__NULL__' ? 'NULL (unset)' : $row->mt;
    $bar = str_repeat('█', (int) min(60, (int) ($row->cnt / max(1, $totalCustomers) * 60)));
    printf("    %-18s %8s  %s\n", $label, fmt((int) $row->cnt), $bar);
}
echo "\n";

// ── 3. For NULL customers, compute presence flags per module ──────────────
echo "[3] Scanning NULL customers for bookings in each module...\n";

$nullRows = DB::table('customers as c')
    ->whereNull('c.deleted_at')
    ->whereNull('c.module_type')
    ->selectRaw('
        c.id, c.full_name, c.phone, c.type, c.account_id,
        EXISTS(SELECT 1 FROM flight_bookings     fb WHERE fb.customer_id = c.id AND fb.deleted_at IS NULL) AS has_flight,
        EXISTS(SELECT 1 FROM bus_bookings        bb WHERE bb.customer_id = c.id AND bb.deleted_at IS NULL) AS has_bus,
        EXISTS(SELECT 1 FROM hajj_umra_bookings  hb WHERE hb.customer_id = c.id AND hb.deleted_at IS NULL) AS has_hajj,
        EXISTS(SELECT 1 FROM visa_bookings       vb WHERE vb.customer_id = c.id AND vb.deleted_at IS NULL) AS has_visa,
        EXISTS(SELECT 1 FROM online_transactions ot WHERE ot.customer_id = c.id AND ot.deleted_at IS NULL) AS has_online,
        EXISTS(SELECT 1 FROM fawry_transactions  ft WHERE ft.client_id   = c.id AND ft.deleted_at IS NULL) AS has_fawry,
        EXISTS(SELECT 1 FROM wallet_transactions wt WHERE wt.customer_id = c.id AND wt.deleted_at IS NULL) AS has_wallet
    ')
    ->get();

$nullCount = count($nullRows);
echo "    Found ".fmt($nullCount)." customers with module_type IS NULL.\n\n";

// ── 4. Classify each NULL row ─────────────────────────────────────────────
$MODULE_NAMES = ['flight', 'bus', 'hajj', 'visa', 'online', 'fawry', 'wallet'];
$PROPOSED_VALUES = [
    'flight' => 'flights',
    'bus'    => 'bus',
    'hajj'   => 'hajj_umra',
    'visa'   => 'visas',
    'online' => 'online',
    'fawry'  => 'office',     // fawry is grouped with office division
    'wallet' => 'office',     // wallet_transfer also lives under office
];

$samples = [];                  // first 20 per category
$byProposed = [];               // proposed module => [ids]
$conflicts  = [];               // each row that hits ≥2 modules
$conflictPairs = [];            // "flight+bus" => count
$untouched  = [];               // no bookings anywhere

foreach ($nullRows as $row) {
    $flags = [];
    foreach ($MODULE_NAMES as $m) {
        $key = 'has_'.$m;
        if ((int) $row->$key === 1) {
            $flags[] = $m;
        }
    }
    $n = count($flags);

    if ($n === 0) {
        $untouched[] = $row;
        continue;
    }
    if ($n === 1) {
        $proposed = $PROPOSED_VALUES[$flags[0]];
        $byProposed[$proposed] ??= [];
        $byProposed[$proposed][] = $row->id;
        if (count($byProposed[$proposed]) <= 20) {
            $samples[$proposed][] = [
                'id'   => (int) $row->id,
                'name' => $row->full_name,
                'phone'=> $row->phone,
                'type' => $row->type,
                'via'  => $flags[0],
            ];
        }
        continue;
    }
    // n >= 2 → conflict
    $conflicts[] = [
        'id'    => (int) $row->id,
        'name'  => $row->full_name,
        'phone' => $row->phone,
        'type'  => $row->type,
        'flags' => $flags,
    ];
    sort($flags);
    $pair = implode('+', $flags);
    $conflictPairs[$pair] = ($conflictPairs[$pair] ?? 0) + 1;
}

// ── 5. Output breakdown ──────────────────────────────────────────────────
echo str_repeat('─', 78)."\n";
echo "[4] PROPOSED BACKFILL — breakdown for NULL customers\n";
echo str_repeat('─', 78)."\n";

$totalProposable = array_sum(array_map('count', $byProposed));
echo "\n";
printf("  %-22s %10s\n", 'proposed module_type', 'count');
printf("  %-22s %10s\n", str_repeat('─', 22), str_repeat('─', 10));
foreach ($byProposed as $module => $ids) {
    printf("  %-22s %10s\n", $module, fmt(count($ids)));
}
printf("  %-22s %10s\n", str_repeat('─', 22), str_repeat('─', 10));
printf("  %-22s %10s\n", 'TOTAL classifiable', fmt($totalProposable));
printf("  %-22s %10s\n", 'CONFLICTS (skip)', fmt(count($conflicts)));
printf("  %-22s %10s\n", 'UNTOUCHED (skip)', fmt(count($untouched)));
printf("  %-22s %10s\n", 'NULL rows total', fmt($nullCount));

echo "\n";
echo "  Sample of rows-to-change (first 20 per proposed module):\n\n";
foreach ($samples as $module => $rows) {
    echo "  ── ".strtoupper($module)." ──\n";
    foreach ($rows as $r) {
        printf("    #%-5d  %-30s  phone=%-15s  type=%-9s  via=%s\n",
            $r['id'],
            trunc($r['name'], 28),
            $r['phone'] ?? '-',
            $r['type'] ?? '-',
            $r['via']
        );
    }
    echo "\n";
}

// ── 6. Conflicts breakdown ───────────────────────────────────────────────
if (! empty($conflictPairs)) {
    echo str_repeat('─', 78)."\n";
    echo "[5] CONFLICTS — customers with bookings in 2+ modules\n";
    echo str_repeat('─', 78)."\n\n";
    echo "  These rows will be LEFT UNCHANGED (module_type stays NULL)\n";
    echo "  until a human decides which module should own them.\n\n";

    arsort($conflictPairs);
    printf("  %-30s %10s\n", 'module combination', 'count');
    printf("  %-30s %10s\n", str_repeat('─', 30), str_repeat('─', 10));
    foreach ($conflictPairs as $pair => $count) {
        printf("  %-30s %10s\n", $pair, fmt($count));
    }
    echo "\n";

    echo "  Sample of first 20 conflicts:\n\n";
    foreach (array_slice($conflicts, 0, 20) as $c) {
        printf("    #%-5d  %-30s  phone=%-15s  flags=%s\n",
            $c['id'],
            trunc($c['name'], 28),
            $c['phone'] ?? '-',
            implode(',', $c['flags'])
        );
    }
    echo "\n";
}

// ── 7. Untouched sample ───────────────────────────────────────────────────
if (! empty($untouched)) {
    echo str_repeat('─', 78)."\n";
    echo "[6] UNTOUCHED — NULL customers with NO bookings anywhere\n";
    echo str_repeat('─', 78)."\n\n";
    echo "  These rows have no bookings in any module.\n";
    echo "  Likely created via Filament UI without ever being used.\n";
    echo "  Will be LEFT UNCHANGED (module_type stays NULL).\n\n";
    echo "  Total: ".fmt(count($untouched))." rows.\n";
    echo "  Sample of first 20:\n\n";
    foreach (array_slice($untouched, 0, 20) as $r) {
        printf("    #%-5d  %-30s  phone=%-15s  type=%-9s\n",
            (int) $r->id,
            trunc($r->full_name, 28),
            $r->phone ?? '-',
            $r->type ?? '-'
        );
    }
    echo "\n";
}

// ── 8. Persist JSON report (filesystem only — NOT to DB) ─────────────────
$reportDir = storage_path('app/dryrun-reports');
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0755, true);
}
$stamp = date('Ymd_His');
$jsonPath = $reportDir."/dryrun_customers_module_type_{$stamp}.json";
$txtPath  = $reportDir."/dryrun_customers_module_type_{$stamp}.txt";

$report = [
    'ran_at'             => date('c'),
    'db_connection'      => config('database.default'),
    'db_database'        => config('database.connections.'.config('database.default').'.database'),
    'total_active_customers' => $totalCustomers,
    'current_distribution' => $currentDistribution->map(fn ($r) => [
        'module_type' => $r->mt === '__NULL__' ? null : $r->mt,
        'count'       => (int) $r->cnt,
    ])->all(),
    'null_rows_total'   => $nullCount,
    'proposed_breakdown' => array_map('count', $byProposed),
    'total_classifiable' => $totalProposable,
    'conflicts_total'   => count($conflicts),
    'conflict_pairs'    => $conflictPairs,
    'untouched_total'   => count($untouched),
    'samples_per_proposed' => $samples,
    'samples_conflicts'  => array_slice($conflicts, 0, 20),
    'samples_untouched'  => array_slice(array_map(fn ($r) => [
        'id'    => (int) $r->id,
        'name'  => $r->full_name,
        'phone' => $r->phone,
        'type'  => $r->type,
    ], $untouched), 0, 20),
];

file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Also dump a plain-text report (echo captured via ob_start).
ob_start();
echo "DRY-RUN customers.module_type backfill\n";
echo "Total active customers: {$totalCustomers}\n";
echo "NULL rows:               {$nullCount}\n";
echo "Classifiable:            {$totalProposable}\n";
echo "Conflicts (skip):        ".count($conflicts)."\n";
echo "Untouched (skip):        ".count($untouched)."\n";
$txtContent = ob_get_clean();
file_put_contents($txtPath, $txtContent);

// ── 9. Final summary banner ───────────────────────────────────────────────
echo str_repeat('═', 78)."\n";
echo "  DRY-RUN COMPLETE — NO DATABASE WRITES PERFORMED\n";
echo str_repeat('═', 78)."\n\n";

echo "  Summary:\n";
printf("    Total active customers : %s\n", fmt($totalCustomers));
printf("    Already classified     : %s\n", fmt($totalCustomers - $nullCount));
printf("    NULL rows total        : %s\n", fmt($nullCount));
printf("    → Classifiable (1 flag): %s\n", fmt($totalProposable));
printf("    → Conflicts  (≥2 flags): %s  (will be SKIPPED, stays NULL)\n", fmt(count($conflicts)));
printf("    → Untouched (0 flags)  : %s  (will be SKIPPED, stays NULL)\n", fmt(count($untouched)));
echo "\n";

echo "  Report files (filesystem only):\n";
echo "    JSON : {$jsonPath}\n";
echo "    TXT  : {$txtPath}\n\n";

echo "  Next steps (operator action required):\n";
echo "    1. Open the JSON report and review the proposed changes.\n";
echo "    2. Decide whether to accept the proposals as-is, or\n";
echo "       exclude / override specific customers.\n";
echo "    3. Resolve conflicts manually (or accept that those rows\n";
echo "       stay NULL and won't appear on any module's list page).\n";
echo "    4. Once approved, run scripts/backfill_customers_module_type.php\n";
echo "       (NOT YET CREATED — will require typed 'YES-APPLY-PROD'\n";
echo "       and create a backup table first).\n";
echo "\n";

exit(0);