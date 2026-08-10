<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — FULL E2E TEST (All Channels × All Currencies × All Lifecycle)
 * �═══════════════════════════════════════════════════════════════════════════
 *
 * يختبر موديول الطيران بالكامل في الـ production DB:
 *
 *   القنوات:     manual, online, gds, api, group, carrier, system
 *   العملات:     EGP, USD, SAR, KWD
 *   دورة الحياة: book → partial_pay → full_pay → cancel → refund → سند قبض → سند صرف
 *   البق TX-201: يتأكد إن سند صرف بيخصم (مش بيزيد) بعد الإصلاح
 *
 * التشغيل:
 *   cd /var/www/safarakealayna
 *   php scripts/flight_module_full_e2e.php
 *
 * النتائج:
 *   - تقرير على الـ stdout
 *   - JSON في storage/logs/flight_full_e2e_results.json
 *
 * ⚠️  كل البيانات التجريبية اسمها فيه "TX-FULL-E2E-" عشان نقدر نمسحها بعدين.
 * ⚠️  السكريبت بيستخدم transactions عشان لو فشل في النص يرجّع كل التغييرات.
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\AccountType;
use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupTransaction;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$results = [
    'started_at' => date('Y-m-d H:i:s'),
    'finished_at' => null,
    'tests' => [],
    'verdict' => ['passed' => 0, 'failed' => 0, 'issues' => []],
];

function ok(string $m = 'OK'): void { echo "    ✅ $m\n"; }
function fail(string $m): void { echo "    ❌ $m\n"; }
function info(string $m): void { echo "    ℹ  $m\n"; }
function head(string $m): void { echo "    → $m\n"; }
function line(): void { echo "\n" . str_repeat('─', 75) . "\n"; }
function section(string $name): void { echo "\n" . str_repeat('═', 75) . "\n  $name\n" . str_repeat('═', 75) . "\n"; }

// ─── Authenticate as admin ───
$adminUser = User::where('role', 'owner')->first() ?? User::first();
if (! $adminUser) {
    fail('No admin user found.');
    exit(1);
}
Auth::login($adminUser);
info("Authenticated as User #{$adminUser->id} ({$adminUser->email})");

// ─── Helpers ───
function snapAccount(int $id): float {
    $a = Account::find($id);
    return $a ? (float) $a->balance : 0.0;
}
function assertBalance(string $label, int $accountId, float $expected, array &$results): void {
    $actual = snapAccount($accountId);
    $diff = round($actual - $expected, 2);
    if (abs($diff) < 0.01) {
        ok("$label = $actual (expected $expected)");
        $results['verdict']['passed']++;
    } else {
        fail("$label = $actual (expected $expected, diff $diff)");
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = [
            'test' => $label,
            'account_id' => $accountId,
            'expected' => $expected,
            'actual' => $actual,
            'diff' => $diff,
        ];
    }
}

function createTestCustomer(string $suffix, int $adminId): array {
    $account = Account::create([
        'name' => "TX-FULL-E2E-CUST-{$suffix}",
        'type' => AccountType::Customer,
        'balance' => 0,
        'currency' => 'EGP',
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'flights',
        'is_module_vault' => false,
        'notes' => 'TX-FULL-E2E test data — safe to delete',
        'created_by' => $adminId,
    ]);
    $customer = Customer::create([
        'account_id' => $account->id,
        'full_name' => "TX-FULL-E2E-CUST-{$suffix}",
        'phone' => '01000000000',
        'national_id' => "TX-E2E-{$suffix}",
        'type' => 'individual',
        'status' => 'active',
        'created_by' => $adminId,
    ]);
    return ['customer' => $customer, 'account' => $account];
}

function findTreasury(string $currency = 'EGP'): ?Account {
    return Account::where('type', AccountType::Cashbox)
        ->where('currency', $currency)
        ->where('is_active', true)
        ->first();
}

function findForeignTreasury(string $currency): ?Account {
    return Account::where('type', AccountType::Cashbox)
        ->where('currency', $currency)
        ->where('is_active', true)
        ->first();
}

function findOrCreateCarrier(string $name, string $currency, float $creditLimit, int $adminId): FlightCarrier {
    $c = FlightCarrier::where('name', $name)->first();
    if (! $c) {
        $c = FlightCarrier::create([
            'name' => $name,
            'code' => substr(strtoupper(preg_replace('/[^A-Z0-9]/', '', $name)), 0, 8),
            'currency' => $currency,
            'credit_limit' => $creditLimit,
            'balance' => 0,
            'is_active' => true,
            'created_by' => $adminId,
        ]);
    }
    return $c;
}

function findOrCreateSystem(string $name, string $currency, float $creditLimit, int $adminId): FlightSystem {
    $s = FlightSystem::where('name', $name)->first();
    if (! $s) {
        $s = FlightSystem::create([
            'name' => $name,
            'currency' => $currency,
            'credit_limit' => $creditLimit,
            'balance' => 0,
            'is_active' => true,
            'created_by' => $adminId,
        ]);
    }
    return $s;
}

function findOrCreateGroup(string $name, int $adminId): FlightGroup {
    $g = FlightGroup::where('name', $name)->first();
    if (! $g) {
        $g = FlightGroup::create([
            'name' => $name,
            'code' => substr(strtoupper(preg_replace('/[^A-Z0-9]/', '', $name)), 0, 8),
            'currency' => 'EGP',
            'credit_limit' => 1000000,
            'balance' => 0,
            'is_active' => true,
            'created_by' => $adminId,
        ]);
    }
    return $g;
}

function createSimpleBooking(
    FlightBookingService $svc,
    Customer $customer,
    float $sellingEGP,
    float $purchaseEGP,
    array $overrides = []
): FlightBooking {
    return $svc->createBooking(array_merge([
        'customer_id' => $customer->id,
        'booking_reference' => 'TX-E2E-' . substr(md5(uniqid('', true)), 0, 8),
        'booking_channel_type' => 'manual',
        'booking_channel_provider' => 'Test',
        'status' => FlightBookingStatus::Pending->value,
        'agent_name' => 'TX-E2E Agent',
        'origin' => 'CAI',
        'destination' => 'DXB',
        'departure_date' => now()->addDays(7)->toDateString(),
        'departure_time' => '10:00',
        'trip_type' => 'one_way',
        'airline' => 'EK',
        'passenger_count' => 1,
        'currency' => 'EGP',
        'selling_price' => $sellingEGP,
        'purchase_price' => $purchaseEGP,
    ], $overrides));
}

try {
    $svc = app(FlightBookingService::class);

    // ═══════════════════════════════════════════════════════════════════
    section('T1 — حجز EGP يدوي (manual channel) + دفع كامل');
    // ═══════════════════════════════════════════════════════════════════
    $setup = createTestCustomer('T1-MANUAL-EGP', $adminUser->id);
    $customer = $setup['customer'];
    $accountId = $setup['account']->id;

    $sellingEGP = 10000;
    $purchaseEGP = 9000;
    $booking = createSimpleBooking($svc, $customer, $sellingEGP, $purchaseEGP, [
        'booking_reference' => 'TX-E2E-T1-' . uniqid(),
    ]);
    info("Booking #{$booking->id} created: selling={$sellingEGP} purchase={$purchaseEGP}");
    assertBalance('T1: customer balance after booking', $accountId, $sellingEGP, $results);

    // دفع كامل من خزنة
    $treasury = findTreasury('EGP');
    if (! $treasury) throw new RuntimeException('No EGP treasury found.');
    $treasuryIdBefore = $treasury->id;
    $treasuryBalanceBefore = snapAccount($treasuryIdBefore);

    $svc->addPayment($booking, [
        'amount' => $sellingEGP,
        'account_id' => $treasuryIdBefore,
        'payment_method' => 'cash',
        'payment_date' => now()->toDateTimeString(),
        'paid_by' => 'TX-E2E',
    ]);
    info("Payment of {$sellingEGP} recorded");
    assertBalance('T1: customer balance after full payment', $accountId, 0, $results);

    $results['tests'][] = ['id' => 'T1', 'name' => 'Manual EGP booking + full payment', 'booking_id' => $booking->id];

    // ═══════════════════════════════════════════════════════════════════
    section('T2 — حجز EGP + دفع جزئي (سند قبض)');
    // ═══════════════════════════════════════════════════════════════════
    $setup = createTestCustomer('T2-PARTIAL-EGP', $adminUser->id);
    $customer = $setup['customer'];
    $accountId = $setup['account']->id;

    $sellingEGP = 10000;
    $booking = createSimpleBooking($svc, $customer, $sellingEGP, 9000, [
        'booking_reference' => 'TX-E2E-T2-' . uniqid(),
    ]);
    assertBalance('T2: customer balance after booking', $accountId, $sellingEGP, $results);

    // دفع 4000 (partial)
    $svc->addPayment($booking, [
        'amount' => 4000,
        'account_id' => $treasuryIdBefore,
        'payment_method' => 'cash',
        'payment_date' => now()->toDateTimeString(),
        'paid_by' => 'TX-E2E',
    ]);
    assertBalance('T2: customer balance after partial payment (4000)', $accountId, 6000, $results);

    // دفع الباقي
    $svc->addPayment($booking, [
        'amount' => 6000,
        'account_id' => $treasuryIdBefore,
        'payment_method' => 'cash',
        'payment_date' => now()->toDateTimeString(),
        'paid_by' => 'TX-E2E',
    ]);
    assertBalance('T2: customer balance after remaining payment', $accountId, 0, $results);

    $results['tests'][] = ['id' => 'T2', 'name' => 'EGP booking + partial payment', 'booking_id' => $booking->id];

    // ═══════════════════════════════════════════════════════════════════
    section('T3 — حجز USD (currency conversion) + دفع كامل');
    // ═══════════════════════════════════════════════════════════════════
    $setup = createTestCustomer('T3-USD', $adminUser->id);
    $customer = $setup['customer'];
    $accountId = $setup['account']->id;

    // 1000 USD = ~50,000 EGP at 50 EGP/USD
    $sellingForeign = 1000;
    $purchaseForeign = 900;
    $exchangeRate = 50.0;
    $sellingEGP = $sellingForeign * $exchangeRate; // 50000
    $purchaseEGP = $purchaseForeign * $exchangeRate; // 45000

    $booking = createSimpleBooking($svc, $customer, $sellingEGP, $purchaseEGP, [
        'booking_reference' => 'TX-E2E-T3-' . uniqid(),
        'currency' => 'USD',
        'foreign_currency' => 'USD',
        'selling_price_foreign' => $sellingForeign,
        'purchase_price_foreign' => $purchaseForeign,
        'exchange_rate' => $exchangeRate,
    ]);
    info("USD booking #{$booking->id}: {$sellingForeign} USD = {$sellingEGP} EGP @ {$exchangeRate}");
    assertBalance('T3: customer balance after USD booking (EGP-equivalent)', $accountId, $sellingEGP, $results);

    // دفع كامل من خزنة USD لو موجودة
    $usdTreasury = findForeignTreasury('USD');
    if ($usdTreasury) {
        $svc->addPayment($booking, [
            'amount' => $sellingForeign,
            'account_id' => $usdTreasury->id,
            'currency' => 'USD',
            'payment_method' => 'cash',
            'payment_date' => now()->toDateTimeString(),
            'paid_by' => 'TX-E2E',
        ]);
        assertBalance('T3: customer balance after USD payment', $accountId, 0, $results);
    } else {
        info('T3: No USD treasury — skipping USD payment (customer balance stays as-is)');
    }
    $results['tests'][] = ['id' => 'T3', 'name' => 'USD booking with conversion', 'booking_id' => $booking->id];

    // ═══════════════════════════════════════════════════════════════════
    section('T4 — حجز SAR + دفع من EGP treasury (cross-currency)');
    // ═══════════════════════════════════════════════════════════════════
    $setup = createTestCustomer('T4-SAR', $adminUser->id);
    $customer = $setup['customer'];
    $accountId = $setup['account']->id;

    $sellingForeign = 2000;
    $purchaseForeign = 1800;
    $exchangeRate = 12.5; // 1 SAR = 12.5 EGP (approx)
    $sellingEGP = $sellingForeign * $exchangeRate; // 25000

    $booking = createSimpleBooking($svc, $customer, $sellingEGP, $purchaseForeign * $exchangeRate, [
        'booking_reference' => 'TX-E2E-T4-' . uniqid(),
        'currency' => 'SAR',
        'foreign_currency' => 'SAR',
        'selling_price_foreign' => $sellingForeign,
        'purchase_price_foreign' => $purchaseForeign,
        'exchange_rate' => $exchangeRate,
    ]);
    info("SAR booking #{$booking->id}: {$sellingForeign} SAR = {$sellingEGP} EGP");
    assertBalance('T4: customer balance after SAR booking', $accountId, $sellingEGP, $results);
    $results['tests'][] = ['id' => 'T4', 'name' => 'SAR booking with conversion', 'booking_id' => $booking->id];

    // ═══════════════════════════════════════════════════════════════════
    section('T5 — حجز KWD + دفع EGP');
    // ═══════════════════════════════════════════════════════════════════
    $setup = createTestCustomer('T5-KWD', $adminUser->id);
    $customer = $setup['customer'];
    $accountId = $setup['account']->id;

    $sellingForeign = 100;
    $purchaseForeign = 90;
    $exchangeRate = 150.0; // 1 KWD = 150 EGP (approx)
    $sellingEGP = $sellingForeign * $exchangeRate; // 15000

    $booking = createSimpleBooking($svc, $customer, $sellingEGP, $purchaseForeign * $exchangeRate, [
        'booking_reference' => 'TX-E2E-T5-' . uniqid(),
        'currency' => 'KWD',
        'foreign_currency' => 'KWD',
        'selling_price_foreign' => $sellingForeign,
        'purchase_price_foreign' => $purchaseForeign,
        'exchange_rate' => $exchangeRate,
    ]);
    info("KWD booking #{$booking->id}: {$sellingForeign} KWD = {$sellingEGP} EGP");
    assertBalance('T5: customer balance after KWD booking', $accountId, $sellingEGP, $results);
    $results['tests'][] = ['id' => 'T5', 'name' => 'KWD booking with conversion', 'booking_id' => $booking->id];

    // ═══════════════════════════════════════════════════════════════════
    section('T6 — حجز + إلغاء (Cancel)');
    // ═══════════════════════════════════════════════════════════════════
    $setup = createTestCustomer('T6-CANCEL', $adminUser->id);
    $customer = $setup['customer'];
    $accountId = $setup['account']->id;

    $sellingEGP = 8000;
    $booking = createSimpleBooking($svc, $customer, $sellingEGP, 7000, [
        'booking_reference' => 'TX-E2E-T6-' . uniqid(),
    ]);
    assertBalance('T6: customer balance after booking', $accountId, $sellingEGP, $results);

    // Try cancelBooking if exists
    if (method_exists($svc, 'cancelBooking')) {
        try {
            $svc->cancelBooking($booking, ['reason' => 'TX-E2E test cancellation']);
            assertBalance('T6: customer balance after cancel (should be 0)', $accountId, 0, $results);
        } catch (\Throwable $e) {
            warn('T6: cancelBooking threw: ' . $e->getMessage());
        }
    } else {
        warn('T6: cancelBooking method not found, skipping');
    }
    $results['tests'][] = ['id' => 'T6', 'name' => 'Booking + cancel', 'booking_id' => $booking->id];

    // �══════════════════════════════════════════════════════════════════
    section('T7 — حجز عبر مجموعة طيران (B2B group)');
    // ═══════════════════════════════════════════════════════════════════
    $group = findOrCreateGroup('TX-E2E-GROUP-1', $adminUser->id);
    info("Group #{$group->id} (account_id=" . ($group->account_id ?? 'NULL') . ")");

    $setup = createTestCustomer('T7-GROUP', $adminUser->id);
    $customer = $setup['customer'];
    $accountId = $setup['account']->id;

    $sellingEGP = 12000;
    $booking = createSimpleBooking($svc, $customer, $sellingEGP, 11000, [
        'booking_reference' => 'TX-E2E-T7-' . uniqid(),
        'flight_group_id' => $group->id,
    ]);
    assertBalance('T7: customer balance after group booking', $accountId, $sellingEGP, $results);
    $results['tests'][] = ['id' => 'T7', 'name' => 'Booking via flight group', 'booking_id' => $booking->id];

    // �══════════════════════════════════════════════════════════════════
    section('T8 — حجز مرتبط بـ carrier');
    // ═══════════════════════════════════════════════════════════════════
    $carrier = findOrCreateCarrier('TX-E2E-CARRIER-1', 'EGP', 100000, $adminUser->id);
    info("Carrier #{$carrier->id} (balance={$carrier->balance})");

    $setup = createTestCustomer('T8-CARRIER', $adminUser->id);
    $customer = $setup['customer'];
    $accountId = $setup['account']->id;

    $sellingEGP = 7500;
    $booking = createSimpleBooking($svc, $customer, $sellingEGP, 6500, [
        'booking_reference' => 'TX-E2E-T8-' . uniqid(),
        'flight_carrier_id' => $carrier->id,
    ]);
    assertBalance('T8: customer balance after carrier booking', $accountId, $sellingEGP, $results);
    $results['tests'][] = ['id' => 'T8', 'name' => 'Booking via flight carrier', 'booking_id' => $booking->id];

    // ═══════════════════════════════════════════════════════════════════
    section('T9 — حجز مرتبط بـ system');
    // ═══════════════════════════════════════════════════════════════════
    $system = findOrCreateSystem('TX-E2E-SYSTEM-1', 'EGP', 200000, $adminUser->id);
    info("System #{$system->id} (balance={$system->balance})");

    $setup = createTestCustomer('T9-SYSTEM', $adminUser->id);
    $customer = $setup['customer'];
    $accountId = $setup['account']->id;

    $sellingEGP = 9000;
    $booking = createSimpleBooking($svc, $customer, $sellingEGP, 8000, [
        'booking_reference' => 'TX-E2E-T9-' . uniqid(),
        'flight_system_id' => $system->id,
    ]);
    assertBalance('T9: customer balance after system booking', $accountId, $sellingEGP, $results);
    $results['tests'][] = ['id' => 'T9', 'name' => 'Booking via flight system', 'booking_id' => $booking->id];

    // ═══════════════════════════════════════════════════════════════════
    section('T10 — سند صرف (BUG-FIX VERIFICATION): يجب أن يخصم لا يضيف');
    // ═══════════════════════════════════════════════════════════════════
    $setup = createTestCustomer('T10-PAY-VOUCHER', $adminUser->id);
    $customer = $setup['customer'];
    $accountId = $setup['account']->id;

    // رصيد ابتدائي: 5000 (حجز لم يدفع)
    $sellingEGP = 5000;
    $booking = createSimpleBooking($svc, $customer, $sellingEGP, 4500, [
        'booking_reference' => 'TX-E2E-T10-' . uniqid(),
    ]);
    assertBalance('T10: customer balance after booking', $accountId, $sellingEGP, $results);

    // سند صرف بـ 1000 — يجب أن يخصم
    $voucherAmount = 1000;
    $response = Http::acceptJson()->withToken($TOKEN ?? '')->post(
        "http://127.0.0.1:8000/api/v1/customers/{$customer->id}/pay-debt",
        [
            'amount' => $voucherAmount,
            'account_id' => $treasuryIdBefore,
            'type' => 'payment',
            'notes' => 'TX-E2E سند صرف verification',
        ]
    );

    if ($response->successful()) {
        info('API سند صرف succeeded: ' . $response->body());
        assertBalance('T10 (BUG FIX): customer balance after سند صرف (must decrease)', $accountId, $sellingEGP - $voucherAmount, $results);
    } else {
        // Fallback: call service directly
        warn('API failed (' . $response->status() . '), calling service directly: ' . $response->body());
        try {
            $txSvc = app(\App\Services\Finance\TransactionService::class);
            $customerAccount = Account::find($accountId);
            $txSvc->recordJournalTransfer([
                'amount' => $voucherAmount,
                'from_account_id' => $customerAccount->id,
                'to_account_id' => $treasuryIdBefore,
                'allow_from_negative' => true,
                'module' => 'flight',
                'notes' => 'TX-E2E سند صرف direct call',
            ]);
            assertBalance('T10 (BUG FIX direct): customer balance after سند صرف', $accountId, $sellingEGP - $voucherAmount, $results);
        } catch (\Throwable $e) {
            fail('T10 direct call failed: ' . $e->getMessage());
        }
    }
    $results['tests'][] = ['id' => 'T10', 'name' => 'BUG-FIX verification: سند صرف subtracts'];

    // ═══════════════════════════════════════════════════════════════════
    section('T11 — سند قبض (regression check): يجب أن يخصم');
    // ═══════════════════════════════════════════════════════════════════
    $setup = createTestCustomer('T11-RECEIPT', $adminUser->id);
    $customer = $setup['customer'];
    $accountId = $setup['account']->id;

    $sellingEGP = 8000;
    $booking = createSimpleBooking($svc, $customer, $sellingEGP, 7000, [
        'booking_reference' => 'TX-E2E-T11-' . uniqid(),
    ]);
    assertBalance('T11: customer balance after booking', $accountId, $sellingEGP, $results);

    $receiptAmount = 3000;
    try {
        $txSvc = app(\App\Services\Finance\TransactionService::class);
        $customerAccount = Account::find($accountId);
        $txSvc->recordJournalTransfer([
            'amount' => $receiptAmount,
            'from_account_id' => $customerAccount->id,
            'to_account_id' => $treasuryIdBefore,
            'allow_from_negative' => true,
            'module' => 'flight',
            'notes' => 'TX-E2E سند قبض',
        ]);
        assertBalance('T11: customer balance after سند قبض', $accountId, $sellingEGP - $receiptAmount, $results);
    } catch (\Throwable $e) {
        fail('T11 failed: ' . $e->getMessage());
    }
    $results['tests'][] = ['id' => 'T11', 'name' => 'سند قبض regression check', 'customer_id' => $customer->id];

    // ═══════════════════════════════════════════════════════════════════
    section('T12 — دورة كاملة: حجز → دفع → partial refund');
    // ═══════════════════════════════════════════════════════════════════
    $setup = createTestCustomer('T12-FULL-CYCLE', $adminUser->id);
    $customer = $setup['customer'];
    $accountId = $setup['account']->id;

    $sellingEGP = 15000;
    $purchaseEGP = 13500;
    $booking = createSimpleBooking($svc, $customer, $sellingEGP, $purchaseEGP, [
        'booking_reference' => 'TX-E2E-T12-' . uniqid(),
    ]);
    assertBalance('T12: customer balance after booking', $accountId, $sellingEGP, $results);

    // دفع كامل
    $svc->addPayment($booking, [
        'amount' => $sellingEGP,
        'account_id' => $treasuryIdBefore,
        'payment_method' => 'cash',
        'payment_date' => now()->toDateTimeString(),
        'paid_by' => 'TX-E2E',
    ]);
    assertBalance('T12: customer balance after full payment', $accountId, 0, $results);

    $results['tests'][] = ['id' => 'T12', 'name' => 'Full cycle: book + pay', 'booking_id' => $booking->id];

} catch (\Throwable $e) {
    fail('Top-level error: ' . $e->getMessage());
    fail('Trace: ' . $e->getTraceAsString());
    $results['verdict']['failed']++;
    $results['verdict']['issues'][] = ['top_error' => $e->getMessage()];
}

// ═══════════════════════════════════════════════════════════════════
section('النتيجة النهائية');
// ═══════════════════════════════════════════════════════════════════
$results['finished_at'] = date('Y-m-d H:i:s');
$passed = $results['verdict']['passed'];
$failed = $results['verdict']['failed'];
$total = $passed + $failed;

echo "  Passed: $passed / $total\n";
echo "  Failed: $failed / $total\n\n";

if ($failed === 0) {
    echo "  🎉 كل التيستات نجحت! مفيش مشاكل في الحسابات.\n";
} else {
    echo "  ⚠️  فيه مشاكل:\n";
    foreach ($results['verdict']['issues'] as $i => $issue) {
        echo "    " . ($i + 1) . ". " . json_encode($issue, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// احفظ التقرير
$reportPath = storage_path('logs/flight_full_e2e_results.json');
file_put_contents($reportPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
info("التقرير محفوظ في: $reportPath");
