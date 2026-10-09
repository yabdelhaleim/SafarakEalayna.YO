<?php

/**
 * Read-only diagnostic: explain WHY the Office P&L is negative.
 *
 * Loads every office-division transaction in the given period
 * (default: current month-to-date) and breaks it down by:
 *   - module  (bus, fawry, online, wallet, general, ...)
 *   - type    (income / expense / refund / writeoff / transfer)
 *   - counterparty (from/to account, related customer/employee)
 *
 * Then surfaces the top 10 transactions by absolute net impact
 * (the ones that drove the loss the most), AND the top 5
 * counterparties by net impact.
 *
 * Output is plain text — copy/paste or grep, no DB writes.
 *
 * Usage:
 *   php scripts/_diag_office_pnl.php                  # current month
 *   php scripts/_diag_office_pnl.php 2026-01-01 2026-08-13
 *   php scripts/_diag_office_pnl.php all              # all-time
 */

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Transaction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Sanity check — sometimes the script is run from a directory where the
// Transaction class picks up a wrong alias. Force the table explicitly
// so the first query doesn't blow up with `from ``[]`` `.
if (Transaction::query()->getModel()->getTable() === '') {
    (new Transaction)->setTable('transactions');
}

// ─── Args ────────────────────────────────────────────────────────────
$officeModules = ['bus', 'fawry', 'online', 'wallet', 'wallet_transfer', 'wallets', 'general', 'service', 'office'];

$argv = $argv;
array_shift($argv); // drop script name

if (! empty($argv) && $argv[0] === 'all') {
    $from = '1970-01-01';
    $to = '2999-12-31';
} elseif (count($argv) >= 2) {
    $from = $argv[0];
    $to = $argv[1];
} else {
    $from = date('Y-m-01');
    $to = date('Y-m-d');
}

echo "=== Office P&L Diagnostic ===\n";
echo "Period: {$from} → {$to}\n";
echo 'Office modules: '.implode(', ', $officeModules)."\n\n";

// ─── Query ──────────────────────────────────────────────────────────
$query = Transaction::query()
    ->whereIn('module', $officeModules)
    ->whereDate('created_at', '>=', $from)
    ->whereDate('created_at', '<=', $to)
    ->with(['fromAccount:id,name,type', 'toAccount:id,name,type', 'createdBy:id,name']);

$total = (clone $query)->count();
echo "Total office transactions in period: {$total}\n\n";

if ($total === 0) {
    echo "(nothing to analyse)\n";
    exit(0);
}

// ─── Per-module / per-type breakdown ─────────────────────────────────
// Sign convention: + = adds to profit, - = subtracts from profit.
//  income     → +
//  expense    → -
//  refund     → -
//  writeoff   → -
//  transfer   → 0 (P&L neutral — money moved between vaults, not lost)
function signedForPnl(string $type): int
{
    return match ($type) {
        'income' => +1,
        'expense', 'refund', 'writeoff' => -1,
        default => 0,
    };
}

$breakdown = [];
$totalRevenue = 0;
$totalExpense = 0;
$totalRefund = 0;
$totalWriteoff = 0;

foreach ($query->lazy(500) as $tx) {
    $mod = $tx->module instanceof BackedEnum ? $tx->module->value : (string) $tx->module;
    $type = $tx->type instanceof BackedEnum ? $tx->type->value : (string) $tx->type;
    $amount = (float) $tx->amount;
    $sign = signedForPnl($type);
    $signed = $amount * $sign;

    $breakdown[$mod][$type] = ($breakdown[$mod][$type] ?? 0) + $amount;
    $breakdown[$mod]['__net'] = ($breakdown[$mod]['__net'] ?? 0) + $signed;
    $breakdown[$mod]['__count'] = ($breakdown[$mod]['__count'] ?? 0) + 1;

    if ($type === 'income') {
        $totalRevenue += $amount;
    } elseif ($type === 'expense') {
        $totalExpense += $amount;
    } elseif ($type === 'refund') {
        $totalRefund += $amount;
    } elseif ($type === 'writeoff') {
        $totalWriteoff += $amount;
    }
}

$netProfit = $totalRevenue - $totalExpense - $totalRefund - $totalWriteoff;

echo "── OVERALL ─────────────────────────────────────────────────\n";
printf("  Total Revenue   : %s ج.م\n", number_format($totalRevenue, 2));
printf("  Total Expense   : %s ج.م\n", number_format($totalExpense, 2));
printf("  Total Refund    : %s ج.م\n", number_format($totalRefund, 2));
printf("  Total Writeoff  : %s ج.م\n", number_format($totalWriteoff, 2));
printf("  NET PROFIT      : %s ج.م  %s\n",
    number_format($netProfit, 2),
    $netProfit < 0 ? '❌ NEGATIVE — فيما يلي التحليل' : '✅ POSITIVE'
);
echo "\n";

echo "── PER MODULE ─────────────────────────────────────────────\n";
printf("%-15s %8s %12s %12s %12s %12s %12s %12s\n",
    'module', 'count', 'income', 'expense', 'refund', 'writeoff', 'transfer', 'NET');
echo str_repeat('-', 95)."\n";
foreach ($breakdown as $mod => $byType) {
    printf("%-15s %8d %12s %12s %12s %12s %12s %12s ج.م\n",
        $mod,
        $byType['__count'] ?? 0,
        number_format($byType['income'] ?? 0, 2),
        number_format($byType['expense'] ?? 0, 2),
        number_format($byType['refund'] ?? 0, 2),
        number_format($byType['writeoff'] ?? 0, 2),
        number_format($byType['transfer'] ?? 0, 2),
        number_format($byType['__net'] ?? 0, 2)
    );
}
echo "\n";

// ─── Top 10 loss-causing transactions ─────────────────────────────
echo "── TOP 10 LOSS-CAUSING TRANSACTIONS (signed impact, descending) ─\n";
$allTx = Transaction::query()
    ->whereIn('module', $officeModules)
    ->whereDate('created_at', '>=', $from)
    ->whereDate('created_at', '<=', $to)
    ->with(['fromAccount:id,name,type', 'toAccount:id,name,type', 'createdBy:id,name'])
    ->get()
    ->map(function ($tx) {
        $type = $tx->type instanceof BackedEnum ? $tx->type->value : (string) $tx->type;
        $sign = signedForPnl($type);
        $signed = (float) $tx->amount * $sign;
        $tx->__signed = $signed;
        $tx->__typeStr = $type;
        $tx->__modStr = $tx->module instanceof BackedEnum ? $tx->module->value : (string) $tx->module;

        return $tx;
    })
    ->sortByDesc(fn ($tx) => abs($tx->__signed))
    ->take(10);

printf("%-6s %-12s %-10s %-6s %-12s %-25s %-25s %s\n",
    'id', 'date', 'module', 'type', 'amount', 'from-account', 'to-account', 'NET impact');
echo str_repeat('-', 130)."\n";
foreach ($allTx as $tx) {
    printf("%-6d %-12s %-10s %-6s %-12s %-25s %-25s %s ج.م\n",
        $tx->id,
        substr((string) $tx->created_at, 0, 10),
        $tx->__modStr,
        $tx->__typeStr,
        number_format((float) $tx->amount, 2),
        mb_substr($tx->fromAccount->name ?? ($tx->from_account_id ?? '—'), 0, 25),
        mb_substr($tx->toAccount->name ?? ($tx->to_account_id ?? '—'), 0, 25),
        number_format($tx->__signed, 2)
    );
}
echo "\n";

// ─── Top 5 counterparties (related customer/employee) ─────────────
echo "── TOP 5 COUNTERPARTIES BY NET IMPACT (Customer/Employee) ─\n";
$counterRows = Transaction::query()
    ->select('related_type', 'related_id',
        DB::raw('SUM(CASE WHEN type IN (\'expense\',\'refund\',\'writeoff\') THEN amount ELSE 0 END) AS total_out'),
        DB::raw('SUM(CASE WHEN type = \'income\' THEN amount ELSE 0 END) AS total_in'),
        DB::raw('COUNT(*) AS cnt'))
    ->whereIn('module', $officeModules)
    ->whereDate('created_at', '>=', $from)
    ->whereDate('created_at', '<=', $to)
    ->whereNotNull('related_type')
    ->groupBy('related_type', 'related_id')
    ->orderByDesc(DB::raw('total_out - total_in'))
    ->limit(10)
    ->get();

foreach ($counterRows as $r) {
    $net = (float) $r->total_out - (float) $r->total_in;
    $name = '—';
    if ($r->related_type === 'App\\Models\\Customer' && $r->related_id) {
        $name = optional(Customer::find($r->related_id))->name ?? '#'.$r->related_id;
    } elseif ($r->related_type === 'App\\Models\\Employee' && $r->related_id) {
        $name = optional(Employee::find($r->related_id))->name ?? '#'.$r->related_id;
    } else {
        $name = $r->related_type ? class_basename($r->related_type).'#'.$r->related_id : '—';
    }
    printf("  %-25s | %-30s | in=%10s  out=%10s  NET=%12s ج.م  (%d tx)\n",
        class_basename($r->related_type ?? ''),
        $name,
        number_format((float) $r->total_in, 2),
        number_format((float) $r->total_out, 2),
        number_format($net, 2),
        $r->cnt
    );
}
echo "\n";

// ─── Top 5 negative-impact accounts (by AccountEntry) ───────────
echo "── TOP 5 ACCOUNTS BY NET OUT-FLOW (AccountEntry debit-credit) ─\n";
$accountRows = DB::table('account_entries as ae')
    ->join('accounts as a', 'a.id', '=', 'ae.account_id')
    ->whereIn('a.module_type', ['office', 'wallet', 'cashbox'])
    ->whereDate('ae.created_at', '>=', $from)
    ->whereDate('ae.created_at', '<=', $to)
    ->groupBy('ae.account_id', 'a.name')
    ->select(
        'ae.account_id',
        'a.name',
        DB::raw('SUM(ae.credit) - SUM(ae.debit) AS net'),
        DB::raw('COUNT(*) AS cnt'),
    )
    ->orderBy('net', 'asc')
    ->limit(5)
    ->get();

foreach ($accountRows as $a) {
    printf("  #%-4d %-30s net=%12s ج.م  (%d entries)\n",
        $a->account_id,
        mb_substr($a->name ?? '—', 0, 30),
        number_format((float) $a->net, 2),
        $a->cnt
    );
}
echo "\n✓ Done. No writes were performed.\n";

function class_basename(?string $fqcn): string
{
    if (! $fqcn) {
        return '—';
    }
    $parts = explode('\\', $fqcn);

    return end($parts);
}
