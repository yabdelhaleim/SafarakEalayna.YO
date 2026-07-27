<?php
define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\VisaStatus;
use App\Models\VisaBooking;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

// Check VisaStatus values
echo 'VisaStatus::Submitted->value = ' . VisaStatus::Submitted->value . PHP_EOL;
echo 'VisaStatus::Cancelled->value = ' . VisaStatus::Cancelled->value . PHP_EOL;

// Check booking #1 actual status
$b1 = VisaBooking::withTrashed()->find(1);
if ($b1) {
    echo 'Booking #1 status raw = ' . $b1->getRawOriginal('status') . PHP_EOL;
    $statusVal = is_object($b1->status) ? $b1->status->value : $b1->status;
    echo 'Booking #1 status     = ' . $statusVal . PHP_EOL;
    echo 'Submitted compare: ' . var_export($statusVal === VisaStatus::Submitted->value, true) . PHP_EOL;
}

// Check accounts balance vs ledger
$checkAccounts = [1, 4, 5, 6, 7, 8, 12];
echo PHP_EOL . '=== Account Balance vs Ledger ===' . PHP_EOL;
foreach ($checkAccounts as $accId) {
    $acc = Account::find($accId);
    if (!$acc) continue;
    $ledger = DB::table('account_entries')->where('account_id', $accId)->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as net')->first();
    $diff = abs((float)$acc->balance - (float)$ledger->net);
    $sign = (float)$acc->balance >= 0 ? '+' : '';
    echo "Account #{$accId} [{$acc->name}] balance={$sign}{$acc->balance} ledger_net={$ledger->net} diff={$diff}" . PHP_EOL;
}

// S02.5 Issue: Customer1 AR
$cust1 = Customer::where('phone', '01099900001')->first();
echo PHP_EOL . '=== Customer1 ===' . PHP_EOL;
echo "account_id={$cust1->account_id}" . PHP_EOL;
if ($cust1->account_id) {
    $acc = Account::find($cust1->account_id);
    echo "balance={$acc->balance}" . PHP_EOL;
    $entries = DB::table('account_entries')
        ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
        ->where('account_entries.account_id', $cust1->account_id)
        ->orderBy('account_entries.id')
        ->select('account_entries.id', 'transactions.id as tx_id', 'account_entries.debit', 'account_entries.credit', 'account_entries.balance_after')
        ->get();
    foreach ($entries as $e) {
        echo "  entry#{$e->id} tx={$e->tx_id} debit={$e->debit} credit={$e->credit} bal_after={$e->balance_after}" . PHP_EOL;
    }
}

// S10 Customer2 issue
$cust2 = Customer::where('phone', '01099900002')->first();
echo PHP_EOL . '=== Customer2 ===' . PHP_EOL;
echo "account_id={$cust2->account_id}" . PHP_EOL;
if ($cust2->account_id) {
    $acc2 = Account::find($cust2->account_id);
    echo "balance={$acc2->balance}" . PHP_EOL;

    $entries = DB::table('account_entries')
        ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
        ->where('account_entries.account_id', $cust2->account_id)
        ->orderBy('account_entries.id')
        ->select('account_entries.id', 'transactions.id as tx_id', 'transactions.related_id', 'account_entries.debit', 'account_entries.credit', 'account_entries.notes')
        ->get();
    foreach ($entries as $e) {
        echo "  entry#{$e->id} tx={$e->tx_id} booking={$e->related_id} debit={$e->debit} credit={$e->credit} notes={$e->notes}" . PHP_EOL;
    }

    // Check which bookings relate to cust2
    $bookings = VisaBooking::withTrashed()->where('customer_id', $cust2->id)->get(['id', 'status', 'deleted_at', 'selling_price', 'income_transaction_id']);
    echo PHP_EOL . "Cust2 Bookings:" . PHP_EOL;
    foreach ($bookings as $b) {
        echo "  booking#{$b->id} status=" . (is_object($b->status) ? $b->status->value : $b->status) . " deleted=" . ($b->deleted_at ? 'yes' : 'no') . " selling={$b->selling_price} income_tx={$b->income_transaction_id}" . PHP_EOL;
    }
}

// S19.2 Issue: what's happening with account_balance vs ledger for signed accounts
echo PHP_EOL . '=== S02.1 Status Comparison Debug ===' . PHP_EOL;
$firstBooking = VisaBooking::withTrashed()->first();
if ($firstBooking) {
    $rawStatus = $firstBooking->getRawOriginal('status');
    $castStatus = $firstBooking->status;
    echo 'raw: ' . var_export($rawStatus, true) . PHP_EOL;
    echo 'cast type: ' . get_class($castStatus) . PHP_EOL;
    echo 'cast value: ' . $castStatus->value . PHP_EOL;
    echo 'Submitted->value: ' . VisaStatus::Submitted->value . PHP_EOL;
    echo 'Comparison: ' . var_export($castStatus->value === VisaStatus::Submitted->value, true) . PHP_EOL;
}
