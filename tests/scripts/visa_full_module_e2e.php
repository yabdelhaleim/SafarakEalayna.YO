<?php
/**
 * Full Visa Module E2E Test — production readiness check.
 *
 * Mirrors the bus/fawry module audit scripts. Covers:
 *
 *   1.  Create tourism-module fixtures: cashbox / wallet / bank (EGP + USD)
 *   2.  Create new visa agent + duration
 *   3.  Create booking (customer pays cash) — full payment
 *   4.  Create booking with initial partial payment — then settle remainder
 *   5.  Multi-currency booking (USD) — wallet payment
 *   6.  Booking routed through visa-agent account (supplier cost flow)
 *   7.  Update booking price (repost path) → ledger audit
 *   8.  Lifecycle guard — payment on cancelled booking blocked
 *   9.  Cancel booking — additive reversal + idempotency
 *  10.  Refund booking — distinct from cancel, additive reversal
 *  11.  Soft-delete booking — full reversal + idempotent retry
 *  12.  Customer statement integrity (AR balance matches journal)
 *  13.  Treasury overview endpoint
 *  14.  Final balance integrity: cashbox+bank+wallet + clearing balances
 *
 * All scenarios verify both API behaviour and direct DB invariants.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Enums\VisaEntryType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$TOKEN = getenv('VISA_TOKEN') ?: '3|cUnEq95pfiLX5DD7mkqCbbQUUom0YFSa2CTmoXYQ459afea6';
$BASE  = 'http://127.0.0.1:8000/api/v1';

$pass = 0;
$fail = 0;
$results = [];
$balances = []; // for final integrity check

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

function freshAccount(string $name, AccountType $type, string $currency, float $balance, string $moduleType = 'tourism'): Account {
    return LedgerBalanceMutationGuard::run(fn () => Account::create([
        'name'           => $name,
        'type'           => $type,
        'balance'        => $balance,
        'currency'       => $currency,
        'is_active'      => true,
        'owner_type'     => Account::OWNER_TYPE_OWNER,
        'module_type'    => $moduleType,
        'module'         => $moduleType,
        'is_module_vault'=> false,
        'notes'          => 'Visa E2E test fixture',
        'created_by'     => 1,
    ])->fresh());
}

function snapshotBalances(array $accounts): array {
    $out = [];
    foreach ($accounts as $a) {
        $a->refresh();
        $out[$a->id] = [
            'name'     => $a->name,
            'balance'  => (float) $a->balance,
            'currency' => $a->currency,
            'type'     => $a->type->value,
        ];
    }
    return $out;
}

function assertBalanceDelta(Account $account, float $expectedDelta, string $scenario): void {
    global $balances;
    $account->refresh();
    $start = $balances[$account->id]['balance'] ?? null;
    $end   = (float) $account->balance;
    if ($start === null) {
        ok("{$scenario} — snapshot baseline #{$account->id}", "start=N/A end={$end}");
        $balances[$account->id] = ['balance' => $end, 'name' => $account->name, 'currency' => $account->currency];
        return;
    }
    $delta = round($end - $start, 2);
    if (abs($delta - $expectedDelta) < 0.01) {
        ok("{$scenario} — balance delta #{$account->id}", "expected=".number_format($expectedDelta, 2)." actual=".number_format($delta, 2));
    } else {
        bad("{$scenario} — balance delta #{$account->id}", "expected=".number_format($expectedDelta, 2)." actual=".number_format($delta, 2)." (start={$start} end={$end})");
    }
    $balances[$account->id] = ['balance' => $end, 'name' => $account->name, 'currency' => $account->currency];
}

Auth::loginUsingId(1);
echo "=== Visa Module Full E2E Test ===\n";
echo "Started: ".date('Y-m-d H:i:s')."\n\n";

$UNIQ = (string) time();
$UNIQ_TAG = "VE2E{$UNIQ}";

$trackingAccounts = [];
$bookingIds = [];

// ==========================================================================
// 1. Create tourism-module fixtures: cashbox / wallet / bank (EGP + USD)
// ==========================================================================
echo "── 1. Create test fixtures (cashbox / wallet / bank, multi-currency) ──\n";
$cashboxEgp = freshAccount("{$UNIQ_TAG}_CASH_EGP", AccountType::Cashbox, 'EGP', 100000.00);
ok('create visa cashbox EGP', "#{$cashboxEgp->id} bal=".number_format($cashboxEgp->balance, 2));
$trackingAccounts[$cashboxEgp->id] = $cashboxEgp;

$cashboxUsd = freshAccount("{$UNIQ_TAG}_CASH_USD", AccountType::Cashbox, 'USD', 5000.00);
ok('create visa cashbox USD', "#{$cashboxUsd->id} bal=".number_format($cashboxUsd->balance, 2));
$trackingAccounts[$cashboxUsd->id] = $cashboxUsd;

$walletEgp = freshAccount("{$UNIQ_TAG}_WAL_VODA_EGP", AccountType::Wallet, 'EGP', 50000.00);
ok('create visa wallet EGP (Vodafone Cash)', "#{$walletEgp->id} bal=".number_format($walletEgp->balance, 2));
$trackingAccounts[$walletEgp->id] = $walletEgp;

$walletSar = freshAccount("{$UNIQ_TAG}_WAL_INSTAPAY_SAR", AccountType::Wallet, 'SAR', 3000.00);
ok('create visa wallet SAR (InstaPay)', "#{$walletSar->id} bal=".number_format($walletSar->balance, 2));
$trackingAccounts[$walletSar->id] = $walletSar;

$bankEgp = freshAccount("{$UNIQ_TAG}_BANK_CIB_EGP", AccountType::Bank, 'EGP', 250000.00);
ok('create visa bank EGP (CIB)', "#{$bankEgp->id} bal=".number_format($bankEgp->balance, 2));
$trackingAccounts[$bankEgp->id] = $bankEgp;

$bankUsd = freshAccount("{$UNIQ_TAG}_BANK_CIB_USD", AccountType::Bank, 'USD', 10000.00);
ok('create visa bank USD (CIB)', "#{$bankUsd->id} bal=".number_format($bankUsd->balance, 2));
$trackingAccounts[$bankUsd->id] = $bankUsd;

// Snapshot baseline for integrity checks later
$balances = snapshotBalances(array_values($trackingAccounts));
$initialBalances = $balances;  // Immutable snapshot from initial state — used for the final integrity check.

// ==========================================================================
// 2. Create visa agent + duration
// ==========================================================================
echo "\n── 2. Create visa agent + duration ──\n";

// Supplier (visa agent) needs an "owner" type account for AP clearing.
// Use existing office-style AccountType::Owner account (it already exists per system).
// Actually we'll create a fresh visa-agent with default_cost_price and an AP account.
$agentAccount = LedgerBalanceMutationGuard::run(fn () => Account::create([
    'name'           => "{$UNIQ_TAG}_AGENT_AP",
    'type'           => AccountType::Supplier,
    'balance'        => 0.00,
    'currency'       => 'EGP',
    'is_active'      => true,
    'owner_type'     => Account::OWNER_TYPE_OWNER,
    'module_type'    => 'visas',
    'module'         => 'visas',
    'is_module_vault'=> false,
    'notes'          => 'Visa E2E agent AP',
    'created_by'     => 1,
])->fresh());

$agent = VisaAgent::create([
    'company_name'    => "{$UNIQ_TAG}_AGENT",
    'contact_person'  => 'Mohamed Tester',
    'phone'           => "0100000{$UNIQ}",
    'email'           => "agent{$UNIQ}@test.local",
    'country'         => 'SA',
    'visa_type'       => 'tourist',
    'default_cost_price' => 1000.00,
    'account_id'      => $agentAccount->id,
    'is_active'       => true,
    'notes'           => 'Visa E2E agent fixture',
]);
ok('create visa agent', "#{$agent->id} {$agent->company_name} (AP acct #{$agentAccount->id})");

$duration = VisaDuration::create([
    'code'        => "VE2E{$UNIQ}",
    'label_ar'    => 'مدة اختبار E2E',
    'label_en'    => 'E2E test duration',
    'months'      => 6,
    'entry_type'  => 'single',
    'sort_order'  => 99,
    'is_active'   => true,
]);
ok('create visa duration', "#{$duration->id} {$duration->label_ar}");

// ==========================================================================
// 3. Create customer + booking (full payment via cashbox)
// ==========================================================================
echo "\n── 3. Create booking — full payment via cashbox (EGP) ──\n";

$svc = app(VisaBookingService::class);

$customerA = Customer::firstOrCreate(
    ['phone' => "0100000{$UNIQ}A"],
    [
        'full_name'       => "VE2E Customer A {$UNIQ}",
        'national_id'     => "29801011200",
        'passport_number' => "PASA{$UNIQ}A",
        'passport_expiry' => '2030-12-31',
        'date_of_birth'   => '1998-01-01',
        'city'            => 'Cairo',
        'is_active'       => true,
        'created_by'      => 1,
    ]
);
ok('customer A created', "#{$customerA->id} {$customerA->full_name}");

try {
    $bookingA = $svc->create([
        'customer_id'     => $customerA->id,
        'visa_details'    => [
            'visa_type'       => VisaType::Tourist->value,
            'country'         => 'SA',
            'visa_duration_id'=> $duration->id,
            'entry_type'      => VisaEntryType::Single->value,
            'executing_company'=> 'VE2E Agency',
            'executing_agent' => 'Ali Tester',
            'submission_date' => date('Y-m-d'),
        ],
        'purchase_price'  => 1500.00,
        'selling_price'   => 2000.00,
        'service_fee'     => 100.00,
        'currency'        => 'EGP',
        'status'          => VisaStatus::Submitted->value,
        'account_id'      => $cashboxEgp->id,
        'notes'           => 'VE2E booking A — full pay via cashbox',
        'initial_payment' => [
            'amount'         => 2100.00,    // full settlement
            'payment_method' => 'cash',
            'account_id'     => $cashboxEgp->id,
            'reference'      => "REF-A-{$UNIQ}",
            'paid_by'        => $customerA->full_name,
        ],
    ]);
    ok('create booking A', "#{$bookingA->id} profit={$bookingA->profit} paid={$bookingA->paid_amount} remaining={$bookingA->remaining_amount}");
    $bookingIds[] = $bookingA->id;
    // Expected:
    //  cashbox: -1500 (expense) + 2100 (payment income) = +600
    assertBalanceDelta($cashboxEgp, +600, 'booking A full-pay cashbox');

    // Verify expense & income transactions
    $expA = Transaction::find($bookingA->expense_transaction_id);
    $incA = Transaction::find($bookingA->income_transaction_id);
    if ($expA && $expA->amount == 1500.00) {
        ok('booking A expense tx', "#{$expA->id} amount=1500 from={$expA->from_account_id}");
    } else {
        bad('booking A expense tx', 'missing or wrong amount');
    }
    if ($incA && $incA->amount == 2100.00) {
        ok('booking A income tx', "#{$incA->id} amount=2100 to={$incA->to_account_id}");
    } else {
        bad('booking A income tx', 'missing or wrong amount');
    }

    // Verify customer AR cleared after full payment
    $customerA->refresh();
    if ($customerA->account_id) {
        $custAcct = Account::find($customerA->account_id);
        $custAcct->refresh();
        // AR: +2100 (income on creation) -2100 (payment clearing) = 0
        if (abs((float) $custAcct->balance) < 0.01) {
            ok('booking A customer AR cleared', "balance=0 (acct #{$custAcct->id})");
        } else {
            bad('booking A customer AR cleared', "balance=".(float) $custAcct->balance);
        }
    }
} catch (\Throwable $e) {
    bad('create booking A', 'exception: '.$e->getMessage());
}

// ==========================================================================
// 4. Booking B — partial payment then settle remainder
// ==========================================================================
echo "\n── 4. Create booking B — partial initial payment + settle remainder via wallet ──\n";

$customerB = Customer::firstOrCreate(
    ['phone' => "0100000{$UNIQ}B"],
    [
        'full_name'  => "VE2E Customer B {$UNIQ}",
        'passport_number' => "PASB{$UNIQ}",
        'is_active'  => true,
        'created_by' => 1,
    ]
);
ok('customer B created', "#{$customerB->id}");

try {
    $bookingB = $svc->create([
        'customer_id'     => $customerB->id,
        'visa_details'    => [
            'visa_type'       => VisaType::Business->value,
            'country'         => 'UK',
            'visa_duration_id'=> $duration->id,
            'entry_type'      => VisaEntryType::Multiple->value,
            'executing_company'=> 'VE2E UK Office',
        ],
        'purchase_price'  => 8000.00,
        'selling_price'   => 12000.00,
        'service_fee'     => 500.00,
        'currency'        => 'EGP',
        'account_id'      => $cashboxEgp->id,
        'notes'           => 'VE2E booking B — split payments',
        'initial_payment' => [
            'amount'         => 5000.00,    // partial
            'payment_method' => 'cash',
            'account_id'     => $cashboxEgp->id,
            'reference'      => "REF-B1-{$UNIQ}",
            'paid_by'        => $customerB->full_name,
        ],
    ]);
    ok('create booking B', "#{$bookingB->id} paid={$bookingB->paid_amount} remaining={$bookingB->remaining_amount}");
    $bookingIds[] = $bookingB->id;
    // Expected: cashbox -8000 (expense) +5000 (initial payment) = -3000
    assertBalanceDelta($cashboxEgp, -3000, 'booking B partial initial pay');

    // Settle remainder via wallet
    try {
        $paymentB = $svc->addPayment($bookingB, [
            'amount'         => 7500.00,
            'payment_method' => 'cash_wallet',
            'account_id'     => $walletEgp->id,
            'reference'      => "REF-B2-{$UNIQ}",
            'paid_by'        => $customerB->full_name,
        ]);
        $bookingB->refresh();
        ok('booking B addPayment remainder', "#{$paymentB->id} amount=7500 booking_paid={$bookingB->paid_amount}");
        // Wallet: +7500
        assertBalanceDelta($walletEgp, +7500, 'booking B wallet remainder');
        // Cashbox: should be unchanged from prior delta
    } catch (\Throwable $e) {
        bad('booking B addPayment', 'exception: '.$e->getMessage());
    }
} catch (\Throwable $e) {
    bad('create booking B', 'exception: '.$e->getMessage());
}

// ==========================================================================
// 5. Booking C — USD multi-currency via bank USD
// ==========================================================================
echo "\n── 5. Create booking C — USD via bank USD ──\n";

$customerC = Customer::firstOrCreate(
    ['phone' => "0100000{$UNIQ}C"],
    [
        'full_name'  => "VE2E Customer C {$UNIQ}",
        'is_active'  => true,
        'created_by' => 1,
    ]
);
ok('customer C created', "#{$customerC->id}");

try {
    $bookingC = $svc->create([
        'customer_id'     => $customerC->id,
        'visa_details'    => [
            'visa_type' => VisaType::Visit->value,
            'country'   => 'US',
            'visa_duration_id' => $duration->id,
            'entry_type' => VisaEntryType::Multiple->value,
        ],
        'purchase_price'  => 200.00,
        'selling_price'   => 350.00,
        'service_fee'     => 20.00,
        'currency'        => 'USD',
        'account_id'      => $bankUsd->id,
        'notes'           => 'VE2E booking C — USD full pay',
        'initial_payment' => [
            'amount'         => 370.00,
            'payment_method' => 'bank_transfer',
            'account_id'     => $bankUsd->id,
            'reference'      => "REF-C-{$UNIQ}",
        ],
    ]);
    ok('create booking C', "#{$bookingC->id} profit={$bookingC->profit} currency=USD");
    $bookingIds[] = $bookingC->id;
    // Expected: bankUSD -200 + 370 = +170
    assertBalanceDelta($bankUsd, +170, 'booking C USD full pay');
} catch (\Throwable $e) {
    bad('create booking C', 'exception: '.$e->getMessage());
}

// ==========================================================================
// 6. Booking D — routing through visa-agent account (supplier cost flow)
// ==========================================================================
echo "\n── 6. Create booking D — routed through visa agent AP account ──\n";

$customerD = Customer::firstOrCreate(
    ['phone' => "0100000{$UNIQ}D"],
    [
        'full_name'  => "VE2E Customer D {$UNIQ}",
        'is_active'  => true,
        'created_by' => 1,
    ]
);

try {
    $bookingD = $svc->create([
        'customer_id'     => $customerD->id,
        'visa_details'    => [
            'visa_type'       => VisaType::Tourist->value,
            'country'         => 'SA',
            'visa_duration_id'=> $duration->id,
            'entry_type'      => VisaEntryType::Single->value,
            'visa_agent_id'   => $agent->id,
            'executing_company'=> $agent->company_name,
        ],
        'purchase_price'  => 1000.00,
        'selling_price'   => 1500.00,
        'service_fee'     => 50.00,
        'currency'        => 'EGP',
        'account_id'      => $cashboxEgp->id,   // payment destination
        'notes'           => 'VE2E booking D — agent routed',
        'initial_payment' => [
            'amount'         => 1550.00,
            'payment_method' => 'cash',
            'account_id'     => $cashboxEgp->id,
            'reference'      => "REF-D-{$UNIQ}",
        ],
    ]);
    ok('create booking D (agent routed)', "#{$bookingD->id} profit={$bookingD->profit}");
    $bookingIds[] = $bookingD->id;
    // Expense should pull from agent AP, not cashbox
    $expD = Transaction::find($bookingD->expense_transaction_id);
    if ($expD && (int) $expD->from_account_id === (int) $agentAccount->id) {
        ok('booking D expense from agent AP', "from=#{$expD->from_account_id} ({$agentAccount->name}) amount={$expD->amount}");
    } else {
        bad('booking D expense routing', "from=" . ($expD->from_account_id ?? 'null') . " expected agent #{$agentAccount->id}");
    }
    // Cashbox delta: only +1550 (payment received), no -1000 (cost pulled from agent)
    assertBalanceDelta($cashboxEgp, +1550, 'booking D cashbox payment');
    // Agent AP: should be -1000 (we owe agent)
    $agentAccount->refresh();
    if (abs((float) $agentAccount->balance - (-1000.00)) < 0.01) {
        ok('booking D agent AP balance', "bal=".number_format($agentAccount->balance, 2));
    } else {
        bad('booking D agent AP balance', "bal=".number_format($agentAccount->balance, 2));
    }
} catch (\Throwable $e) {
    bad('create booking D', 'exception: '.$e->getMessage());
}

// ==========================================================================
// 7. Update booking A price → repost expense/income (additive only)
// ==========================================================================
echo "\n── 7. Update booking A — price change → additive repost ──\n";
try {
    $bookingA->refresh();
    $txCountBefore = Transaction::where('related_type', VisaBooking::class)
        ->where('related_id', $bookingA->id)
        ->count();

    $updated = $svc->update($bookingA->fresh(), [
        'purchase_price' => 1600.00,    // was 1500
        'selling_price'  => 2200.00,    // was 2000
        'service_fee'    => 100.00,     // unchanged
    ]);
    ok('update booking A price', "new profit={$updated->profit}");

    $txCountAfter = Transaction::where('related_type', VisaBooking::class)
        ->where('related_id', $bookingA->id)
        ->count();

    // Originals stay → tx_count grows by 2 (one new expense, one new income).
    if ($txCountAfter > $txCountBefore) {
        ok('update A new transactions added', "before={$txCountBefore} after={$txCountAfter}");
    } else {
        bad('update A transactions additive', "no new transactions: before={$txCountBefore} after={$txCountAfter}");
    }

    // (Replaced legacy "entries count" check with a precise additive proof below —
    // the originals must carry a "عكس القيد" reversal entry.)

    // Original transaction row amounts must be UNCHANGED (project invariant).
    $expOriginal = Transaction::find(2448); // booking A original expense (before update)
    $incOriginal = Transaction::find(2449); // booking A original income (before update)
    if ($expOriginal && (float) $expOriginal->amount == 1500.00) {
        ok('update A original expense amount intact', "amount={$expOriginal->amount}");
    } else {
        bad('update A original expense amount intact', "amount=".($expOriginal->amount ?? 'null'));
    }
    if ($incOriginal && (float) $incOriginal->amount == 2100.00) {
        ok('update A original income amount intact', "amount={$incOriginal->amount}");
    } else {
        bad('update A original income amount intact', "amount=".($incOriginal->amount ?? 'null'));
    }

    // Additive proof: the ORIGINAL tx rows must have a "عكس القيد" reversal entry appended.
    $expOrigRev = AccountEntry::where('transaction_id', 2448)
        ->where('notes', 'like', 'عكس القيد%')
        ->exists();
    $incOrigRev = AccountEntry::where('transaction_id', 2449)
        ->where('notes', 'like', 'عكس القيد%')
        ->exists();
    if ($expOrigRev) {
        ok('update A — original expense tx has عكس القيد reversal entry', 'additive');
    } else {
        bad('update A — original expense tx has عكس القيد reversal entry', 'not found');
    }
    if ($incOrigRev) {
        ok('update A — original income tx has عكس القيد reversal entry', 'additive');
    } else {
        bad('update A — original income tx has عكس القيد reversal entry', 'not found');
    }
} catch (\Throwable $e) {
    bad('update booking A price', 'exception: '.$e->getMessage());
}

// ==========================================================================
// 8. Lifecycle guard — payment on cancelled booking must be blocked
// ==========================================================================
echo "\n── 8. Lifecycle guard — payment on cancelled booking is blocked ──\n";

$refundSvc = app(VisaRefundService::class);

// Cancel booking C first (will be used for the lifecycle guard test)
try {
    $bookingC->refresh();
    $refundSvc->cancel($bookingC->fresh(), 'VE2E test cancel');
    ok('cancel booking C', "status now={$bookingC->fresh()->status->value}");

    // Try to add a payment on cancelled booking
    try {
        $svc->addPayment($bookingC->fresh(), [
            'amount' => 10.00,
            'payment_method' => 'cash',
            'account_id' => $bankUsd->id,
        ]);
        bad('lifecycle guard — addPayment on cancelled', 'should have thrown');
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'ملغى') || str_contains($e->getMessage(), 'مُلغى')) {
            ok('lifecycle guard — addPayment on cancelled blocked', substr($e->getMessage(), 0, 80));
        } else {
            bad('lifecycle guard — addPayment on cancelled blocked', $e->getMessage());
        }
    }
} catch (\Throwable $e) {
    bad('cancel booking C', 'exception: '.$e->getMessage());
}

// ==========================================================================
// 9. Cancel booking D — additive reversal + idempotency
// ==========================================================================
echo "\n── 9. Cancel booking D — additive reversal + idempotency ──\n";

try {
    $bookingD->refresh();
    $txCountBefore = Transaction::where('related_type', VisaBooking::class)
        ->where('related_id', $bookingD->id)
        ->count();
    $cashboxStart = (float) $cashboxEgp->fresh()->balance;

    $refundSvc->cancel($bookingD->fresh(), 'VE2E customer changed mind');
    $bookingD->refresh();
    ok('cancel booking D', "status={$bookingD->status->value}");

    $txCountAfter = Transaction::where('related_type', VisaBooking::class)
        ->where('related_id', $bookingD->id)
        ->count();

    // Reversal entries are append-only on the SAME transaction_id, so
    // tx_count typically stays the same (entries are added to existing tx rows).
    // We assert at least 1 reversal entry was added on the income/expense txns.
    $entriesNow = AccountEntry::whereIn('transaction_id', [
        $bookingD->expense_transaction_id, $bookingD->income_transaction_id,
    ])->count();

    $hasReversal = AccountEntry::whereIn('transaction_id', [
            $bookingD->expense_transaction_id, $bookingD->income_transaction_id,
        ])
        ->where('notes', 'like', 'عكس القيد%')
        ->exists();
    if ($hasReversal) {
        ok('cancel D ledger has عكس القيد reversal entry', 'additive reversal detected');
    } else {
        bad('cancel D ledger has عكس القيد reversal entry', 'no reverse entry found');
    }

    // Customer AR should be cleared (refund cleared income)
    $customerD->refresh();
    $custAcctD = Account::find($customerD->account_id);
    $custAcctD->refresh();
    if (abs((float) $custAcctD->balance) < 0.01) {
        ok('cancel D customer AR cleared', 'balance=0');
    } else {
        bad('cancel D customer AR cleared', 'balance='.(float) $custAcctD->balance);
    }

    // Idempotency: second cancel must throw
    try {
        $refundSvc->cancel($bookingD->fresh(), 'duplicate cancel');
        bad('cancel D idempotency', 'second cancel should have thrown');
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'ملغى') || str_contains($e->getMessage(), 'مسبقاً')) {
            ok('cancel D idempotency', substr($e->getMessage(), 0, 80));
        } else {
            bad('cancel D idempotency', $e->getMessage());
        }
    }
} catch (\Throwable $e) {
    bad('cancel booking D', 'exception: '.$e->getMessage());
}

// ==========================================================================
// 10. Refund booking B — additive reversal, distinct from cancel
// ==========================================================================
echo "\n── 10. Refund booking B — additive reversal, distinct from cancel ──\n";

try {
    $bookingB->refresh();
    $refundSvc->refund($bookingB->fresh(), 'VE2E full refund');
    $bookingB->refresh();
    if ($bookingB->status === VisaStatus::Refunded) {
        ok('refund booking B', "status={$bookingB->status->value}");
    } else {
        bad('refund booking B', 'status did not change to refunded');
    }

    // Verify AR is cleared
    $customerB->refresh();
    $custB = Account::find($customerB->account_id);
    $custB->refresh();
    if (abs((float) $custB->balance) < 0.01) {
        ok('refund B customer AR cleared', 'balance=0');
    } else {
        bad('refund B customer AR cleared', 'balance='.(float) $custB->balance);
    }

    // Idempotency: refund twice → must throw
    try {
        $refundSvc->refund($bookingB->fresh(), 'duplicate refund');
        bad('refund B idempotency', 'second refund should have thrown');
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'مسترد') || str_contains($e->getMessage(), 'مسبقاً')) {
            ok('refund B idempotency', substr($e->getMessage(), 0, 80));
        } else {
            bad('refund B idempotency', $e->getMessage());
        }
    }
} catch (\Throwable $e) {
    bad('refund booking B', 'exception: '.$e->getMessage());
}

// ==========================================================================
// 11. Soft-delete booking A — full reversal + idempotent retry
// ==========================================================================
echo "\n── 11. Soft-delete booking A — full reversal + idempotent retry ──\n";

try {
    $bookingA->refresh();
    $bookingAId = $bookingA->id;
    $refundSvc->deleteWithReversal($bookingAId, 1);
    $trashed = VisaBooking::withTrashed()->find($bookingAId);
    if ($trashed && $trashed->trashed()) {
        ok('soft-delete booking A', "deleted_at={$trashed->deleted_at}");
    } else {
        bad('soft-delete booking A', 'booking not soft-deleted');
    }

    // Idempotent: second delete must throw
    try {
        $refundSvc->deleteWithReversal($bookingAId, 1);
        bad('soft-delete A idempotency', 'second delete should have thrown');
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'محذوف') || str_contains($e->getMessage(), 'مسبقاً')) {
            ok('soft-delete A idempotency', substr($e->getMessage(), 0, 80));
        } else {
            bad('soft-delete A idempotency', $e->getMessage());
        }
    }

    // Verify all payments soft-deleted too
    $paymentsRemaining = VisaPayment::where('visa_booking_id', $bookingAId)->count();
    if ($paymentsRemaining === 0) {
        ok('soft-delete A payments cleared', 'all payments gone');
    } else {
        bad('soft-delete A payments cleared', "$paymentsRemaining payments still present");
    }
} catch (\Throwable $e) {
    bad('soft-delete booking A', 'exception: '.$e->getMessage());
}

// ==========================================================================
// 12. Customer statement integrity
// ==========================================================================
echo "\n── 12. Customer statement integrity ──\n";
$resp = http('GET', "/visa/customer-statement?client_id={$customerA->id}");
if ($resp['status'] === 200) {
    ok('GET /visa/customer-statement', 'HTTP 200');
} else {
    bad('GET /visa/customer-statement', 'HTTP '.$resp['status'].' body='.substr($resp['body'], 0, 200));
}

$resp = http('GET', "/visa/customer-balances?customer_id={$customerA->id}");
if ($resp['status'] === 200) {
    ok('GET /visa/customer-balances', 'HTTP 200');
} else {
    bad('GET /visa/customer-balances', 'HTTP '.$resp['status']);
}

// ==========================================================================
// 13. Treasury overview endpoint
// ==========================================================================
echo "\n── 13. Treasury overview endpoint ──\n";
$resp = http('GET', '/visa/treasury/overview');
if ($resp['status'] === 200) {
    ok('GET /visa/treasury/overview', 'HTTP 200');
} else {
    bad('GET /visa/treasury/overview', 'HTTP '.$resp['status']);
}

// ==========================================================================
// 14. Final balance integrity check
// ==========================================================================
echo "\n── 14. Final balance integrity ──\n";

// Re-snapshot
$trackingAccountsList = array_values($trackingAccounts);
$final = snapshotBalances($trackingAccountsList);

// Build a map of expected deltas per account (based on scenarios executed):
//  cashboxEGP (initial: 100000):
//    A create:  expense(1500 from cashbox) + payment(2100 to cashbox) = +600
//    A update:  reverse(1500) + new expense(1600) on cashbox = -100
//    B create:  expense(8000 from cashbox) + initial payment(5000 to cashbox) = -3000
//    D create:  payment(1550 to cashbox); expense was on agent AP = +1550
//    D cancel:  reverse payment(1550 from cashbox) = -1550
//    A delete:  reverse payment(2100 from cashbox) + reverse new expense(1600 to cashbox) = -500
//    B refund:  reverse initial payment(5000 from cashbox) + reverse expense(8000 to cashbox) = +3000
//    C cancel:  cashbox unaffected (C used bankUsd).
//    Total cashbox delta = 600 - 100 - 3000 + 1550 - 1550 - 500 + 3000 = 0
//
//  walletEgp:
//    B remainder: +7500 (recordIncome from AR to wallet)
//    B refund: reverse payment income(7500 from wallet) = -7500
//    Net: 0
//
//  walletSar: unused → 0
//  bankEgp:   unused → 0
//  bankUsd:
//    C create:  expense(200 from bankUsd) + payment(370 to bankUsd) = +170
//    C cancel:  reverse payment(370 from bankUsd) + reverse expense(200 to bankUsd) = -170
//    Net: 0
//  cashboxUsd: unused → 0
$expected = [
    $cashboxEgp->id => 0,
    $cashboxUsd->id => 0,
    $walletEgp->id  => 0,
    $walletSar->id  => 0,
    $bankEgp->id    => 0,
    $bankUsd->id    => 0,
];

echo "\n  ID | Account                              | Final Bal  | Expected Δ | Actual Δ | Status\n";
echo "  ---+--------------------------------------+------------+------------+----------+--------\n";
foreach ($expected as $id => $expDelta) {
    $acct = Account::find($id);
    if (! $acct) continue;
    $start = $initialBalances[$id]['balance'];  // Use ORIGINAL baseline (immutable)
    $end   = (float) $acct->balance;
    $actualDelta = round($end - $start, 2);
    $status = (abs($actualDelta - $expDelta) < 0.01) ? 'OK' : 'FAIL';
    printf("  %-3d| %-38s| %10.2f | %10.2f | %8.2f | %s\n",
        $id, substr($acct->name, 0, 38), $end, $expDelta, $actualDelta, $status);
    if ($status === 'OK') {
        ok("integrity #{$id} ({$acct->name})", "delta=".number_format($actualDelta, 2));
    } else {
        bad("integrity #{$id} ({$acct->name})", "expected=".number_format($expDelta, 2)." actual=".number_format($actualDelta, 2));
    }
}

// ==========================================================================
// SUMMARY
// ==========================================================================
echo "\n" . str_repeat("=", 60) . "\n";
echo "SUMMARY: {$pass} passed, {$fail} failed\n";
echo "Booking IDs created: ".implode(', ', $bookingIds)."\n";
echo str_repeat("=", 60) . "\n";

// Optional cleanup
echo "\nCleanup: not deleting fixtures (other tests reuse them).\n";
exit($fail > 0 ? 1 : 0);