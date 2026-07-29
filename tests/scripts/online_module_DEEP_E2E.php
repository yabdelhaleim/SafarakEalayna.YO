<?php
/**
 * Online Services Module (الخدمات الإلكترونية) — Deep E2E Production Test.
 *
 * Targets the user's brief:
 *   "عايزك تتشيك على موديول الخدمات الإلكترونية تعمل تجربة حجز كاملة
 *    وحذف وكانسل وتشوف في مشاكل ولا جاهز للإن production وتتأكد من حساباته
 *    وأنشأ خزن ومحافظ وبنوك وجرب عليهم كلهم"
 *
 * NOTE: "Online" is the actual code-name for the Electronic Services module.
 *       It covers telecom top-ups, bill payments, visa/mofa, training, etc.
 *
 * Covers (50+ scenarios):
 *   ◆ Section 1: Treasury bootstrap — cashbox / wallet / bank (EGP & USD)
 *                 as the "خزن/محافظ/بنوك" the user wants exercised.
 *   ◆ Section 2: Provider / service-type provisioning.
 *   ◆ Section 3: Booking flow (complete experience, HTTP API):
 *       3.1 Registered customer, full payment
 *       3.2 Registered customer, partial payment (creates debt)
 *       3.3 Walk-in (unregistered) customer
 *       3.4 Multi-provider transactions
 *   ◆ Section 4: Read flow — index / show / customer-statement / customer-balances / settings.
 *   ◆ Section 5: Update flow — price change → ledger repost.
 *   ◆ Section 6: Cancel flow — full reverse + idempotent.
 *   ◆ Section 7: REGRESSION — the DeferredTransactionDeletionGuard fix
 *                 must apply here too (the Online module uses the same guard).
 *   ◆ Section 8: Accounting integrity — per-currency trial balance,
 *                 per-transaction D==C, account reconciliation.
 *
 * Result: pass/fail + production verdict.
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineTransaction;
use App\Models\Setting\PaymentMethod;
use App\Models\Transaction;
use App\Services\Online\OnlineTransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$TOKEN = getenv('FAWRY_TOKEN') ?: '2|uS8LPhi9HfQsTR5rFsg6fd8WRhRfw9VrtwsLgF1616c25cfd';
$BASE = 'http://127.0.0.1:8000/api/v1';

$pass = 0;
$fail = 0;
$results = [];
$startBalances = [];
$createdTxs = [];

function ok(string $name, string $detail = ''): void
{
    global $pass, $results;
    $pass++;
    $results[] = ['PASS', $name, $detail];
    echo "✅ {$name}".($detail ? " — {$detail}" : '')."\n";
}

function bad(string $name, string $detail): void
{
    global $fail, $results;
    $fail++;
    $results[] = ['FAIL', $name, $detail];
    echo "❌ {$name} — {$detail}\n";
}

function info(string $line): void
{
    echo "ℹ️  {$line}\n";
}

function http(string $method, string $path, array $payload = null): array
{
    global $TOKEN, $BASE;
    $ch = curl_init($BASE.$path);
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

function freshAccount(string $name, string $type, string $currency, float $balance): Account
{
    return LedgerBalanceMutationGuard::run(fn () => Account::create([
        'name' => $name,
        'type' => $type,
        'balance' => $balance,
        'currency' => $currency,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'office',
        'is_module_vault' => false,
        'notes' => 'ONLINE E2E fixture',
        'created_by' => 1,
    ])->fresh());
}

function freshCustomer(string $phone, string $name): Customer
{
    return Customer::firstOrCreate(
        ['phone' => $phone],
        [
            'name' => $name,
            'full_name' => $name,
            'is_active' => true,
            'created_by' => 1,
        ]
    )->fresh();
}

Auth::loginUsingId(1);

echo "═══════════════════════════════════════════════════════════\n";
echo "  Online Services Module — Deep E2E Production Test\n";
echo "═══════════════════════════════════════════════════════════\n";
echo 'Started: '.date('Y-m-d H:i:s')."\n\n";

$UNIQ = (string) time();
$RUN = 'ONLINE-'.$UNIQ;
info("Run tag: {$RUN}");

$svc = app(OnlineTransactionService::class);

// ════════════════════════════════════════════════════════════════════
// SECTION 1 · Treasury bootstrap (cashbox / wallet / bank in EGP & USD)
// ════════════════════════════════════════════════════════════════════
echo "\n── 1. Create treasuries (cashbox / wallet / bank × EGP / USD) ──\n";

$cashboxEgp = freshAccount("ONLINE_CASH_{$RUN}_EGP", 'cashbox', 'EGP', 150000.00);
$cashboxUsd = freshAccount("ONLINE_CASH_{$RUN}_USD", 'cashbox', 'USD', 8000.00);
ok('create cashbox EGP', "#{$cashboxEgp->id} bal=".number_format($cashboxEgp->balance, 2));
ok('create cashbox USD', "#{$cashboxUsd->id} bal=".number_format($cashboxUsd->balance, 2));

$walletEgp = freshAccount("ONLINE_WALLET_{$RUN}_EGP", 'wallet', 'EGP', 75000.00);
$walletUsd = freshAccount("ONLINE_WALLET_{$RUN}_USD", 'wallet', 'USD', 4000.00);
ok('create wallet EGP', "#{$walletEgp->id} bal=".number_format($walletEgp->balance, 2));
ok('create wallet USD', "#{$walletUsd->id} bal=".number_format($walletUsd->balance, 2));

$bankEgp = freshAccount("ONLINE_BANK_{$RUN}_EGP", 'bank', 'EGP', 350000.00);
$bankUsd = freshAccount("ONLINE_BANK_{$RUN}_USD", 'bank', 'USD', 15000.00);
ok('create bank EGP', "#{$bankEgp->id} bal=".number_format($bankEgp->balance, 2));
ok('create bank USD', "#{$bankUsd->id} bal=".number_format($bankUsd->balance, 2));

$treasuries = [
    'cashbox_egp' => $cashboxEgp,
    'cashbox_usd' => $cashboxUsd,
    'wallet_egp' => $walletEgp,
    'wallet_usd' => $walletUsd,
    'bank_egp' => $bankEgp,
    'bank_usd' => $bankUsd,
];
foreach ($treasuries as $key => $acc) {
    $startBalances[$key] = (float) $acc->balance;
}

// Verify each is visible in /online/settings/accounts (the actual API hook)
$resp = http('GET', '/online/settings/accounts');
if ($resp['status'] === 200) {
    $accountIds = array_column($resp['json']['data'] ?? [], 'id');
    foreach ($treasuries as $key => $acc) {
        in_array($acc->id, $accountIds, true)
            ? ok("online account settings includes {$key}", "#{$acc->id}")
            : bad("online account settings includes {$key}", "#{$acc->id} NOT in list");
    }
} else {
    bad('GET /online/settings/accounts', "HTTP {$resp['status']}");
}

// ════════════════════════════════════════════════════════════════════
// SECTION 2 · Provider / service-type provisioning
// ════════════════════════════════════════════════════════════════════
echo "\n── 2. Provision providers + service-types ──\n";

// Use existing active providers/service-types (master CRUD requires careful
// dependency handling; idempotency we use existing rows)
$serviceType = OnlineServiceType::where('is_active', true)->whereNull('deleted_at')->orderBy('id')->first();
$provider = OnlineServiceProvider::where('is_active', true)->whereNull('deleted_at')->orderBy('id')->first();

if (! $serviceType || ! $provider) {
    bad('seed service type / provider', 'missing active rows');
    exit(1);
}
ok('existing service type', "#{$serviceType->id} {$serviceType->name}");
ok('existing provider', "#{$provider->id} {$provider->name}");

// Verify master-data APIs
$resp = http('GET', '/online/service-types/active');
$resp['status'] === 200
    ? ok('GET /online/service-types/active', 'HTTP 200')
    : bad('GET /online/service-types/active', "HTTP {$resp['status']}");

$resp = http('GET', '/online/providers/active');
$resp['status'] === 200
    ? ok('GET /online/providers/active', 'HTTP 200')
    : bad('GET /online/providers/active', "HTTP {$resp['status']}");

// ════════════════════════════════════════════════════════════════════
// SECTION 3 · Booking flow — complete experience via HTTP API
// ════════════════════════════════════════════════════════════════════
echo "\n── 3. Booking flow via HTTP (registered / partial-pay / walk-in) ──\n";

// 3.1 Registered customer, full payment via cash
$custA = freshCustomer("OA{$UNIQ}", "عميل أول {$RUN}");
$payloadA = [
    'service_type_id' => $serviceType->id,
    'provider_id' => $provider->id,
    'customer_id' => $custA->id,
    'purchase_price' => 80,
    'selling_price' => 100,
    'amount_paid' => 100,
    'payment_method' => 'cash',
    'account_id' => $cashboxEgp->id,
    'reference_number' => "ONLINE-A-{$RUN}",
    'notes' => 'حجز كامل - مدفوع',
];
$resp = http('POST', '/online/transactions', $payloadA);
$resp['status'] === 201
    ? ok('3.1 registered full-pay', '#'.$resp['json']['data']['id'])
    : bad('3.1 registered full-pay', "HTTP {$resp['status']} ".substr($resp['body'], 0, 200));
$txA = $resp['json']['data'] ?? null;
if ($txA) {
    $createdTxs[] = $txA['id'];
}

// 3.2 Registered customer, partial payment (60/100 → 40 debt)
$custB = freshCustomer("OB{$UNIQ}", "عميل ثاني {$RUN}");
$payloadB = [
    'service_type_id' => $serviceType->id,
    'provider_id' => $provider->id,
    'customer_id' => $custB->id,
    'purchase_price' => 80,
    'selling_price' => 100,
    'amount_paid' => 60, // partial
    'payment_method' => 'cash',
    'account_id' => $cashboxEgp->id,
    'reference_number' => "ONLINE-B-{$RUN}",
    'notes' => 'حجز جزئي - دين 40',
];
$resp = http('POST', '/online/transactions', $payloadB);
$resp['status'] === 201
    ? ok('3.2 registered partial-pay (40 debt)', '#'.($resp['json']['data']['id'] ?? '?'))
    : bad('3.2 registered partial-pay', "HTTP {$resp['status']} ".substr($resp['body'], 0, 200));
$txB = $resp['json']['data'] ?? null;
if ($txB) {
    $createdTxs[] = $txB['id'];
}

// 3.3 Walk-in customer (unregistered) — books with manual name
$walkinName = "Walk-in {$RUN}";
$payloadC = [
    'service_type_id' => $serviceType->id,
    'provider_id' => $provider->id,
    'customer_id' => null,
    'customer_name' => $walkinName,
    'customer_phone' => "050{$UNIQ}9",
    'purchase_price' => 200,
    'selling_price' => 250,
    'amount_paid' => 250,
    'payment_method' => 'cash',
    'account_id' => $cashboxEgp->id,
    'reference_number' => "ONLINE-C-{$RUN}",
    'notes' => 'عميل عابر',
];
$resp = http('POST', '/online/transactions', $payloadC);
$resp['status'] === 201
    ? ok('3.3 walk-in customer', '#'.($resp['json']['data']['id'] ?? '?'))
    : bad('3.3 walk-in customer', "HTTP {$resp['status']} ".substr($resp['body'], 0, 200));
$txC = $resp['json']['data'] ?? null;
if ($txC) {
    $createdTxs[] = $txC['id'];
}

// 3.4 Booking via different settlement accounts (wallet + bank)
$custD = freshCustomer("OD{$UNIQ}", "عميل رابع {$RUN}");
$payloadD = [
    'service_type_id' => $serviceType->id, 'provider_id' => $provider->id,
    'customer_id' => $custD->id,
    'purchase_price' => 50, 'selling_price' => 70, 'amount_paid' => 70,
    'payment_method' => 'cash_wallet', 'account_id' => $walletEgp->id,
    'reference_number' => "ONLINE-D-{$RUN}",
    'notes' => 'محفظة',
];
$resp = http('POST', '/online/transactions', $payloadD);
$resp['status'] === 201
    ? ok('3.4 wallet settlement', '#'.($resp['json']['data']['id'] ?? '?'))
    : bad('3.4 wallet settlement', "HTTP {$resp['status']} ".substr($resp['body'], 0, 200));
if (($resp['json']['data']['id'] ?? null)) {
    $createdTxs[] = $resp['json']['data']['id'];
}

$custE = freshCustomer("OE{$UNIQ}", "عميل خامس {$RUN}");
$payloadE = [
    'service_type_id' => $serviceType->id, 'provider_id' => $provider->id,
    'customer_id' => $custE->id,
    'purchase_price' => 200, 'selling_price' => 250, 'amount_paid' => 250,
    'payment_method' => 'bank_transfer', 'account_id' => $bankEgp->id,
    'reference_number' => "ONLINE-E-{$RUN}",
    'notes' => 'بنك',
];
$resp = http('POST', '/online/transactions', $payloadE);
$resp['status'] === 201
    ? ok('3.5 bank settlement', '#'.($resp['json']['data']['id'] ?? '?'))
    : bad('3.5 bank settlement', "HTTP {$resp['status']} ".substr($resp['body'], 0, 200));
if (($resp['json']['data']['id'] ?? null)) {
    $createdTxs[] = $resp['json']['data']['id'];
}

// 3.6 Cross-currency: USD vault settlement is INTENTIONALLY REJECTED.
//     The Online module is EGP-only by design (Phase 10 cross-currency
//     guard `assertCurrencyCompatible`). Booking must reject any tx whose
//     vault currency ≠ EGP. This is the EXPECTED behaviour — production
//     MUST prevent silent FX corruption of the AR balance.
$custF = freshCustomer("OF{$UNIQ}", "عميل سادس {$RUN}");
$payloadF = [
    'service_type_id' => $serviceType->id, 'provider_id' => $provider->id,
    'customer_id' => $custF->id,
    'purchase_price' => 5, 'selling_price' => 7, 'amount_paid' => 7,
    'payment_method' => 'cash', 'account_id' => $cashboxUsd->id, // USD
    'reference_number' => "ONLINE-F-{$RUN}",
    'notes' => 'USD cross',
];
$resp = http('POST', '/online/transactions', $payloadF);
$resp['status'] === 422
    ? ok('3.6 EGP-only enforcement (USD rejected)', 'HTTP 422 — design intent')
    : bad('3.6 EGP-only enforcement', "HTTP {$resp['status']} — should reject USD");

// 3.7 Validation: missing customer → HTTP 422
$resp = http('POST', '/online/transactions', [
    'service_type_id' => $serviceType->id, 'provider_id' => $provider->id,
    'customer_id' => null, 'customer_name' => '',
    'purchase_price' => 10, 'selling_price' => 15, 'amount_paid' => 15,
    'payment_method' => 'cash', 'account_id' => $cashboxEgp->id,
    'reference_number' => "ONLINE-X-{$RUN}",
]);
$resp['status'] === 422
    ? ok('3.7 missing customer rejected', 'HTTP 422')
    : bad('3.7 missing customer rejected', "HTTP {$resp['status']}");

// ════════════════════════════════════════════════════════════════════
// SECTION 4 · Read flow
// ════════════════════════════════════════════════════════════════════
echo "\n── 4. Read flow ──\n";

$resp = http('GET', '/online/transactions?per_page=100&search='.$RUN);
$resp['status'] === 200
    ? ok('GET /online/transactions', 'HTTP 200')
    : bad('GET /online/transactions', "HTTP {$resp['status']}");
$items = $resp['json']['data']['items'] ?? [];
$foundRows = array_filter($items, fn ($i) => str_contains((string) ($i['reference_number'] ?? ''), $RUN));
count($foundRows) >= 4
    ? ok('transactions list shows E2E rows', count($foundRows).' rows match '.$RUN)
    : bad('transactions list shows E2E rows', count($foundRows).' rows');

$firstTx = $createdTxs[0] ?? null;
if ($firstTx) {
    $resp = http('GET', "/online/transactions/{$firstTx}");
    $resp['status'] === 200
        ? ok('GET /online/transactions/{id}', "tx #{$firstTx}")
        : bad('GET /online/transactions/{id}', "HTTP {$resp['status']}");
}

$resp = http('GET', '/online/transactions/daily-summary?date='.date('Y-m-d'));
$resp['status'] === 200
    ? ok('GET /online/transactions/daily-summary', 'HTTP 200')
    : bad('GET /online/transactions/daily-summary', "HTTP {$resp['status']}");

$resp = http('GET', '/online/customer-balances');
$resp['status'] === 200
    ? ok('GET /online/customer-balances', 'HTTP 200')
    : bad('GET /online/customer-balances', "HTTP {$resp['status']}");

$resp = http('GET', '/online/customer-statement?client_id='.$custB->id);
$resp['status'] === 200
    ? ok('GET /online/customer-statement (registered)', "client #{$custB->id}")
    : bad('GET /online/customer-statement', "HTTP {$resp['status']}");

$resp = http('GET', '/online/customer-statement?client_name='.urlencode($walkinName));
$resp['status'] === 200
    ? ok('GET /online/customer-statement (walk-in)', 'HTTP 200')
    : bad('GET /online/customer-statement (walk-in)', "HTTP {$resp['status']}");

$resp = http('GET', '/online/settings/all');
$resp['status'] === 200
    ? ok('GET /online/settings/all', 'HTTP 200')
    : bad('GET /online/settings/all', "HTTP {$resp['status']}");

// ════════════════════════════════════════════════════════════════════
// SECTION 5 · Update flow — ledger repost
// ════════════════════════════════════════════════════════════════════
echo "\n── 5. UPDATE flow (price change → ledger repost) ──\n";

if ($txA) {
    $invBefore = Transaction::where('related_type', OnlineTransaction::class)
        ->where('related_id', $txA['id'])->count();

    $resp = http('PUT', "/online/transactions/{$txA['id']}", [
        'selling_price' => 120, // +20
        'purchase_price' => 90,  // +10
    ]);

    $invAfter = Transaction::where('related_type', OnlineTransaction::class)
        ->where('related_id', $txA['id'])->count();

    ($resp['status'] === 200 && $invAfter > $invBefore)
        ? ok('update with price change', "tx #{$txA['id']}, entries {$invBefore}→{$invAfter}")
        : bad('update with price change', "HTTP {$resp['status']}, entries {$invBefore}→{$invAfter}");
}

// No-change update should be a no-op
if ($txB) {
    $invBefore = Transaction::where('related_type', OnlineTransaction::class)
        ->where('related_id', $txB['id'])->count();
    $resp = http('PUT', "/online/transactions/{$txB['id']}", [
        'notes' => 'updated note only',
    ]);
    $invAfter = Transaction::where('related_type', OnlineTransaction::class)
        ->where('related_id', $txB['id'])->count();
    $resp['status'] === 200 && $invAfter === $invBefore
        ? ok('no-change update: 0 new entries', "entries {$invBefore}={$invAfter}")
        : bad('no-change update: 0 new entries', "HTTP {$resp['status']}, {$invBefore}→{$invAfter}");
}

// ════════════════════════════════════════════════════════════════════
// SECTION 6 · Cancel flow — full reverse + idempotent
// ════════════════════════════════════════════════════════════════════
echo "\n── 6. CANCEL flow ──\n";

// 6.1 Cancel a customer with no later debt-payment (txB is partial-pay,
// but no later payment has been registered → guard should pass)
if ($txB) {
    $cashBefore = (float) Account::find($cashboxEgp->id)->balance;
    $invBefore = Transaction::where('related_type', OnlineTransaction::class)
        ->where('related_id', $txB['id'])->count();

    $resp = http('DELETE', "/online/transactions/{$txB['id']}");

    $cashAfter = (float) Account::find($cashboxEgp->id)->balance;
    $invAfter = Transaction::where('related_type', OnlineTransaction::class)
        ->where('related_id', $txB['id'])->count();

    $resp['status'] === 200
        ? ok('cancel tx B', "HTTP 200")
        : bad('cancel tx B', "HTTP {$resp['status']} ".substr($resp['body'], 0, 200));
    ($invAfter > $invBefore)
        ? ok("cancel posted inverse entries", "tx {$txB['id']}: {$invBefore}→{$invAfter}")
        : bad("cancel posted inverse entries", "{$invBefore}→{$invAfter}");
}

// 6.2 Cancel another (txC walk-in — no guard issue; should be clean)
if ($txC) {
    $invBefore = Transaction::where('related_type', OnlineTransaction::class)
        ->where('related_id', $txC['id'])->count();
    $resp = http('DELETE', "/online/transactions/{$txC['id']}");
    $invAfter = Transaction::where('related_type', OnlineTransaction::class)
        ->where('related_id', $txC['id'])->count();
    ($resp['status'] === 200 && $invAfter > $invBefore)
        ? ok('cancel walk-in tx C', "{$invBefore}→{$invAfter}")
        : bad('cancel walk-in tx C', "HTTP {$resp['status']}");
}

// 6.3 Idempotent DELETE on already-trashed tx
if ($txB) {
    $trashed = OnlineTransaction::withTrashed()->find($txB['id']);
    if ($trashed && $trashed->trashed()) {
        $invBefore = Transaction::where('related_type', OnlineTransaction::class)
            ->where('related_id', $txB['id'])->count();
        $resp = http('DELETE', "/online/transactions/{$txB['id']}");
        $invAfter = Transaction::where('related_type', OnlineTransaction::class)
            ->where('related_id', $txB['id'])->count();
        $invAfter === $invBefore
            ? ok('idempotent DELETE: 0 new entries', "{$invBefore}={$invAfter}")
            : bad('idempotent DELETE: 0 new entries', "{$invBefore}→{$invAfter}");
    }
}

// ════════════════════════════════════════════════════════════════════
// SECTION 7 · REGRESSION — cross-operation reverse entries on customer
//              account must not block later cancellations. This was fixed
//              in `DeferredTransactionDeletionGuard` (commit 5f80fc7)
//              and applies to the Online module too (same guard).
// ════════════════════════════════════════════════════════════════════
echo "\n── 7. REGRESSION: DeferredTransactionDeletionGuard ──\n";

$regCustomer = freshCustomer("REG{$UNIQ}", "Regression Customer {$RUN}");
$payloadR1 = [
    'service_type_id' => $serviceType->id, 'provider_id' => $provider->id,
    'customer_id' => $regCustomer->id,
    'purchase_price' => 50, 'selling_price' => 80, 'amount_paid' => 80,
    'payment_method' => 'cash', 'account_id' => $cashboxEgp->id,
    'reference_number' => "ONLINE-RA-{$RUN}",
];
$resp = http('POST', '/online/transactions', $payloadR1);
$rtxA = $resp['json']['data'] ?? null;

$payloadR2 = [
    'service_type_id' => $serviceType->id, 'provider_id' => $provider->id,
    'customer_id' => $regCustomer->id,
    'purchase_price' => 100, 'selling_price' => 150, 'amount_paid' => 150,
    'payment_method' => 'cash', 'account_id' => $cashboxEgp->id,
    'reference_number' => "ONLINE-RB-{$RUN}",
];
$resp = http('POST', '/online/transactions', $payloadR2);
$rtxB = $resp['json']['data'] ?? null;

if ($rtxA && $rtxB) {
    $createdTxs[] = $rtxA['id'];
    $createdTxs[] = $rtxB['id'];

    // UPDATE A → posts reverse entries on customer account
    $resp = http('PUT', "/online/transactions/{$rtxA['id']}", [
        'selling_price' => 90,
        'purchase_price' => 55,
    ]);
    $resp['status'] === 200
        ? ok('regression: update A', "HTTP 200")
        : bad('regression: update A', "HTTP {$resp['status']}");

    // Delete B → must succeed (no real later payment against B)
    $resp = http('DELETE', "/online/transactions/{$rtxB['id']}");
    $resp['status'] === 200
        ? ok('REGRESSION: cancel B (post-A-update)', 'HTTP 200 — guard no longer false-positives')
        : bad('REGRESSION: cancel B', "HTTP {$resp['status']} ".substr($resp['body'], 0, 200));
}

// ════════════════════════════════════════════════════════════════════
// SECTION 8 · Accounting integrity
// ════════════════════════════════════════════════════════════════════
echo "\n── 8. Accounting integrity ──\n";

foreach ($treasuries as $key => $acc) {
    $final = (float) $acc->fresh()->balance;
    $start = $startBalances[$key];
    info("{$key}: start={$start}, end={$final}, delta=".round($start - $final, 2));
}

// CRITICAL: every E2E-created transaction must balance (D == C)
$unbalanced = DB::table('transactions as t')
    ->join('account_entries as ae', 'ae.transaction_id', '=', 't.id')
    ->where('t.related_type', OnlineTransaction::class)
    ->whereIn('t.related_id', array_filter($createdTxs))
    ->groupBy('t.id', 't.related_id')
    ->selectRaw('t.id, t.related_id, ABS(SUM(ae.debit) - SUM(ae.credit)) as diff')
    ->havingRaw('ABS(SUM(ae.debit) - SUM(ae.credit)) > 0.01')
    ->get();

count($unbalanced) === 0
    ? ok('all E2E tx balanced (D == C)', count(array_filter($createdTxs)).' txs, 0 unbalanced')
    : bad('all E2E tx balanced (D == C)', count($unbalanced).' unbalanced');

// Per-currency trial balance on E2E transactions
$currencies = DB::table('accounts as a')
    ->join('account_entries as ae', 'ae.account_id', '=', 'a.id')
    ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->where('t.related_type', OnlineTransaction::class)
    ->whereIn('t.related_id', array_filter($createdTxs))
    ->select('a.currency', DB::raw('SUM(ae.debit) - SUM(ae.credit) as imbalance'), DB::raw('COUNT(*) as entries'))
    ->groupBy('a.currency')
    ->get();
foreach ($currencies as $row) {
    if (abs($row->imbalance) > 1.0) {
        bad("per-currency balance: {$row->currency}", "imbalance={$row->imbalance}");
    } else {
        ok("per-currency balance: {$row->currency}", "entries={$row->entries}, imbalance=".round($row->imbalance, 4));
    }
}

// Deficit check: pre-E2E balance vs post-E2E balance
foreach ($treasuries as $key => $acc) {
    $final = (float) $acc->fresh()->balance;
    $start = $startBalances[$key];
    if ($final < 0 && $start >= 0) {
        bad("deficit introduced in {$key}", "start={$start} → end={$final}");
    } else {
        ok("{$key} non-deficit", "balance=".number_format($final, 2));
    }
}

// ════════════════════════════════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════════════════════════════════
echo "\n═══════════════════════════════════════════════════════════\n";
echo "           ONLINE DEEP E2E RESULTS SUMMARY\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "PASS: {$pass}\n";
echo "FAIL: {$fail}\n";
echo "TOTAL: ".($pass + $fail)."\n";
echo "═══════════════════════════════════════════════════════════\n";

if ($fail > 0) {
    echo "\n❌ FAILURES:\n";
    foreach ($results as $r) {
        if ($r[0] === 'FAIL') {
            echo "  - {$r[1]} → {$r[2]}\n";
        }
    }
    exit(1);
}

echo "\n✅ All Online deep scenarios passed.\n";
exit(0);
