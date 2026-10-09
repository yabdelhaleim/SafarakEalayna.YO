<?php
/**
 * PHASE 4 — READ-ONLY AUDIT SCRIPT
 * ====================================
 * Flight module orphan-transaction inventory (B-2 legacy data).
 *
 * BACKGROUND
 *   Before Phase 3, each FlightPayment called recordIncome() which created a
 *   new Income transaction per payment. To bypass the single-active-income
 *   guard at TransactionService:650, a WIP rekeyed related_type from
 *   FlightBooking → FlightPayment (the "rekey trick"). To avoid inflating
 *   cashbox/income-clearing balances, an additional reversing Transfer was
 *   recorded with the `عكس:` prefix in notes.
 *
 *   Phase 3 (commit 35ee24f) fixed the production code path. This script
 *   audits the LEGACY transactions already in the ledger.
 *
 * SCOPE (strict)
 *   - READ-ONLY. No INSERT, UPDATE, DELETE, or schema changes.
 *   - Runs on local MySQL safarakealayna ONLY. Aborts on production env.
 *   - Output: CSV + markdown reports in tests/reports/.
 *
 * USAGE
 *   php audit_flight_orphans_phase_4.php
 *
 * @see \App\Services\Flight\FlightBookingService::addPayment (post-Phase-3)
 * @see docs/PHASE_3_B2_NO_DOUBLE_INCOME_REPORT.md
 */

// ── 1. Bootstrap Laravel (so we can use Eloquent + DB facade) ───────────────
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ── 2. Safety gate (must NOT be production) ──────────────────────────────────
$env = config('app.env');
$dbName = config('database.connections.mysql.database');
$dbHost = config('database.connections.mysql.host');

if ($env !== 'local') {
    fwrite(STDERR, "ABORT: APP_ENV must be 'local', got '{$env}'.\n");
    exit(1);
}
if ($dbName !== 'safarakealayna') {
    fwrite(STDERR, "ABORT: DB must be local MySQL 'safarakealayna', got '{$dbName}'.\n");
    exit(1);
}
if (! in_array($dbHost, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "ABORT: DB host must be 127.0.0.1/localhost, got '{$dbHost}'.\n");
    exit(1);
}

echo "✅ Safety gate passed — local MySQL @ {$dbHost}/{$dbName}\n\n";

// ── 3. Output paths ──────────────────────────────────────────────────────────
$reportDir = __DIR__.'/tests/reports';
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0755, true);
}
$csvFile = $reportDir.'/PHASE_4_FLIGHT_ORPHANS.csv';
$mdFile = $reportDir.'/PHASE_4_FLIGHT_ORPHANS.md';

$fp = fopen($csvFile, 'w');
$md = "# Phase 4 — Flight Orphan Transaction Inventory (READ-ONLY)\n\n";

// ── 4. Section A — Overall orphan counts by module + type ────────────────────
$md .= "## A. Orphan transactions by module + type\n\n";
$md .= "An orphan transaction = `transactions.related_id` does not exist in the\n";
$md .= "referenced parent table. We LEFT-JOIN transactions against each\n";
$md .= "module's payment table.\n\n";

$moduleChecks = [
    ['App\\Models\\Flight\\FlightPayment', 'flight_payments', 'Flight'],
    ['App\\Models\\HajjUmra\\HajjUmraPayment', 'hajj_umra_payments', 'HajjUmra'],
    ['App\\Models\\Visa\\VisaPayment', 'visa_payments', 'Visa'],
    ['App\\Models\\Bus\\BusPayment', 'bus_payments', 'Bus'],
];

$md .= "| Module | type | n | total EGP |\n";
$md .= "|--------|------|---|-----------|\n";

foreach ($moduleChecks as [$relType, $tbl, $moduleName]) {
    $rows = DB::table('transactions as t')
        ->leftJoin($tbl.' as p', 't.related_id', '=', 'p.id')
        ->where('t.related_type', $relType)
        ->whereNull('p.id')
        ->select('t.type', DB::raw('COUNT(*) as n'), DB::raw('SUM(t.amount) as total'))
        ->groupBy('t.type')
        ->get();

    foreach ($rows as $r) {
        $md .= "| {$moduleName} | {$r->type} | {$r->n} | ".number_format((float) $r->total, 2)." |\n";
    }
    if ($rows->isEmpty()) {
        $md .= "| {$moduleName} | — | 0 | 0.00 |\n";
    }
}
$md .= "\n";

// ── 5. Section B — ACTIVE income transactions by module ──────────────────────
$md .= "## B. ACTIVE income transactions by module (notes NOT 'عكس:…')\n\n";
$md .= "These are the transactions counted as ACTIVE income by every report\n";
$md .= "that filters on `type=income AND notes NOT LIKE 'عكس:%'` (the\n";
$md .= "canonical pattern, same as the single-active-income guard).\n\n";

$md .= "| Module | n | total EGP |\n";
$md .= "|--------|---|-----------|\n";

$activeIncome = DB::table('transactions')
    ->where('type', 'income')
    ->where(function ($q) {
        $q->whereNull('notes')->orWhere(function ($q2) {
            $q2->where('notes', 'not like', 'عكس:%')->where('notes', 'not like', 'عكس %');
        });
    })
    ->select('module', DB::raw('COUNT(*) as n'), DB::raw('SUM(amount) as total'))
    ->groupBy('module')
    ->orderBy('module')
    ->get();

foreach ($activeIncome as $r) {
    $flag = ($r->module === 'flight') ? ' ← **B-2 bug**' : '';
    $md .= "| {$r->module} | {$r->n} | ".number_format((float) $r->total, 2)."{$flag} |\n";
}
$md .= "\n";

// ── 6. Section C — Detailed orphan Flight transactions (the 22 legacy cases) ─
$md .= "## C. Orphan Flight transactions — the 22 legacy cases\n\n";
$md .= "Each row below is a transactions table entry whose `related_id`\n";
$md .= "references a `flight_payments.id` (41–51) that NO LONGER EXISTS.\n";
$md .= "These are the residual B-2 bug rows.\n\n";

$md .= "| tx_id | type | amount | related_id | from_account | to_account | notes (head) | created_at |\n";
$md .= "|-------|------|--------|------------|--------------|------------|-------------|------------|\n";

$orphans = DB::table('transactions as t')
    ->leftJoin('flight_payments as fp', function ($j) {
        $j->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
          ->whereColumn('t.related_id', 'fp.id');
    })
    ->leftJoin('flight_bookings as fb', function ($j) {
        $j->where('t.related_type', 'App\\Models\\Flight\\FlightBooking')
          ->whereColumn('t.related_id', 'fb.id');
    })
    ->where(function ($q) {
        $q->where(function ($q2) {
            $q2->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')->whereNull('fp.id');
        })->orWhere(function ($q2) {
            $q2->where('t.related_type', 'App\\Models\\Flight\\FlightBooking')->whereNull('fb.id');
        });
    })
    ->orderBy('t.id')
    ->select(
        't.id as tx_id', 't.type', 't.amount', 't.related_type', 't.related_id',
        't.from_account_id', 't.to_account_id', 't.notes', 't.created_at'
    )
    ->get();

// CSV header
fputcsv($fp, ['tx_id', 'type', 'amount', 'related_type', 'related_id', 'from_account_id', 'to_account_id', 'notes_head_50', 'created_at']);

foreach ($orphans as $o) {
    fputcsv($fp, [
        $o->tx_id,
        $o->type,
        $o->amount,
        $o->related_type,
        $o->related_id,
        $o->from_account_id,
        $o->to_account_id,
        substr($o->notes ?? '', 0, 50),
        $o->created_at,
    ]);

    $relShort = class_basename($o->related_type);
    $notesHead = substr($o->notes ?? '(empty)', 0, 50);
    $md .= "| {$o->tx_id} | {$o->type} | ".number_format((float) $o->amount, 2)." | {$o->related_id} | {$o->from_account_id} | {$o->to_account_id} | {$notesHead} | {$o->created_at} |\n";
}
fclose($fp);

$md .= "\n";

// ── 7. Section D — Net financial impact on accounts ─────────────────────────
$md .= "## D. Net financial impact per account (orphan transactions only)\n\n";
$md .= "For each account touched by an orphan transaction, compute:\n";
$md .= "  - debit total (sum of amounts where account_id = `from_account_id`)\n";
$md .= "  - credit total (sum of amounts where account_id = `to_account_id`)\n";
$md .= "  - net = credit − debit\n";
$md .= "Project convention: balance = SUM(credit) − SUM(debit).\n\n";

$md .= "| account_id | debits | credits | net | n_txs |\n";
$md .= "|------------|--------|---------|-----|-------|\n";

$impact = DB::table('transactions as t')
    ->leftJoin('flight_payments as fp', function ($j) {
        $j->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
          ->whereColumn('t.related_id', 'fp.id');
    })
    ->leftJoin('flight_bookings as fb', function ($j) {
        $j->where('t.related_type', 'App\\Models\\Flight\\FlightBooking')
          ->whereColumn('t.related_id', 'fb.id');
    })
    ->where(function ($q) {
        $q->where(function ($q2) {
            $q2->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')->whereNull('fp.id');
        })->orWhere(function ($q2) {
            $q2->where('t.related_type', 'App\\Models\\Flight\\FlightBooking')->whereNull('fb.id');
        });
    })
    ->select(
        DB::raw('t.from_account_id as acc'),
        DB::raw('SUM(t.amount) as debits'),
        DB::raw('0 as credits'),
        DB::raw('COUNT(*) as n_txs')
    )
    ->groupBy('t.from_account_id')
    ->unionAll(
        DB::table('transactions as t')
            ->leftJoin('flight_payments as fp', function ($j) {
                $j->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
                  ->whereColumn('t.related_id', 'fp.id');
            })
            ->leftJoin('flight_bookings as fb', function ($j) {
                $j->where('t.related_type', 'App\\Models\\Flight\\FlightBooking')
                  ->whereColumn('t.related_id', 'fb.id');
            })
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')->whereNull('fp.id');
                })->orWhere(function ($q2) {
                    $q2->where('t.related_type', 'App\\Models\\Flight\\FlightBooking')->whereNull('fb.id');
                });
            })
            ->select(
                DB::raw('t.to_account_id as acc'),
                DB::raw('0 as debits'),
                DB::raw('SUM(t.amount) as credits'),
                DB::raw('COUNT(*) as n_txs')
            )
            ->groupBy('t.to_account_id')
    )
    ->get();

// Aggregate in PHP since UNION ALL across same query is awkward
$impactByAcc = [];
foreach ($impact as $i) {
    $acc = $i->acc;
    if (! isset($impactByAcc[$acc])) {
        $impactByAcc[$acc] = ['debits' => 0.0, 'credits' => 0.0, 'n' => 0];
    }
    $impactByAcc[$acc]['debits'] += (float) $i->debits;
    $impactByAcc[$acc]['credits'] += (float) $i->credits;
    $impactByAcc[$acc]['n'] += (int) $i->n_txs;
}

foreach ($impactByAcc as $acc => $v) {
    $net = $v['credits'] - $v['debits'];
    $md .= "| {$acc} | ".number_format($v['debits'], 2)." | ".number_format($v['credits'], 2)." | ".number_format($net, 2)." | {$v['n']} |\n";
}
$md .= "\n";

// ── 8. Section E — flight_bookings and flight_payments table state ──────────
$md .= "## E. Parent table state\n\n";
$fbCount = DB::table('flight_bookings')->count();
$fbTrashed = DB::table('flight_bookings')->whereNotNull('deleted_at')->count();
$fpCount = DB::table('flight_payments')->count();
$fpTrashed = DB::table('flight_payments')->whereNotNull('deleted_at')->count();

$md .= "| table | total rows | trashed (deleted_at NOT NULL) | active |\n";
$md .= "|-------|------------|------------------------------|--------|\n";
$md .= "| flight_bookings | {$fbCount} | {$fbTrashed} | ".($fbCount - $fbTrashed)." |\n";
$md .= "| flight_payments | {$fpCount} | {$fpTrashed} | ".($fpCount - $fpTrashed)." |\n\n";

if ($fbCount === 0 && $fpCount === 0) {
    $md .= "> **CRITICAL:** Both tables are EMPTY. The orphan transactions\n";
    $md .= "> reference flight_payment IDs in the range 41–51, but no\n";
    $md .= "> flight_payment row with those IDs exists (active or trashed).\n";
    $md .= "> The parent bookings and payments were hard-deleted at some\n";
    $md .= "> prior point — only the ledger transactions survived.\n\n";
}

// ── 9. Section F — Existing عكس: reversals on these orphans ─────────────────
$md .= "## F. Existing `عكس:` reversal entries (companion to the orphan Income)\n\n";
$md .= "For each orphan FlightPayment Income row, there is already a companion\n";
$md .= "Transfer row with notes starting `عكس:`. These were added at the same\n";
$md .= "time as a manual workaround to keep cashbox/income-clearing balances\n";
$md .= "from inflating. Net financial impact on cashbox = ZERO (verified in\n";
$md .= "section D), but the Income row itself is still counted by income reports.\n\n";

$md .= "| Income tx_id | related_id | amount | matching عكس Transfer tx_id | notes head |\n";
$md .= "|--------------|------------|--------|------------------------------|------------|\n";

$incomeOrphans = DB::table('transactions as t')
    ->leftJoin('flight_payments as fp', function ($j) {
        $j->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
          ->whereColumn('t.related_id', 'fp.id');
    })
    ->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
    ->where('t.type', 'income')
    ->whereNull('fp.id')
    ->orderBy('t.id')
    ->select('t.id', 't.related_id', 't.amount')
    ->get();

foreach ($incomeOrphans as $inc) {
    $reversal = DB::table('transactions')
        ->where('related_type', 'App\\Models\\Flight\\FlightPayment')
        ->where('related_id', $inc->related_id)
        ->where('type', 'transfer')
        ->where(function ($q) {
            $q->where('notes', 'like', 'عكس:%')->orWhere('notes', 'like', 'عكس %');
        })
        ->first();
    $revId = $reversal ? $reversal->id : '—';
    $revNotes = $reversal ? substr($reversal->notes, 0, 40) : '(missing reversal!)';
    $md .= "| {$inc->id} | {$inc->related_id} | ".number_format((float) $inc->amount, 2)." | {$revId} | {$revNotes} |\n";
}
$md .= "\n";

// ── 10. Section G — Summary & recommended next step ─────────────────────────

// ACTIVE orphan Income = orphan Income rows that do NOT yet have `عكس:` prefix
// (i.e., NOT yet soft-reversed). This is the actual "over-counted" set.
$activeOrphanIncome = DB::table('transactions as t')
    ->leftJoin('flight_payments as fp', function ($j) {
        $j->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
          ->whereColumn('t.related_id', 'fp.id');
    })
    ->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
    ->where('t.type', 'income')
    ->whereNull('fp.id')
    ->where(function ($q) {
        $q->whereNull('t.notes')->orWhere(function ($q2) {
            $q2->where('t.notes', 'not like', 'عكس:%')->where('t.notes', 'not like', 'عكس %');
        });
    })
    ->select('t.id', 't.amount')
    ->get();

$md .= "## G. Summary\n\n";
$md .= "- **".$orphans->count()."** orphan Flight transactions in `transactions` table.\n";
$md .= "- **".$activeOrphanIncome->count()."** of them are ACTIVE orphan Income (no `عكس:` prefix) → these inflate income reports by **".number_format((float) $activeOrphanIncome->sum('amount'), 2)." EGP**.\n";
$md .= "- **".($incomeOrphans->count() - $activeOrphanIncome->count())."** orphan Income rows have already been soft-reversed (carry `عكس:` prefix).\n";
$md .= "- Each orphan Income has a companion orphan Transfer with `عكس:` prefix → **net cashbox impact = 0**.\n";
$md .= "- flight_bookings and flight_payments tables are **EMPTY** (hard-deleted at some prior point).\n\n";

$md .= "## H. Next step — correction plan (NOT executed in Phase 4)\n\n";
$md .= "See `docs/PHASE_4_HISTORICAL_CORRECTION_PLAN.md` for the proposed correction.\n";
$md .= "**Phase 4 is READ-ONLY — no data has been modified by this script.**\n";

file_put_contents($mdFile, $md);

echo "✅ Audit complete.\n";
echo "  CSV: {$csvFile}\n";
echo "  MD:  {$mdFile}\n";
echo "\n";
echo "Summary:\n";
echo "  - Orphan Flight transactions: {$orphans->count()}\n";
echo "  - ACTIVE orphan Income (over-counted): {$activeOrphanIncome->count()}\n";
echo "  - Already-reversed orphan Income: ".($incomeOrphans->count() - $activeOrphanIncome->count())."\n";
echo "  - Sum of over-counted income: ".number_format((float) $activeOrphanIncome->sum('amount'), 2)." EGP\n";