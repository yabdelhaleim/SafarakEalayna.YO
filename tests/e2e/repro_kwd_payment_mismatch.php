<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * REPRODUCTION: KWD booking with Jazeera carrier — payment currency mismatch
 * ════════════════════════════════════════════════════════════════════════════
 *
 * السيناريو من المستخدم:
 *   - حجز من سيستم دينار (KWD)
 *   - Carrier = الجزيرة (Jazeera, KWD)
 *   - شراء = 50 KWD = 8,000 EGP  (rate = 160)
 *   - بيع  = 8500 KWD = 1,360,000 EGP
 *
 * الحالة قبل 2026-07-23:
 *   API يرجّع "عملة الدفع (EGP) لا تطابق عملة الحجز (KWD)"
 *   ← هذا الـ script كان يطبع ❌ على الخطوة B.
 *
 * الحالة بعد الإصلاح (2026-07-23):
 *   - addPayment() يقبل الدفع بـ EGP لحجز KWD عندما يكون حساب العميل AR بـ EGP
 *   - تحويل تلقائي بسعر الحجز (booking.exchange_rate = 160)
 *   - booking.original_currency = 'EGP'
 *   - booking.original_amount   = 8500 (KWD — معادلة 1,360,000 / 160)
 *   - FlightPayment.currency    = 'EGP'
 *   - الـ script الآن يطبع ✅ على الخطوة B.
 *
 * التشغيل: php tests/e2e/repro_kwd_payment_mismatch.php
 * المتطلب: server شغّال على http://127.0.0.1:8000 + admin@safarakealayna.com / password
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

$now = date('Y-m-d H:i:s');
echo "═══════════════════════════════════════════════════════════════\n";
echo "  REPRODUCTION — KWD payment mismatch — {$now}\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Step 1: Login
$loginResp = Http::acceptJson()->post(url('/api/v1/auth/login'), [
    'email' => 'admin@safarakealayna.com',
    'password' => 'password',
]);
if (! $loginResp->successful()) {
    fwrite(STDERR, "Login failed: {$loginResp->status()} — {$loginResp->body()}\n");
    exit(1);
}
$token = $loginResp->json('data.token') ?? $loginResp->json('data.access_token') ?? $loginResp->json('token');
if (! $token) {
    fwrite(STDERR, "Login succeeded but no token in response: {$loginResp->body()}\n");
    exit(1);
}
echo "✅ Login OK (token length: ".strlen($token).")\n\n";
$auth = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

// Step 2: Find or create test fixtures
$admin = User::where('email', 'admin@safarakealayna.com')->first();
Auth::login($admin);

DB::transaction(function () use (&$fixtures) {
    // Jazeera (KWD) carrier
    $kwdSystem = FlightSystem::firstOrCreate(
        ['code' => 'REP_KWD_SYS'],
        ['name' => 'KWD System (Repro)', 'type' => 'manual', 'description' => 'Reproduction KWD system',
         'is_active' => true, 'balance' => 100000, 'currency' => 'KWD',
         'credit_limit' => 500000, 'created_by' => 1]
    );

    $jazeera = FlightCarrier::firstOrCreate(
        ['code' => 'J9_REP'],
        ['flight_system_id' => $kwdSystem->id, 'name' => 'الجزيرة (Repro)',
         'is_active' => true, 'balance' => 5000, 'currency' => 'KWD',
         'credit_limit' => 10000, 'created_by' => 1]
    );

    // EGP cash account (the "wrong" account the user keeps picking)
    $egpCash = Account::firstOrCreate(
        ['name' => 'Repro EGP Cash', 'currency' => 'EGP'],
        ['type' => \App\Enums\AccountType::Cashbox, 'balance' => 100000, 'is_active' => true,
         'owner_type' => Account::OWNER_TYPE_OWNER, 'module_type' => 'flights',
         'is_module_vault' => true, 'created_by' => 1]
    );

    // KWD cash account (the "right" account that should exist)
    $kwdCash = Account::firstOrCreate(
        ['name' => 'Repro KWD Cash', 'currency' => 'KWD'],
        ['type' => \App\Enums\AccountType::Cashbox, 'balance' => 5000, 'is_active' => true,
         'owner_type' => Account::OWNER_TYPE_OWNER, 'module_type' => 'flights',
         'is_module_vault' => true, 'created_by' => 1]
    );

    // Customer
    $customer = Customer::firstOrCreate(
        ['phone' => '010REPRO001'],
        ['full_name' => 'عميل تجريبي KWD', 'national_id' => '29001011234567',
         'travel_country' => 'الكويت', 'created_by' => 1]
    );

    $fixtures = [
        'kwd_system_id' => $kwdSystem->id,
        'jazeera_id'    => $jazeera->id,
        'egp_cash_id'   => $egpCash->id,
        'kwd_cash_id'   => $kwdCash->id,
        'customer_id'   => $customer->id,
    ];
});

echo "Fixtures ready:\n";
foreach ($fixtures as $k => $v) echo "  $k = $v\n";
echo "\n";

// Step 3: Discover what the payment-account dropdown actually returns for KWD bookings
echo "─── STEP A: List liquidity accounts the dropdown WOULD show ───\n";
$accountsResp = Http::withHeaders($auth)->get(url('/api/v1/finance/accounts?types=cashbox,bank,wallet'));
if ($accountsResp->successful()) {
    $allAccounts = $accountsResp->json('data.items') ?? $accountsResp->json('data') ?? [];
    $byCurrency = [];
    foreach ($allAccounts as $a) {
        $curr = $a['currency'] ?? '?';
        $byCurrency[$curr] = ($byCurrency[$curr] ?? 0) + 1;
    }
    echo "  Total liquidity accounts: ".count($allAccounts)."\n";
    foreach ($byCurrency as $curr => $count) {
        echo "    $curr: $count\n";
    }
    $kwdAccounts = array_filter($allAccounts, fn($a) => strtoupper($a['currency'] ?? '') === 'KWD');
    echo "  KWD liquidity accounts available: ".count($kwdAccounts)."\n";
    if (count($kwdAccounts) === 0) {
        echo "  ⚠️  NO KWD ACCOUNTS IN DROPDOWN — this is the root cause!\n";
    } else {
        foreach ($kwdAccounts as $a) {
            echo "    [{$a['id']}] {$a['name']} ({$a['currency']}, bal={$a['balance']})\n";
        }
    }
} else {
    echo "  ❌ Failed to list accounts: {$accountsResp->status()}\n";
}
echo "\n";

// Step 4: Try to create the booking exactly as the user described
echo "─── STEP B: POST booking (currency=KWD, payment=EGP cash, carrier=Jazeera) ───\n";
$payload = [
    'customer_id'          => $fixtures['customer_id'],
    'flight_carrier_id'    => $fixtures['jazeera_id'],
    'flight_system_id'     => $fixtures['kwd_system_id'],
    'currency'             => 'KWD',
    'foreign_currency'     => 'KWD',
    'exchange_rate'        => 160.0,
    'purchase_price_foreign' => 50.0,    // 50 KWD
    'selling_price'        => 8500.0,   // 8500 KWD (user wrote "8500مصري = 1,360,000 جم")
    'pnr'                  => 'REPRO-KWD-1',
    'trip_type'            => 'one_way',
    'from_airport'         => 'CAI',
    'to_airport'           => 'KWI',
    'departure_date'       => '2026-08-01',
    'departure_time'       => '10:00',
    'passengers'           => [
        ['first_name' => 'Test', 'last_name' => 'User', 'passenger_type' => 'adult'],
    ],
    'payment' => [
        'amount'         => 8500.0,         // full payment in KWD
        'account_id'     => $fixtures['egp_cash_id'],   // <-- EGP account (this is what the user keeps picking)
        'payment_method' => 'cash',
    ],
];

$bookingResp = Http::withHeaders($auth)->post(url('/api/v1/flight/bookings'), $payload);
echo "  HTTP status: {$bookingResp->status()}\n";
echo "  Response body:\n";
echo "    ".str_replace("\n", "\n    ", $bookingResp->body())."\n\n";

if (! $bookingResp->successful()) {
    echo "  ❌ Booking creation failed.\n";
    echo "  This is the user's reported issue.\n\n";

    // ── Step C: now try with the matching KWD account
    echo "─── STEP C: Same booking, but payment account = KWD cash (the matching one) ───\n";
    $payload['payment']['account_id'] = $fixtures['kwd_cash_id'];
    $bookingResp2 = Http::withHeaders($auth)->post(url('/api/v1/flight/bookings'), $payload);
    echo "  HTTP status: {$bookingResp2->status()}\n";
    echo "  Response body:\n";
    echo "    ".str_replace("\n", "\n    ", $bookingResp2->body())."\n";
    if ($bookingResp2->successful()) {
        echo "\n  ✅ Booking succeeds when payment account matches the booking currency.\n";
    }
} else {
    echo "  ✅ Booking succeeded on first try — issue is fixed.\n";
    $body = $bookingResp->json('data') ?? [];
    if (!empty($body)) {
        echo "    booking.id              = ".($body['id'] ?? '?')."\n";
        echo "    booking.currency         = ".($body['currency'] ?? '?')."\n";
        echo "    booking.original_currency= ".($body['original_currency'] ?? '?')."\n";
        echo "    booking.original_amount  = ".($body['original_amount'] ?? '?')."\n";
    }
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  Reproduction complete.\n";
echo "═══════════════════════════════════════════════════════════════\n";
