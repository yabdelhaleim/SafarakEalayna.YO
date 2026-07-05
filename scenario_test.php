<?php
use App\Models\Account;
use App\Models\Transaction;

$user = \App\Models\User::where('email', 'admin@test.com')->first();
$userId = $user->id;
auth()->login($user);

$egpTreasury = Account::where('name', 'خزينة اختبار EGP')->first();
$sarTreasury = Account::where('name', 'خزينة اختبار SAR')->first();
$carrier = FlightCarrier::where('code', 'SV-TEST')->first();
$customer = Customer::where('full_name', 'عميل اختبار شامل')->first();

echo "\n========== STEP 1: شحن السعودية 5000 SAR ==========\n";
try {
    $result = app(\App\Services\Flight\FlightCarrierRechargeService::class)->rechargeFromAccount(
        $carrier, $sarTreasury, 5000.00, "شحن اختبار"
    );
    echo "✓ Recharge OK\n";
    echo "  Carrier balance: " . number_format((float)$result["carrier"]->balance, 2) . " SAR\n";
    echo "  SAR Treasury balance: " . number_format((float)$result["source_account"]->balance, 2) . " SAR\n";
} catch (\Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    exit;
}

echo "\n========== STEP 2: حجز طيران 4000 EGP (تكلفة 3800) ==========\n";
try {
    $booking = app(\App\Services\Flight\FlightBookingService::class)->createBooking([
        "customer_id" => $customer->id,
        "airline_name" => "الخطوط السعودية - اختبار",
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
    echo "✓ Booking created: #" . $booking->id . " ({$booking->booking_number})\n";
    echo "  Selling: " . number_format((float)$booking->selling_price, 2) . " EGP\n";
    echo "  Purchase: " . number_format((float)$booking->purchase_price, 2) . " EGP\n";
    echo "  Profit: " . number_format((float)$booking->profit, 2) . " EGP\n";
} catch (\Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    exit;
}

$egpTreasury->refresh(); $sarTreasury->refresh(); $carrier->refresh();
echo "\n  EGP Treasury بعد: " . number_format((float)$egpTreasury->balance, 2) . " EGP\n";
echo "  SAR Treasury بعد: " . number_format((float)$sarTreasury->balance, 2) . " SAR\n";
echo "  Carrier بعد: " . number_format((float)$carrier->balance, 2) . " SAR\n";
echo "  رصيد مسبق ناقلي بعد: " . number_format((float)Account::where("name", "رصيد مسبق — ناقلو الطيران")->value("balance"), 2) . " EGP\n";

echo "\n========== STEP 3: حجز ثاني 2500 EGP ==========\n";
try {
    $booking2 = app(\App\Services\Flight\FlightBookingService::class)->createBooking([
        "customer_id" => $customer->id,
        "airline_name" => "الخطوط السعودية - اختبار",
        "from_airport" => "JED",
        "to_airport" => "RUH",
        "departure_date" => "2026-08-20",
        "trip_type" => "one_way",
        "currency" => "EGP",
        "purchase_price" => 2200,
        "selling_price" => 2500,
        "flight_carrier_id" => $carrier->id,
        "purchase_balance_source" => "carrier",
        "account_id" => $egpTreasury->id,
        "passengers" => [["first_name" => "اختبار", "last_name" => "٢", "type" => "adult"]],
        "payment" => ["amount" => 2500, "payment_method" => "cash", "account_id" => $egpTreasury->id],
    ]);
    echo "✓ Booking #" . $booking2->id . " (سعر {$booking2->selling_price} EGP, تكلفة {$booking2->purchase_price} EGP)\n";
} catch (\Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n========== BALANCES بعد الحجزين ==========\n";
$egpTreasury->refresh(); $carrier->refresh();
printf("EGP Treasury: %.2f EGP\n", (float)$egpTreasury->balance);
printf("SAR Treasury: %.2f SAR\n", (float)$sarTreasury->balance);
printf("Carrier: %.4f SAR\n", (float)$carrier->balance);
printf("رصيد مسبق ناقلي: %.2f EGP\n", (float)Account::where("name", "رصيد مسبق — ناقلو الطيران")->value("balance"));
printf("إقفال تكاليف الطيران: %.2f EGP\n", (float)Account::where("name", "إقفال تكاليف الطيران")->value("balance"));
printf("إقفال مبيعات الطيران (نظام): %.2f EGP\n", (float)Account::where("name", "إقفال مبيعات الطيران (نظام)")->value("balance"));

echo "\n========== STEP 4: إلغاء الحجز الأول ==========\n";
try {
    $refund = app(\App\Services\Flight\FlightBookingService::class)->cancelBooking($booking, [
        "airline_penalty" => 200,
        "office_penalty" => 100,
        "account_id" => $egpTreasury->id,
        "notes" => "إلغاء اختبار",
    ]);
    echo "✓ Cancellation: refund = " . number_format((float)$refund->refund_amount, 2) . " EGP\n";
} catch (\Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n========== FINAL BALANCES ==========\n";
$egpTreasury->refresh(); $carrier->refresh();
printf("EGP Treasury: %.2f EGP\n", (float)$egpTreasury->balance);
printf("SAR Treasury: %.2f SAR\n", (float)$sarTreasury->balance);
printf("Carrier: %.4f SAR\n", (float)$carrier->balance);
printf("رصيد مسبق ناقلي: %.2f EGP\n", (float)Account::where("name", "رصيد مسبق — ناقلو الطيران")->value("balance"));
printf("إقفال تكاليف الطيران: %.2f EGP\n", (float)Account::where("name", "إقفال تكاليف الطيران")->value("balance"));

echo "\n========== STEP 5: TRIAL BALANCE ==========\n";
$tb = app(\App\Services\Finance\TreasuryService::class)->getTrialBalance();
foreach (["total_balances", "total_liquidity", "due_to_us", "due_from_us", "current_capital",
          "base_capital", "profits", "expected_capital", "variance", "status"] as $k) {
    printf("  %s: %s\n", str_pad($k, 20), is_numeric($tb[$k]) ? number_format((float)$tb[$k], 2) : $tb[$k]);
}

echo "\n========== STEP 6: RECENT TRANSACTIONS (last 8) ==========\n";
$txs = Transaction::where("module", "flight")->orderBy("id", "desc")->limit(8)->get();
foreach ($txs as $t) {
    $fa = Account::find($t->from_account_id);
    $ta = Account::find($t->to_account_id);
    printf("Tx#%d | %s | %.2f | %s → %s\n",
        $t->id, $t->type->value, (float)$t->amount,
        mb_substr($fa?->name ?? "-", 0, 30),
        mb_substr($ta?->name ?? "-", 0, 30));
}

echo "\n========== DONE ==========\n";
echo "دلوقتي روح للـ Filament وشوف:\n";
echo "  1. Treasury Overview (الخزائن وحسابات التحصيل)\n";
echo "  2. قائمة الحسابات — تأكد إن الرصيد المسبق بقى أقل من 5000\n";
echo "  3. trial balance — تأكد إن status = متساوية أو زيادة طفيفة\n";
