<?php
/**
 * Fawry module accounting audit
 *
 * Verifies per-currency ledger balance for Fawry module transactions,
 * machine balances, and identifies any orphaned or imbalanced entries.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryMachineTransaction;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryPaymentMethod;
use App\Models\Fawry\FawryTransaction;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

Auth::loginUsingId(1);

echo "================================================================================\n";
echo "  FAWRY MODULE ACCOUNTING AUDIT\n";
echo "  Date: " . now()->toDateTimeString() . "\n";
echo "================================================================================\n\n";

// 1. Per-currency balance for all Fawry transactions
echo "1. PER-CURRENCY LEDGER BALANCE (Fawry module)\n";
echo "----------------------------------------------------------------\n";

$allFawryTx = Transaction::where('module', TransactionModule::Fawry->value)->get();
$imbalanced = [];

foreach ($allFawryTx as $tx) {
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

echo "  Total Fawry transactions: " . count($allFawryTx) . "\n";
echo "  Per-currency imbalances: " . count($imbalanced) . "\n";
if (!empty($imbalanced)) {
    foreach (array_slice($imbalanced, 0, 5) as $line) {
        echo "  ❌ $line\n";
    }
} else {
    echo "  ✅ All Fawry transactions balanced per-currency\n";
}
echo "\n";

// 2. Machine balance integrity
echo "2. FAWRY MACHINE BALANCE INTEGRITY\n";
echo "----------------------------------------------------------------\n";

$machines = FawryMachine::all();
foreach ($machines as $m) {
    $sumDebits = (float) FawryMachineTransaction::where('fawry_machine_id', $m->id)
        ->where('type', 'debit')->sum('amount');
    $sumCredits = (float) FawryMachineTransaction::where('fawry_machine_id', $m->id)
        ->where('type', 'credit')->sum('amount');

    $expectedBalance = $sumCredits - $sumDebits; // initial 0 + credits - debits
    $actualBalance = (float) $m->balance;

    if (abs($expectedBalance - $actualBalance) < 0.01) {
        echo "  ✅ Machine #{$m->id} {$m->name}: balance={$actualBalance} (verified)\n";
    } else {
        echo "  ❌ Machine #{$m->id}: expected={$expectedBalance} actual={$actualBalance}\n";
    }
}
echo "\n";

// 3. Customer AR balances for Fawry transactions
echo "3. CUSTOMER AR BALANCES (Fawry transactions with client_id)\n";
echo "----------------------------------------------------------------\n";

$customers = DB::table('fawry_transactions')
    ->whereNotNull('client_id')
    ->distinct()
    ->pluck('client_id');

foreach ($customers as $cid) {
    $customer = \App\Models\Customer::find($cid);
    if (!$customer) continue;

    $totalSold = (float) DB::table('fawry_transactions')
        ->where('client_id', $cid)
        ->whereNull('deleted_at')
        ->sum('selling_price');

    $totalPaid = (float) DB::table('fawry_transactions')
        ->where('client_id', $cid)
        ->whereNull('deleted_at')
        ->sum('amount');

    $expectedAR = $totalSold - $totalPaid;
    echo "  Customer #{$cid} {$customer->full_name}: sold={$totalSold} paid={$totalPaid} expected_AR={$expectedAR}\n";
}
echo "\n";

// 4. Fawry module totals
echo "4. FAWRY MODULE TOTALS\n";
echo "----------------------------------------------------------------\n";

$totals = DB::table('fawry_transactions')
    ->whereNull('deleted_at')
    ->selectRaw('operation_type, COUNT(*) as count, SUM(selling_price) as sold, SUM(fawry_price) as cost, SUM(profit) as profit')
    ->groupBy('operation_type')
    ->get();

foreach ($totals as $row) {
    echo "  {$row->operation_type}: count={$row->count}, sold={$row->sold}, cost={$row->cost}, profit={$row->profit}\n";
}
echo "\n";

// 5. Operation types and payment methods
echo "5. FAWRY CONFIGURATION\n";
echo "----------------------------------------------------------------\n";
echo "  Operation types: " . FawryOperationType::count() . "\n";
foreach (FawryOperationType::all() as $ot) {
    echo "    - {$ot->code} ({$ot->name_ar})\n";
}
echo "  Payment methods: " . FawryPaymentMethod::count() . "\n";
foreach (FawryPaymentMethod::all() as $pm) {
    echo "    - {$pm->code} ({$pm->name_ar})\n";
}
echo "\n";

// 6. Prepaid account balance
echo "6. FAWRY PREPAID & CLEARING ACCOUNTS\n";
echo "----------------------------------------------------------------\n";
$prepaid = Account::where('name', 'like', '%ماكينات فوري%')->where('type', 'owner')->first();
$incomeClear = Account::where('name', 'إقفال إيرادات فوري')->first();
$expenseClear = Account::where('name', 'إقفال تكاليف فوري')->first();

echo "  Prepaid Fawry: #" . ($prepaid?->id ?? 'NULL') . " balance={$prepaid?->balance}\n";
echo "  Income clearing: #" . ($incomeClear?->id ?? 'NULL') . " balance={$incomeClear?->balance}\n";
echo "  Expense clearing: #" . ($expenseClear?->id ?? 'NULL') . " balance={$expenseClear?->balance}\n";
echo "\n";

// 7. Fawry cashboxes
echo "7. FAWRY CASHBOXES\n";
echo "----------------------------------------------------------------\n";
foreach (Account::where('name', 'like', '%فوري%')->where('type', 'cashbox')->get() as $cb) {
    echo "  #{$cb->id} {$cb->name} balance={$cb->balance} {$cb->currency}\n";
}
echo "\n";

echo "================================================================================\n";
echo "  AUDIT COMPLETE\n";
echo "================================================================================\n";
