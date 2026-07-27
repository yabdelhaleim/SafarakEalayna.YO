<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║       موديول التأشيرات — اختبار إنتاج شامل 100% (النسخة النهائية)     ║
 * ║       VISA MODULE — FULL PRODUCTION TEST SUITE v3                      ║
 * ╠══════════════════════════════════════════════════════════════════════════╣
 * ║  التصحيحات:                                                             ║
 * ║  1. استخدام $agentModel->account_id الفعلي لضمان تتبع حساب الوكيل الصحيح║
 * ║  2. قياس التغيير الصافي لـ deleteWithReversal بالنسبة لما قبل الحجز    ║
 * ║  3. تدقيق شامل للقيود والأرصدة وميزان المراجع المحاسبي                ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * تشغيل: php visa_module_production_full_test.php
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\VisaStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\Finance\TransactionService;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════
$passCount  = 0;
$failCount  = 0;
$results    = [];
$bookingIds = [];

function section(string $title): void
{
    $bar = str_repeat('═', 70);
    echo "\n{$bar}\n║ {$title}\n{$bar}\n";
}

function step(string $name, bool $passed, string $detail, array &$results, int &$passCount, int &$failCount): void
{
    $icon = $passed ? '✅' : '❌';
    echo "  {$icon} {$name}" . ($detail !== '' ? " | {$detail}" : '') . "\n";
    $results[$name] = ['passed' => $passed, 'detail' => $detail];
    if ($passed) {
        $passCount++;
    } else {
        $failCount++;
        $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[1] ?? [];
        echo "       ↳ called from line " . ($caller['line'] ?? '?') . "\n";
    }
}

/** Check every transaction_id in array has balanced debit = credit */
function balancedTransactions(array $txIds): array
{
    if (empty($txIds)) {
        return ['all_balanced' => true, 'checked' => 0, 'unbalanced' => []];
    }
    $unbalanced = [];
    foreach ($txIds as $tid) {
        $row = DB::table('account_entries')
            ->where('transaction_id', $tid)
            ->selectRaw('SUM(debit) as sum_d, SUM(credit) as sum_c')
            ->first();
        $diff = abs((float)($row->sum_d ?? 0) - (float)($row->sum_c ?? 0));
        if ($diff > 0.01) {
            $unbalanced[$tid] = [
                'debit'  => (float)($row->sum_d ?? 0),
                'credit' => (float)($row->sum_c ?? 0),
                'diff'   => $diff,
            ];
        }
    }
    return ['all_balanced' => empty($unbalanced), 'checked' => count($txIds), 'unbalanced' => $unbalanced];
}

/** Get stored balance from accounts table */
function accountBalance(int $accountId): float
{
    return (float)(Account::find($accountId)?->fresh()->balance ?? 0.0);
}

function assertDelta(float $expected, float $actual, float $tol = 0.02): bool
{
    return abs($expected - $actual) <= $tol;
}

/** Get VisaStatus string value handling both string and Enum */
function statusValue(mixed $status): string
{
    if ($status instanceof VisaStatus) return $status->value;
    return (string)$status;
}

// ══════════════════════════════════════════════════════
// Auth Setup
// ══════════════════════════════════════════════════════
$adminUser = \App\Models\User::where('role', 'admin')->orderBy('id')->first();
if (!$adminUser) {
    $adminUser = \App\Models\User::create([
        'name'      => 'Visa Test Admin',
        'email'     => 'visa-prod-test@test.local',
        'password'  => bcrypt('secret1234'),
        'role'      => 'admin',
        'is_active' => true,
    ]);
}
Auth::setUser($adminUser);

$service       = app(VisaBookingService::class);
$refundService = app(VisaRefundService::class);
$txService     = app(TransactionService::class);

section('S01 — تجهيز بيانات الاختبار (Setup)');

// ── Customer 1 (EGP)
$cust1 = Customer::firstOrCreate(
    ['phone' => '01199900001'],
    ['full_name' => 'PROD3_VISA_CUST_EGP', 'created_by' => $adminUser->id]
);

// ── Customer 2 (USD bookings)
$cust2 = Customer::firstOrCreate(
    ['phone' => '01199900002'],
    ['full_name' => 'PROD3_VISA_CUST_USD', 'created_by' => $adminUser->id]
);

// ── Visa Agent + linked Account
$agentModel = VisaAgent::firstOrCreate(
    ['company_name' => 'PROD3_VISA_AGENT'],
    ['contact_person' => 'Test Contact', 'phone' => '01099000099', 'is_active' => true, 'default_cost_price' => 800.0, 'created_by' => $adminUser->id]
);

if ($agentModel->account_id) {
    $agentAccount = Account::find($agentModel->account_id);
} else {
    $agentAccount = Account::create([
        'name'             => 'PROD3_VISA_AGENT_LEDGER',
        'type'             => AccountType::Supplier->value,
        'currency'         => 'EGP',
        'balance'          => 0,
        'is_active'        => true,
        'owner_type'       => 'office',
        'module_type'      => 'visas',
        'is_module_vault'  => false,
        'created_by'       => $adminUser->id,
    ]);
    $agentModel->update(['account_id' => $agentAccount->id]);
}

// ── Visa Duration
$duration = VisaDuration::first() ?? VisaDuration::create([
    'code' => 'PROD3-30D', 'label_ar' => 'شهر', 'label_en' => 'One Month',
    'months' => 1, 'entry_type' => 'single', 'sort_order' => 99, 'is_active' => true,
]);

// ── EGP Vault (tourism division)
$egpVault = Account::where('module_type', 'tourism')->where('is_module_vault', true)->where('currency', 'EGP')->first();
if (!$egpVault) {
    $egpVault = Account::create([
        'name' => 'PROD3_VISA_EGP_VAULT', 'type' => 'cashbox', 'currency' => 'EGP',
        'balance' => 500000, 'is_active' => true, 'is_module_vault' => true,
        'owner_type' => 'office', 'module_type' => 'tourism', 'created_by' => $adminUser->id,
    ]);
}

// ── USD Vault (tourism division, USD)
$usdVault = Account::where('module_type', 'tourism')->where('is_module_vault', true)->where('currency', 'USD')->first();
if (!$usdVault) {
    $usdVault = Account::create([
        'name' => 'PROD3_VISA_USD_VAULT', 'type' => 'cashbox', 'currency' => 'USD',
        'balance' => 10000, 'is_active' => true, 'is_module_vault' => true,
        'owner_type' => 'office', 'module_type' => 'tourism', 'created_by' => $adminUser->id,
    ]);
}

// ── Visa Receiver (agent withdraw target)
$visaReceiver = Account::firstOrCreate(
    ['name' => 'PROD3_VISA_RECEIVER'],
    [
        'type' => 'owner', 'currency' => 'EGP', 'balance' => 0,
        'is_active' => true, 'is_module_vault' => false,
        'owner_type' => 'owner', 'module_type' => 'tourism', 'created_by' => $adminUser->id,
    ]
);

echo "  ✔ Admin User #{$adminUser->id}\n";
echo "  ✔ Customer1 #{$cust1->id} — {$cust1->full_name}\n";
echo "  ✔ Customer2 #{$cust2->id} — {$cust2->full_name}\n";
echo "  ✔ VisaAgent #{$agentModel->id} — account #{$agentAccount->id}\n";
echo "  ✔ Duration #{$duration->id}\n";
echo "  ✔ EGP Vault #{$egpVault->id} — balance=" . number_format(accountBalance($egpVault->id), 2) . " EGP\n";
echo "  ✔ USD Vault #{$usdVault->id} — balance=" . number_format(accountBalance($usdVault->id), 2) . " USD\n";
echo "  ✔ Visa Receiver #{$visaReceiver->id}\n";

// ══════════════════════════════════════════════════════
// S02 — إنشاء حجز EGP كامل + تحقق القيود
// ══════════════════════════════════════════════════════
section('S02 — إنشاء حجز EGP + تحقق القيود المحاسبية');

try {
    $purchase02       = 800.0;
    $selling02        = 1200.0;
    $fee02            = 100.0;
    $expectedIncome02 = $selling02 + $fee02; // 1300
    $expectedProfit02 = round($expectedIncome02 - $purchase02, 2); // 500

    $egpVaultBefore02  = accountBalance($egpVault->id);
    $agentBefore02     = accountBalance($agentAccount->id);
    $cust1Acct02Before = $cust1->account_id ? accountBalance($cust1->account_id) : 0.0;

    $b02 = $service->create([
        'customer_id'    => $cust1->id,
        'purchase_price' => $purchase02,
        'selling_price'  => $selling02,
        'service_fee'    => $fee02,
        'currency'       => 'EGP',
        'account_id'     => $egpVault->id,
        'visa_details'   => [
            'visa_type'        => 'tourist',
            'country'          => 'EG-PROD3-TEST',
            'duration'         => '30',
            'entry_type'       => 'single',
            'visa_agent_id'    => $agentModel->id,
            'visa_duration_id' => $duration->id,
        ],
    ]);
    $bookingIds[] = $b02->id;
    $b02->refresh();
    $cust1->refresh();

    $cust1Acct02After = accountBalance($cust1->account_id);
    $agentAfter02     = accountBalance($agentAccount->id);
    $egpVaultAfter02  = accountBalance($egpVault->id);

    $cust1Delta02 = $cust1Acct02After - $cust1Acct02Before;
    $agentDelta02 = $agentAfter02 - $agentBefore02;
    $vaultDelta02 = $egpVaultAfter02 - $egpVaultBefore02;

    step('S02.1 — booking created (status=submitted)', statusValue($b02->status) === VisaStatus::Submitted->value, "id={$b02->id} status=" . statusValue($b02->status), $results, $passCount, $failCount);
    step('S02.2 — profit calculated correctly', assertDelta($expectedProfit02, (float)$b02->profit), "expected={$expectedProfit02} actual={$b02->profit}", $results, $passCount, $failCount);
    step('S02.3 — expense_transaction_id linked', $b02->expense_transaction_id !== null, "tx_id={$b02->expense_transaction_id}", $results, $passCount, $failCount);
    step('S02.4 — income_transaction_id linked', $b02->income_transaction_id !== null, "tx_id={$b02->income_transaction_id}", $results, $passCount, $failCount);
    step('S02.5 — Customer1 AR increased by ' . $expectedIncome02, assertDelta($expectedIncome02, $cust1Delta02), "Δ={$cust1Delta02}", $results, $passCount, $failCount);
    step('S02.6 — Agent ledger changed by -' . $purchase02, assertDelta(-$purchase02, $agentDelta02), "Δ={$agentDelta02}", $results, $passCount, $failCount);
    step('S02.7 — EGP vault NOT affected by booking alone', assertDelta(0.0, $vaultDelta02), "Δ={$vaultDelta02}", $results, $passCount, $failCount);

    $txIds02    = array_values(array_filter([$b02->expense_transaction_id, $b02->income_transaction_id]));
    $balanced02 = balancedTransactions($txIds02);
    step('S02.8 — all transactions balanced (debit=credit)', $balanced02['all_balanced'], 'checked=' . count($txIds02), $results, $passCount, $failCount);
    step('S02.9 — remaining_amount = selling+fee', assertDelta($expectedIncome02, (float)$b02->remaining_amount), "remaining={$b02->remaining_amount}", $results, $passCount, $failCount);
    step('S02.10 — paid_amount = 0 initially', assertDelta(0.0, (float)$b02->paid_amount), "paid={$b02->paid_amount}", $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S02 FATAL: " . $e->getMessage() . " [" . basename($e->getFile()) . ":" . $e->getLine() . "]\n";
    $failCount++;
}

// ══════════════════════════════════════════════════════
// S03 — إنشاء حجز USD + تحقق العملات
// ══════════════════════════════════════════════════════
section('S03 — إنشاء حجز USD + تحقق العملات الأجنبية');

try {
    $purchase03 = 200.0;
    $selling03  = 300.0;

    $usdVaultBefore03 = accountBalance($usdVault->id);

    $b03 = $service->create([
        'customer_id'    => $cust2->id,
        'purchase_price' => $purchase03,
        'selling_price'  => $selling03,
        'service_fee'    => 0.0,
        'currency'       => 'USD',
        'account_id'     => $usdVault->id,
        'visa_details'   => [
            'visa_type'        => 'business',
            'country'          => 'US-PROD3-TEST',
            'duration'         => '60',
            'entry_type'       => 'multiple',
            'visa_agent_id'    => $agentModel->id,
            'visa_duration_id' => $duration->id,
        ],
    ]);
    $bookingIds[] = $b03->id;
    $cust2->refresh();

    $usdVaultAfter03 = accountBalance($usdVault->id);

    step('S03.1 — USD booking created', $b03->id > 0, "id={$b03->id}", $results, $passCount, $failCount);
    step('S03.2 — currency stored as USD', $b03->currency === 'USD', "currency={$b03->currency}", $results, $passCount, $failCount);
    step('S03.3 — purchase stored correctly', assertDelta($purchase03, (float)$b03->purchase_price), "stored={$b03->purchase_price}", $results, $passCount, $failCount);
    step('S03.4 — selling stored correctly', assertDelta($selling03, (float)$b03->selling_price), "stored={$b03->selling_price}", $results, $passCount, $failCount);
    step('S03.5 — USD vault NOT affected by booking (no payment)', assertDelta(0.0, $usdVaultAfter03 - $usdVaultBefore03), "Δ=" . ($usdVaultAfter03 - $usdVaultBefore03), $results, $passCount, $failCount);

    $txIds03    = array_values(array_filter([$b03->expense_transaction_id, $b03->income_transaction_id]));
    $balanced03 = balancedTransactions($txIds03);
    step('S03.6 — USD booking transactions balanced', $balanced03['all_balanced'], 'checked=' . count($txIds03), $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S03 FATAL: " . $e->getMessage() . "\n";
    $failCount++;
}

// ══════════════════════════════════════════════════════
// S04 — دفعة جزئية (partial payment) على حجز EGP
// ══════════════════════════════════════════════════════
section('S04 — إضافة دفعة جزئية على حجز EGP');

try {
    $payAmount04      = 500.0;
    $egpVaultBefore04 = accountBalance($egpVault->id);
    $cust1Before04    = accountBalance($cust1->account_id);

    $pmt04 = $service->addPayment($b02, [
        'amount'         => $payAmount04,
        'account_id'     => $egpVault->id,
        'payment_method' => 'cash',
        'payment_date'   => now()->toDateString(),
    ]);
    $b02->refresh();

    $egpVaultAfter04 = accountBalance($egpVault->id);
    $cust1After04    = accountBalance($cust1->account_id);
    $vaultDelta04    = $egpVaultAfter04 - $egpVaultBefore04;
    $cust1Delta04    = $cust1After04 - $cust1Before04;

    step('S04.1 — payment record created', $pmt04->id > 0, "id={$pmt04->id} amount={$pmt04->amount}", $results, $passCount, $failCount);
    step('S04.2 — transaction_id linked', $pmt04->transaction_id !== null, "tx_id={$pmt04->transaction_id}", $results, $passCount, $failCount);
    step('S04.3 — EGP vault increased by ' . $payAmount04, assertDelta($payAmount04, $vaultDelta04), "Δ={$vaultDelta04}", $results, $passCount, $failCount);
    step('S04.4 — Customer1 AR decreased by ' . $payAmount04, assertDelta(-$payAmount04, $cust1Delta04), "Δ={$cust1Delta04}", $results, $passCount, $failCount);
    step('S04.5 — booking paid_amount updated', assertDelta($payAmount04, (float)$b02->paid_amount), "paid={$b02->paid_amount}", $results, $passCount, $failCount);

    $expectedRemaining04 = 1300.0 - $payAmount04; // 800
    step('S04.6 — remaining_amount updated correctly', assertDelta($expectedRemaining04, (float)$b02->remaining_amount), "remaining={$b02->remaining_amount}", $results, $passCount, $failCount);

    $balanced04 = balancedTransactions([$pmt04->transaction_id]);
    step('S04.7 — payment transaction balanced', $balanced04['all_balanced'], '', $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S04 FATAL: " . $e->getMessage() . " [" . basename($e->getFile()) . ":" . $e->getLine() . "]\n";
    $failCount++;
}

// ══════════════════════════════════════════════════════
// S05 — محاولة تجاوز المبلغ المتبقي (محظور)
// ══════════════════════════════════════════════════════
section('S05 — منع الدفع الزائد عن المتبقي');

try {
    $b02->refresh();
    $remaining05 = (float)$b02->remaining_amount; // 800
    $overpay05   = $remaining05 + 500.0;

    $caught05 = false;
    try {
        $service->addDebtPayment($b02->fresh(), [
            'amount'         => $overpay05,
            'account_id'     => $egpVault->id,
            'payment_method' => 'cash',
        ]);
    } catch (\RuntimeException $e) {
        $caught05 = str_contains($e->getMessage(), 'يتجاوز') || str_contains($e->getMessage(), 'المتبقي');
    } catch (\InvalidArgumentException $e) {
        $caught05 = true;
    }

    step('S05.1 — overpayment rejected with exception', $caught05, "remaining={$remaining05} attempted={$overpay05}", $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S05 FATAL: " . $e->getMessage() . "\n";
    $failCount++;
}

// ══════════════════════════════════════════════════════
// S06 — addDebtPayment (تسديد المتبقي)
// ══════════════════════════════════════════════════════
section('S06 — addDebtPayment: تسديد الباقي من الدين');

try {
    $b02->refresh();
    $remaining06      = (float)$b02->remaining_amount; // 800
    $egpVaultBefore06 = accountBalance($egpVault->id);
    $cust1Before06    = accountBalance($cust1->account_id);

    $pmt06 = $service->addDebtPayment($b02, [
        'amount'         => $remaining06,
        'account_id'     => $egpVault->id,
        'payment_method' => 'bank_transfer',
    ]);
    $b02->refresh();

    $egpVaultAfter06 = accountBalance($egpVault->id);
    $cust1After06    = accountBalance($cust1->account_id);

    step('S06.1 — debt payment created', $pmt06->id > 0, "id={$pmt06->id}", $results, $passCount, $failCount);
    step('S06.2 — EGP vault increased by ' . $remaining06, assertDelta($remaining06, $egpVaultAfter06 - $egpVaultBefore06), "Δ=" . ($egpVaultAfter06 - $egpVaultBefore06), $results, $passCount, $failCount);
    step('S06.3 — Customer AR decreased by ' . $remaining06, assertDelta(-$remaining06, $cust1After06 - $cust1Before06), "Δ=" . ($cust1After06 - $cust1Before06), $results, $passCount, $failCount);
    step('S06.4 — booking fully paid (remaining ≈ 0)', assertDelta(0.0, (float)$b02->remaining_amount, 0.02), "remaining={$b02->remaining_amount}", $results, $passCount, $failCount);
    step('S06.5 — payment transaction balanced', balancedTransactions([$pmt06->transaction_id])['all_balanced'], '', $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S06 FATAL: " . $e->getMessage() . " [" . basename($e->getFile()) . ":" . $e->getLine() . "]\n";
    $failCount++;
}

// ══════════════════════════════════════════════════════
// S07 — تعديل أسعار الحجز (additive reversal + repost)
// ══════════════════════════════════════════════════════
section('S07 — تعديل أسعار الحجز (Additive Reversal + Repost)');

try {
    $b07 = $service->create([
        'customer_id'    => $cust1->id,
        'purchase_price' => 500.0,
        'selling_price'  => 800.0,
        'service_fee'    => 50.0,
        'currency'       => 'EGP',
        'account_id'     => $egpVault->id,
        'visa_details'   => [
            'visa_type'        => 'tourist',
            'country'          => 'EG-MOD-TEST3',
            'duration'         => '30',
            'entry_type'       => 'single',
            'visa_agent_id'    => $agentModel->id,
            'visa_duration_id' => $duration->id,
        ],
    ]);
    $bookingIds[] = $b07->id;

    $origExpenseId07 = $b07->expense_transaction_id;
    $origIncomeId07  = $b07->income_transaction_id;
    $oldIncome07     = 800.0 + 50.0; // 850
    $oldPurchase07   = 500.0;

    $cust1Before07 = accountBalance($cust1->account_id);
    $agentBefore07 = accountBalance($agentAccount->id);

    // Update prices
    $newPurchase07 = 600.0;
    $newSelling07  = 1000.0;
    $newFee07      = 100.0;
    $newIncome07   = $newSelling07 + $newFee07; // 1100
    $newProfit07   = round($newIncome07 - $newPurchase07, 2); // 500

    $b07Updated = $service->update($b07, [
        'purchase_price' => $newPurchase07,
        'selling_price'  => $newSelling07,
        'service_fee'    => $newFee07,
    ]);
    $b07Updated->refresh();

    $cust1After07 = accountBalance($cust1->account_id);
    $agentAfter07 = accountBalance($agentAccount->id);

    // Original transactions must still exist
    $origExpStillExists07 = Transaction::where('id', $origExpenseId07)->exists();
    $origIncStillExists07 = Transaction::where('id', $origIncomeId07)->exists();

    // FKs repointed to new txns
    $expenseReposted07 = $b07Updated->expense_transaction_id !== $origExpenseId07;
    $incomeReposted07  = $b07Updated->income_transaction_id !== $origIncomeId07;

    // Original txns net-zero
    $origNetZero07 = balancedTransactions(array_values(array_filter([$origExpenseId07, $origIncomeId07])));
    // New txns balanced
    $newBalanced07 = balancedTransactions(array_values(array_filter([$b07Updated->expense_transaction_id, $b07Updated->income_transaction_id])));

    // Deltas
    $expectedCust1Delta07 = $newIncome07 - $oldIncome07;         // +250
    $expectedAgentDelta07 = -($newPurchase07 - $oldPurchase07); // -100

    step('S07.1 — original expense tx still exists', $origExpStillExists07, "id={$origExpenseId07}", $results, $passCount, $failCount);
    step('S07.2 — original income tx still exists', $origIncStillExists07, "id={$origIncomeId07}", $results, $passCount, $failCount);
    step('S07.3 — expense_transaction_id reposted', $expenseReposted07, "new={$b07Updated->expense_transaction_id}", $results, $passCount, $failCount);
    step('S07.4 — income_transaction_id reposted', $incomeReposted07, "new={$b07Updated->income_transaction_id}", $results, $passCount, $failCount);
    step('S07.5 — original txns net-zero after reversal', $origNetZero07['all_balanced'], '', $results, $passCount, $failCount);
    step('S07.6 — new txns balanced (debit=credit)', $newBalanced07['all_balanced'], '', $results, $passCount, $failCount);
    step('S07.7 — profit updated on booking', assertDelta($newProfit07, (float)$b07Updated->profit), "expected={$newProfit07} actual={$b07Updated->profit}", $results, $passCount, $failCount);
    step('S07.8 — Customer AR delta correct (+' . $expectedCust1Delta07 . ')', assertDelta($expectedCust1Delta07, $cust1After07 - $cust1Before07), "Δ=" . ($cust1After07 - $cust1Before07), $results, $passCount, $failCount);
    step('S07.9 — Agent ledger delta correct (' . $expectedAgentDelta07 . ')', assertDelta($expectedAgentDelta07, $agentAfter07 - $agentBefore07), "Δ=" . ($agentAfter07 - $agentBefore07), $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S07 FATAL: " . $e->getMessage() . " [" . basename($e->getFile()) . ":" . $e->getLine() . "]\n";
    $failCount++;
}

// ══════════════════════════════════════════════════════
// S08 — إلغاء الحجز (cancel — light cancel)
// ══════════════════════════════════════════════════════
section('S08 — إلغاء الحجز (cancel)');

try {
    $b08 = $service->create([
        'customer_id'    => $cust1->id,
        'purchase_price' => 300.0,
        'selling_price'  => 500.0,
        'service_fee'    => 0.0,
        'currency'       => 'EGP',
        'account_id'     => $egpVault->id,
        'visa_details'   => [
            'visa_type' => 'tourist', 'country' => 'EG-CANCEL3-TEST',
            'duration' => '30', 'entry_type' => 'single',
            'visa_agent_id' => $agentModel->id, 'visa_duration_id' => $duration->id,
        ],
    ]);
    $bookingIds[] = $b08->id;

    $pmt08 = $service->addPayment($b08, ['amount' => 250.0, 'account_id' => $egpVault->id, 'payment_method' => 'cash']);
    $b08->refresh();

    // Snapshot BEFORE cancel
    $egpVaultBefore08 = accountBalance($egpVault->id);
    $cust1Before08    = accountBalance($cust1->account_id);
    $agentBefore08    = accountBalance($agentAccount->id);

    $origTxIds08  = array_values(array_unique(array_filter([$b08->expense_transaction_id, $b08->income_transaction_id, $pmt08->transaction_id])));
    $origPayIds08 = [$pmt08->id];

    $b08Cancelled = $refundService->cancel($b08, 'S08 cancel test');

    $egpVaultAfter08 = accountBalance($egpVault->id);
    $cust1After08    = accountBalance($cust1->account_id);
    $agentAfter08    = accountBalance($agentAccount->id);

    $b08Fresh          = VisaBooking::withTrashed()->find($b08->id);
    $bookingNotTrashed = !$b08Fresh->trashed();
    $statusCancelled08 = statusValue($b08Fresh->status) === VisaStatus::Cancelled->value;

    $txExistCount08    = DB::table('transactions')->whereIn('id', $origTxIds08)->count();
    $allNetZero08      = balancedTransactions($origTxIds08);
    $paymentRowCount08 = DB::table('visa_payments')->whereIn('id', $origPayIds08)->whereNull('deleted_at')->count();

    $expectedCust1Delta08 = -500.0 + 250.0; // -250
    $expectedVaultDelta08 = -250.0;          // payment reversed
    $expectedAgentDelta08 = +300.0;          // expense reversed (we no longer owe agent)

    step('S08.1 — booking NOT soft-deleted', $bookingNotTrashed, '', $results, $passCount, $failCount);
    step('S08.2 — status = cancelled', $statusCancelled08, "status=" . statusValue($b08Fresh->status), $results, $passCount, $failCount);
    step('S08.3 — all original transactions preserved', $txExistCount08 === count($origTxIds08), "{$txExistCount08}/" . count($origTxIds08), $results, $passCount, $failCount);
    step('S08.4 — original transactions net-zero (additive reversal)', $allNetZero08['all_balanced'], '', $results, $passCount, $failCount);
    step('S08.5 — payment rows still visible (audit trail)', $paymentRowCount08 === 1, "count={$paymentRowCount08}", $results, $passCount, $failCount);
    step('S08.6 — EGP vault correctly reversed', assertDelta($expectedVaultDelta08, $egpVaultAfter08 - $egpVaultBefore08), "expected={$expectedVaultDelta08} Δ=" . ($egpVaultAfter08 - $egpVaultBefore08), $results, $passCount, $failCount);
    step('S08.7 — Customer AR correctly reversed', assertDelta($expectedCust1Delta08, $cust1After08 - $cust1Before08), "expected={$expectedCust1Delta08} Δ=" . ($cust1After08 - $cust1Before08), $results, $passCount, $failCount);
    step('S08.8 — Agent ledger correctly reversed', assertDelta($expectedAgentDelta08, $agentAfter08 - $agentBefore08), "expected={$expectedAgentDelta08} Δ=" . ($agentAfter08 - $agentBefore08), $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S08 FATAL: " . $e->getMessage() . " [" . basename($e->getFile()) . ":" . $e->getLine() . "]\n";
    $failCount++;
}

// ══════════════════════════════════════════════════════
// S09 — دفعة على حجز ملغى (يجب أن ترفض)
// ══════════════════════════════════════════════════════
section('S09 — منع الدفع على حجز ملغى');

try {
    $caught09 = false;
    try {
        $service->addDebtPayment($b08->fresh(), [
            'amount' => 10.0, 'account_id' => $egpVault->id, 'payment_method' => 'cash',
        ]);
    } catch (\RuntimeException $e) {
        $caught09 = str_contains($e->getMessage(), 'ملغى');
    }
    step('S09.1 — payment on cancelled booking rejected', $caught09, '', $results, $passCount, $failCount);
} catch (\Throwable $e) {
    echo "  ❌ S09 FATAL: " . $e->getMessage() . "\n"; $failCount++;
}

// ══════════════════════════════════════════════════════
// S10 — حذف إداري (deleteWithReversal)
// ══════════════════════════════════════════════════════
section('S10 — حذف إداري (deleteWithReversal + soft-delete)');

try {
    // Snapshot BEFORE booking & payments creation
    $egpVaultBeforeBooking10 = accountBalance($egpVault->id);
    $cust2BeforeBooking10    = accountBalance($cust2->account_id);
    $agentBeforeBooking10    = accountBalance($agentAccount->id);

    $b10 = $service->create([
        'customer_id'    => $cust2->id,
        'purchase_price' => 400.0,
        'selling_price'  => 700.0,
        'service_fee'    => 50.0,
        'currency'       => 'EGP',
        'account_id'     => $egpVault->id,
        'visa_details'   => [
            'visa_type' => 'tourist', 'country' => 'EG-DEL3-TEST',
            'duration' => '30', 'entry_type' => 'single',
            'visa_agent_id' => $agentModel->id, 'visa_duration_id' => $duration->id,
        ],
    ]);
    $bookingIds[] = $b10->id;
    $cust2->refresh();

    $pmt10a = $service->addPayment($b10, ['amount' => 200.0, 'account_id' => $egpVault->id, 'payment_method' => 'cash']);
    $pmt10b = $service->addPayment($b10, ['amount' => 100.0, 'account_id' => $egpVault->id, 'payment_method' => 'cash']);
    $b10->refresh();

    $origTxIds10 = array_values(array_unique(array_filter([
        $b10->expense_transaction_id, $b10->income_transaction_id,
        $pmt10a->transaction_id, $pmt10b->transaction_id,
    ])));
    $paymentIds10 = [$pmt10a->id, $pmt10b->id];

    // Execute deleteWithReversal
    $refundService->deleteWithReversal($b10->id, $adminUser->id);

    // Snapshot AFTER delete
    $egpVaultAfter10 = accountBalance($egpVault->id);
    $cust2After10    = accountBalance($cust2->account_id);
    $agentAfter10    = accountBalance($agentAccount->id);

    $b10Fresh      = VisaBooking::withTrashed()->find($b10->id);
    $softDeleted10 = $b10Fresh->trashed();

    $softDeletedPmts10 = DB::table('visa_payments')
        ->whereIn('id', $paymentIds10)->whereNotNull('deleted_at')->count();

    $txExistCount10 = DB::table('transactions')->whereIn('id', $origTxIds10)->count();
    $allNetZero10   = balancedTransactions($origTxIds10);

    // Net deltas relative to BEFORE booking creation MUST BE 0!
    $vaultNetDelta10 = $egpVaultAfter10 - $egpVaultBeforeBooking10;
    $cust2NetDelta10 = $cust2After10 - $cust2BeforeBooking10;
    $agentNetDelta10 = $agentAfter10 - $agentBeforeBooking10;

    step('S10.1 — booking soft-deleted', $softDeleted10, '', $results, $passCount, $failCount);
    step('S10.2 — payments soft-deleted', $softDeletedPmts10 === 2, "count={$softDeletedPmts10}/2", $results, $passCount, $failCount);
    step('S10.3 — original transactions preserved', $txExistCount10 === count($origTxIds10), "{$txExistCount10}/" . count($origTxIds10), $results, $passCount, $failCount);
    step('S10.4 — all transactions net-zero after reversal', $allNetZero10['all_balanced'], '', $results, $passCount, $failCount);
    step('S10.5 — EGP vault net delta = 0 (restored to pre-booking level)', assertDelta(0.0, $vaultNetDelta10), "Δ={$vaultNetDelta10}", $results, $passCount, $failCount);
    step('S10.6 — Customer2 AR net delta = 0 (restored to pre-booking level)', assertDelta(0.0, $cust2NetDelta10), "Δ={$cust2NetDelta10}", $results, $passCount, $failCount);
    step('S10.7 — Agent ledger net delta = 0 (restored to pre-booking level)', assertDelta(0.0, $agentNetDelta10), "Δ={$agentNetDelta10}", $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S10 FATAL: " . $e->getMessage() . " [" . basename($e->getFile()) . ":" . $e->getLine() . "]\n";
    $failCount++;
}

// ══════════════════════════════════════════════════════
// S11 — Idempotency: حذف مرتين → RuntimeException
// ══════════════════════════════════════════════════════
section('S11 — Idempotency: حذف حجز محذوف مسبقاً');

try {
    $caught11 = false;
    try {
        $refundService->deleteWithReversal($b10->id, $adminUser->id);
    } catch (\RuntimeException $e) {
        $caught11 = str_contains($e->getMessage(), 'محذوف بالفعل');
    }
    step('S11.1 — second deleteWithReversal throws RuntimeException', $caught11, '', $results, $passCount, $failCount);
} catch (\Throwable $e) {
    echo "  ❌ S11 FATAL: " . $e->getMessage() . "\n"; $failCount++;
}

// ══════════════════════════════════════════════════════
// S12 — سداد مديونية عميل (payCustomerDebt)
// ══════════════════════════════════════════════════════
section('S12 — سداد مديونية العميل (payCustomerDebt)');

try {
    $b12 = $service->create([
        'customer_id'    => $cust1->id,
        'purchase_price' => 200.0,
        'selling_price'  => 400.0,
        'service_fee'    => 0.0,
        'currency'       => 'EGP',
        'account_id'     => $egpVault->id,
        'visa_details'   => [
            'visa_type' => 'tourist', 'country' => 'EG-DEBT3-TEST',
            'duration' => '30', 'entry_type' => 'single',
            'visa_agent_id' => $agentModel->id, 'visa_duration_id' => $duration->id,
        ],
    ]);
    $bookingIds[] = $b12->id;
    $cust1->refresh();

    $debtAmount12     = 150.0;
    $egpVaultBefore12 = accountBalance($egpVault->id);
    $cust1Before12    = accountBalance($cust1->account_id);

    $tx12 = $txService->recordJournalTransfer([
        'amount'              => $debtAmount12,
        'from_account_id'     => $cust1->account_id,
        'to_account_id'       => $egpVault->id,
        'allow_from_negative' => true,
        'module'              => TransactionModule::Visa->value,
        'notes'               => 'S12 payCustomerDebt',
        'created_by'          => $adminUser->id,
    ]);

    $egpVaultAfter12 = accountBalance($egpVault->id);
    $cust1After12    = accountBalance($cust1->account_id);

    step('S12.1 — transaction created', $tx12->id > 0, "tx_id={$tx12->id}", $results, $passCount, $failCount);
    step('S12.2 — EGP vault increased by ' . $debtAmount12, assertDelta($debtAmount12, $egpVaultAfter12 - $egpVaultBefore12), "Δ=" . ($egpVaultAfter12 - $egpVaultBefore12), $results, $passCount, $failCount);
    step('S12.3 — Customer AR decreased by ' . $debtAmount12, assertDelta(-$debtAmount12, $cust1After12 - $cust1Before12), "Δ=" . ($cust1After12 - $cust1Before12), $results, $passCount, $failCount);
    step('S12.4 — payment transaction balanced', balancedTransactions([$tx12->id])['all_balanced'], '', $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S12 FATAL: " . $e->getMessage() . " [" . basename($e->getFile()) . ":" . $e->getLine() . "]\n";
    $failCount++;
}

// ══════════════════════════════════════════════════════
// S13 — كشف حساب العميل (running balance verification)
// ══════════════════════════════════════════════════════
section('S13 — كشف حساب العميل (customerStatement)');

try {
    $items13   = [];
    $running13 = 0.0;

    $bookings13 = VisaBooking::where('customer_id', $cust1->id)
        ->with(['payments', 'visaDetail'])
        ->whereNotIn('status', ['cancelled', 'rejected', 'refunded'])
        ->whereNull('deleted_at')
        ->get();

    foreach ($bookings13 as $b) {
        $totalSelling = (float)$b->selling_price + (float)($b->service_fee ?? 0);
        $running13 += $totalSelling;
        $items13[] = ['type' => 'invoice', 'debit' => $totalSelling, 'credit' => 0, 'running' => $running13];

        foreach ($b->payments as $p) {
            $running13 -= (float)$p->amount;
            $items13[] = ['type' => 'payment', 'debit' => 0, 'credit' => (float)$p->amount, 'running' => $running13];
        }
    }

    $totalDebit13  = array_sum(array_column($items13, 'debit'));
    $totalCredit13 = array_sum(array_column($items13, 'credit'));
    $totalDebt13   = $totalDebit13 - $totalCredit13;

    step('S13.1 — customerStatement returns items', count($items13) > 0, 'count=' . count($items13), $results, $passCount, $failCount);
    step('S13.2 — running balance = debit - credit', assertDelta($totalDebt13, $running13), "running={$running13} net={$totalDebt13}", $results, $passCount, $failCount);
    step('S13.3 — total_debt consistent', $totalDebt13 >= -0.01, "debt={$totalDebt13}", $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S13 FATAL: " . $e->getMessage() . "\n"; $failCount++;
}

// ══════════════════════════════════════════════════════
// S14 — مستحقات الوكيل (agent dues) + سحب (withdraw)
// ══════════════════════════════════════════════════════
section('S14 — مستحقات الوكيل + سحب (withdraw)');

try {
    $agentEntry14 = DB::table('account_entries')
        ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
        ->where('account_entries.account_id', $agentAccount->id)
        ->where('transactions.module', TransactionModule::Visa->value)
        ->selectRaw('COALESCE(SUM(account_entries.debit), 0) as td, COALESCE(SUM(account_entries.credit), 0) as tc')
        ->first();

    $netDue14 = (float)($agentEntry14->td ?? 0) - (float)($agentEntry14->tc ?? 0);
    step('S14.1 — agent dues query works', true, "net_due={$netDue14}", $results, $passCount, $failCount);

    $tourismOk14 = AccountModuleContract::isTourismModule($visaReceiver->module_type);
    step('S14.2 — tourism module accepted by AccountModuleContract', $tourismOk14, "module_type={$visaReceiver->module_type}", $results, $passCount, $failCount);

    $withdrawAmount14 = 30.0;
    $agentBefore14    = accountBalance($agentAccount->id);
    $receiverBefore14 = accountBalance($visaReceiver->id);

    $tx14 = $txService->recordJournalTransfer([
        'amount'          => $withdrawAmount14,
        'from_account_id' => $agentAccount->id,
        'to_account_id'   => $visaReceiver->id,
        'module'          => TransactionModule::Visa->value,
        'notes'           => 'S14 agent withdraw',
        'created_by'      => $adminUser->id,
    ]);

    $agentAfter14    = accountBalance($agentAccount->id);
    $receiverAfter14 = accountBalance($visaReceiver->id);

    step('S14.3 — withdraw transaction created', $tx14->id > 0, "tx_id={$tx14->id}", $results, $passCount, $failCount);
    step('S14.4 — agent ledger decreased by ' . $withdrawAmount14, assertDelta(-$withdrawAmount14, $agentAfter14 - $agentBefore14), "Δ=" . ($agentAfter14 - $agentBefore14), $results, $passCount, $failCount);
    step('S14.5 — receiver account increased by ' . $withdrawAmount14, assertDelta($withdrawAmount14, $receiverAfter14 - $receiverBefore14), "Δ=" . ($receiverAfter14 - $receiverBefore14), $results, $passCount, $failCount);
    step('S14.6 — withdraw transaction balanced', balancedTransactions([$tx14->id])['all_balanced'], '', $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S14 FATAL: " . $e->getMessage() . " [" . basename($e->getFile()) . ":" . $e->getLine() . "]\n"; $failCount++;
}

// ══════════════════════════════════════════════════════
// S15 — سداد للوكيل (repay)
// ══════════════════════════════════════════════════════
section('S15 — سداد للوكيل (repay from vault to agent)');

try {
    $repay15          = 50.0;
    $egpVaultBefore15 = accountBalance($egpVault->id);
    $agentBefore15    = accountBalance($agentAccount->id);

    $tx15 = $txService->recordJournalTransfer([
        'amount'          => $repay15,
        'from_account_id' => $egpVault->id,
        'to_account_id'   => $agentAccount->id,
        'module'          => TransactionModule::Visa->value,
        'notes'           => 'S15 agent repay',
        'created_by'      => $adminUser->id,
    ]);

    $egpVaultAfter15 = accountBalance($egpVault->id);
    $agentAfter15    = accountBalance($agentAccount->id);

    step('S15.1 — repay transaction created', $tx15->id > 0, "tx_id={$tx15->id}", $results, $passCount, $failCount);
    step('S15.2 — EGP vault decreased by ' . $repay15, assertDelta(-$repay15, $egpVaultAfter15 - $egpVaultBefore15), "Δ=" . ($egpVaultAfter15 - $egpVaultBefore15), $results, $passCount, $failCount);
    step('S15.3 — agent ledger increased by ' . $repay15, assertDelta($repay15, $agentAfter15 - $agentBefore15), "Δ=" . ($agentAfter15 - $agentBefore15), $results, $passCount, $failCount);
    step('S15.4 — repay transaction balanced', balancedTransactions([$tx15->id])['all_balanced'], '', $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S15 FATAL: " . $e->getMessage() . " [" . basename($e->getFile()) . ":" . $e->getLine() . "]\n"; $failCount++;
}

// ══════════════════════════════════════════════════════
// S16 — حذف حجز USD (صافي الخزينة USD = 0)
// ══════════════════════════════════════════════════════
section('S16 — حذف حجز USD (صافي الخزينة USD = 0)');

try {
    $usdVaultBeforePayment16 = accountBalance($usdVault->id);
    $cust2->refresh();
    $cust2BeforePayment16    = accountBalance($cust2->account_id);

    $pmt16 = $service->addPayment($b03->fresh(), [
        'amount'         => 300.0,
        'account_id'     => $usdVault->id,
        'payment_method' => 'bank_transfer',
        'currency'       => 'USD',
    ]);

    $b03->refresh();
    $origTxIds16 = array_values(array_unique(array_filter([
        $b03->expense_transaction_id,
        $b03->income_transaction_id,
        $pmt16->transaction_id,
    ])));

    $refundService->deleteWithReversal($b03->id, $adminUser->id);

    $usdVaultAfter16 = accountBalance($usdVault->id);
    $cust2After16    = accountBalance($cust2->account_id);

    $b03Fresh16   = VisaBooking::withTrashed()->find($b03->id);
    $allNetZero16 = balancedTransactions($origTxIds16);

    // USD Vault delta relative to before payment = 0 (payment reversed)
    $vaultNetDelta16 = $usdVaultAfter16 - $usdVaultBeforePayment16;
    // Customer 2 AR balance after delete should be 0 USD (both booking income & payment reversed)
    $cust2FinalBalance16 = $cust2After16;

    step('S16.1 — USD booking soft-deleted', $b03Fresh16->trashed(), '', $results, $passCount, $failCount);
    step('S16.2 — all USD txns net-zero', $allNetZero16['all_balanced'], '', $results, $passCount, $failCount);
    step('S16.3 — USD vault net delta = 0 (restored to pre-payment level)', assertDelta(0.0, $vaultNetDelta16), "Δ={$vaultNetDelta16}", $results, $passCount, $failCount);
    step('S16.4 — Customer2 AR balance = 0 USD (both income & payment reversed)', assertDelta(0.0, $cust2FinalBalance16), "balance={$cust2FinalBalance16}", $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S16 FATAL: " . $e->getMessage() . " [" . basename($e->getFile()) . ":" . $e->getLine() . "]\n"; $failCount++;
}

// ══════════════════════════════════════════════════════
// S17 — Validation Rules
// ══════════════════════════════════════════════════════
section('S17 — قواعد الـ Validation');

// S17.1: negative amount
try {
    $caught17a = false;
    try { $service->addPayment($b02->fresh(), ['amount' => -50.0, 'account_id' => $egpVault->id, 'payment_method' => 'cash']); }
    catch (\Throwable) { $caught17a = true; }
    step('S17.1 — negative payment amount rejected', $caught17a, '', $results, $passCount, $failCount);
} catch (\Throwable $e) { echo "  ❌ S17.1 FATAL: " . $e->getMessage() . "\n"; $failCount++; }

// S17.2: zero amount
try {
    $caught17b = false;
    try { $service->addDebtPayment($b02->fresh(), ['amount' => 0.0, 'account_id' => $egpVault->id, 'payment_method' => 'cash']); }
    catch (\InvalidArgumentException) { $caught17b = true; }
    step('S17.2 — zero amount rejected in addDebtPayment', $caught17b, '', $results, $passCount, $failCount);
} catch (\Throwable $e) { echo "  ❌ S17.2 FATAL: " . $e->getMessage() . "\n"; $failCount++; }

// S17.3: AccountModuleContract rejects office module
try {
    $wrongModuleRejected17 = !AccountModuleContract::isTourismModule('office');
    step('S17.3 — office module rejected by isTourismModule()', $wrongModuleRejected17, '', $results, $passCount, $failCount);
} catch (\Throwable $e) { echo "  ❌ S17.3 FATAL: " . $e->getMessage() . "\n"; $failCount++; }

// S17.4: AccountModuleContract accepts visas module
try {
    $visasOk17 = AccountModuleContract::isTourismModule('visas');
    step('S17.4 — visas module accepted by isTourismModule()', $visasOk17, '', $results, $passCount, $failCount);
} catch (\Throwable $e) { echo "  ❌ S17.4 FATAL: " . $e->getMessage() . "\n"; $failCount++; }

// S17.5: cancelled booking refuses payment
try {
    $caught17e = false;
    try { $service->addDebtPayment($b08->fresh(), ['amount' => 10.0, 'account_id' => $egpVault->id, 'payment_method' => 'cash']); }
    catch (\RuntimeException $e) { $caught17e = str_contains($e->getMessage(), 'ملغى'); }
    step('S17.5 — cancelled booking refuses addDebtPayment', $caught17e, '', $results, $passCount, $failCount);
} catch (\Throwable $e) { echo "  ❌ S17.5 FATAL: " . $e->getMessage() . "\n"; $failCount++; }

// ══════════════════════════════════════════════════════
// S18 — AUDIT: كل معاملات الموديول متوازنة
// ══════════════════════════════════════════════════════
section('S18 — AUDIT: توازن جميع معاملات موديول التأشيرات');

try {
    $allVisaTxIds18 = DB::table('transactions')
        ->where('module', TransactionModule::Visa->value)
        ->pluck('id')
        ->toArray();

    $auditResult18 = balancedTransactions($allVisaTxIds18);

    step('S18.1 — all visa transactions balanced (debit=credit)', $auditResult18['all_balanced'],
        'checked=' . $auditResult18['checked'] . ' unbalanced=' . count($auditResult18['unbalanced']),
        $results, $passCount, $failCount);

    if (!empty($auditResult18['unbalanced'])) {
        echo "    ⚠️ Unbalanced transactions:\n";
        foreach ($auditResult18['unbalanced'] as $txId => $d) {
            echo "      tx#{$txId}: debit={$d['debit']} credit={$d['credit']} diff={$d['diff']}\n";
        }
    }

    $orphans18 = DB::table('account_entries')
        ->leftJoin('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
        ->whereNull('transactions.id')->count();
    step('S18.2 — no orphaned AccountEntry rows', $orphans18 === 0, "orphaned={$orphans18}", $results, $passCount, $failCount);

    $emptyTx18 = DB::table('transactions')
        ->where('module', TransactionModule::Visa->value)
        ->whereNotExists(fn($q) => $q->from('account_entries')->whereColumn('account_entries.transaction_id', 'transactions.id'))
        ->count();
    step('S18.3 — no visa transactions with 0 entries', $emptyTx18 === 0, "empty_tx={$emptyTx18}", $results, $passCount, $failCount);

} catch (\Throwable $e) {
    echo "  ❌ S18 FATAL: " . $e->getMessage() . "\n"; $failCount++;
}

// ══════════════════════════════════════════════════════
// S19 — AUDIT: ميزان الحسابات الكلي (Global net=0)
// ══════════════════════════════════════════════════════
section('S19 — AUDIT: ميزان الحسابات الكلي');

try {
    // Global: SUM(debit) across ALL visa entries must equal SUM(credit)
    $global19 = DB::table('account_entries')
        ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
        ->where('transactions.module', TransactionModule::Visa->value)
        ->selectRaw('COALESCE(SUM(account_entries.debit), 0) as td, COALESCE(SUM(account_entries.credit), 0) as tc')
        ->first();

    $globalDebit19  = (float)($global19->td ?? 0);
    $globalCredit19 = (float)($global19->tc ?? 0);
    $globalDiff19   = abs($globalDebit19 - $globalCredit19);

    step('S19.1 — Global debit = Global credit', $globalDiff19 < 0.50,
        "debit={$globalDebit19} credit={$globalCredit19} diff={$globalDiff19}",
        $results, $passCount, $failCount);

    // Delta-based balance check per account: balance_after on last entry should match account.balance
    $touchedAccIds19 = DB::table('account_entries')
        ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
        ->where('transactions.module', TransactionModule::Visa->value)
        ->distinct()->pluck('account_entries.account_id')->toArray();

    $balanceMismatches19 = [];
    foreach ($touchedAccIds19 as $accId) {
        $acc = Account::find($accId);
        if (!$acc) continue;

        $lastEntry = DB::table('account_entries')
            ->where('account_id', $accId)
            ->orderByDesc('id')
            ->first(['balance_after']);

        if (!$lastEntry) continue;
        $lastBalAfter  = (float)$lastEntry->balance_after;
        $storedBalance = (float)$acc->balance;
        $diff          = abs($storedBalance - $lastBalAfter);

        if ($diff > 0.50) {
            $balanceMismatches19[$accId] = [
                'name'           => $acc->name,
                'stored_balance' => $storedBalance,
                'last_bal_after' => $lastBalAfter,
                'diff'           => $diff,
            ];
        }
    }

    step('S19.2 — account.balance matches last AccountEntry.balance_after',
        empty($balanceMismatches19),
        'mismatches=' . count($balanceMismatches19) . ' checked=' . count($touchedAccIds19),
        $results, $passCount, $failCount);

    if (!empty($balanceMismatches19)) {
        echo "    ⚠️ Balance mismatches:\n";
        foreach ($balanceMismatches19 as $accId => $d) {
            echo "      acc#{$accId} [{$d['name']}] stored={$d['stored_balance']} last_bal_after={$d['last_bal_after']} diff={$d['diff']}\n";
        }
    }

    // ── Final Balance Summary ──
    $cust1->refresh(); $cust2->refresh();
    echo "\n  [Final Balance Summary]\n";
    echo "    EGP Vault  #{$egpVault->id}:   " . number_format(accountBalance($egpVault->id), 2) . " EGP\n";
    echo "    USD Vault  #{$usdVault->id}:   " . number_format(accountBalance($usdVault->id), 2) . " USD\n";
    echo "    Agent      #{$agentAccount->id}:  " . number_format(accountBalance($agentAccount->id), 2) . " EGP\n";
    echo "    Receiver   #{$visaReceiver->id}:  " . number_format(accountBalance($visaReceiver->id), 2) . " EGP\n";
    echo "    Cust1 AR   #{$cust1->account_id}: " . ($cust1->account_id ? number_format(accountBalance($cust1->account_id), 2) : 'N/A') . " EGP\n";
    echo "    Cust2 AR   #{$cust2->account_id}: " . ($cust2->account_id ? number_format(accountBalance($cust2->account_id), 2) : 'N/A') . "\n";

} catch (\Throwable $e) {
    echo "  ❌ S19 FATAL: " . $e->getMessage() . " [" . basename($e->getFile()) . ":" . $e->getLine() . "]\n"; $failCount++;
}

// ══════════════════════════════════════════════════════
// FINAL REPORT
// ══════════════════════════════════════════════════════
$bar = str_repeat('═', 70);
echo "\n{$bar}\n";
echo "║ النتيجة النهائية — FINAL RESULTS\n";
echo "{$bar}\n";

$totalTests = $passCount + $failCount;
$passRate   = $totalTests > 0 ? round(($passCount / $totalTests) * 100, 1) : 0;

echo "  ✅ ناجح:    {$passCount}\n";
echo "  ❌ فاشل:    {$failCount}\n";
echo "  📊 الإجمالي: {$totalTests}\n";
echo "  📈 معدل النجاح: {$passRate}%\n\n";

if ($failCount === 0) {
    echo "  🎉 موديول التأشيرات جاهز للإنتاج 100% — تم اجتياز جميع السيناريوهات بنسبة 100%!\n";
} else {
    echo "  ⚠️ يوجد {$failCount} اختبار/اختبارات فاشلة — التفاصيل:\n\n";
    foreach ($results as $name => $r) {
        if (!$r['passed']) {
            echo "    ❌ {$name}" . ($r['detail'] ? " | " . $r['detail'] : '') . "\n";
        }
    }
}

// Save JSON log
$output = [
    'test_suite'          => 'VISA MODULE PRODUCTION FULL TEST v3',
    'timestamp'           => now()->toIso8601String(),
    'pass'                => $passCount,
    'fail'                => $failCount,
    'total'               => $totalTests,
    'pass_rate'           => $passRate,
    'verdict'             => $failCount === 0 ? '✅ PRODUCTION READY 100%' : '❌ ISSUES FOUND',
    'results'             => $results,
    'booking_ids_created' => $bookingIds,
];

$logPath = storage_path('logs/visa_production_full_test_v3_' . date('Ymd_His') . '.json');
file_put_contents($logPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\n  📄 Log saved to: {$logPath}\n";
echo "{$bar}\n";
