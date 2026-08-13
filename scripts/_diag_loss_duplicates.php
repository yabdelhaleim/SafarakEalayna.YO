<?php
/**
 * Step 1 of loss investigation — find duplicate transactions in the office
 * period. A duplicate candidate is a transaction whose (amount, type,
 * from_account_id, to_account_id, related_type, related_id, DATE, notes)
 * tuple appears more than once. We surface every such group so the user
 * can verify whether they are legitimate (e.g. two-bus chains) or a real
 * double-posting bug.
 *
 * Read-only. No DB writes.
 *
 * Usage:
 *   php scripts/_diag_loss_duplicates.php
 *   php scripts/_diag_loss_duplicates.php 2026-07-01 2026-08-13
 */

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// ─── Args ────────────────────────────────────────────────────────────
$argv = $argv; array_shift($argv);
if (count($argv) >= 2) {
    $from = $argv[0];
    $to = $argv[1];
} else {
    $from = '2026-07-01';
    $to = '2026-08-13';
}

echo "=== Step 1: Loss Duplicates Diagnostic ===\n";
echo "Period: {$from} → {$to}\n";
echo "Looking for transactions where the (amount, type, from, to, related, date, notes) tuple repeats.\n\n";

// Office modules — same canon list as ProfitLossReportService
$officeModules = ['bus', 'fawry', 'online', 'wallet', 'wallet_transfer', 'wallets', 'general', 'service', 'office'];

// Group transactions by their "fingerprint" — every column that, if all
// match, the second insert is almost certainly a duplicate post. We
// exclude created_at (sub-second diff can hide dupes) and use DATE() so
// the day matches.
$rows = DB::table('transactions')
    ->whereIn('module', $officeModules)
    ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
    ->select(
        DB::raw('amount'),
        'type',
        'from_account_id',
        'to_account_id',
        'related_type',
        'related_id',
        DB::raw('DATE(created_at) as day'),
        'notes',
        DB::raw('COUNT(*) as occurrences'),
        DB::raw('GROUP_CONCAT(id ORDER BY id ASC) as ids')
    )
    ->groupBy(
        'amount', 'type', 'from_account_id', 'to_account_id',
        'related_type', 'related_id', DB::raw('DATE(created_at)'), 'notes'
    )
    ->having('occurrences', '>', 1)
    ->orderByDesc('occurrences')
    ->orderBy('amount', 'desc')
    ->limit(50)
    ->get();

if ($rows->isEmpty()) {
    echo "✅ No exact-fingerprint duplicates found in {$from} → {$to}.\n";
    echo "   (This means the loss isn't caused by a simple double-posting bug.)\n";
    exit(0);
}

echo "⚠️  Found {$rows->count()} candidate duplicate group(s).\n\n";

$rank = 1;
foreach ($rows as $r) {
    $type = str_pad($r->type, 10);
    $amount = number_format((float) $r->amount, 2);
    $ids = $r->ids;
    $day = $r->day;
    $related = $r->related_type && $r->related_id
        ? "{$r->related_type}#{$r->related_id}"
        : '—';
    $from = $r->from_account_id ? "#{$r->from_account_id}" : '—';
    $to = $r->to_account_id ? "#{$r->to_account_id}" : '—';

    echo "── Group #{$rank} ({$r->occurrences} copies) ─────────────\n";
    echo "  ID(s)             : {$ids}\n";
    echo "  Date              : {$day}\n";
    echo "  Type              : {$type}\n";
    echo "  Amount            : {$amount}\n";
    echo "  From → To account : {$from} → {$to}\n";
    echo "  Related entity    : {$related}\n";
    echo "  Notes             : " . (mb_substr($r->notes ?? '', 0, 80)) . "\n";

    // Now fetch the full rows for these IDs so we can show details
    $idList = explode(',', $ids);
    $details = DB::table('transactions as t')
        ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
        ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
        ->whereIn('t.id', $idList)
        ->select('t.id', 't.created_at', 't.created_by',
            'fa.name as from_acc', 'ta.name as to_acc',
            't.amount', 't.type', 't.notes'
        )
        ->orderBy('t.id')
        ->get();

    echo "  Detail rows:\n";
    foreach ($details as $d) {
        $createdBy = $d->created_by ? "(by #{$d->created_by})" : '';
        echo "    #{$d->id}  {$d->created_at}  $createdBy\n";
        echo "      amount={$d->amount}  type={$d->type}\n";
        echo "      from={$d->from_acc}  to={$d->to_acc}\n";
        echo "      notes=" . (mb_substr($d->notes ?? '', 0, 60)) . "\n";
    }
    echo "\n";
    $rank++;
}

echo "✓ Done. Read-only script — no DB writes performed.\n";