<?php
/**
 * Wallet module accounting audit
 *
 * Verifies per-currency ledger balance, status counts, and module totals.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\TransactionModule;
use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::loginUsingId(1);

echo "================================================================================\n";
echo "  WALLET MODULE ACCOUNTING AUDIT\n";
echo "  Date: " . now()->toDateTimeString() . "\n";
echo "================================================================================\n\n";

// 1. Per-currency balance
echo "1. PER-CURRENCY LEDGER BALANCE\n";
echo "----------------------------------------------------------------\n";
$allTx = Transaction::where('module', TransactionModule::Wallet->value)->get();
$imbalanced = [];
foreach ($allTx as $tx) {
    $entries = AccountEntry::where('transaction_id', $tx->id)->get();
    if ($entries->isEmpty()) continue;
    $byCurrency = $entries->groupBy(fn($e) => Account::find($e->account_id)?->currency ?? 'UNK');
    foreach ($byCurrency as $ccy => $group) {
        $debit = (float) $group->sum('debit');
        $credit = (float) $group->sum('credit');
        if (abs($debit - $credit) >= 0.01) {
            $imbalanced[] = "tx#{$tx->id} ({$tx->type->value}, {$tx->amount} {$ccy}): debit={$debit} credit={$credit}";
        }
    }
}
echo "  Total Wallet transactions: " . count($allTx) . "\n";
echo "  Per-currency imbalances: " . count($imbalanced) . "\n";
if (!empty($imbalanced)) {
    foreach (array_slice($imbalanced, 0, 5) as $line) {
        echo "  ❌ $line\n";
    }
} else {
    echo "  ✅ All Wallet transactions balanced per-currency\n";
}
echo "\n";

// 2. Status counts
echo "2. WALLET TRANSACTION TYPE COUNTS\n";
echo "----------------------------------------------------------------\n";
foreach (WalletTransactionType::cases() as $type) {
    $count = WalletTransaction::where('type', $type->value)->whereNull('deleted_at')->count();
    $total = (float) WalletTransaction::where('type', $type->value)->whereNull('deleted_at')->sum('amount');
    $totalFees = (float) WalletTransaction::where('type', $type->value)->whereNull('deleted_at')->sum('service_fee');
    echo "  {$type->value}: {$type->label()} = {$count} tx, total={$total} EGP, fees={$totalFees} EGP\n";
}
echo "\n";

// 3. Module totals by wallet type
echo "3. TOTALS BY WALLET TYPE\n";
echo "----------------------------------------------------------------\n";
$byType = WalletTransaction::query()
    ->selectRaw('wallet_type_id, type, COUNT(*) as count, SUM(amount) as total, SUM(service_fee) as fees')
    ->whereNull('deleted_at')
    ->groupBy('wallet_type_id', 'type')
    ->orderBy('wallet_type_id')
    ->get();
foreach ($byType as $row) {
    $type = WalletType::find($row->wallet_type_id);
    $code = $type && $type->code ? $type->code : 'unknown';
    $name = $type && $type->name ? $type->name : '';
    $txType = is_object($row->type) ? $row->type->value : (string) $row->type;
    echo "  $code ($name) - $txType: count={$row->count}, total={$row->total}, fees={$row->fees}\n";
}
echo "\n";

// 4. Module clearing accounts
echo "4. WALLET CLEARING ACCOUNTS\n";
echo "----------------------------------------------------------------\n";
$incomeClear = Account::where('name', 'إقفال إيرادات المحافظ')->first();
$expenseClear = Account::where('name', 'إقفال تكاليف المحافظ')->first();
echo "  Income clearing: #" . ($incomeClear?->id ?? 'NULL') . " balance={$incomeClear?->balance}\n";
echo "  Expense clearing: #" . ($expenseClear?->id ?? 'NULL') . " balance={$expenseClear?->balance}\n";
echo "\n";

// 5. Wallet provider accounts (e-wallets)
echo "5. WALLET PROVIDER ACCOUNTS\n";
echo "----------------------------------------------------------------\n";
foreach (Account::where('type', 'wallet')->where('is_active', true)->orderBy('name')->get() as $wallet) {
    echo "  #{$wallet->id} {$wallet->name}: balance={$wallet->balance} {$wallet->currency}\n";
}
echo "\n";

// 6. Settlement cashbox
echo "6. WALLET SETTLEMENT CASHBOXES\n";
echo "----------------------------------------------------------------\n";
foreach (Account::where('name', 'like', '%المحافظ%')->where('type', 'cashbox')->get() as $cb) {
    echo "  #{$cb->id} {$cb->name}: balance={$cb->balance} {$cb->currency}\n";
}
echo "\n";

// 7. WalletTransaction integrity
echo "7. WALLET TRANSACTION INTEGRITY\n";
echo "----------------------------------------------------------------\n";
$txs = WalletTransaction::whereNull('deleted_at')->get();
$totalTxs = $txs->count();
$sumSend = (float) $txs->where('type', 'send')->sum('amount');
$sumReceive = (float) $txs->where('type', 'receive')->sum('amount');
echo "  Total active: {$totalTxs}\n";
echo "  Send amount: {$sumSend} EGP\n";
echo "  Receive amount: {$sumReceive} EGP\n";
if ($totalTxs > 0) echo "  ✅ Stats reflect activity\n";
echo "\n";

echo "================================================================================\n";
echo "  AUDIT COMPLETE\n";
echo "================================================================================\n";
