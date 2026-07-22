<?php
/**
 * Online Services module accounting audit
 *
 * Verifies per-currency ledger balance, status counts, and module totals.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\OnlineTransactionStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::loginUsingId(1);

echo "================================================================================\n";
echo "  ONLINE SERVICES MODULE ACCOUNTING AUDIT\n";
echo "  Date: " . now()->toDateTimeString() . "\n";
echo "================================================================================\n\n";

// 1. Per-currency balance
echo "1. PER-CURRENCY LEDGER BALANCE\n";
echo "----------------------------------------------------------------\n";
$allOnlineTx = Transaction::where('module', TransactionModule::Online->value)->get();
$imbalanced = [];
foreach ($allOnlineTx as $tx) {
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
echo "  Total Online transactions: " . count($allOnlineTx) . "\n";
echo "  Per-currency imbalances: " . count($imbalanced) . "\n";
if (!empty($imbalanced)) {
    foreach (array_slice($imbalanced, 0, 5) as $line) {
        echo "  ❌ $line\n";
    }
} else {
    echo "  ✅ All Online transactions balanced per-currency\n";
}
echo "\n";

// 2. Status counts
echo "2. ONLINE TRANSACTION STATUS COUNTS\n";
echo "----------------------------------------------------------------\n";
foreach (OnlineTransactionStatus::cases() as $status) {
    $count = OnlineTransaction::where('status', $status->value)->whereNull('deleted_at')->count();
    echo "  {$status->value}: {$status->label()} = {$count}\n";
}
echo "\n";

// 3. Module totals by service type
echo "3. TOTALS BY SERVICE TYPE\n";
echo "----------------------------------------------------------------\n";
$byType = OnlineTransaction::query()
    ->selectRaw('service_type_id, COUNT(*) as count, SUM(selling_price) as sold, SUM(purchase_price) as cost, SUM(profit) as profit')
    ->whereNull('deleted_at')
    ->where('status', 'completed')
    ->groupBy('service_type_id')
    ->get();
foreach ($byType as $row) {
    $type = OnlineServiceType::find($row->service_type_id);
    $code = $type && $type->code ? $type->code : 'unknown';
    $name = $type && $type->name_ar ? $type->name_ar : '';
    echo "  $code ($name): count={$row->count}, profit={$row->profit}\n";
}
echo "\n";

// 4. Top providers
echo "4. TOTALS BY PROVIDER\n";
echo "----------------------------------------------------------------\n";
$byProvider = OnlineTransaction::query()
    ->selectRaw('provider_id, COUNT(*) as count, SUM(profit) as profit')
    ->whereNull('deleted_at')
    ->where('status', 'completed')
    ->whereNotNull('provider_id')
    ->groupBy('provider_id')
    ->get();
foreach ($byProvider as $row) {
    $provider = OnlineServiceProvider::find($row->provider_id);
    $code = $provider && $provider->code ? $provider->code : 'unknown';
    echo "  $code: count={$row->count}, profit={$row->profit}\n";
}
echo "\n";

// 5. Module clearing accounts
echo "5. ONLINE CLEARING ACCOUNTS\n";
echo "----------------------------------------------------------------\n";
$incomeClear = Account::where('name', 'إقفال إيرادات الخدمات الإلكترونية')->first();
$expenseClear = Account::where('name', 'إقفال تكاليف الخدمات الإلكترونية')->first();
echo "  Income clearing: #" . ($incomeClear?->id ?? 'NULL') . " balance={$incomeClear?->balance}\n";
echo "  Expense clearing: #" . ($expenseClear?->id ?? 'NULL') . " balance={$expenseClear?->balance}\n";
echo "\n";

// 6. Online cashboxes
echo "6. ONLINE CASHBOXES\n";
echo "----------------------------------------------------------------\n";
foreach (Account::where('name', 'like', '%إلكترونية%')->where('type', 'cashbox')->get() as $cb) {
    echo "  #{$cb->id} {$cb->name} balance={$cb->balance} {$cb->currency}\n";
}
echo "\n";

echo "================================================================================\n";
echo "  AUDIT COMPLETE\n";
echo "================================================================================\n";
