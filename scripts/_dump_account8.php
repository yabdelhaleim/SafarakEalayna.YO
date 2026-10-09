<?php

/**
 * Dump all transactions + ledger entries for Account #8 (خزينة ياسر المكتب).
 *
 * For each transaction touching account #8, prints:
 *   - transaction id, date, type, module, amount
 *   - from_account / to_account (names)
 *   - related entity (booking etc.)
 *   - notes
 *   - linked AccountEntry rows on account #8 (credit/debit)
 *
 * Then prints:
 *   - reconciliation: transactions net vs ledger net
 *   - breakdown by module
 *   - breakdown by type
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use Illuminate\Contracts\Console\Kernel;

function safeStr($v): string
{
    if ($v === null) {
        return '(null)';
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_scalar($v)) {
        return (string) $v;
    }
    if ($v instanceof BackedEnum) {
        return (string) $v->value;
    }

    return '<'.get_debug_type($v).'>';
}

$acc = Account::find(8);
if (! $acc) {
    echo "❌ Account #8 not found.\n";
    exit(1);
}

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo '  Account #8 — '.safeStr($acc->name).'  (stored balance: '.safeStr($acc->balance)." EGP)\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

// Fetch all transactions touching this account
$txs = Transaction::where(function ($q) use ($acc) {
    $q->where('from_account_id', $acc->id)
        ->orWhere('to_account_id', $acc->id);
})
    ->with(['fromAccount:id,name,type', 'toAccount:id,name,type', 'createdBy:id,name'])
    ->orderBy('created_at', 'desc')
    ->get();

echo "  TRANSACTION COUNT: {$txs->count()}\n\n";

$runningBalance = 0;
$idx = 0;
foreach ($txs as $tx) {
    $idx++;
    $isIn = $tx->to_account_id === $acc->id;
    $isOut = $tx->from_account_id === $acc->id;
    $direction = $isIn ? 'IN →' : ($isOut ? '← OUT' : '?');
    $other = $isIn
        ? ($tx->fromAccount->name ?? '?')
        : ($tx->toAccount->name ?? '?');

    echo "  ┌─ [#{$idx}] Transaction #{$tx->id}  {$direction}\n";
    echo "  │  date:       {$tx->created_at}\n";
    echo '  │  type:       '.safeStr($tx->type)."\n";
    echo '  │  module:     '.safeStr($tx->module)."\n";
    echo '  │  amount:     '.number_format($tx->amount, 2)." EGP\n";
    echo '  │  from:       '.($tx->from_account_id ? "{$tx->from_account_id} ".safeStr($tx->fromAccount->name ?? '?') : '(none)')."\n";
    echo '  │  to:         '.($tx->to_account_id ? "{$tx->to_account_id} ".safeStr($tx->toAccount->name ?? '?') : '(none)')."\n";
    echo '  │  related:    '.safeStr($tx->related_type).' #'.safeStr($tx->related_id)."\n";
    echo '  │  created_by: '.($tx->createdBy ? "{$tx->createdBy->id} ".safeStr($tx->createdBy->name) : '?')."\n";
    echo '  │  notes:      '.safeStr($tx->notes)."\n";

    // Entries for this transaction on account #8
    $entries = AccountEntry::where('transaction_id', $tx->id)
        ->where('account_id', $acc->id)
        ->get();

    if ($entries->count() > 0) {
        $credit = 0;
        $debit = 0;
        foreach ($entries as $e) {
            $credit += (float) $e->credit;
            $debit += (float) $e->debit;
        }
        $sign = $credit > 0 ? '+' : ($debit > 0 ? '-' : '·');
        echo "  │  ledger:     {$entries->count()} entry(ies) on #8 → credit=".number_format($credit, 2)
            .' debit='.number_format($debit, 2)
            ." [{$sign}".number_format($credit - $debit, 2)."]\n";
    } else {
        echo "  │  ledger:     ⚠️  NO ENTRIES on account #8 for this transaction!\n";
    }

    echo "  └──────────────────────────────────────────────────────────────\n";
}

// Reconciliation
echo "\n═══════════════════════════════════════════════════════════════════════════════\n";
echo "  RECONCILIATION\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n";

$incoming = (float) Transaction::where('to_account_id', $acc->id)->sum('amount');
$outgoing = (float) Transaction::where('from_account_id', $acc->id)->sum('amount');
$txNet = $incoming - $outgoing;

$creditTotal = (float) AccountEntry::where('account_id', $acc->id)->sum('credit');
$debitTotal = (float) AccountEntry::where('account_id', $acc->id)->sum('debit');
$ledgerNet = $creditTotal - $debitTotal;

echo '  Transactions SUM(amount) where to_account_id = 8:    '.number_format($incoming, 2)."\n";
echo '  Transactions SUM(amount) where from_account_id = 8:  '.number_format($outgoing, 2)."\n";
echo '  Transactions NET:                                    '.number_format($txNet, 2)."\n\n";
echo '  Ledger SUM(credit) on account #8:                   '.number_format($creditTotal, 2)."\n";
echo '  Ledger SUM(debit) on account #8:                    '.number_format($debitTotal, 2)."\n";
echo '  Ledger NET:                                         '.number_format($ledgerNet, 2)."\n\n";
echo '  Stored balance:                                     '.number_format((float) $acc->balance, 2)."\n";
echo "  ───────────────────────────────────────────────────────\n";
echo '  DRIFT (ledger NET − tx NET):                         '.number_format($ledgerNet - $txNet, 2)."\n";
echo '  LEDGER matches stored balance?                       '.(abs($ledgerNet - (float) $acc->balance) < 0.01 ? '✅ YES' : '❌ NO')."\n\n";

// Breakdown by module
echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "  BREAKDOWN BY MODULE\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n";
$byModule = Transaction::where(function ($q) use ($acc) {
    $q->where('from_account_id', $acc->id)
        ->orWhere('to_account_id', $acc->id);
})
    ->selectRaw('module, COUNT(*) as cnt, SUM(amount) as total')
    ->groupBy('module')
    ->get();

foreach ($byModule as $row) {
    printf("  %-15s  count=%3d  total=%10.2f\n",
        safeStr($row->module),
        $row->cnt,
        $row->total
    );
}
echo "\n";

// Breakdown by type
echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "  BREAKDOWN BY TYPE\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n";
$byType = Transaction::where(function ($q) use ($acc) {
    $q->where('from_account_id', $acc->id)
        ->orWhere('to_account_id', $acc->id);
})
    ->selectRaw('type, COUNT(*) as cnt, SUM(amount) as total')
    ->groupBy('type')
    ->get();

foreach ($byType as $row) {
    printf("  %-15s  count=%3d  total=%10.2f\n",
        safeStr($row->type),
        $row->cnt,
        $row->total
    );
}
echo "\n";

// Breakdown by CREATOR
echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "  BREAKDOWN BY CREATOR\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n";
$byCreator = Transaction::where(function ($q) use ($acc) {
    $q->where('from_account_id', $acc->id)
        ->orWhere('to_account_id', $acc->id);
})
    ->with('createdBy:id,name,email,role')
    ->get()
    ->groupBy('created_by');

foreach ($byCreator as $userId => $list) {
    $creator = $list->first()->createdBy;
    $name = $creator ? "{$creator->id} ".safeStr($creator->name).' ('.safeStr($creator->role).')' : "User #{$userId}";
    $total = $list->sum('amount');
    printf("  %-50s  count=%3d  total=%10.2f\n",
        $name,
        $list->count(),
        $total
    );
}
echo "\n";

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "  Done.\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n";
