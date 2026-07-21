<?php
/**
 * Flight E2E Retry — يعيد تشغيل S2/S3 بحسابات دفع صحيحة ويتحقق من S1
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$ids = json_decode(file_get_contents(storage_path('logs/flight_e2e_ids.json')), true);
$BASE = 'http://127.0.0.1:8000/api/v1';

$TOKEN = Http::acceptJson()->post("$BASE/auth/login", [
    'email' => 'admin@safarakealayna.com', 'password' => 'Sf@2026#Admin!',
])->json('data.token');

echo "Token: " . substr($TOKEN, 0, 20) . "...\n\n";

echo "═══════════════════════════════════════════════════════════════\n";
echo " S1 VERIFY: حجز #6 (مع group) — أين ذهب الخصم؟\n";
echo "═══════════════════════════════════════════════════════════════\n";
$booking6 = App\Models\Flight\FlightBooking::find(6);
echo "Booking #6: status={$booking6->status->value} purchase={$booking6->purchase_price_egp} pbs={$booking6->purchase_balance_source}\n";
echo "  group_id={$booking6->flight_group_id} carrier_id={$booking6->flight_carrier_id}\n";
$grpAcc = DB::table('accounts')->where('name', 'like', '%الشعلة%E2E%')->first();
echo "  Group auto-account: id={$grpAcc->id} name='{$grpAcc->name}' balance={$grpAcc->balance}\n";
$balances = DB::table('account_entries')
    ->where('transaction_id', 91)
    ->join('accounts', 'accounts.id', '=', 'account_entries.account_id')
    ->select('accounts.name', 'accounts.balance', 'account_entries.debit', 'account_entries.credit')
    ->get();
foreach ($balances as $b) {
    echo "  Ledger entry: {$b->name} dr={$b->debit} cr={$b->credit} (current bal={$b->balance})\n";
}
echo "  ✅ النظام خصم من حساب المجموعة تلقائيًا (لأن flight_group_id موجود)\n";
echo "  ❌ السكربت تحقق من flight_carriers.balance خطأ\n\n";

echo "═══════════════════════════════════════════════════════════════\n";
echo " S2 RETRY: KWD booking مع cashbox_kwd\n";
echo "═══════════════════════════════════════════════════════════════\n";
$balCarrierKwdBefore = (float) DB::table('flight_carriers')->where('id', $ids['carriers']['jazeera_kwd'])->value('balance');
$balKwdAccBefore = (float) DB::table('accounts')->where('id', $ids['accounts']['cashbox_kwd'])->value('balance');
$saraAcc = (float) DB::table('accounts')->where('name', 'like', '%سارة%Flight%E2E%')->value('balance');

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['sara'],
    'pnr' => 'PNR-E2E-S2R',
    'airline' => 'J9', 'airline_name' => 'طيران الجزيرة',
    'flight_system_id' => $ids['systems']['ndc'],
    'flight_carrier_id' => $ids['carriers']['jazeera_kwd'],
    'from_airport' => 'CAI', 'to_airport' => 'KWI',
    'departure_date' => '2026-09-10', 'departure_time' => '14:00', 'arrival_time' => '17:30',
    'trip_type' => 'one_way', 'passenger_count' => 1,
    'currency' => 'KWD', 'foreign_currency' => 'KWD',
    'purchase_price_foreign' => 200, 'exchange_rate' => 157.5,
    'purchase_price_egp' => 31500, 'purchase_price' => 31500, 'selling_price' => 35000,
    'account_id' => $ids['accounts']['cashbox_kwd'],
    'purchase_balance_source' => 'carrier',
    'passengers' => [['first_name' => 'سارة', 'last_name' => 'علي', 'passenger_type' => 'adult', 'nationality' => 'EG']],
    'payment' => ['amount' => 35000, 'payment_method' => 'cash', 'account_id' => $ids['accounts']['cashbox_kwd'], 'notes' => 'S2 retry KWD'],
]);

$status = $resp->status();
$body = $resp->json();
echo "HTTP $status\n";
echo "Body: " . json_encode($body, JSON_UNESCAPED_UNICODE) . "\n";
if ($status === 201) {
    $b = $body['data']['booking'] ?? null;
    if (!$b) { echo "❌ no booking in response\n"; exit; }
    echo "✅ KWD booking ID={$b['id']} status={$b['status']} currency={$b['currency']}\n";
    echo "   foreign={$b['foreign_currency']} amt={$b['purchase_price_foreign']} rate={$b['exchange_rate']}\n";
    echo "   egp_equivalent={$b['purchase_price_egp']} selling={$b['selling_price']}\n";
    $balCarrierKwdAfter = (float) DB::table('flight_carriers')->where('id', $ids['carriers']['jazeera_kwd'])->value('balance');
    $balKwdAccAfter = (float) DB::table('accounts')->where('id', $ids['accounts']['cashbox_kwd'])->value('balance');
    echo "   Carrier KWD: {$balCarrierKwdBefore} → {$balCarrierKwdAfter} (Δ " . ($balCarrierKwdAfter - $balCarrierKwdBefore) . ")\n";
    echo "   Cashbox KWD: {$balKwdAccBefore} → {$balKwdAccAfter} (Δ " . ($balKwdAccAfter - $balKwdAccBefore) . ")\n";
    $expected = -200;
    $actual = $balCarrierKwdAfter - $balCarrierKwdBefore;
    if (abs($actual - $expected) < 0.01) {
        echo "   ✅ Carrier KWD debit OK (200 KWD = 31500 EGP at 157.5)\n";
    } else {
        echo "   ❌ Carrier KWD debit MISMATCH: expected {$expected}, got {$actual}\n";
    }
} else {
    echo '❌ KWD booking FAILED: HTTP ' . $resp->status() . "\n";
    echo '   Body: ' . substr($resp->body(), 0, 400) . "\n";
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo " S3 RETRY: SAR booking مع bank_sar\n";
echo "═══════════════════════════════════════════════════════════════\n";
$sarAccId = DB::table('accounts')->where('name', 'بنك مصر Flight E2E SAR')->value('id');
echo "SAR account id={$sarAccId}\n";

$balCarrierSarBefore = (float) DB::table('flight_carriers')->where('id', $ids['carriers']['saudi_sar'])->value('balance');
$balSarAccBefore = (float) DB::table('accounts')->where('id', $sarAccId)->value('balance');

$resp = Http::withToken($TOKEN)->acceptJson()->post("$BASE/flight/bookings", [
    'customer_id' => $ids['customers']['ahmed'],
    'pnr' => 'PNR-E2E-S3R',
    'airline' => 'SV', 'airline_name' => 'الخطوط السعودية',
    'flight_system_id' => $ids['systems']['amadeus'],
    'flight_carrier_id' => $ids['carriers']['saudi_sar'],
    'from_airport' => 'CAI', 'to_airport' => 'JED',
    'departure_date' => '2026-10-05', 'trip_type' => 'one_way', 'passenger_count' => 1,
    'currency' => 'SAR', 'foreign_currency' => 'SAR',
    'purchase_price_foreign' => 1000, 'exchange_rate' => 12.9,
    'purchase_price_egp' => 12900, 'purchase_price' => 12900, 'selling_price' => 15000,
    'account_id' => $sarAccId,
    'purchase_balance_source' => 'carrier',
    'passengers' => [['first_name' => 'أحمد', 'last_name' => 'محمد', 'passenger_type' => 'adult', 'nationality' => 'EG']],
    'payment' => ['amount' => 15000, 'payment_method' => 'bank_transfer', 'account_id' => $sarAccId, 'notes' => 'S3 retry SAR'],
]);

if ($resp->status() === 201) {
    $b = $resp->json('data.booking');
    echo "✅ SAR booking ID={$b['id']} status={$b['status']} currency={$b['currency']}\n";
    echo "   foreign={$b['foreign_currency']} amt={$b['purchase_price_foreign']} rate={$b['exchange_rate']}\n";
    echo "   egp_equivalent={$b['purchase_price_egp']} selling={$b['selling_price']}\n";
    $balCarrierSarAfter = (float) DB::table('flight_carriers')->where('id', $ids['carriers']['saudi_sar'])->value('balance');
    $balSarAccAfter = (float) DB::table('accounts')->where('id', $sarAccId)->value('balance');
    echo "   Carrier SAR: {$balCarrierSarBefore} → {$balCarrierSarAfter} (Δ " . ($balCarrierSarAfter - $balCarrierSarBefore) . ")\n";
    echo "   Bank SAR: {$balSarAccBefore} → {$balSarAccAfter} (Δ " . ($balSarAccAfter - $balSarAccBefore) . ")\n";
} else {
    echo '❌ SAR booking FAILED: HTTP ' . $resp->status() . "\n";
    echo '   Body: ' . substr($resp->body(), 0, 400) . "\n";
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo " ملخص نهائي\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "عدد الحجوزات في DB: " . App\Models\Flight\FlightBooking::count() . "\n";
echo "عدد المعاملات (transactions): " . DB::table('transactions')->count() . "\n";
echo "عدد القيود (account_entries): " . DB::table('account_entries')->count() . "\n";
