<?php
/**
 * Production diagnostic script for cash box deficit issue.
 * USAGE:
 *   1. Upload this file to production: scp diag_prod.php user@server:/tmp/diag_prod.php
 *   2. Run:  php artisan tinker --execute='require "/tmp/diag_prod.php";'
 */

use App\Models\Account;
use App\Models\Transaction;
use App\Models\AccountEntry;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPayment;

echo "\n========== [1] ACCOUNTS BY SUSPICIOUS NAME ==========\n";
$suspiciousAccounts = Account::query()
    ->where(function ($q) {
        $q->where('name', 'like', '%رصيد مسح%')
          ->orWhere('name', 'like', '%إقفال بكاشية%')
          ->orWhere('name', 'like', '%تنفيذ طيران%')
          ->orWhere('name', 'like', '%مسح%')
          ->orWhere('name', 'like', '%بكاشية%')
          ->orWhere('name', 'like', '%عجز%');
    })
    ->orderBy('name')
    ->get(['id', 'name', 'type', 'balance', 'currency', 'module_type', 'is_active', 'created_by', 'created_at']);

foreach ($suspiciousAccounts as $a) {
    printf("ID=%-4d | %-45s | type=%-10s | bal=%12.2f %-3s | module=%-10s | active=%s | created_by=%s | at=%s\n",
        $a->id, mb_substr($a->name, 0, 45), $a->type?->value ?? '-', (float)$a->balance, $a->currency,
        $a->module_type ?? '-', $a->is_active ? 'Y' : 'N',
        $a->created_by ?? '-',
        $a->created_at ? $a->created_at->format('Y-m-d H:i') : '-'
    );
}

echo "\n========== [2] ALL CASHBOX ACCOUNTS (Flights module) ==========\n";
$cashboxes = Account::query()
    ->where('type', 'cashbox')
    ->whereIn('module_type', ['flights', 'tourism', null])
    ->where('is_active', true)
    ->orderBy('name')
    ->get(['id', 'name', 'balance', 'currency', 'module_type', 'is_module_vault']);
foreach ($cashboxes as $a) {
    printf("ID=%-4d | %-50s | bal=%12.2f %-3s | module=%-10s | vault=%s\n",
        $a->id, mb_substr($a->name, 0, 50), (float)$a->balance, $a->currency,
        $a->module_type ?? '-', $a->is_module_vault ? 'Y' : 'N'
    );
}

echo "\n========== [3] SAUDI AIRLINES BOOKINGS (last 5) ==========\n";
$svaBookings = FlightBooking::query()
    ->where(function ($q) {
        $q->where('airline_name', 'like', '%سعودية%')
          ->orWhere('airline_name', 'like', '%Saudi%')
          ->orWhere('airline_name', 'like', '%Saudia%')
          ->orWhere('airline_name', 'like', '%SV%');
    })
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get(['id', 'booking_number', 'pnr', 'selling_price', 'purchase_price', 'currency', 'status', 'created_at', 'customer_id']);
foreach ($svaBookings as $b) {
    $paid = FlightPayment::where('flight_booking_id', $b->id)->sum('amount');
    printf("Bk#%d | %s | PNR=%s | sell=%.2f %s | pur=%.2f | paid=%.2f | status=%s | at=%s | cust=%d\n",
        $b->id, $b->booking_number, $b->pnr ?? '-',
        (float)$b->selling_price, $b->currency, (float)$b->purchase_price, (float)$paid,
        $b->status->value ?? $b->status, $b->created_at?->format('Y-m-d H:i'), $b->customer_id
    );
}

echo "\n========== [4] LATEST FLIGHT BOOKING (any airline) ==========\n";
$latest = FlightBooking::query()->orderBy('created_at', 'desc')->first();
if ($latest) {
    printf("Bk#%d | %s | PNR=%s | airline=%s | sell=%.2f %s | pur=%.2f | status=%s | at=%s | cust=%d\n",
        $latest->id, $latest->booking_number, $latest->pnr ?? '-', $latest->airline_name,
        (float)$latest->selling_price, $latest->currency, (float)$latest->purchase_price,
        $latest->status->value ?? $latest->status,
        $latest->created_at?->format('Y-m-d H:i'), $latest->customer_id
    );

    echo "\n--- Transactions for booking #{$latest->id} ---\n";
    $txs = Transaction::query()
        ->where('related_type', 'App\\Models\\Flight\\FlightBooking')
        ->where('related_id', $latest->id)
        ->orderBy('id')
        ->get();
    foreach ($txs as $t) {
        printf("Tx#%d | type=%-10s | amt=%10.2f | from=%-4s | to=%-4s | notes=%s\n",
            $t->id, $t->type->value, (float)$t->amount,
            $t->from_account_id ?? '-', $t->to_account_id ?? '-',
            mb_substr($t->notes ?? '', 0, 60)
        );
    }

    echo "\n--- Account Entries for booking #{$latest->id} ---\n";
    $txIds = $txs->pluck('id')->toArray();
    if ($txIds) {
        $entries = AccountEntry::query()
            ->whereIn('transaction_id', $txIds)
            ->with('account:id,name,type,module_type')
            ->orderBy('id')
            ->get();
        foreach ($entries as $e) {
            $acc = $e->account;
            printf("Ent#%d | Tx#%d | %-40s (id=%-3d, type=%-10s) | D=%10.2f | C=%10.2f | bal_after=%10.2f\n",
                $e->id, $e->transaction_id,
                mb_substr($acc->name ?? '?', 0, 40), $acc->id, $acc->type?->value ?? '?',
                (float)$e->debit, (float)$e->credit, (float)$e->balance_after
            );
        }
    }
}

echo "\n========== [5] TOTAL CASHBOX BALANCE (Flights, filtered) ==========\n";
$total = Account::query()
    ->whereIn('type', ['cashbox', 'wallet', 'bank', 'treasury', 'post'])
    ->whereIn('module_type', ['flights', 'tourism'])
    ->where('is_active', true)
    ->where('name', 'not like', '%عميل%')
    ->where('name', 'not like', '%شركة%')
    ->where('name', 'not like', '%مورد%')
    ->where('name', 'not like', '%إقفال%')
    ->where('name', 'not like', '%(نظام)%')
    ->where('name', 'not like', '%ذممة%')
    ->where('name', 'not like', '%رصيد مسبق%')
    ->sum('balance');
printf("Sum of 'real' cashboxes (filtered): %.2f EGP\n", (float)$total);

echo "\n========== [6] RECENT TRANSACTIONS (last 24h, flights) ==========\n";
$recentTxs = Transaction::query()
    ->where('module', 'flight')
    ->where('created_at', '>=', now()->subDay())
    ->with('fromAccount:id,name', 'toAccount:id,name')
    ->orderBy('id', 'desc')
    ->limit(15)
    ->get();
foreach ($recentTxs as $t) {
    printf("Tx#%d | %s | %.2f | from=%s -> to=%s | %s\n",
        $t->id, $t->type->value, (float)$t->amount,
        mb_substr($t->fromAccount?->name ?? '-', 0, 25),
        mb_substr($t->toAccount?->name ?? '-', 0, 25),
        mb_substr($t->notes ?? '', 0, 50)
    );
}

echo "\n========== DONE ==========\n";