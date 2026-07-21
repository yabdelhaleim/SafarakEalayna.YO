<?php
/**
 * End-to-end production-readiness test for the Bus module (v2 — fixed assertions).
 *
 * Validates:
 *   - Booking creation in EGP, USD, SAR
 *   - Multi-currency customer account handling
 *   - Payment flows with FX
 *   - Cancellation with penalties & refunds
 *   - Deletion (simple + with reversal)
 *   - Per-currency ledger balance (double-entry)
 *
 * Each scenario is isolated to fresh entities. Cross-currency entries are
 * validated per-currency (USD vs EGP can't be summed directly).
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusPayment;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\Bus\BusBookingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$results = [];
$failures = [];

function ok(string $scenario, string $detail = ''): void
{
    global $results;
    $results[] = ['status' => '✅ PASS', 'scenario' => $scenario, 'detail' => $detail];
    echo "✅ {$scenario}".($detail ? " — {$detail}" : '')."\n";
}

function fail(string $scenario, string $detail): void
{
    global $results, $failures;
    $results[] = ['status' => '❌ FAIL', 'scenario' => $scenario, 'detail' => $detail];
    $failures[] = ['scenario' => $scenario, 'detail' => $detail];
    echo "❌ {$scenario} — {$detail}\n";
}

function section(string $title): void
{
    echo "\n".str_repeat('=', 80)."\n";
    echo "  {$title}\n";
    echo str_repeat('=', 80)."\n";
}

function assertFloat(string $name, float $expected, float $actual, float $epsilon = 0.01): void
{
    if (abs($expected - $actual) < $epsilon) {
        ok($name, "expected={$expected} actual={$actual}");
    } else {
        fail($name, "expected={$expected} actual={$actual}");
    }
}

Auth::loginUsingId(1);
$service = app(BusBookingService::class);

$usdCashbox = Account::where('name', 'خزينة الباص الدولارية')->first();
$sarCashbox = Account::where('name', 'خزينة الباص الريال السعودي')->first();
$officeCashbox = Account::find(1);
$egpInv = BusInventory::find(2);
$usdInv = BusInventory::find(6);
$sarInv = BusInventory::find(7);

function freshCustomer(string $name, string $phone, string $nationality = 'EG'): Customer
{
    return Customer::firstOrCreate(
        ['phone' => $phone],
        [
            'full_name' => $name,
            'type' => 'individual',
            'customer_tier' => 'STANDARD',
            'nationality' => $nationality,
        ]
    );
}

// =====================================================================
// SCENARIO 1: Create EGP booking
// =====================================================================
section('SCENARIO 1: Create EGP booking (existing inventory)');

$customerA = freshCustomer('عميل اختبار 1 - مصري', '01010010001');
$booking1 = $service->createBooking([
    'inventory_id' => $egpInv->id,
    'customer_id' => $customerA->id,
    'quantity' => 3,
    'notes' => 'اختبار - حجز EGP - 3 تذاكر',
]);
ok('S1.1 Create EGP booking', "id={$booking1->id} total={$booking1->total_price} {$booking1->currency}");

assertFloat('S1.2 Total = 3 × 150 EGP', 450.00, (float) $booking1->total_price);
if ($booking1->currency === 'EGP') ok('S1.3 Currency = EGP'); else fail('S1.3', $booking1->currency);
assertFloat('S1.4 Profit = 3 × (150-110) = 120', 120.00, (float) $booking1->profit);
$egpInv->refresh();
if ((int) $egpInv->available_tickets === 47) ok('S1.5 Inventory decremented (50 → 47)'); else fail('S1.5', "got {$egpInv->available_tickets}");

// =====================================================================
// SCENARIO 2: Create USD booking
// =====================================================================
section('SCENARIO 2: Create USD booking (Cairo → Aqaba)');

$customerB = freshCustomer('عميل اختبار 2 - سعودي', '01010010002', 'SA');
$booking2 = $service->createBooking([
    'inventory_id' => $usdInv->id,
    'customer_id' => $customerB->id,
    'quantity' => 2,
    'notes' => 'اختبار - USD - 2 تذاكر',
]);
ok('S2.1 Create USD booking', "id={$booking2->id} total={$booking2->total_price} {$booking2->currency}");

if ($booking2->currency === 'USD') ok('S2.2 Currency = USD'); else fail('S2.2', $booking2->currency);
assertFloat('S2.3 Total = 2 × 30 = 60 USD', 60.00, (float) $booking2->total_price);
assertFloat('S2.4 FX rate snapshot (49.50)', 49.50, (float) $booking2->exchange_rate_to_egp);

$customerBfresh = Customer::find($customerB->id);
$customerAccountB = Account::find($customerBfresh->account_id);
if ($customerAccountB && $customerAccountB->currency === 'USD') {
    ok('S2.5 Customer has USD AR account', "#{$customerAccountB->id} balance={$customerAccountB->balance} USD");
} else {
    fail('S2.5', "expected USD, got ".($customerAccountB?->currency ?? 'null'));
}

$company4 = BusCompany::find(4);
$companyAccount = Account::find($company4->account_id);
if ($companyAccount && (float) $companyAccount->balance < 0) {
    $expectedEgp = 22 * 2 * 49.50;
    assertFloat('S2.6 Company AR balance in EGP (-2178)', -$expectedEgp, (float) $companyAccount->balance);
} else {
    fail('S2.6', "company account missing or not negative");
}

// =====================================================================
// SCENARIO 3: Create SAR booking
// =====================================================================
section('SCENARIO 3: Create SAR booking (Cairo → Jeddah)');

$customerC = freshCustomer('عميل اختبار 3 - مصري', '01010010003');
$booking3 = $service->createBooking([
    'inventory_id' => $sarInv->id,
    'customer_id' => $customerC->id,
    'quantity' => 4,
    'notes' => 'اختبار - SAR - 4 تذاكر',
]);
ok('S3.1 Create SAR booking', "id={$booking3->id} total={$booking3->total_price} {$booking3->currency}");

if ($booking3->currency === 'SAR') ok('S3.2 Currency = SAR'); else fail('S3.2', $booking3->currency);
assertFloat('S3.3 Total = 4 × 120 = 480 SAR', 480.00, (float) $booking3->total_price);

$customerCfresh = Customer::find($customerC->id);
$customerAccountC = Account::find($customerCfresh->account_id);
if ($customerAccountC && $customerAccountC->currency === 'SAR') {
    ok('S3.4 Customer has SAR AR account', "#{$customerAccountC->id} balance={$customerAccountC->balance} SAR");
} else {
    fail('S3.4', "expected SAR, got ".($customerAccountC?->currency ?? 'null'));
}

// =====================================================================
// SCENARIO 4: Pay partial EGP
// =====================================================================
section('SCENARIO 4: Pay partial EGP booking');

$booking1->refresh();
$paid1 = $service->payBooking($booking1, [
    'amount' => 150.00,
    'payment_method' => 'cash',
    'account_id' => $officeCashbox->id,
    'notes' => 'دفعة جزئية',
]);
$paid1->refresh();
assertFloat('S4.1 Partial payment (150 of 450)', 150.00, (float) $paid1->paid_amount);
if ($paid1->payment_status->value === 'partial') ok('S4.2 Payment status = partial'); else fail('S4.2', $paid1->payment_status->value);

$payment = BusPayment::where('booking_id', $booking1->id)->first();
if ($payment && $payment->transaction_id) ok('S4.3 Payment has GL transaction', "tx_id={$payment->transaction_id}");
else fail('S4.3', 'no transaction_id');

// =====================================================================
// SCENARIO 5: Pay full USD from USD cashbox (FX-aware)
// =====================================================================
section('SCENARIO 5: Pay full USD booking from USD cashbox (FX)');

$booking2->refresh();
$usdBefore = (float) $usdCashbox->balance;
$paid2 = $service->payBooking($booking2, [
    'amount' => 60.00,
    'payment_method' => 'cash',
    'account_id' => $usdCashbox->id,
    'notes' => 'دفع USD',
]);
$paid2->refresh();
assertFloat('S5.1 USD full payment (60 USD)', 60.00, (float) $paid2->paid_amount);
if ($paid2->payment_status->value === 'paid' && $paid2->status->value === 'paid') {
    ok('S5.2 Booking status = paid');
} else {
    fail('S5.2', "got {$paid2->payment_status->value}/{$paid2->status->value}");
}

$usdCashbox->refresh();
assertFloat('S5.3 USD cashbox +60 USD', $usdBefore + 60.00, (float) $usdCashbox->balance);

// Verify per-currency balance of payment transaction
$payment2 = $paid2->payments->first();
$tx = Transaction::find($payment2->transaction_id);
$entries = AccountEntry::where('transaction_id', $tx->id)->get();
$byCurrency = $entries->groupBy(fn($e) => Account::find($e->account_id)?->currency ?? 'UNK');
$perCurrencyOk = true;
foreach ($byCurrency as $ccy => $group) {
    $debit = (float) $group->sum('debit');
    $credit = (float) $group->sum('credit');
    if (abs($debit - $credit) >= 0.01) {
        $perCurrencyOk = false;
        fail("S5.4 {$ccy} imbalance", "debit={$debit} credit={$credit}");
    }
}
if ($perCurrencyOk) {
    ok('S5.4 Payment tx is balanced per-currency', "ccys: ".implode(',', $byCurrency->keys()->all()));
}

// =====================================================================
// SCENARIO 6: Pay remaining → paid
// =====================================================================
section('SCENARIO 6: Pay remaining balance');

$booking1->refresh();
$remaining = (float) $booking1->total_price - (float) $booking1->paid_amount;
$paidFinal = $service->payBooking($booking1, [
    'amount' => $remaining,
    'payment_method' => 'bank_transfer',
    'account_id' => $officeCashbox->id,
]);
$paidFinal->refresh();
if ($paidFinal->status->value === 'paid' && $paidFinal->payment_status->value === 'paid') {
    ok('S6.1 Final payment → paid', "total_paid={$paidFinal->paid_amount}");
} else {
    fail('S6.1', "got {$paidFinal->status->value}/{$paidFinal->payment_status->value}");
}

// =====================================================================
// SCENARIO 7: Cancel paid EGP with penalty
// =====================================================================
section('SCENARIO 7: Cancel paid EGP booking with penalty + refund');

$customerD = freshCustomer('عميل اختبار 4', '01010010004');
$booking7 = $service->createBooking([
    'inventory_id' => $egpInv->id,
    'customer_id' => $customerD->id,
    'quantity' => 2,
    'notes' => 'للإلغاء',
]);
$service->payBooking($booking7, [
    'amount' => 300.00,
    'payment_method' => 'cash',
    'account_id' => $officeCashbox->id,
]);
$booking7->refresh();

$cashboxBefore = (float) $officeCashbox->balance;
$refund = $service->cancelBooking($booking7, [
    'company_penalty' => 50.00,
    'office_penalty' => 20.00,
    'account_id' => $officeCashbox->id,
    'notes' => 'إلغاء مع خصم',
]);
ok('S7.1 Booking cancelled', "refund_id={$refund->id}");
assertFloat('S7.2 Refund = 300 - 70 = 230 EGP', 230.00, (float) $refund->refund_amount);

$booking7->refresh();
if ($booking7->status->value === 'refunded') ok('S7.3 Booking status = refunded');
else fail('S7.3', "got {$booking7->status->value}");

$officeCashbox->refresh();
assertFloat('S7.4 Cashbox decreased by refund', $cashboxBefore - 230.00, (float) $officeCashbox->balance);

// =====================================================================
// SCENARIO 8: Cancel with no payments
// =====================================================================
section('SCENARIO 8: Cancel booking (no payments, no penalty)');

$customerE = freshCustomer('عميل اختبار 5', '01010010005');
$booking8 = $service->createBooking([
    'inventory_id' => $egpInv->id,
    'customer_id' => $customerE->id,
    'quantity' => 1,
]);
$booking8->refresh();

$refund8 = $service->cancelBooking($booking8, [
    'company_penalty' => 0,
    'office_penalty' => 0,
]);
$booking8->refresh();
if ($booking8->status->value === 'cancelled') ok('S8.1 Status = cancelled'); else fail('S8.1', $booking8->status->value);
assertFloat('S8.2 Refund amount = 0', 0.00, (float) $refund8->refund_amount);

// =====================================================================
// SCENARIO 9: Delete (simple)
// =====================================================================
section('SCENARIO 9: Delete booking (simple, no payments)');

$customerF = freshCustomer('عميل اختبار 6', '01010010006');
$booking9 = $service->createBooking([
    'inventory_id' => $egpInv->id,
    'customer_id' => $customerF->id,
    'quantity' => 1,
]);
$availBefore = (int) $egpInv->fresh()->available_tickets;
$service->deleteBooking($booking9);
$availAfter = (int) $egpInv->fresh()->available_tickets;
if ($availAfter === $availBefore + 1) ok('S9.1 Inventory restored', "{$availBefore} → {$availAfter}");
else fail('S9.1', "expected ".($availBefore + 1).", got {$availAfter}");

$trashed = BusBooking::withTrashed()->find($booking9->id);
if ($trashed && $trashed->trashed()) ok('S9.2 Booking soft-deleted');
else fail('S9.2', 'trashed() check failed');

// =====================================================================
// SCENARIO 10: Delete with reversal
// =====================================================================
section('SCENARIO 10: Delete booking with reversal (has payments)');

$customerG = freshCustomer('عميل اختبار 7', '01010010007');
$booking10 = $service->createBooking([
    'inventory_id' => $egpInv->id,
    'customer_id' => $customerG->id,
    'quantity' => 2,
]);
$service->payBooking($booking10, [
    'amount' => 100.00,
    'payment_method' => 'cash',
    'account_id' => $officeCashbox->id,
]);
$booking10->refresh();

$availBefore = (int) $egpInv->fresh()->available_tickets;
$service->deleteBookingWithReversal($booking10->id);
$availAfter = (int) $egpInv->fresh()->available_tickets;
if ($availAfter === $availBefore + 2) ok('S10.1 Inventory restored after reversal', "{$availBefore} → {$availAfter}");
else fail('S10.1', "expected ".($availBefore + 2).", got {$availAfter}");

$trashed = BusBooking::withTrashed()->find($booking10->id);
if ($trashed && $trashed->trashed()) ok('S10.2 Booking soft-deleted');
else fail('S10.2', 'soft-delete failed');

try {
    $service->deleteBookingWithReversal($booking10->id);
    fail('S10.3 Idempotency', 'second call should have thrown');
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'محذوف بالفعل')) {
        ok('S10.3 Idempotent — second call throws clean Arabic error');
    } else {
        fail('S10.3', "wrong error: {$e->getMessage()}");
    }
}

// =====================================================================
// SCENARIO 11: Per-currency ledger integrity
// =====================================================================
section('SCENARIO 11: Accounting integrity (per-currency)');

// Per-currency balance: for each transaction, group entries by the account's
// currency and verify debit=credit within each group. Cross-currency
// transactions are intentionally split between currencies.
$unbalanced = [];
$allTx = Transaction::all();
foreach ($allTx as $tx) {
    $entries = AccountEntry::where('transaction_id', $tx->id)->get();
    if ($entries->isEmpty()) continue;

    $byCurrency = $entries->groupBy(fn($e) => Account::find($e->account_id)?->currency ?? 'UNK');
    foreach ($byCurrency as $ccy => $group) {
        $debit = (float) $group->sum('debit');
        $credit = (float) $group->sum('credit');
        if (abs($debit - $credit) >= 0.01) {
            $unbalanced[] = "tx#{$tx->id} {$ccy}: debit={$debit} credit={$credit}";
        }
    }
}

if (empty($unbalanced)) {
    ok('S11.1 All transactions balanced per-currency', "checked {$allTx->count()} transactions");
} else {
    fail('S11.1', count($unbalanced)." imbalances: ".implode('; ', array_slice($unbalanced, 0, 5)));
}

// =====================================================================
// SCENARIO 12: Inventory integrity
// =====================================================================
section('SCENARIO 12: Inventory math consistency');

$egpInv->refresh();
$total = (int) $egpInv->total_tickets;
$available = (int) $egpInv->available_tickets;
$bookedActive = (int) BusBooking::where('inventory_id', $egpInv->id)
    ->whereIn('status', ['pending', 'paid', 'partial'])
    ->sum('quantity');
if ($available + $bookedActive <= $total) {
    ok('S12.1 Inventory math consistent', "total={$total}, avail={$available}, active_booked={$bookedActive}");
} else {
    fail('S12.1', "avail + active > total: {$available} + {$bookedActive} > {$total}");
}

// =====================================================================
// SCENARIO 13: Stats
// =====================================================================
section('SCENARIO 13: getBookingStats()');

$stats = $service->getBookingStats();
ok('S13.1 Stats returns structure', json_encode([
    'total_bookings' => $stats['total_bookings'],
    'paid_bookings' => $stats['paid_bookings'],
    'pending_bookings' => $stats['pending_bookings'],
    'cancelled_bookings' => $stats['cancelled_bookings'],
    'total_revenue' => round($stats['total_revenue'], 2),
    'pending_payments' => round($stats['pending_payments'], 2),
], JSON_UNESCAPED_UNICODE));

// =====================================================================
// FINAL REPORT
// =====================================================================
section('FINAL REPORT');

$pass = count(array_filter($results, fn($r) => str_starts_with($r['status'], '✅')));
$fail = count(array_filter($results, fn($r) => str_starts_with($r['status'], '❌')));

echo "\nTotal scenarios run: " . count($results) . "\n";
echo "✅ PASS: {$pass}\n";
echo "❌ FAIL: {$fail}\n";

if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f['scenario']}: {$f['detail']}\n";
    }
    exit(1);
}

echo "\n🎉 All scenarios passed!\n";
exit(0);
