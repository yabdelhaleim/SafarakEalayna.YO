<?php
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

echo "\n========== [1] الـ 9 customers debt > 0 ==========\n";
$accounts = Account::query()
    ->where('type', 'customer')
    ->whereIn('module_type', ['office', 'bus', 'fawry', 'online', 'wallet', null])
    ->where('balance', '>', 0)
    ->orderBy('balance', 'desc')
    ->get(['id', 'name', 'balance', 'currency', 'module_type']);

foreach ($accounts as $a) {
    $cust = Customer::where('account_id', $a->id)->first();
    printf("Acc#%-3d | mod=%-10s | bal=%10.2f | cust=%s | %s\n",
        $a->id,
        $a->module_type ?? 'NULL',
        (float)$a->balance,
        $cust?->id ?? '?',
        mb_substr($a->name ?? '?', 0, 35));
}

echo "\n========== [2] module_types summary ==========\n";
$types = Account::query()
    ->where('type', 'customer')
    ->select('module_type', DB::raw('count(*) as cnt'), DB::raw('sum(balance) as total'))
    ->groupBy('module_type')
    ->get();
foreach ($types as $t) {
    printf("  mod=%-12s | count=%-3d | total=%.2f\n",
        $t->module_type ?? 'NULL', $t->cnt, (float)$t->total);
}

echo "\n========== [3] Flight bookings count per debt customer ==========\n";
foreach ($accounts as $a) {
    $cust = Customer::where('account_id', $a->id)->first();
    if (! $cust) continue;
    echo "Cust#{$cust->id} ({$cust->full_name}): ";
    echo "flights=" . ($cust->flight_bookings_count ?? '?') . " ";
    echo "bus=" . ($cust->bus_bookings_count ?? '?') . " ";
    echo "fawry=" . ($cust->fawry_transactions_count ?? '?') . " ";
    echo "online=" . ($cust->online_transactions_count ?? '?') . " ";
    echo "wallet=" . ($cust->wallet_transactions_count ?? '?') . "\n";
}

echo "\n========== DONE ==========\n";
