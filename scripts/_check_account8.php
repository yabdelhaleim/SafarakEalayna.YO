<?php

/**
 * Quick check for Account #8 (خزينة ياسر المكتب).
 *
 * Returns:
 *   - account info (id, name, type, balance)
 *   - transaction count (both directions)
 *   - total credits
 *   - total debits
 *   - net (credits - debits)
 *   - drift vs stored balance
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use Illuminate\Contracts\Console\Kernel;

$acc = Account::find(8);
if (! $acc) {
    echo "❌ Account #8 not found.\n";
    exit(1);
}

echo "═══════════════════════════════════════════════════════\n";
echo "  Account #8 — {$acc->name}\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  ID:              {$acc->id}\n";
echo "  Type:            {$acc->type}\n";
echo "  module_type:     {$acc->module_type}\n";
echo "  Currency:        {$acc->currency}\n";
echo '  Is active:       '.($acc->is_active ? 'Yes' : 'No')."\n";
echo "  Stored balance:  {$acc->balance}\n\n";

// Count transactions on both sides
$txCount = Transaction::where(function ($q) use ($acc) {
    $q->where('from_account_id', $acc->id)
        ->orWhere('to_account_id', $acc->id);
})->count();

echo "───────────────────────────────────────────────────────\n";
echo "  TRANSACTIONS (transactions table)\n";
echo "───────────────────────────────────────────────────────\n";
echo "  Count:           {$txCount}\n";

// Sum amounts by direction
$incoming = Transaction::where('to_account_id', $acc->id)->sum('amount');
$outgoing = Transaction::where('from_account_id', $acc->id)->sum('amount');

echo "  Incoming total:  {$incoming}  (to_account_id = 8)\n";
echo "  Outgoing total:  {$outgoing}  (from_account_id = 8)\n";
echo '  Net (in - out):  '.number_format($incoming - $outgoing, 2)."\n\n";

// Sum credit/debit from account_entries
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

// Breakdown by module
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
        $row->module ?? 'NULL',
        $row->cnt,
        $row->total
    );
}

echo "\n═══════════════════════════════════════════════════════\n";
echo "  Done.\n";
echo "═══════════════════════════════════════════════════════\n";
