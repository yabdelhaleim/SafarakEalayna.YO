<?php
use App\Models\Account;
use App\Models\Transaction;
use App\Support\Finance\LedgerBalanceMutationGuard;

$user = \App\Models\User::where('email', 'admin@test.com')->first();
$userId = $user->id;
auth()->login($user);

// استخدم timestamp فريد لتجنب التضارب
$tag = substr(md5((string) microtime(true)), 0, 6);

$egpTreasury = Account::create([
    "name" => "خزينة اختبار EGP {$tag}", "type" => "cashbox", "currency" => "EGP",
    "balance" => 50000, "is_active" => true, "owner_type" => "office",
    "module_type" => "flights", "is_module_vault" => true, "created_by" => $userId,
]);
$sarTreasury = Account::create([
    "name" => "خزينة اختبار SAR {$tag}", "type" => "cashbox", "currency" => "SAR",
    "balance" => 5000, "is_active" => true, "owner_type" => "office",
    "module_type" => "flights", "is_module_vault" => true, "created_by" => $userId,
]);
$sys = \App\Models\Flight\FlightSystem::create([
    "name" => "نظام اختبار {$tag}", "code" => "TEST-{$tag}", "type" => "gds",
    "currency" => "EGP", "balance" => 0, "credit_limit" => 0,
    "is_active" => true, "created_by" => $userId,
]);
$carrier = \App\Models\Flight\FlightCarrier::create([
    "flight_system_id" => $sys->id, "name" => "السعودية اختبار {$tag}",
    "code" => "SV-{$tag}", "currency" => "SAR", "balance" => 0,
    "credit_limit" => 0, "is_active" => true, "created_by" => $userId,
]);
$customer = \App\Models\Customer::create([
    "full_name" => "عميل اختبار {$tag}", "phone" => "010000000{$tag}",
    "is_active" => true, "created_by" => $userId,
]);

echo "\n========== STEP 1: شحن السعودية 5000 SAR ==========\n";
try {
    $result = app(\App\Services\Flight\FlightCarrierRechargeService::class)->rechargeFromAccount(
        $carrier, $sarTreasury, 5000.00, "شحن اختبار v2"
    );
    echo "✓ Recharge OK\n";
    echo "  Carrier.balance: " . number_format((float)$result["carrier"]->balance, 2) . " SAR\n";
    echo "  SAR Treasury: " . number_format((float)$result["source_account"]->balance, 2) . " SAR\n";
} catch (\Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    exit;
}

// Find prepaid GL account
$prepaidCarrier = Account::where('name', 'رصيد مسبق — ناقلو الطيران')->first();
echo "  Prepaid GL (رصيد مسبق ناقلي): " . number_format((float)$prepaidCarrier->balance, 2) . " EGP\n";

echo "\n========== STEP 2: حجز 4000 EGP (تكلفة 3800) ==========\n";
$booking = app(\App\Services\Flight\FlightBookingService::class)->createBooking([
    "customer_id" => $customer->id,
    "airline_name" => "السعودية اختبار {$tag}",
    "from_airport" => "CAI",
    "to_airport" => "JED",
    "departure_date" => "2026-08-15",
    "trip_type" => "one_way",
    "currency" => "EGP",
    "purchase_price" => 3800,
    "selling_price" => 4000,
    "flight_carrier_id" => $carrier->id,
    "purchase_balance_source" => "carrier",
    "account_id" => $egpTreasury->id,
    "passengers" => [["first_name" => "اختبار", "last_name" => "١", "type" => "adult"]],
    "payment" => ["amount" => 4000, "payment_method" => "cash", "account_id" => $egpTreasury->id],
]);
echo "✓ Booking #" . $booking->id . " (profit: {$booking->profit} EGP)\n";

$prepaidCarrier->refresh(); $carrier->refresh(); $egpTreasury->refresh(); $sarTreasury->refresh();
echo "  Treasury EGP: " . number_format((float)$egpTreasury->balance, 2) . "\n";
echo "  Treasury SAR: " . number_format((float)$sarTreasury->balance, 2) . "\n";
echo "  Carrier: " . number_format((float)$carrier->balance, 4) . " SAR\n";
echo "  Prepaid GL: " . number_format((float)$prepaidCarrier->balance, 2) . " EGP\n";
echo "  Cost Clearing: " . number_format((float)Account::where('name', 'إقفال تكاليف الطيران')->value('balance'), 2) . " EGP\n";

echo "\n========== RECENT TRANSACTIONS ==========\n";
$txs = Transaction::where("module", "flight")->orderBy("id", "desc")->limit(5)->get();
foreach ($txs as $t) {
    $fa = Account::find($t->from_account_id);
    $ta = Account::find($t->to_account_id);
    printf("Tx#%d | %s | %.2f %s | %s → %s\n",
        $t->id, $t->type->value, (float)$t->amount,
        $fa?->currency ?? '?',
        mb_substr($fa?->name ?? "-", 0, 30),
        mb_substr($ta?->name ?? "-", 0, 30));
}

echo "\n========== DONE ==========\n";
echo "tag=$tag (للعثور على البيانات بسهولة)\n";
