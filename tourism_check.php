<?php
/**
 * Tourism P&L reconciliation script.
 *
 * Usage (from the project root):
 *     php artisan tinker < tourism_check.php
 * or
 *     cat tourism_check.php | php artisan tinker
 */

use App\Services\Reports\ProfitLossReportService;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Support\Facades\DB;

$svc = app(ProfitLossReportService::class);
$from = '2026-08-01';
$to   = '2026-08-31';

echo "════════════════════════════════════════════════════════\n";
echo " TOURISM P&L  ({$from} → {$to})\n";
echo "════════════════════════════════════════════════════════\n";

/* 1) Aggregated P&L exactly as the dashboard reads it */
$pl = $svc->report(['from_date' => $from, 'to_date' => $to, 'category' => 'tourism']);
printf("Revenue        : %12.2f\n", $pl['totalRevenues']);
printf("COGS           : %12.2f\n", $pl['totalCogs']);
printf("Operating exp. : %12.2f\n", $pl['totalExpenses']);
printf("Refunds        : %12.2f\n", $pl['totalRefunds']);
printf("Gross profit   : %12.2f\n", $pl['grossProfit']);
printf("NET profit     : %12.2f\n", $pl['netProfit']);
printf("Tx scanned     : %d\n",        $pl['meta']['transactions_scanned']);
printf("Tx included    : %d\n",        $pl['meta']['transactions_included']);

/* 2) Per-module breakdown — which sub-division dragged profit down? */
echo "\n── By module (tourism only) ─────────────────────────\n";
$mb = $svc->moduleBreakdown(['from_date' => $from, 'to_date' => $to]);
foreach ($mb['by_module'] as $r) {
    if (! in_array($r['module'], ['flight', 'hajj_umra', 'visa', 'tourism'], true)) {
        continue;
    }
    printf("%-12s income=%12.2f  cogs=%12.2f  exp=%12.2f  profit=%12.2f\n",
        $r['module'], $r['income'], $r['cogs'], $r['expense'], $r['profit']);
}

/* 3) Top expense buckets */
echo "\n── Top expense buckets ──────────────────────────────\n";
if (empty($pl['expensesList'])) {
    echo "(no expense buckets)\n";
} else {
    foreach (array_slice($pl['expensesList'], 0, 10) as $row) {
        printf("%-50s %12.2f\n", $row['name'], $row['amount']);
    }
}

/* 4) Refunds list */
echo "\n── Refunds ──────────────────────────────────────────\n";
if (empty($pl['refundsList'])) {
    echo "(no refunds)\n";
} else {
    foreach ($pl['refundsList'] as $row) {
        printf("%-50s %12.2f\n", $row['name'], $row['amount']);
    }
}

/* 5) Worst 25 tourism transactions by absolute amount */
echo "\n── Top 25 tourism transactions by |amount| ─────────\n";
$maps = app(LedgerClearingAccounts::class)->moduleAccountMaps();
$clearingIds = array_unique(array_merge(
    array_keys($maps['income']),
    array_keys($maps['expense'])
));

$rows = DB::table('transactions as t')
    ->leftJoin('accounts as to_acc',   't.to_account_id',   '=', 'to_acc.id')
    ->leftJoin('accounts as from_acc', 't.from_account_id', '=', 'from_acc.id')
    ->whereBetween('t.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
    ->whereIn('t.module', ['flight', 'hajj_umra', 'visa', 'tourism'])
    ->whereIn('t.type', ['income', 'expense', 'refund', 'transfer'])
    ->where(function ($q) use ($clearingIds) {
        $q->whereIn('t.from_account_id', $clearingIds)
          ->orWhereIn('t.to_account_id',   $clearingIds);
    })
    ->orderByDesc(DB::raw('ABS(t.amount)'))
    ->limit(25)
    ->get([
        't.id', 't.type', 't.module', 't.amount', 't.notes', 't.created_at',
        'from_acc.name as from_name', 'to_acc.name as to_name',
    ]);

if ($rows->isEmpty()) {
    echo "(no transactions matched)\n";
} else {
    foreach ($rows as $r) {
        $line = ($r->from_name ?? '-').' → '.($r->to_name ?? '-');
        if (! empty($r->notes)) {
            $line .= '  |  '.$r->notes;
        }
        printf("#%-6d %-9s %-9s %12.2f  %s\n",
            $r->id, $r->type, $r->module, $r->amount, mb_substr($line, 0, 70)
        );
    }
}

/* 6) Active tourism bookings — informational (not part of P&L) */
echo "\n── Active tourism bookings (debt = not yet collected) ─\n";

$flightDebt = DB::table('flight_bookings')
    ->whereNull('deleted_at')
    ->whereIn('status', ['confirmed', 'pending', 'issued'])
    ->selectRaw('COUNT(*) AS bookings, COALESCE(SUM(selling_price),0) AS selling')
    ->first();
printf("Flight   : %d bookings, total selling = %12.2f\n",
    $flightDebt->bookings ?? 0, $flightDebt->selling ?? 0);

$hajjDebt = DB::table('hajj_umra_bookings')
    ->whereNull('deleted_at')
    ->whereIn('status', ['confirmed', 'pending', 'issued'])
    ->selectRaw('COUNT(*) AS bookings, COALESCE(SUM(selling_price),0) AS selling')
    ->first();
printf("Hajj/Umra: %d bookings, total selling = %12.2f\n",
    $hajjDebt->bookings ?? 0, $hajjDebt->selling ?? 0);

$visaDebt = DB::table('visa_bookings')
    ->whereNull('deleted_at')
    ->whereIn('status', ['confirmed', 'pending', 'issued'])
    ->selectRaw('COUNT(*) AS bookings, COALESCE(SUM(selling_price),0) AS selling')
    ->first();
printf("Visa     : %d bookings, total selling = %12.2f\n",
    $visaDebt->bookings ?? 0, $visaDebt->selling ?? 0);

echo "\n════════════════════════════════════════════════════════\n";
echo " NOTE: outstanding amounts are NOT part of P&L.\n";
echo " P&L recognises revenue ONLY when cash is collected.\n";
echo "════════════════════════════════════════════════════════\n";
