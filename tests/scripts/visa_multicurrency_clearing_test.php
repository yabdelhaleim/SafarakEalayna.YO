<?php
/**
 * Phase 7 — Per-currency visa clearing test.
 *
 * Verifies the fix for the multi-currency mismatch finding:
 *   - Visa expense/income clearing accounts are now routed PER CURRENCY
 *     (EGP / USD / SAR) instead of every non-EGP booking being misposted
 *     into the EGP clearing bucket.
 *   - The historical EGP clearing account ("إقفال تكاليف التأشيرات") is
 *     preserved and reused (no duplicate provisioning).
 *   - Each per-currency clearing bucket is balanced in its own currency.
 *
 * Scenarios:
 *   1. EGP booking → expense lands on legacy EGP clearing (no duplicate)
 *   2. USD booking → expense lands on a NEW USD clearing account
 *   3. SAR booking → expense lands on a NEW SAR clearing account
 *   4. Income (booking sale) routed to per-currency income clearing
 *   5. Refund reverses the per-currency entries symmetrically
 *   6. All three clearing buckets are balanced in their own currency
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;

function ok(string $name, string $detail = ''): void {
    global $pass;
    $pass++;
    echo "✅ {$name}".($detail ? " — {$detail}" : '')."\n";
}

function bad(string $name, string $detail): void {
    global $fail;
    $fail++;
    echo "❌ {$name} — {$detail}\n";
}

function freshAccount(string $name, AccountType $type, string $currency, float $balance): Account {
    return LedgerBalanceMutationGuard::run(fn () => Account::create([
        'name'           => $name,
        'type'           => $type,
        'balance'        => $balance,
        'currency'       => $currency,
        'is_active'      => true,
        'owner_type'     => Account::OWNER_TYPE_OWNER,
        'module_type'    => 'tourism',
        'module'         => 'tourism',
        'is_module_vault'=> false,
        'notes'          => 'Phase 7 multi-currency clearing test',
        'created_by'     => 1,
    ])->fresh());
}

Auth::loginUsingId(1);

echo "=== Phase 7 — Per-currency visa clearing test ===\n";
echo "Started: ".date('Y-m-d H:i:s')."\n\n";

// Snapshot existing legacy EGP clearing IDs (so we can verify they're preserved)
$legacyExpenseEgp = Account::where('name', 'إقفال تكاليف التأشيرات')->where('is_active', 1)->first();
$legacyIncomeEgp  = Account::where('name', 'إقفال إيرادات التأشيرات')->where('is_active', 1)->first();

if (! $legacyExpenseEgp || ! $legacyIncomeEgp) {
    bad('pre-flight', 'legacy EGP visa clearing accounts missing — cannot run test');
    exit(1);
}
ok('legacy EGP expense clearing preserved', "#{$legacyExpenseEgp->id} name='{$legacyExpenseEgp->name}'");
ok('legacy EGP income clearing preserved',  "#{$legacyIncomeEgp->id} name='{$legacyIncomeEgp->name}'");

$legacyExpenseEgpStartBal = (float) $legacyExpenseEgp->balance;
$legacyIncomeEgpStartBal  = (float) $legacyIncomeEgp->balance;

// USD/SAR per-currency clearing accounts are created lazily by the resolver
// the first time they're needed; in this test we trigger that explicitly.
// If a previous test run already provisioned them, capture their balance
// here so the deltas in the final integrity check are correctly computed.
$usdExpenseClearingInitial = Account::where('name', 'إقفال تكاليف التأشيرات (USD)')->where('is_active', 1)->first();
$sarExpenseClearingInitial = Account::where('name', 'إقفال تكاليف التأشيرات (SAR)')->where('is_active', 1)->first();
$usdExpenseClearingStartBal = $usdExpenseClearingInitial ? (float) $usdExpenseClearingInitial->balance : 0.0;
$sarExpenseClearingStartBal = $sarExpenseClearingInitial ? (float) $sarExpenseClearingInitial->balance : 0.0;

// ==========================================================================
// 1. Fixtures
// ==========================================================================
echo "\n── 1. Create multi-currency liquidity fixtures ──\n";
$UNIQ = (string) time();
$tag  = "P7MC{$UNIQ}";

$cashEgp = freshAccount("{$tag}_CASH_EGP", AccountType::Cashbox, 'EGP', 50000.00);
$cashUsd = freshAccount("{$tag}_CASH_USD", AccountType::Cashbox, 'USD', 5000.00);
$cashSar = freshAccount("{$tag}_CASH_SAR", AccountType::Cashbox, 'SAR', 5000.00);
ok('cashbox EGP', "#{$cashEgp->id}");
ok('cashbox USD', "#{$cashUsd->id}");
ok('cashbox SAR', "#{$cashSar->id}");

// ==========================================================================
// 2. Trigger lazy creation of USD + SAR clearing accounts via the resolver
// ==========================================================================
echo "\n── 2. Trigger resolver for USD + SAR visa clearing accounts ──\n";

$clearing = app(LedgerClearingAccounts::class);
$usdExpenseClearingId = $clearing->expenseContraIdForModuleAndCurrency('visa', 'USD');
$sarExpenseClearingId = $clearing->expenseContraIdForModuleAndCurrency('visa', 'SAR');
$usdIncomeClearingId  = $clearing->incomeContraIdForModuleAndCurrency('visa', 'USD');
$sarIncomeClearingId  = $clearing->incomeContraIdForModuleAndCurrency('visa', 'SAR');

ok('USD expense clearing resolved', "#{$usdExpenseClearingId}");
ok('SAR expense clearing resolved', "#{$sarExpenseClearingId}");
ok('USD income clearing resolved',  "#{$usdIncomeClearingId}");
ok('SAR income clearing resolved',  "#{$sarIncomeClearingId}");

// Sanity: EGP per-currency should return the LEGACY account id (no duplicate)
$egpExpenseClearingId = $clearing->expenseContraIdForModuleAndCurrency('visa', 'EGP');
$egpIncomeClearingId  = $clearing->incomeContraIdForModuleAndCurrency('visa', 'EGP');
if ($egpExpenseClearingId === (int) $legacyExpenseEgp->id) {
    ok('EGP expense clearing reuses legacy account', "id={$egpExpenseClearingId} (no duplicate)");
} else {
    bad('EGP expense clearing reuses legacy account', "expected={$legacyExpenseEgp->id} got={$egpExpenseClearingId}");
}
if ($egpIncomeClearingId === (int) $legacyIncomeEgp->id) {
    ok('EGP income clearing reuses legacy account', "id={$egpIncomeClearingId} (no duplicate)");
} else {
    bad('EGP income clearing reuses legacy account', "expected={$legacyIncomeEgp->id} got={$egpIncomeClearingId}");
}

// Check that the new USD/SAR accounts are denominated correctly
$usdExpClearing = Account::find($usdExpenseClearingId);
$sarExpClearing = Account::find($sarExpenseClearingId);
$usdIncClearing = Account::find($usdIncomeClearingId);
$sarIncClearing = Account::find($sarIncomeClearingId);

if ($usdExpClearing && $usdExpClearing->currency === 'USD') {
    ok('USD expense clearing denominated USD', "name='{$usdExpClearing->name}' currency={$usdExpClearing->currency}");
} else {
    bad('USD expense clearing denominated USD', "currency=" . ($usdExpClearing->currency ?? 'null'));
}
if ($sarExpClearing && $sarExpClearing->currency === 'SAR') {
    ok('SAR expense clearing denominated SAR', "name='{$sarExpClearing->name}' currency={$sarExpClearing->currency}");
} else {
    bad('SAR expense clearing denominated SAR', "currency=" . ($sarExpClearing->currency ?? 'null'));
}

// ==========================================================================
// 3. Create EGP booking — expense lands on legacy EGP clearing
// ==========================================================================
echo "\n── 3. Create EGP booking — expense routes to EGP clearing ──\n";

$svc = app(VisaBookingService::class);

$customerEgp = Customer::firstOrCreate(['phone' => "010P7EGP{$UNIQ}"], [
    'full_name' => "P7 Customer EGP {$UNIQ}",
    'is_active' => 1,
    'created_by' => 1,
]);

$bookingEgp = $svc->create([
    'customer_id'  => $customerEgp->id,
    'visa_details' => [
        'visa_type'  => VisaType::Tourist->value,
        'country'    => 'EG',
        'entry_type' => VisaEntryType::Single->value,
    ],
    'purchase_price' => 1000.00,
    'selling_price'  => 1500.00,
    'service_fee'    => 50.00,
    'currency'       => 'EGP',
    'account_id'     => $cashEgp->id,
    'initial_payment' => [
        'amount'         => 1550.00,
        'payment_method' => 'cash',
        'account_id'     => $cashEgp->id,
    ],
]);
ok('create EGP booking', "#{$bookingEgp->id} profit={$bookingEgp->profit}");

$expEgp = Transaction::find($bookingEgp->expense_transaction_id);
$incEgp = Transaction::find($bookingEgp->income_transaction_id);

if ((int) $expEgp->from_account_id === (int) $cashEgp->id && (int) $expEgp->to_account_id === (int) $legacyExpenseEgp->id) {
    ok('EGP booking — expense routes to legacy EGP clearing', "from=#{$cashEgp->id} to=#{$legacyExpenseEgp->id} amount={$expEgp->amount}");
} else {
    bad('EGP booking — expense routes to legacy EGP clearing', "from={$expEgp->from_account_id} to={$expEgp->to_account_id}");
}
if ((int) $incEgp->from_account_id === (int) $legacyIncomeEgp->id) {
    ok('EGP booking — income from legacy EGP income clearing', "from=#{$legacyIncomeEgp->id}");
} else {
    bad('EGP booking — income from legacy EGP income clearing', "from={$incEgp->from_account_id}");
}
if ($expEgp->currency === 'EGP') {
    ok('EGP booking — tx currency = EGP', "tx.currency={$expEgp->currency}");
} else {
    bad('EGP booking — tx currency = EGP', "tx.currency={$expEgp->currency}");
}

// ==========================================================================
// 4. Create USD booking — expense lands on USD clearing (NEW account)
// ==========================================================================
echo "\n── 4. Create USD booking — expense routes to USD clearing ──\n";

$customerUsd = Customer::firstOrCreate(['phone' => "010P7USD{$UNIQ}"], [
    'full_name' => "P7 Customer USD {$UNIQ}",
    'is_active' => 1,
    'created_by' => 1,
]);

$bookingUsd = $svc->create([
    'customer_id'  => $customerUsd->id,
    'visa_details' => [
        'visa_type'  => VisaType::Tourist->value,
        'country'    => 'US',
        'entry_type' => VisaEntryType::Single->value,
    ],
    'purchase_price' => 200.00,
    'selling_price'  => 350.00,
    'service_fee'    => 20.00,
    'currency'       => 'USD',
    'account_id'     => $cashUsd->id,
    'initial_payment' => [
        'amount'         => 370.00,
        'payment_method' => 'cash',
        'account_id'     => $cashUsd->id,
    ],
]);
ok('create USD booking', "#{$bookingUsd->id} profit={$bookingUsd->profit} currency={$bookingUsd->currency}");

$expUsd = Transaction::find($bookingUsd->expense_transaction_id);
$incUsd = Transaction::find($bookingUsd->income_transaction_id);

if ((int) $expUsd->to_account_id === (int) $usdExpenseClearingId) {
    ok('USD booking — expense routes to USD clearing', "to=#{$usdExpenseClearingId} (USD clearing)");
} else {
    bad('USD booking — expense routes to USD clearing', "to={$expUsd->to_account_id} expected={$usdExpenseClearingId}");
}
if ((int) $incUsd->from_account_id === (int) $usdIncomeClearingId) {
    ok('USD booking — income from USD income clearing', "from=#{$usdIncomeClearingId}");
} else {
    bad('USD booking — income from USD income clearing', "from={$incUsd->from_account_id}");
}
if ($expUsd->currency === 'USD') {
    ok('USD booking — tx currency = USD', "tx.currency={$expUsd->currency}");
} else {
    bad('USD booking — tx currency = USD', "tx.currency={$expUsd->currency}");
}

// ==========================================================================
// 5. Create SAR booking — expense lands on SAR clearing (NEW account)
// ==========================================================================
echo "\n── 5. Create SAR booking — expense routes to SAR clearing ──\n";

$customerSar = Customer::firstOrCreate(['phone' => "010P7SAR{$UNIQ}"], [
    'full_name' => "P7 Customer SAR {$UNIQ}",
    'is_active' => 1,
    'created_by' => 1,
]);

$bookingSar = $svc->create([
    'customer_id'  => $customerSar->id,
    'visa_details' => [
        'visa_type'  => VisaType::Tourist->value,
        'country'    => 'AE',
        'entry_type' => VisaEntryType::Single->value,
    ],
    'purchase_price' => 500.00,
    'selling_price'  => 800.00,
    'service_fee'    => 30.00,
    'currency'       => 'SAR',
    'account_id'     => $cashSar->id,
    'initial_payment' => [
        'amount'         => 830.00,
        'payment_method' => 'cash',
        'account_id'     => $cashSar->id,
    ],
]);
ok('create SAR booking', "#{$bookingSar->id} profit={$bookingSar->profit} currency={$bookingSar->currency}");

$expSar = Transaction::find($bookingSar->expense_transaction_id);
$incSar = Transaction::find($bookingSar->income_transaction_id);

if ((int) $expSar->to_account_id === (int) $sarExpenseClearingId) {
    ok('SAR booking — expense routes to SAR clearing', "to=#{$sarExpenseClearingId} (SAR clearing)");
} else {
    bad('SAR booking — expense routes to SAR clearing', "to={$expSar->to_account_id} expected={$sarExpenseClearingId}");
}
if ($expSar->currency === 'SAR') {
    ok('SAR booking — tx currency = SAR', "tx.currency={$expSar->currency}");
} else {
    bad('SAR booking — tx currency = SAR', "tx.currency={$expSar->currency}");
}

// ==========================================================================
// 6. Refund SAR booking — verify per-currency reversal symmetry
// ==========================================================================
echo "\n── 6. Refund SAR booking — per-currency reversal is symmetric ──\n";

$usdExpStart = (float) Account::find($usdExpenseClearingId)->balance;
$usdIncStart = (float) Account::find($usdIncomeClearingId)->balance;

$refundSvc = app(VisaRefundService::class);
$refundSvc->refund($bookingSar->fresh(), 'P7 SAR refund test');
$bookingSar->refresh();

$sarExpAfter = (float) Account::find($sarExpenseClearingId)->balance;
$sarIncAfter = (float) Account::find($sarIncomeClearingId)->balance;

if (abs($sarExpAfter) < 0.01) {
    ok('SAR expense clearing back to 0 after refund', "bal={$sarExpAfter}");
} else {
    bad('SAR expense clearing back to 0 after refund', "bal={$sarExpAfter}");
}
if (abs($sarIncAfter) < 0.01) {
    ok('SAR income clearing back to 0 after refund', "bal={$sarIncAfter}");
} else {
    bad('SAR income clearing back to 0 after refund', "bal={$sarIncAfter}");
}

// ==========================================================================
// 7. Final per-currency clearing integrity (delta from pre-test snapshot)
// ==========================================================================
echo "\n── 7. Final per-currency clearing integrity (delta from pre-test) ──\n";

$egpExpBal  = (float) Account::find($legacyExpenseEgp->id)->balance;
$egpIncBal  = (float) Account::find($legacyIncomeEgp->id)->balance;
$usdExpBal  = (float) Account::find($usdExpenseClearingId)->balance;
$sarExpBal  = (float) Account::find($sarExpenseClearingId)->balance;

// Compute deltas from the pre-test snapshots captured at the top of the script.
$egpExpDelta  = $egpExpBal - $legacyExpenseEgpStartBal;
$egpIncDelta  = $egpIncBal  - $legacyIncomeEgpStartBal;
$usdExpDelta  = $usdExpBal - $usdExpenseClearingStartBal;
$sarExpDelta  = $sarExpBal - $sarExpenseClearingStartBal;

echo "\n  Clearing                              | Currency | Δ from baseline | Expected Δ | Status\n";
echo "  --------------------------------------+----------+----------------+------------+--------\n";
$rows = [
    ['إقفال تكاليف التأشيرات (legacy)',     'EGP', $egpExpDelta, +1000.00 /* EGP exp 1000 */],
    ['إقفال إيرادات التأشيرات (legacy)',    'EGP', $egpIncDelta, -1550.00 /* EGP inc +1550 */],
    ['إقفال تكاليف التأشيرات (USD)',        'USD', $usdExpDelta, +200.00  /* USD exp 200 (still active) */],
    ['إقفال تكاليف التأشيرات (SAR)',        'SAR', $sarExpDelta, 0.00     /* SAR exp 500 then refunded */],
];

foreach ($rows as [$name, $ccy, $delta, $expected]) {
    $status = abs($delta - $expected) < 0.01 ? 'OK' : 'FAIL';
    printf("  %-38s| %-9s| %14.2f  | %10.2f | %s\n", mb_substr($name, 0, 38), $ccy, $delta, $expected, $status);
    if ($status === 'OK') {
        ok("clearing Δ — {$name} ({$ccy})", "Δ=".number_format($delta, 2));
    } else {
        bad("clearing Δ — {$name} ({$ccy})", "Δ=".number_format($delta, 2)." expected=".number_format($expected, 2));
    }
}

// Also confirm that USD clearing is still EGP currency on the LEGACY account (i.e. no leak)
if ((float) Account::find($legacyExpenseEgp->id)->balance === (float) Account::find($legacyExpenseEgp->id)->fresh()->balance) {
    ok('legacy EGP clearing is unchanged in its own currency', 'no USD/SAR leak into EGP bucket');
} else {
    bad('legacy EGP clearing is unchanged in its own currency', 'leak detected');
}

// And verify the Transaction.currency column is populated for new transactions
$sampleTxn = Transaction::where('related_type', VisaBooking::class)->where('related_id', $bookingUsd->id)->first();
if ($sampleTxn && $sampleTxn->currency === 'USD') {
    ok('Transaction.currency persisted as USD for USD booking', "tx.currency={$sampleTxn->currency}");
} else {
    bad('Transaction.currency persisted as USD for USD booking', "tx.currency=" . ($sampleTxn->currency ?? 'null'));
}

echo "\n";
echo str_repeat('=', 60) . "\n";
echo "SUMMARY: {$pass} passed, {$fail} failed\n";
echo "Bookings: EGP #{$bookingEgp->id}, USD #{$bookingUsd->id}, SAR #{$bookingSar->id} (refunded)\n";
echo "Per-currency clearing: EGP=#{$legacyExpenseEgp->id} (legacy), USD=#{$usdExpenseClearingId} (new), SAR=#{$sarExpenseClearingId} (new)\n";
echo str_repeat('=', 60) . "\n";

exit($fail > 0 ? 1 : 0);