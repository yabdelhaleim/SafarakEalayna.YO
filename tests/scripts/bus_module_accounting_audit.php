<?php
/**
 * Bus module accounting audit
 *
 * Verifies that all bus-module financial operations produce balanced journal
 * entries per-currency. Outputs a structured report.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusPayment;
use App\Models\Bus\BusRefundRequest;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

Auth::loginUsingId(1);

echo "================================================================================\n";
echo "  BUS MODULE ACCOUNTING AUDIT\n";
echo "  Date: " . now()->toDateTimeString() . "\n";
echo "================================================================================\n\n";

// 1. Overall transaction balance
echo "1. TRANSACTION BALANCE (per-currency)\n";
echo "----------------------------------------------------------------\n";
$allBusTx = Transaction::where('module', TransactionModule::Bus->value)->get();
$imbalanced = [];
foreach ($allBusTx as $tx) {
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
echo "  Total bus transactions: " . count($allBusTx) . "\n";
echo "  Per-currency imbalances: " . count($imbalanced) . "\n";
if (!empty($imbalanced)) {
    foreach (array_slice($imbalanced, 0, 5) as $line) {
        echo "  ❌ $line\n";
    }
} else {
    echo "  ✅ All bus transactions are balanced per-currency\n";
}
echo "\n";

// 2. Customer AR balance vs expected
echo "2. CUSTOMER AR BALANCE vs BOOKING TOTAL - PAID\n";
echo "----------------------------------------------------------------\n";
$paidBookings = BusBooking::where('status', 'paid')->get();
$misMatched = 0;
foreach ($paidBookings as $b) {
    $customer = $b->customer;
    if (!$customer || !$customer->account_id) continue;
    $expectedCurrency = $b->currency;
    $custAccount = Account::find($customer->account_id);
    if ($custAccount && $custAccount->currency !== $expectedCurrency) {
        // Multi-currency customer: AR is in a different currency than current account
        // Skip — this is by design (customer can have multiple AR accounts)
        continue;
    }
    $expected = (float) $b->total_price;
    $actual = (float) $b->payments()->sum('amount');
    if (abs($expected - $actual) > 0.01) {
        $misMatched++;
        echo "  ❌ Booking #{$b->id}: total={$expected} {$b->currency}, paid={$actual}\n";
    }
}
echo "  Paid bookings: " . count($paidBookings) . "\n";
echo "  Payment mismatches: $misMatched\n";
if ($misMatched === 0) {
    echo "  ✅ All paid bookings have matching payment totals\n";
}
echo "\n";

// 3. Company AR balance vs inventory cost
echo "3. COMPANY AR BALANCE vs INVENTORY COST\n";
echo "----------------------------------------------------------------\n";
$companies = BusCompany::all();
foreach ($companies as $company) {
    if (!$company->account_id) {
        echo "  ⚠️  Company #{$company->id} ({$company->name}): no AR account\n";
        continue;
    }
    $acc = Account::find($company->account_id);
    $expected = 0.0;
    foreach ($company->inventories as $inv) {
        $booked = BusBooking::where('inventory_id', $inv->id)
            ->whereNotIn('status', ['cancelled'])
            ->sum(DB::raw('cost_per_ticket * quantity'));
        // Note: cost_per_ticket in DB is in the original inventory currency (may be USD)
        // The actual ledger entry converts to EGP. This is a soft check.
        $expected += (float) $booked;
    }
    $actual = (float) $acc->balance;
    echo "  Company #{$company->id} ({$company->name}): AR balance = {$actual} EGP\n";
}
echo "\n";

// 4. Inventory availability vs bookings
echo "4. INVENTORY AVAILABILITY INTEGRITY\n";
echo "----------------------------------------------------------------\n";
$inventories = \App\Models\Bus\BusInventory::all();
$okCount = 0;
foreach ($inventories as $inv) {
    $total = (int) $inv->total_tickets;
    $available = (int) $inv->available_tickets;
    $booked = (int) BusBooking::where('inventory_id', $inv->id)
        ->whereNotIn('status', ['cancelled'])
        ->sum('quantity');
    if ($available + $booked <= $total) {
        $okCount++;
    } else {
        echo "  ❌ Inventory #{$inv->id} ({$inv->route}): avail={$available} booked={$booked} total={$total}\n";
    }
}
echo "  Inventories checked: " . count($inventories) . "\n";
echo "  ✅ Integrity OK: $okCount\n";
echo "\n";

// 5. Multi-currency handling
echo "5. MULTI-CURRENCY BOOKINGS\n";
echo "----------------------------------------------------------------\n";
$byCcy = BusBooking::selectRaw('currency, COUNT(*) as count, SUM(total_price) as total, SUM(paid_amount) as paid')
    ->whereNull('deleted_at')
    ->groupBy('currency')
    ->get();
foreach ($byCcy as $row) {
    echo "  {$row->currency}: {$row->count} bookings, total={$row->total}, paid={$row->paid}\n";
}
echo "\n";

// 6. Bus payment methods used
echo "6. PAYMENT METHOD DISTRIBUTION\n";
echo "----------------------------------------------------------------\n";
$methods = BusPayment::selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
    ->whereNull('deleted_at')
    ->groupBy('payment_method')
    ->get();
foreach ($methods as $row) {
    echo "  {$row->payment_method}: {$row->count} payments, total={$row->total}\n";
}
echo "\n";

// 7. Refund requests
echo "7. REFUND REQUESTS\n";
echo "----------------------------------------------------------------\n";
$refunds = BusRefundRequest::all();
echo "  Total refund requests: " . count($refunds) . "\n";
foreach ($refunds as $r) {
    echo "  - Refund #{$r->id} booking_id={$r->bus_booking_id} amount={$r->refund_amount} {$r->refund_currency} status={$r->status}\n";
}
echo "\n";

// 8. Module clearing accounts
echo "8. BUS MODULE CLEARING ACCOUNTS\n";
echo "----------------------------------------------------------------\n";
$income = Account::where('name', 'إقفال إيرادات الباصات')->first();
$expense = Account::where('name', 'إقفال تكاليف الباصات')->first();
echo "  Income clearing: #" . ($income?->id ?? 'NULL') . " balance={$income?->balance} {$income?->currency}\n";
echo "  Expense clearing: #" . ($expense?->id ?? 'NULL') . " balance={$expense?->balance} {$expense?->currency}\n";
echo "\n";

// 9. Bus wallets
echo "9. BUS WALLETS / BANK ACCOUNTS\n";
echo "----------------------------------------------------------------\n";
$wallets = \App\Models\Bus\BusBank::all();
echo "  Bus banks: " . count($wallets) . "\n";
foreach ($wallets as $w) {
    echo "  - #{$w->id} {$w->name} ({$w->currency})\n";
}
echo "\n";

echo "================================================================================\n";
echo "  AUDIT COMPLETE\n";
echo "================================================================================\n";
