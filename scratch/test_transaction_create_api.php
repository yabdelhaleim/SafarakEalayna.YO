<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\User;
use App\Services\Finance\LedgerReconciliationService;
use App\Services\Finance\TransactionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

$user = User::where('role', 'admin')->first();
Auth::login($user);

$cashbox = Account::where('name', 'Main Cashbox')->first()
    ?? Account::where('type', 'cashbox')->where('is_active', true)->first();

if (! $cashbox) {
    echo "No cashbox found\n";
    exit(1);
}

$beforeBalance = (float) $cashbox->balance;
$service = app(TransactionService::class);

echo "Testing income on #{$cashbox->id} {$cashbox->name} balance={$beforeBalance}\n";

$tx = $service->recordIncome([
    'amount' => 1.00,
    'to_account_id' => $cashbox->id,
    'module' => 'general',
    'notes' => 'API smoke test — delete me',
    'created_by' => $user->id,
]);

$entries = AccountEntry::where('transaction_id', $tx->id)->count();
$sums = AccountEntry::where('transaction_id', $tx->id)
    ->selectRaw('SUM(debit) d, SUM(credit) c')->first();

echo "Created tx#{$tx->id} type={$tx->type->value} entries={$entries} D={$sums->d} C={$sums->c}\n";
echo 'Cashbox after income: '.$cashbox->fresh()->balance."\n";

$service->voidTransactionJournal($tx);
$tx->delete();
echo 'After void+delete cashbox: '.$cashbox->fresh()->balance."\n";

$scan = app(LedgerReconciliationService::class)->runPostingAndBalanceIntegrityScan();
echo 'Global OK: '.($scan['global_totals_ok'] ? 'yes' : 'no')." treasury_drift={$scan['treasury_liquidity_drift_count']}\n";
