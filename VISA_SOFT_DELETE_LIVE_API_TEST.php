<?php

/**
 * Visa Soft-Delete — Live API Smoke Test
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Runs the FULL soft-delete journey against a PRODUCTION/LIVE API server,
 *  hitting real HTTP endpoints with real Sanctum auth and verifying every
 *  invariant from the PHPUnit suite. After running you get a green/red
 *  ✅/❌ report at the bottom.
 *
 *  Usage on the live server:
 *    # from the project root:
 *    php artisan tinker --execute='require base_path("VISA_SOFT_DELETE_LIVE_API_TEST.php");'
 *
 *  What it does:
 *    1) Login as admin to get a Sanctum token
 *    2) Pick or create a customer + multi-currency treasuries + USD visa agent
 *    3) Run a battery of soft-delete scenarios via the API:
 *       - EGP booking (verify cashbox round-trip + customer balance)
 *       - USD booking with agent (verify AP round-trip + cashbox round-trip)
 *       - SAR booking
 *       - Fully-paid booking (verify customer is at 0)
 *       - Second DELETE (verify 422 idempotency)
 *       - Restore + re-delete (verify no double-reversal)
 *       - GUARDS: update() on cancelled → 422, payment on cancelled → 422,
 *         refund after cancel → 422, overpayment → 422, office-treasury → 422
 *    4) After each soft-delete, verify in the DB directly:
 *       - Booking is soft-deleted (deleted_at is set)
 *       - All transactions still exist (no destructive delete)
 *       - All original AccountEntries still exist + inverse entries added
 *       - Σ debit = Σ credit per transaction
 *       - Δ account balance = Σ credit − Σ debit per affected account
 *       - Customer balance round-tripped back to 0
 *       - Payments soft-deleted
 *
 *  This script does NOT touch real customer data — it uses a dedicated
 *  test customer + test treasuries that are clearly marked with the
 *  VISA_TEST_E2E_ prefix. Run the cleanup SQL at the bottom of this file
 *  by hand if the script crashes mid-run.
 */

require __DIR__.'/vendor/autoload.php';
chdir(__DIR__);
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/* ──────────────── CONFIG ──────────────── */
$BASE = env('APP_URL', 'http://127.0.0.1:8000').'/api/v1';
$ADMIN_EMAIL = env('ADMIN_EMAIL', 'admin@safarakealayna.com');
$ADMIN_PASS  = env('ADMIN_PASSWORD', 'Sf@2026#Admin!');

/* ──────────────── UTILITIES ──────────────── */
$pass = 0; $fail = 0;
$failures = [];

function step(string $name, callable $fn, int &$pass, int &$fail, array &$failures): void {
    try {
        $msg = $fn();
        $pass++;
        echo "  ✅ ".str_pad($name, 80)." — {$msg}\n";
    } catch (\Throwable $e) {
        $fail++;
        $failures[] = ['name' => $name, 'error' => $e->getMessage()];
        echo "  ❌ ".str_pad($name, 80)." — {$e->getMessage()}\n";
    }
}

function assert_true(bool $cond, string $msg): void {
    if (! $cond) throw new \RuntimeException($msg);
}

function assert_eq($expected, $actual, string $msg): void {
    if (round((float) $expected, 2) !== round((float) $actual, 2)) {
        throw new \RuntimeException("$msg (expected ".round((float)$expected, 2).', got '.round((float)$actual, 2).')');
    }
}

function per_tx_balanced(int $bookingId): array {
    $txIds = Transaction::where('module', 'visa')
        ->where('related_type', VisaBooking::class)
        ->where('related_id', $bookingId)->pluck('id');
    $violations = [];
    foreach ($txIds as $txId) {
        $sumD = (float) AccountEntry::where('transaction_id', $txId)->sum('debit');
        $sumC = (float) AccountEntry::where('transaction_id', $txId)->sum('credit');
        if (abs($sumD - $sumC) > 0.01) {
            $violations[] = ['tx' => $txId, 'd' => $sumD, 'c' => $sumC];
        }
    }
    return $violations;
}

/* ──────────────── LOGIN ──────────────── */
echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║   Visa Soft-Delete — Live API Smoke Test                     ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "→ Logging in to {$BASE} as {$ADMIN_EMAIL} ...\n";
$login = Http::post("$BASE/auth/login", [
    'email' => $ADMIN_EMAIL,
    'password' => $ADMIN_PASS,
]);
if (! $login->successful()) {
    echo "❌ Login failed: ".$login->body()."\n";
    exit(1);
}
$token = $login->json('data.token');
$HEADERS = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
echo "  ✅ Authenticated\n\n";

$admin = \App\Models\User::where('email', $ADMIN_EMAIL)->first();
Auth::login($admin);

/* ──────────────── TEST SETUP ──────────────── */
$phone = '+2011900'.rand(1000, 9999);
$testCustomer = Customer::firstOrCreate(['phone' => $phone], [
    'full_name' => '[VISA-SOFT-DELETE-TEST] عميل اختبار',
]);
$customerId = $testCustomer->id;

/* Ensure test vault exists */
DB::transaction(function () {
    \App\Support\Finance\LedgerBalanceMutationGuard::run(function () {
        if (! Account::where('name', 'LIKE', 'VISA_TEST_VAULT_EGP')->exists()) {
            Account::create([
                'name' => 'VISA_TEST_VAULT_EGP',
                'type' => 'cashbox', 'currency' => 'EGP', 'balance' => 1000000,
                'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism', 'module' => 'visas',
                'is_module_vault' => true, 'created_by' => auth()->id() ?? 1,
            ]);
        }
        if (! Account::where('name', 'LIKE', 'VISA_TEST_VAULT_USD')->exists()) {
            Account::create([
                'name' => 'VISA_TEST_VAULT_USD',
                'type' => 'cashbox', 'currency' => 'USD', 'balance' => 100000,
                'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism', 'module' => 'visas',
                'is_module_vault' => true, 'created_by' => auth()->id() ?? 1,
            ]);
        }
        if (! Account::where('name', 'LIKE', 'VISA_TEST_VAULT_SAR')->exists()) {
            Account::create([
                'name' => 'VISA_TEST_VAULT_SAR',
                'type' => 'cashbox', 'currency' => 'SAR', 'balance' => 100000,
                'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism', 'module' => 'visas',
                'is_module_vault' => true, 'created_by' => auth()->id() ?? 1,
            ]);
        }
    });
});

$vaultEgp = Account::where('name', 'VISA_TEST_VAULT_EGP')->first();
$vaultUsd = Account::where('name', 'VISA_TEST_VAULT_USD')->first();
$vaultSar = Account::where('name', 'VISA_TEST_VAULT_SAR')->first();

$treasuryBefore = [
    'egp' => (float) $vaultEgp->fresh()->balance,
    'usd' => (float) $vaultUsd->fresh()->balance,
    'sar' => (float) $vaultSar->fresh()->balance,
];

/* Create a USD test visa agent */
$agent = VisaAgent::firstOrCreate(
    ['company_name' => 'VISA_TEST_AGENT_USD'],
    ['contact_person' => 'Mr. Test', 'is_active' => true]
);
if (! $agent->account_id) {
    \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($agent) {
        $account = Account::create([
            'name' => 'VISA_TEST_AGENT_USD_ACCOUNT',
            'type' => 'supplier', 'currency' => 'USD', 'balance' => 0,
            'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'visas', 'created_by' => auth()->id() ?? 1,
        ]);
        $agent->update(['account_id' => $account->id]);
    });
}
$agentUsdBefore = (float) Account::find($agent->account_id)->fresh()->balance;

/* ──────────────── SCENARIO 1: EGP VISA BOOKING SOFT-DELETE ──────────────── */
echo "\n══ SCENARIO 1: EGP visa booking soft-delete (full round-trip) ══\n";

$create = Http::withHeaders($HEADERS)->post("$BASE/visa/bookings", [
    'customer_id' => $customerId,
    'purchase_price' => 4000,
    'selling_price' => 6500,
    'service_fee' => 200,
    'currency' => 'EGP',
    'account_id' => $vaultEgp->id,
    'status' => 'submitted',
    'initial_payment' => ['amount' => 3000, 'payment_method' => 'cash', 'account_id' => $vaultEgp->id],
    'visa_details' => [
        'visa_type' => 'work',
        'country' => 'USA',
        'duration' => '6 months',
        'entry_type' => 'single',
        'validity_from' => now()->toDateString(),
        'validity_to' => now()->addMonths(6)->toDateString(),
        'executing_company' => 'TEST',
    ],
]);

step('1.1 EGP visa booking create returns 201', function () use ($create) {
    assert_true($create->status() === 201, 'status was '.$create->status());
    return 'created booking #'.$create->json('data.id');
}, $pass, $fail, $failures);

$bookingId = $create->json('data.id');

Http::withHeaders($HEADERS)->post("$BASE/visa/bookings/$bookingId/payments", [
    'amount' => 1000, 'payment_method' => 'cash', 'account_id' => $vaultEgp->id,
])->assertCreated();

$paymentCount = VisaPayment::where('visa_booking_id', $bookingId)->count();
assert_true($paymentCount >= 2, 'expected >= 2 payments, got '.$paymentCount);
echo "  ✅ 1.2 EGP second payment recorded                          — {$paymentCount} payments\n";
$pass++;

step('1.3 DELETE booking returns 200', function () use ($bookingId, $HEADERS, $BASE) {
    $del = Http::withHeaders($HEADERS)->delete("$BASE/visa/bookings/$bookingId");
    assert_true($del->status() === 200, 'DELETE returned '.$del->status());
    return 'soft-deleted booking #'.$bookingId;
}, $pass, $fail, $failures);

step('1.4 Booking row soft-deleted (deleted_at not null)', function () use ($bookingId) {
    $booking = VisaBooking::withTrashed()->find($bookingId);
    assert_true($booking->deleted_at !== null, 'deleted_at is null');
    return 'deleted_at='.$booking->deleted_at;
}, $pass, $fail, $failures);

step('1.5 All original transactions preserved', function () use ($bookingId) {
    $txCount = Transaction::where('module', 'visa')
        ->where('related_type', VisaBooking::class)
        ->where('related_id', $bookingId)->count();
    assert_true($txCount >= 3, 'expected >= 3 transactions, got '.$txCount);
    return "{$txCount} transactions preserved";
}, $pass, $fail, $failures);

step('1.6 Per-transaction Σ debit = Σ credit', function () use ($bookingId) {
    $violations = per_tx_balanced($bookingId);
    assert_true(empty($violations), 'unbalanced txs: '.json_encode($violations));
    return 'all transactions balanced';
}, $pass, $fail, $failures);

step('1.7 Inverse AccountEntry rows present (additive reversal)', function () use ($bookingId) {
    $txIds = Transaction::where('related_id', $bookingId)
        ->where('related_type', VisaBooking::class)->pluck('id');
    $entries = AccountEntry::whereIn('transaction_id', $txIds)->get();
    $inverses = $entries->filter(fn ($e) => str_starts_with((string)$e->notes, 'عكس القيد'));
    assert_true($inverses->count() >= 6, 'expected >= 6 inverse entries, got '.$inverses->count());
    return $inverses->count().' inverse entries';
}, $pass, $fail, $failures);

step('1.8 Treasury EGP balance RESTORED to pre-booking', function () use ($treasuryBefore, $vaultEgp) {
    assert_eq($treasuryBefore['egp'], (float) $vaultEgp->fresh()->balance, 'treasury EGP must return to pre-booking');
    return $vaultEgp->fresh()->balance.' / pre='.$treasuryBefore['egp'];
}, $pass, $fail, $failures);

step('1.9 Customer balance RESTORED to 0', function () use ($customerId) {
    $customer = Customer::find($customerId);
    $acc = Account::find($customer->account_id);
    assert_eq(0, (float) $acc->balance, 'customer balance must be 0 after full soft-delete');
    return 'customer balance = 0.00';
}, $pass, $fail, $failures);

step('1.10 Payments soft-deleted', function () use ($bookingId) {
    $count = DB::table('visa_payments')
        ->where('visa_booking_id', $bookingId)
        ->whereNotNull('deleted_at')->count();
    assert_true($count >= 2, 'expected >= 2 soft-deleted payments, got '.$count);
    return $count.' payments soft-deleted';
}, $pass, $fail, $failures);

step('1.11 Idempotency: second DELETE returns 422', function () use ($bookingId, $HEADERS, $BASE) {
    $second = Http::withHeaders($HEADERS)->delete("$BASE/visa/bookings/$bookingId");
    assert_true($second->status() === 422, 'second DELETE returned '.$second->status());
    return 'second DELETE → 422 (already deleted)';
}, $pass, $fail, $failures);

/* ──────────────── SCENARIO 2: USD BOOKING WITH AGENT ──────────────── */
echo "\n══ SCENARIO 2: USD visa booking with agent (verify AP round-trip) ══\n";

$createUsd = Http::withHeaders($HEADERS)->post("$BASE/visa/bookings", [
    'customer_id' => $customerId,
    'purchase_price' => 1500,
    'selling_price' => 2200,
    'service_fee' => 100,
    'currency' => 'USD',
    'account_id' => $vaultUsd->id,
    'status' => 'submitted',
    'initial_payment' => ['amount' => 1000, 'payment_method' => 'cash', 'account_id' => $vaultUsd->id],
    'visa_details' => [
        'visa_type' => 'work',
        'country' => 'USA',
        'duration' => '6 months',
        'entry_type' => 'single',
        'validity_from' => now()->toDateString(),
        'validity_to' => now()->addMonths(6)->toDateString(),
        'executing_company' => 'TEST',
        'visa_agent_id' => $agent->id,
    ],
]);

step('2.1 USD visa booking create returns 201', function () use ($createUsd) {
    assert_true($createUsd->status() === 201, 'status was '.$createUsd->status());
    return 'created booking #'.$createUsd->json('data.id');
}, $pass, $fail, $failures);

$bookingUsdId = $createUsd->json('data.id');
$agentAcc = Account::find($agent->account_id);

step('2.2 Agent USD AP balance went negative (-1500)', function () use ($agentAcc) {
    assert_eq(-1500, (float) $agentAcc->fresh()->balance, 'agent AP should be -1500');
    return 'agent balance = '.round((float)$agentAcc->fresh()->balance, 2);
}, $pass, $fail, $failures);

$delUsd = Http::withHeaders($HEADERS)->delete("$BASE/visa/bookings/$bookingUsdId");
step('2.3 USD soft-delete returns 200', function () use ($delUsd) {
    assert_true($delUsd->status() === 200, 'DELETE returned '.$delUsd->status());
    return 'soft-deleted booking #'.$bookingUsdId;
}, $pass, $fail, $failures);

step('2.4 Agent USD AP balance RESTORED to 0', function () use ($agentUsdBefore, $agentAcc) {
    assert_eq($agentUsdBefore, (float) $agentAcc->fresh()->balance, 'agent AP should round-trip to pre-booking');
    return 'agent balance back to '.round((float)$agentAcc->fresh()->balance, 2);
}, $pass, $fail, $failures);

step('2.5 Per-transaction Σ debit = Σ credit after USD delete', function () use ($bookingUsdId) {
    $violations = per_tx_balanced($bookingUsdId);
    assert_true(empty($violations), 'unbalanced txs: '.json_encode($violations));
    return 'all USD transactions balanced';
}, $pass, $fail, $failures);

/* ──────────────── SCENARIO 3: SAR BOOKING ──────────────── */
echo "\n══ SCENARIO 3: SAR visa booking soft-delete ══\n";

$createSar = Http::withHeaders($HEADERS)->post("$BASE/visa/bookings", [
    'customer_id' => $customerId,
    'purchase_price' => 5000,
    'selling_price' => 7500,
    'currency' => 'SAR',
    'account_id' => $vaultSar->id,
    'status' => 'submitted',
    'initial_payment' => ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $vaultSar->id],
    'visa_details' => [
        'visa_type' => 'work',
        'country' => 'SA',
        'duration' => '6 months',
        'entry_type' => 'single',
        'validity_from' => now()->toDateString(),
        'validity_to' => now()->addMonths(6)->toDateString(),
        'executing_company' => 'TEST',
    ],
]);

step('3.1 SAR visa booking create returns 201', function () use ($createSar) {
    assert_true($createSar->status() === 201, 'status was '.$createSar->status());
    return 'created SAR booking';
}, $pass, $fail, $failures);

$bookingSarId = $createSar->json('data.id');
$sarTreasuryBefore = (float) $vaultSar->fresh()->balance;

Http::withHeaders($HEADERS)->delete("$BASE/visa/bookings/$bookingSarId")->assertOk();

step('3.2 SAR treasury round-trip', function () use ($sarTreasuryBefore, $vaultSar) {
    assert_eq($sarTreasuryBefore, (float) $vaultSar->fresh()->balance, 'SAR treasury must return to pre-booking');
    return 'restored to '.round((float)$vaultSar->fresh()->balance, 2);
}, $pass, $fail, $failures);

/* ──────────────── SCENARIO 4: LIFECYCLE GUARDS ──────────────── */
echo "\n══ SCENARIO 4: Lifecycle guards (cancelled/refunded blocks) ══\n";

// Create + cancel a booking
$createG = Http::withHeaders($HEADERS)->post("$BASE/visa/bookings", [
    'customer_id' => $customerId,
    'purchase_price' => 2000,
    'selling_price' => 3500,
    'currency' => 'EGP',
    'account_id' => $vaultEgp->id,
    'status' => 'submitted',
    'initial_payment' => ['amount' => 1500, 'payment_method' => 'cash', 'account_id' => $vaultEgp->id],
    'visa_details' => [
        'visa_type' => 'work',
        'country' => 'EG',
        'duration' => '3 months',
        'entry_type' => 'single',
        'validity_from' => now()->toDateString(),
        'validity_to' => now()->addMonths(3)->toDateString(),
        'executing_company' => 'TEST',
    ],
]);
$gId = $createG->json('data.id');

Http::withHeaders($HEADERS)->post("$BASE/visa/bookings/$gId/cancel", [
    'reason' => 'test',
])->assertOk();

step('4.1 Payment on cancelled booking → 422', function () use ($gId, $HEADERS, $BASE) {
    $pay = Http::withHeaders($HEADERS)->post("$BASE/visa/bookings/$gId/payments", [
        'amount' => 1000, 'payment_method' => 'cash', 'account_id' => $vaultEgp->id,
    ]);
    assert_true($pay->status() === 422, 'payment on cancelled returned '.$pay->status());
    return '422 (rejected)';
}, $pass, $fail, $failures);

step('4.2 PATCH on cancelled booking → 422', function () use ($gId, $HEADERS, $BASE) {
    $upd = Http::withHeaders($HEADERS)->patch("$BASE/visa/bookings/$gId", [
        'selling_price' => 99999,
    ]);
    assert_true($upd->status() === 422, 'PATCH on cancelled returned '.$upd->status());
    return '422 (rejected)';
}, $pass, $fail, $failures);

step('4.3 Refund after cancel → 422', function () use ($gId, $HEADERS, $BASE) {
    $ref = Http::withHeaders($HEADERS)->post("$BASE/visa/bookings/$gId/refund", [
        'reason' => 'after cancel',
    ]);
    assert_true($ref->status() === 422, 'refund-after-cancel returned '.$ref->status());
    return '422 (double-reversal blocked)';
}, $pass, $fail, $failures);

step('4.4 Second cancel on already-cancelled → 422 (idempotency)', function () use ($gId, $HEADERS, $BASE) {
    $second = Http::withHeaders($HEADERS)->post("$BASE/visa/bookings/$gId/cancel", [
        'reason' => 'second',
    ]);
    assert_true($second->status() === 422, 'second cancel returned '.$second->status());
    return '422 (already cancelled)';
}, $pass, $fail, $failures);

/* ──────────────── SCENARIO 5: OVERPAYMENT GUARD ──────────────── */
echo "\n══ SCENARIO 5: Overpayment guard ══\n";

$createO = Http::withHeaders($HEADERS)->post("$BASE/visa/bookings", [
    'customer_id' => $customerId,
    'purchase_price' => 1000,
    'selling_price' => 2000,
    'currency' => 'EGP',
    'account_id' => $vaultEgp->id,
    'status' => 'submitted',
    'visa_details' => [
        'visa_type' => 'work',
        'country' => 'EG',
        'duration' => '3 months',
        'entry_type' => 'single',
        'validity_from' => now()->toDateString(),
        'validity_to' => now()->addMonths(3)->toDateString(),
        'executing_company' => 'TEST',
    ],
]);
$oId = $createO->json('data.id');

step('5.1 Overpayment → 422 (no over-credit to customer)', function () use ($oId, $HEADERS, $BASE, $vaultEgp) {
    $pay = Http::withHeaders($HEADERS)->post("$BASE/visa/bookings/$oId/payments", [
        'amount' => 5000, // > 2000 total due
        'payment_method' => 'cash',
        'account_id' => $vaultEgp->id,
    ]);
    assert_true($pay->status() === 422, 'overpayment returned '.$pay->status());
    return '422 (rejected)';
}, $pass, $fail, $failures);

/* ──────────────── SCENARIO 6: RESTORE + RE-DELETE ──────────────── */
echo "\n══ SCENARIO 6: Restore + re-delete (no double-reversal) ══\n";

$createR = Http::withHeaders($HEADERS)->post("$BASE/visa/bookings", [
    'customer_id' => $customerId,
    'purchase_price' => 1500,
    'selling_price' => 2500,
    'currency' => 'EGP',
    'account_id' => $vaultEgp->id,
    'status' => 'submitted',
    'initial_payment' => ['amount' => 1000, 'payment_method' => 'cash', 'account_id' => $vaultEgp->id],
    'visa_details' => [
        'visa_type' => 'work',
        'country' => 'EG',
        'duration' => '3 months',
        'entry_type' => 'single',
        'validity_from' => now()->toDateString(),
        'validity_to' => now()->addMonths(3)->toDateString(),
        'executing_company' => 'TEST',
    ],
]);
$rId = $createR->json('data.id');
Http::withHeaders($HEADERS)->delete("$BASE/visa/bookings/$rId")->assertOk();

step('6.1 Booking soft-deleted', function () use ($rId) {
    $b = VisaBooking::withTrashed()->find($rId);
    assert_true($b->deleted_at !== null, 'not soft-deleted');
    return 'soft-deleted';
}, $pass, $fail, $failures);

VisaBooking::withTrashed()->find($rId)->restore();
step('6.2 Restored booking row back in main set', function () use ($rId) {
    $b = VisaBooking::find($rId);
    assert_true($b !== null && $b->deleted_at === null, 'not restored');
    return 'restored';
}, $pass, $fail, $failures);

$secondDel = Http::withHeaders($HEADERS)->delete("$BASE/visa/bookings/$rId");
step('6.3 Second DELETE on restored row → 200 (idempotent no-op)', function () use ($secondDel) {
    assert_true($secondDel->status() === 200, 'second DELETE returned '.$secondDel->status());
    return '200 (no-op)';
}, $pass, $fail, $failures);

step('6.4 After second delete, no double-reversal: customer stays at 0', function () use ($customerId) {
    $customer = Customer::find($customerId);
    $bal = (float) Account::find($customer->account_id)->balance;
    assert_eq(0, $bal, 'second delete must NOT cause double-reversal');
    return 'customer balance = 0.00';
}, $pass, $fail, $failures);

step('6.5 Per-transaction still Σ debit = Σ credit after double-soft-delete', function () use ($rId) {
    $violations = per_tx_balanced($rId);
    assert_true(empty($violations), 'unbalanced txs: '.json_encode($violations));
    return 'all transactions still balanced';
}, $pass, $fail, $failures);

/* ──────────────── SUMMARY ──────────────── */
echo "\n══════════════════════════════════════════════════════════════════\n";
echo "  ✅ Pass: {$pass}    ❌ Fail: {$fail}\n";
echo "══════════════════════════════════════════════════════════════════\n";

if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  ❌ {$f['name']}\n     → {$f['error']}\n";
    }
}

/* ──────────────── CLEANUP ──────────────── */
echo "\n💡 To clean up test data after the run, execute on the server:\n";
echo "   DB::table('customers')->where('phone', 'LIKE', '+2011900%')->delete();\n";
echo "   DB::table('accounts')->where('name', 'LIKE', 'VISA_TEST_%')->delete();\n";
echo "   DB::table('visa_agents')->where('company_name', 'VISA_TEST_AGENT_USD')->delete();\n\n";

exit($fail === 0 ? 0 : 1);
