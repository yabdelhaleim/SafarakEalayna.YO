<?php

/**
 * Quick check for Account #8 (خزينة ياسر المكتب).
 * v2: enum-safe (uses ->value for BackedEnum attributes).
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use Illuminate\Contracts\Console\Kernel;

/**
 * Safely convert any value (string, BackedEnum, null) to a printable string.
 */
function safeStr(mixed $v): string
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
    if (is_object($v) && method_exists($v, '__toString')) {
        return (string) $v;
    }

    return '<'.get_debug_type($v).'>';
}

$acc = Account::find(8);
if (! $acc) {
    echo "❌ Account #8 not found.\n";
    exit(1);
}

echo "═══════════════════════════════════════════════════════\n";
echo '  Account #8 — '.safeStr($acc->name)."\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  ID:              {$acc->id}\n";
echo '  Type:            '.safeStr($acc->type)."\n";
echo '  module_type:     '.safeStr($acc->module_type)."\n";
echo '  Currency:        '.safeStr($acc->currency)."\n";
echo '  Is active:       '.($acc->is_active ? 'Yes' : 'No')."\n";
echo '  Stored balance:  '.safeStr($acc->balance)."\n\n";

$txCount = Transaction::where(function ($q) use ($acc) {
    $q->where('from_account_id', $acc->id)
        ->orWhere('to_account_id', $acc->id);
})->count();

echo "───────────────────────────────────────────────────────\n";
echo "  TRANSACTIONS (transactions table)\n";
echo "───────────────────────────────────────────────────────\n";
echo "  Count:           {$txCount}\n";

$incoming = (float) Transaction::where('to_account_id', $acc->id)->sum('amount');
$outgoing = (float) Transaction::where('from_account_id', $acc->id)->sum('amount');

echo '  Incoming total:  '.number_format($incoming, 2)."  (to_account_id = 8)\n";
echo '  Outgoing total:  '.number_format($outgoing, 2)."  (from_account_id = 8)\n";
echo '  Net (in - out):  '.number_format($incoming - $outgoing, 2)."\n\n";

$creditTotal = (float) AccountEntry::where('account_id', $acc->id)->sum('credit');
$debitTotal = (float) AccountEntry::where('account_id', $acc->id)->sum('debit');

echo "───────────────────────────────────────────────────────\n";
echo "  ACCOUNT ENTRIES (account_entries table — ledger)\n";
echo "───────────────────────────────────────────────────────\n";
echo '  SUM(credit):     '.number_format($creditTotal, 2)."\n";
echo '  SUM(debit):      '.number_format($debitTotal, 2)."\n";
echo '  Net (credit - debit): '.number_format($creditTotal - $debitTotal, 2)."\n";
echo '  Net matches stored balance: '
   .(abs(($creditTotal - $debitTotal) - (float) $acc->balance) < 0.01 ? '✅ YES' : '❌ NO')
   ."\n\n";

echo "───────────────────────────────────────────────────────\n";
echo "  BREAKDOWN BY MODULE\n";
echo "───────────────────────────────────────────────────────\n";
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

echo "\n═══════════════════════════════════════════════════════\n";
echo "  Done.\n";
echo "═══════════════════════════════════════════════════════\n";
