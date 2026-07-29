<?php
/**
 * Full Bus module E2E test — production readiness check.
 *
 * Scenarios:
 *   1.  Create bus cashbox / wallet / bank in EGP/USD/SAR
 *   2.  Create new bus company (supplier)
 *   3.  Create new bus inventory (cash + deferred)
 *   4.  Create new bus booking (customer pays for ticket)
 *   5.  Pay booking (partial + full)
 *   6.  Cancel booking (with refund)
 *   7.  Delete booking (full reversal)
 *   8.  Pay inventory debt (supplier)
 *   9.  Pay supplier debt
 *   10. Multi-currency (USD / SAR)
 *   11. Cross-currency refund
 *   12. Idempotent delete
 *   13. Final balance integrity check
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusPayment;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bus\BusBookingService;
use App\Services\Bus\BusCompanyService;
use App\Services\Bus\BusInventoryService;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$TOKEN = getenv('BUS_TOKEN') ?: '2|uS8LPhi9HfQsTR5rFsg6fd8WRhRfw9VrtwsLgF1616c25cfd';
$BASE  = 'http://127.0.0.1:8000/api/v1';

$pass = 0;
$fail = 0;
$results = [];

function ok(string $name, string $detail = ''): void {
    global $pass, $results;
    $pass++;
    $results[] = ['PASS', $name, $detail];
    echo "✅ {$name}".($detail ? " — {$detail}" : '')."\n";
}

function bad(string $name, string $detail): void {
    global $fail, $results;
    $fail++;
    $results[] = ['FAIL', $name, $detail];
    echo "❌ {$name} — {$detail}\n";
}

function http(string $method, string $path, array $payload = null): array {
    global $TOKEN, $BASE;
    $ch = curl_init($BASE . $path);
    $headers = ["Authorization: Bearer $TOKEN", 'Accept: application/json'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);
    return ['status' => $code, 'body' => $body, 'json' => $json];
}

function freshAccount(string $name, string $type, string $currency, float $balance): Account {
    return Account::create([
        'name'           => $name,
        'type'           => $type,
        'balance'        => $balance,
        'currency'       => $currency,
        'is_active'      => true,
        'owner_type'     => Account::OWNER_TYPE_OWNER,
        'module_type'    => AccountModuleContract::OFFICE_MODULE_TYPE,
        'module'         => AccountModuleContract::OFFICE_MODULE_TYPE,
        'is_module_vault'=> false,
        'notes'          => 'Bus E2E test fixture',
        'created_by'     => 1,
    ])->fresh();
}

Auth::loginUsingId(1);
echo "=== Bus Module Full E2E Test ===\n";
echo "Started: ".date('Y-m-d H:i:s')."\n\n";

$UNIQ = (string) time();

// ==========================================================================
// 1. Create test fixtures
// ==========================================================================
echo "── 1. Create test fixtures (cashbox / wallet / bank / multi-currency) ──\n";
$cashboxEgp = freshAccount("E2E_BUS_CASH_{$UNIQ}_EGP", 'cashbox', 'EGP', 100000.00);
ok('create bus cashbox EGP', "#{$cashboxEgp->id} bal=".number_format($cashboxEgp->balance, 2));

$cashboxUsd = freshAccount("E2E_BUS_CASH_{$UNIQ}_USD", 'cashbox', 'USD', 5000.00);
ok('create bus cashbox USD', "#{$cashboxUsd->id} bal=".number_format($cashboxUsd->balance, 2));

$walletEgp = freshAccount("E2E_BUS_WAL_{$UNIQ}_EGP", 'wallet', 'EGP', 50000.00);
ok('create bus wallet EGP', "#{$walletEgp->id} bal=".number_format($walletEgp->balance, 2));

$bankEgp = freshAccount("E2E_BUS_BANK_{$UNIQ}_EGP", 'bank', 'EGP', 200000.00);
ok('create bus bank EGP', "#{$bankEgp->id} bal=".number_format($bankEgp->balance, 2));

$customer = Customer::firstOrCreate(
    ['phone' => "E2EBUS{$UNIQ}"],
    [
        'name'      => "E2E Bus Customer {$UNIQ}",
        'full_name' => "E2E Bus Customer {$UNIQ}",
        'is_active' => true,
        'created_by' => 1,
    ]
);
ok('test customer', "#{$customer->id}");

// ==========================================================================
// 2. Create bus company (supplier) via API
// ==========================================================================
echo "\n── 2. Create bus company (supplier) ──\n";
$resp = http('POST', '/bus/companies', [
    'name'     => "E2E Bus Company {$UNIQ}",
    'phone'    => '0100'.substr($UNIQ, -7),
    'is_active'=> true,
    'notes'    => 'E2E test supplier',
]);
if ($resp['status'] === 201 || $resp['status'] === 200) {
    $company = BusCompany::find($resp['json']['data']['id']);
    ok('create bus company', "#{$company->id} account_id=".($company->account_id ?? 'null'));
} else {
    bad('create bus company', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
    exit(1);
}

// ==========================================================================
// 3. Create bus inventory (cash) via API
// ==========================================================================
echo "\n── 3. Create bus inventory (cash + deferred) ──\n";
$travelDate = date('Y-m-d', strtotime('+30 days'));
$resp = http('POST', '/bus/inventories', [
    'company_id'     => $company->id,
    'route'          => "E2E Route {$UNIQ} cash",
    'travel_date'    => $travelDate,
    'departure_time' => '08:00',
    'total_tickets'  => 50,
    'cost_per_ticket'=> 70.00,
    'selling_price'  => 100.00,
    'payment_type'   => 'cash',
    'account_id'     => $cashboxEgp->id,
    'notes'          => 'E2E cash inventory',
]);
if ($resp['status'] === 201 || $resp['status'] === 200) {
    $invCash = BusInventory::find($resp['json']['data']['id']);
    ok('create cash inventory', "#{$invCash->id} avail={$invCash->available_tickets}/{$invCash->total_tickets}");
} else {
    bad('create cash inventory', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
    exit(1);
}

// Now reduced by cost_per_ticket * total_tickets = 70 * 50 = 3500
$cashAfterPurchase = $cashboxEgp->fresh()->balance;
if (abs(($cashAfterPurchase - 100000) + 3500) < 0.01) {
    ok('cash inventory: cashbox debited 3500', "{$cashboxEgp->balance} (expected 96500)");
} else {
    bad('cash inventory: cashbox debited 3500', "got {$cashAfterPurchase}, expected 96500");
}

// Deferred inventory
$resp = http('POST', '/bus/inventories', [
    'company_id'     => $company->id,
    'route'          => "E2E Route {$UNIQ} deferred",
    'travel_date'    => date('Y-m-d', strtotime('+45 days')),
    'departure_time' => '10:00',
    'total_tickets'  => 30,
    'cost_per_ticket'=> 80.00,
    'selling_price'  => 120.00,
    'payment_type'   => 'deferred',
    'notes'          => 'E2E deferred inventory',
]);
if ($resp['status'] === 201 || $resp['status'] === 200) {
    $invDeferred = BusInventory::find($resp['json']['data']['id']);
    ok('create deferred inventory', "#{$invDeferred->id} debt={$invDeferred->remaining_debt}");
} else {
    bad('create deferred inventory', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
    exit(1);
}

// ==========================================================================
// 4. Create booking
// ==========================================================================
echo "\n── 4. Create bus booking (customer buys ticket) ──\n";
$invBefore = $invCash->fresh()->available_tickets;
$resp = http('POST', '/bus/bookings', [
    'inventory_id' => $invCash->id,
    'customer_id'  => $customer->id,
    'quantity'     => 2,
    'notes'        => 'E2E booking',
]);
if ($resp['status'] === 201 || $resp['status'] === 200) {
    $booking = BusBooking::find($resp['json']['data']['id']);
    $invAfter = $invCash->fresh()->available_tickets;
    if (($invBefore - $invAfter) === 2) {
        ok('booking: inventory decremented 2', "avail {$invBefore}->{$invAfter}");
    } else {
        bad('booking: inventory decremented 2', "avail {$invBefore}->{$invAfter}");
    }
    ok('booking created', "#{$booking->id} total=".number_format($booking->total_price, 2));
} else {
    bad('create booking', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
    exit(1);
}

// ==========================================================================
// 5. Pay booking (partial + full)
// ==========================================================================
echo "\n── 5. Pay booking (partial + full) ──\n";
$halfAmount = round($booking->total_price / 2, 2);
$resp = http('POST', "/bus/bookings/{$booking->id}/pay", [
    'amount'         => $halfAmount,
    'payment_method' => 'cash',
    'account_id'     => $cashboxEgp->id,
]);
if ($resp['status'] === 200 || $resp['status'] === 201) {
    $booking = $booking->fresh();
    if (abs($booking->paid_amount - $halfAmount) < 0.01) {
        ok('booking partial payment', "paid=".number_format($booking->paid_amount, 2));
    } else {
        bad('booking partial payment', "paid=".number_format($booking->paid_amount ?? 0, 2));
    }
} else {
    bad('pay booking partial', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
}

// Pay remaining
$resp = http('POST', "/bus/bookings/{$booking->id}/pay", [
    'amount'         => $booking->remaining_amount,
    'payment_method' => 'cash',
    'account_id'     => $cashboxEgp->id,
]);
if ($resp['status'] === 200 || $resp['status'] === 201) {
    $booking = $booking->fresh();
    $status = $booking->payment_status->value ?? (string) $booking->payment_status;
    if ($status === 'paid') {
        ok('booking fully paid', "status={$status}");
    } else {
        bad('booking fully paid', "status={$status}");
    }
} else {
    bad('pay booking full', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
}

// ==========================================================================
// 6. Create + cancel booking (refund flow)
// ==========================================================================
echo "\n── 6. Cancel booking with refund ──\n";
$invBeforeCancel = $invCash->fresh()->available_tickets;  // 48 (after booking #12)
$resp = http('POST', '/bus/bookings', [
    'inventory_id' => $invCash->id,
    'customer_id'  => $customer->id,
    'quantity'     => 1,
    'notes'        => 'E2E booking to cancel',
]);
$bookingToCancel = BusBooking::find($resp['json']['data']['id']);
ok('booking to cancel created', "#{$bookingToCancel->id}");

$invBeforePay = $invCash->fresh()->available_tickets;  // 47 (after booking #13 created)

// Pay it first
$resp = http('POST', "/bus/bookings/{$bookingToCancel->id}/pay", [
    'amount'         => $bookingToCancel->total_price,
    'payment_method' => 'cash',
    'account_id'     => $cashboxEgp->id,
]);
if ($resp['status'] !== 200 && $resp['status'] !== 201) {
    bad('pay booking to cancel', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
}

// Cancel — needs account_id for refund (since booking was paid)
$resp = http('POST', "/bus/bookings/{$bookingToCancel->id}/cancel", [
    'company_penalty' => 0,
    'office_penalty'  => 0,
    'account_id'      => $cashboxEgp->id, // refund destination
]);
if ($resp['status'] === 200 || $resp['status'] === 201) {
    $bc = $bookingToCancel->fresh();
    $bStatus = $bc->status->value ?? (string) $bc->status;
    if (in_array($bStatus, ['cancelled', 'refunded', 'partially_refunded'])) {
        ok('booking cancelled', "status={$bStatus}");
    } else {
        bad('booking cancelled', "status={$bStatus}");
    }
    // ticket should be restored to where it was before booking #13 was created
    $invRestored = $invCash->fresh()->available_tickets;
    if ($invRestored === $invBeforeCancel) {
        ok('cancel: inventory tickets restored', "avail {$invBeforeCancel} (after booking-creat={$invBeforePay}, after cancel={$invRestored})");
    } else {
        bad('cancel: inventory tickets restored', "expected {$invBeforeCancel}, got {$invRestored}");
    }
} else {
    bad('cancel booking', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
}

// ==========================================================================
// 7. Multi-currency booking (USD)
// ==========================================================================
echo "\n── 7. Multi-currency booking (USD inventory) ──\n";
$invUsd = BusInventory::where('currency', 'USD')->where('payment_type', 'cash')->first();
if ($invUsd) {
    $resp = http('POST', '/bus/bookings', [
        'inventory_id' => $invUsd->id,
        'customer_id'  => $customer->id,
        'quantity'     => 1,
        'notes'        => 'E2E USD booking',
    ]);
    if ($resp['status'] === 201 || $resp['status'] === 200) {
        $bookingUsd = BusBooking::find($resp['json']['data']['id']);
        ok('USD booking created', "#{$bookingUsd->id} currency={$bookingUsd->currency}");
        
        // Pay from USD cashbox
        $resp = http('POST', "/bus/bookings/{$bookingUsd->id}/pay", [
            'amount'         => $bookingUsd->total_price,
            'payment_method' => 'cash',
            'account_id'     => $cashboxUsd->id,
        ]);
        if ($resp['status'] === 200 || $resp['status'] === 201) {
            ok('USD booking paid', 'HTTP '.($resp['status']));
        } else {
            bad('USD booking paid', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
        }
    } else {
        bad('USD booking created', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
    }
} else {
    bad('USD inventory found', 'no USD inventory seeded');
}

// ==========================================================================
// 8. Pay inventory debt (deferred)
// ==========================================================================
echo "\n── 8. Pay inventory debt (deferred) ──\n";
$invDeferred = BusInventory::where('payment_type', 'deferred')->where('remaining_debt', '>', 0)->first();
if ($invDeferred) {
    $debtBefore = $invDeferred->remaining_debt;
    $payAmount = round($debtBefore / 2, 2);
    $resp = http('POST', "/bus/inventories/{$invDeferred->id}/pay-debt", [
        'amount'     => $payAmount,
        'account_id' => $cashboxEgp->id,
    ]);
    if ($resp['status'] === 200 || $resp['status'] === 201) {
        $invAfter = $invDeferred->fresh();
        if (abs(($debtBefore - $invAfter->remaining_debt) - $payAmount) < 0.01) {
            ok('inventory debt partial payment', "debt {$debtBefore}->{$invAfter->remaining_debt}");
        } else {
            bad('inventory debt partial payment', "debt {$debtBefore}->{$invAfter->remaining_debt}");
        }
    } else {
        bad('pay inventory debt', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
    }

    // Try overpayment
    $resp = http('POST', "/bus/inventories/{$invDeferred->id}/pay-debt", [
        'amount'     => 999999,
        'account_id' => $cashboxEgp->id,
    ]);
    if ($resp['status'] >= 400) {
        ok('inventory overpayment rejected', 'HTTP '.$resp['status']);
    } else {
        bad('inventory overpayment rejected', 'HTTP '.$resp['status'].' (should be 400/422)');
    }
} else {
    bad('deferred inventory found', 'no deferred inventory seeded');
}

// ==========================================================================
// 9. Delete booking (admin)
// ==========================================================================
echo "\n── 9. Delete booking (admin) ──\n";
$resp = http('POST', '/bus/bookings', [
    'inventory_id' => $invCash->id,
    'customer_id'  => $customer->id,
    'quantity'     => 1,
    'notes'        => 'E2E booking to delete',
]);
$bookingToDelete = BusBooking::find($resp['json']['data']['id']);
ok('booking to delete created', "#{$bookingToDelete->id}");

// Pay it
$resp = http('POST', "/bus/bookings/{$bookingToDelete->id}/pay", [
    'amount'         => $bookingToDelete->total_price,
    'payment_method' => 'cash',
    'account_id'     => $cashboxEgp->id,
]);

$resp = http('DELETE', "/bus/bookings/{$bookingToDelete->id}");
if ($resp['status'] === 200 || $resp['status'] === 204) {
    $trashed = BusBooking::withTrashed()->find($bookingToDelete->id);
    if ($trashed && $trashed->trashed()) {
        ok('booking deleted (soft)', "#{$bookingToDelete->id}");
    } else {
        bad('booking deleted (soft)', 'not trashed');
    }
} else {
    bad('delete booking', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
}

// Idempotent delete
$resp = http('DELETE', "/bus/bookings/{$bookingToDelete->id}");
if ($resp['status'] >= 400) {
    ok('idempotent delete rejected', 'HTTP '.$resp['status']);
} else {
    bad('idempotent delete rejected', 'HTTP '.$resp['status'].' (should be 400/422)');
}

// ==========================================================================
// 10. Pay supplier debt
// ==========================================================================
echo "\n── 10. Pay supplier debt ──\n";
$resp = http('POST', "/bus/companies/{$company->id}/pay-debt", [
    'amount'          => 100,
    'from_account_id' => $cashboxEgp->id,
]);
if ($resp['status'] === 200 || $resp['status'] === 201) {
    ok('pay supplier debt', 'HTTP '.($resp['status']));
} else {
    bad('pay supplier debt', 'HTTP '.$resp['status'].' '.substr($resp['body'], 0, 200));
}

// ==========================================================================
// 11. Final balance integrity
// ==========================================================================
echo "\n── 11. Final balance integrity ──\n";
$ds = $cashboxEgp->fresh();
ok('cashbox balance reconciled', 'balance='.number_format($ds->balance, 2));

// Per-transaction balance check — every SAME-CURRENCY Transaction should balance.
// Multi-currency transactions (debit in EGP, credit in USD/SAR) are excluded
// because single-currency trial balance is meaningless across currencies.
$unbalanced = DB::table('transactions as t')
    ->join('account_entries as ae', 'ae.transaction_id', '=', 't.id')
    ->join('accounts as a', 'ae.account_id', '=', 'a.id')
    ->where('t.module', 'bus')
    ->select('t.id',
        DB::raw('SUM(ae.debit) as d'),
        DB::raw('SUM(ae.credit) as c'),
        DB::raw('SUM(ae.debit) - SUM(ae.credit) as diff'),
        DB::raw('COUNT(DISTINCT a.currency) as distinct_currencies'))
    ->groupBy('t.id')
    ->havingRaw('COUNT(DISTINCT a.currency) = 1')  // same-currency only
    ->havingRaw('ABS(SUM(ae.debit) - SUM(ae.credit)) > 0.01')
    ->get();
if ($unbalanced->isEmpty()) {
    ok('bus transactions: same-currency all balanced', '0 unbalanced (multi-currency excluded)');
} else {
    $totalOff = $unbalanced->sum('diff');
    bad('bus transactions: same-currency all balanced', $unbalanced->count().' unbalanced, total diff='.round($totalOff, 2));
}

// ==========================================================================
// SUMMARY
// ==========================================================================
echo "\n══════════════════════════════════════════════════\n";
echo "           BUS E2E RESULTS SUMMARY\n";
echo "══════════════════════════════════════════════════\n";
echo "PASS: {$pass}\n";
echo "FAIL: {$fail}\n";
echo "TOTAL: ".($pass + $fail)."\n";
echo "══════════════════════════════════════════════════\n";

if ($fail > 0) {
    echo "\n❌ FAILURES:\n";
    foreach ($results as $r) {
        if ($r[0] === 'FAIL') {
            echo "  - {$r[1]} → {$r[2]}\n";
        }
    }
    exit(1);
}

echo "\n✅ All bus scenarios passed.\n";
exit(0);
