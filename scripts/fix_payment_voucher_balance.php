<?php

/**
 * Phase 1 — Bug TX-201: تصحيح رصيد العملاء بعد سند صرف معكوس.
 *
 * السيناريو:
 *   - رصيد العميل = مديونية (AR): موجب = العميل يدين لنا.
 *   - `CustomerController::payDebt()` بنوع `payment` (سند صرف) كان يبادل
 *     from/to فيصدر قيد يزيد رصيد العميل بدل ما ينقصه.
 *   - النتيجة: رصيد العميل صار = رصيد_صحيح + (2 × amount) بدلاً من
 *     رصيد_صحيح - amount.
 *
 * مثال خلف الاعصر:
 *   - قبل السند: 119,047 EGP
 *   - سند صرف 50,000 (البق): 119,047 + 50,000 = 169,047 EGP (غلط)
 *   - ما يجب: 119,047 - 50,000 = 69,047 EGP (صح)
 *   - فرق التصحيح: 169,047 - 69,047 = 100,000 EGP = 2 × 50,000
 *
 * الاستخدام:
 *   --dry-run                    عرض المتأثرين بدون تعديل (الافتراضي)
 *   --apply                      تطبيق التصحيح فعلياً
 *   --customer="خلف الاعصر"      تقييد على عميل واحد (مطابقة LIKE)
 *   --customer-id=123            تقييد على customer_id محدد
 *   --since="2026-08-01"         تاريخ بداية البحث عن القيود البقيّة (افتراضي: قبل 30 يوم)
 *   --yes                        تخطي التأكيد التفاعلي
 *
 * آمن بطبيعته:
 *   - بدون --apply: لا يكتب شيئاً
 *   - مع --apply: يعرض الخطة وينتظر التأكيد (إلا مع --yes)
 *   - مع --apply: كل شيء داخل DB::transaction مع rollback عند أي خطأ
 *   - يكتب AccountEntry جديدة بـ debit/credit (append-only) فلا يخرق قاعدة
 *     الثبات المالي في `AccountEntry` (الـ model يحذّر صراحةً من حذف/تعديل
 *     القيود الموجودة).
 *
 * تشغيل:
 *   php scripts/fix_payment_voucher_balance.php --dry-run
 *   php scripts/fix_payment_voucher_balance.php --apply --customer="خلف الاعصر"
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;

// ───── CLI args ─────
$opts = getopt('', ['dry-run', 'apply', 'customer::', 'customer-id::', 'since::', 'yes']);
$apply = isset($opts['apply']);
$dryRun = isset($opts['dry-run']) || !$apply; // default safe
$yes = isset($opts['yes']);
$customerName = isset($opts['customer']) ? (string) $opts['customer'] : null;
$customerId = isset($opts['customer-id']) ? (int) $opts['customer-id'] : null;
$since = isset($opts['since'])
    ? (string) $opts['since']
    : now()->subDays(30)->toDateTimeString();

$mode = $apply ? 'APPLY' : 'DRY-RUN';
echo "─────────────────────────────────────────────────────────────\n";
echo "  سكريبت تصحيح أرصدة العملاء بعد سند صرف معكوس (TX-201)\n";
echo "  الوضع: {$mode}\n";
echo "  منذ: {$since}\n";
if ($customerName) echo "  تقييد بالاسم: {$customerName}\n";
if ($customerId)   echo "  تقييد بالمعرّف: {$customerId}\n";
echo "─────────────────────────────────────────────────────────────\n\n";

// ───── Find affected transactions ─────
// Criteria:
//   - notes contains "سند صرف" or "إرجاع مبلغ العميل" or "دفع للعميل"
//   - created at or after `$since`
//   - to_account_id is a customer account
//   - from_account_id is a treasury/bank (we paid FROM the treasury)
//   - amount > 0
//
// Note: with the buggy code, to_account_id = customer, from_account_id = treasury
// With the corrected code, BOTH directions would have to_account_id = treasury,
// so this query naturally excludes the corrected ones.
$query = Transaction::query()
    ->where('created_at', '>=', $since)
    ->where(function ($q) {
        $q->where('notes', 'like', '%سند صرف%')
          ->orWhere('notes', 'like', '%إرجاع مبلغ العميل%')
          ->orWhere('notes', 'like', '%دفع للعميل%');
    })
    ->where('amount', '>', 0)
    ->whereNotNull('to_account_id')
    ->whereNotNull('from_account_id');

$candidateTx = $query->orderBy('created_at', 'desc')->get();

if ($candidateTx->isEmpty()) {
    echo "لم يتم العثور على أي قيود سند صرف منذ {$since}.\n";
    echo "إذا كان التاريخ الافتراضي مبكراً، مرّر --since=\"YYYY-MM-DD\".\n";
    exit(0);
}

// Filter to those whose to_account_id is a customer account (i.e. the buggy direction)
$customerAccountIds = Customer::query()
    ->whereNotNull('account_id')
    ->pluck('account_id')
    ->all();
$customerAccountIdSet = array_flip($customerAccountIds);

$buggyTx = $candidateTx->filter(function (Transaction $tx) use ($customerAccountIdSet, $customerId, $customerName) {
    if (! isset($customerAccountIdSet[$tx->to_account_id])) {
        return false; // to is not a customer account → not a buggy payDebt
    }

    $customer = Customer::where('account_id', $tx->to_account_id)->first();
    if (! $customer) {
        return false;
    }

    if ($customerId && $customer->id !== $customerId) {
        return false;
    }

    if ($customerName && stripos((string) $customer->full_name, $customerName) === false) {
        return false;
    }

    // Attach for downstream use
    $tx->setAttribute('_customer', $customer);
    $tx->setAttribute('_customer_account_id', $tx->to_account_id);
    return true;
})->values();

if ($buggyTx->isEmpty()) {
    echo "لم يتم العثور على أي قيود سند صرف للعملاء المطابقين.\n";
    exit(0);
}

// ───── Group by customer for the report ─────
$grouped = [];
foreach ($buggyTx as $tx) {
    $customer = $tx->getAttribute('_customer');
    $accountId = $tx->getAttribute('_customer_account_id');
    $grouped[$accountId] ??= [
        'customer' => $customer,
        'account' => Account::find($accountId),
        'transactions' => [],
        'total_amount' => 0.0,
    ];
    $grouped[$accountId]['transactions'][] = $tx;
    $grouped[$accountId]['total_amount'] += (float) $tx->amount;
}

// ───── Print plan ─────
echo "سيتم تصحيح الحسابات التالية:\n\n";

$rows = [];
$totalFix = 0.0;
foreach ($grouped as $accountId => $info) {
    $account = $info['account'];
    $customer = $info['customer'];
    $amount = $info['total_amount'];
    // Bug made balance go UP by `amount` per tx instead of DOWN by `amount`.
    // Net error per tx: balance is off by +2×amount.
    // Per customer (could be multiple tx): fix = 2 × total_amount.
    $fix = round(2 * $amount, 2);
    $currentBalance = (float) $account->balance;
    $correctedBalance = round($currentBalance - $fix, 2);
    $totalFix += $fix;

    $rows[] = [
        'customer_id' => $customer->id,
        'customer_name' => $customer->full_name,
        'account_id' => $accountId,
        'current_balance' => $currentBalance,
        'tx_count' => count($info['transactions']),
        'total_amount' => $amount,
        'fix_amount' => $fix,
        'corrected_balance' => $correctedBalance,
        'tx_ids' => array_map(fn ($t) => $t->id, $info['transactions']),
    ];
}

printf("%-6s  %-30s  %-13s  %-12s  %-13s  %-13s  %-13s\n",
    'ID', 'اسم العميل', 'رصيد حالي', 'سندات صرف', 'مبلغ التصحيح', 'الرصيد بعد', 'TX IDs');
echo str_repeat('─', 110) . "\n";

foreach ($rows as $r) {
    printf("%-6d  %-30s  %13s  %12s  %13s  %13s  %s\n",
        $r['customer_id'],
        mb_substr($r['customer_name'], 0, 28),
        number_format($r['current_balance'], 2, '.', ','),
        $r['tx_count'] . ' ×',
        number_format($r['fix_amount'], 2, '.', ','),
        number_format($r['corrected_balance'], 2, '.', ','),
        implode(', ', $r['tx_ids'])
    );
}

echo "\nإجمالي المبلغ الذي سيُخصم من أرصدة العملاء: " . number_format($totalFix, 2, '.', ',') . " EGP\n\n";

// ───── Confirm + execute (only when --apply) ─────
if ($dryRun) {
    echo "[وضع dry-run] لا تغييرات. شغّل مع --apply لتنفيذ التصحيح:\n";
    echo "  php scripts/fix_payment_voucher_balance.php --apply" . ($customerName ? " --customer=\"{$customerName}\"" : '') . "\n";
    exit(0);
}

if (! $yes) {
    echo "هل تريد المتابعة بكتابة التصحيح؟ اكتب 'نعم' أو 'yes' للمتابعة: ";
    $answer = trim((string) fgets(STDIN));
    if (! in_array(strtolower($answer), ['نعم', 'yes', 'y'], true)) {
        echo "تم الإلغاء.\n";
        exit(0);
    }
}

echo "جاري التطبيق...\n";
DB::transaction(function () use ($rows) {
    foreach ($rows as $r) {
        $accountId = $r['account_id'];
        $fix = $r['fix_amount'];
        $txIds = $r['tx_ids'];

        // 1) Update the account balance directly.
        //    Wrap in LedgerBalanceMutationGuard::run() so the Account::booted()
        //    updating guard (which rejects unauthorized balance writes) lets
        //    this correction through — this IS an authorized ledger mutation,
        //    paired with an AccountEntry row below.
        LedgerBalanceMutationGuard::run(function () use ($accountId, $fix, &$newBalance) {
            $account = Account::where('id', $accountId)->lockForUpdate()->firstOrFail();
            $newBalance = round((float) $account->balance - $fix, 2);
            $account->balance = $newBalance;
            $account->save();
        });

        // 2) Append a balancing AccountEntry (append-only — never modify the
        //    original buggy entries; create an offsetting one).
        //    The fix is a DEBIT on the customer account (=balance -= amount).
        //    transaction_id = null (this is an operational correction, not
        //    linked to a business transaction).
        AccountEntry::create([
            'account_id'     => $accountId,
            'transaction_id' => null,
            'debit'          => $fix,
            'credit'         => 0.00,
            'balance_after'  => $newBalance,
            'notes'          => 'تصحيح TX-201: عكس قيد سند صرف معكوس #' . implode(', #', $txIds)
                . ' — العميل: ' . $r['customer_name']
                . ' — رصيد سابق: ' . number_format($r['current_balance'], 2, '.', ',')
                . ' → رصيد مصحّح: ' . number_format($newBalance, 2, '.', ','),
        ]);
    }
});

echo "✅ تم تطبيق التصحيح على " . count($rows) . " حساب عميل.\n";
echo "راجع storage/logs/laravel.log لأي استثناءات.\n";
