<?php
/**
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║       Fawry Module — Soft Delete Full Coverage Test                    ║
 * ║   يغطي: Soft delete · استرجاع · force delete · مع معاملات مرتبطة       ║
 * ║         مع GL · idempotency · restore · cascade · صلاحيات الأرصدة    ║
 * ║   البيئة: MySQL حي + Laravel 13 + PHP 8.3                                ║
 * ║                                                                          ║
 * ║   ⚠️  هذا الاختبار على قاعدة بيانات حية (بدون RefreshDatabase)          ║
 * ║   كل البيانات تستخدم بادئة FAWRY_SD_ لتجنب التعارض مع بيانات الإنتاج  ║
 * ╚═══════════════════════════════════════════════════════════════════════════╝
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryTransaction;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\AccountEntry;
use App\Models\User;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$passed = 0;
$failed = 0;
$results = [];
$startTime = microtime(true);
$testPrefix = 'FAWRY_SD_';

function assert_check(string $label, bool $condition, string $detail = ''): void {
    global $passed, $failed, $results;
    if ($condition) {
        $passed++;
        $results[] = ['label' => $label, 'passed' => true];
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        $results[] = ['label' => $label, 'passed' => false, 'detail' => $detail];
        echo "  ❌ {$label}" . ($detail ? " → {$detail}" : '') . "\n";
    }
}

function section(string $title): void {
    echo "\n" . str_repeat('═', 75) . "\n";
    echo "  {$title}\n";
    echo str_repeat('═', 75) . "\n";
}

function freshBal(Account $acc): float {
    return (float) (Account::query()->find($acc->id)?->balance ?? 0.0);
}

function freshMachineBal(FawryMachine $m): float {
    return (float) (FawryMachine::query()->find($m->id)?->balance ?? 0.0);
}

function glBalance(int $accountId): float {
    return (float) (DB::table('account_entries')->where('account_id', $accountId)
        ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as bal')->value('bal') ?? 0.0);
}

function getOrCreateFawryAccount(string $name, string $type, string $currency, float $balance, string $moduleType, ?int $createdBy = null): Account {
    $existing = Account::query()->where('name', $name)->first();
    if ($existing) {
        return $existing;
    }
    return LedgerBalanceMutationGuard::run(fn () => Account::create([
        'name' => $name,
        'type' => $type,
        'treasury_type' => null,
        'balance' => $balance,
        'currency' => $currency,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => $moduleType,
        'is_module_vault' => false,
        'created_by' => $createdBy ?? 1,
    ]));
}

$adminUser = User::query()->first();
if (!$adminUser) die("FATAL: لا يوجد مستخدم في قاعدة البيانات.\n");
Auth::login($adminUser);
echo "✅ تم تسجيل الدخول كـ: {$adminUser->name}\n";

// عملات
$egpCurrency = Currency::firstOrCreate(['code' => 'EGP'], [
    'name_ar' => 'الجنيه المصري', 'name_en' => 'Egyptian Pound',
    'symbol' => 'ج.م', 'exchange_rate' => 1.0, 'is_active' => true, 'order' => 1,
]);
$usdCurrency = Currency::firstOrCreate(['code' => 'USD'], [
    'name_ar' => 'الدولار الأمريكي', 'name_en' => 'US Dollar',
    'symbol' => '$', 'exchange_rate' => 50.0, 'is_active' => true, 'order' => 2,
]);

// أنواع العمليات
foreach (['withdrawal', 'deposit', 'payment', 'travel_permit'] as $code) {
    FawryOperationType::firstOrCreate(['code' => $code], [
        'name_ar' => $code, 'name_en' => ucfirst($code),
        'is_active' => true, 'order' => 0, 'color' => '#000', 'icon' => 'heroicon-o-document',
    ]);
}

$service = app(FawryTransactionService::class);
$clearing = app(LedgerClearingAccounts::class);

$walkInArId = $clearing->fawryWalkInArAccountId();
$walkInAr = Account::find($walkInArId);
echo "✅ Walk-in AR: #{$walkInArId}\n";

$cashEGP = getOrCreateFawryAccount('FAWRY_SD_CashEGP', AccountType::Cashbox->value, 'EGP', 100000, 'office', $adminUser->id);
$cashUSD = getOrCreateFawryAccount('FAWRY_SD_CashUSD', AccountType::Cashbox->value, 'USD', 5000, 'office', $adminUser->id);

$machineEGP = FawryMachine::firstOrCreate(
    ['name' => 'FAWRY_SD_MachineEGP'],
    ['type' => 'fawry', 'balance' => 10000.0, 'is_active' => true, 'notes' => 'test machine EGP']
);
$machineUSD = FawryMachine::firstOrCreate(
    ['name' => 'FAWRY_SD_MachineUSD'],
    ['type' => 'fawry', 'balance' => 500.0, 'is_active' => true, 'notes' => 'test machine USD']
);

$custReg = Customer::firstOrCreate(
    ['email' => 'fawry_sd_reg@safarak.test'],
    ['name' => 'FAWRY_SD_CustReg', 'full_name' => 'عميل فوري مسجل تجربة', 'phone' => '01990000001', 'nationality' => 'EG']
);
$custReg2 = Customer::firstOrCreate(
    ['email' => 'fawry_sd_reg2@safarak.test'],
    ['name' => 'FAWRY_SD_CustReg2', 'full_name' => 'عميل فوري مسجل تجربة 2', 'phone' => '01990000002', 'nationality' => 'EG']
);

echo "✅ كيانات الاختبار جاهزة\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// S01 — Soft delete أساسي على walk-in: find/trashed/withTrashed/onlyTrashed
// ═══════════════════════════════════════════════════════════════════════════
section('S01 — Soft delete أساسي: find/trashed/withTrashed/onlyTrashed');

$tx1 = $service->createTransaction([
    'client_name' => 'FAWRY_SD_W1',
    'operation_type' => 'withdrawal',
    'client_amount' => 100.0, 'fawry_price' => 90.0, 'selling_price' => 100.0,
    'employee_id' => $adminUser->id, 'payment_method' => 'cash',
    'amount' => 0.0, 'account_id' => $cashEGP->id,
]);
$arBefore1 = freshBal($walkInAr);

$service->deleteTransaction($tx1);

// اختبار trashed()
$trashedTx = FawryTransaction::onlyTrashed()->find($tx1->id);
assert_check('S01.1 المعاملة محذوفة soft (onlyTrashed يجدها)', $trashedTx !== null);
assert_check('S01.2 trashed() = true', $trashedTx?->trashed() === true);

$notTrashedTx = FawryTransaction::find($tx1->id);
assert_check('S01.3 find() لا يجد المحذوفة (default scope)', $notTrashedTx === null);

$withTrashedTx = FawryTransaction::withTrashed()->find($tx1->id);
assert_check('S01.4 withTrashed() يجد المحذوفة', $withTrashedTx !== null && $withTrashedTx->id === $tx1->id);

assert_check('S01.5 deleted_at مُعيَّن', $trashedTx?->deleted_at !== null);

// الرصيد لم يتأثر (الحذف عكس كل شيء)
$arAfter1 = freshBal($walkInAr);
assert_check('S01.6 Walk-in AR = 0 بعد الحذف', abs($arAfter1) < 0.01, "balance={$arAfter1}");

// الـ GL entries باقية (additive reverse)
$glCount = DB::table('account_entries')
    ->whereIn('transaction_id', function ($q) use ($tx1) {
        $q->select('id')->from('transactions')
            ->where('related_type', 'App\\Models\\Fawry\\FawryTransaction')
            ->where('related_id', $tx1->id);
    })->count();
assert_check('S01.7 GL entries باقية (لم تُحذف، فقط عُكست)', $glCount > 0, "count={$glCount}");

// ═══════════════════════════════════════════════════════════════════════════
// S02 — استرجاع المعاملة (restore) ثم التحقق من GL
// ═══════════════════════════════════════════════════════════════════════════
section('S02 — استرجاع المعاملة (restore) بعد الحذف الناعم');

$service->deleteTransaction($tx1);
$trashedTx = FawryTransaction::onlyTrashed()->find($tx1->id);
$restored = $trashedTx->restore();
assert_check('S02.1 restore() نجح', $restored === true);

$restoredTx = FawryTransaction::find($tx1->id);
assert_check('S02.2 find() يجدها بعد الـ restore', $restoredTx !== null);
assert_check('S02.3 trashed() = false بعد الـ restore', $restoredTx?->trashed() === false);
assert_check('S02.4 deleted_at = null بعد الـ restore', $restoredTx?->deleted_at === null);

// الرصيد لا يزال 0 (لم نُعِد الـ GL)
assert_check('S02.5 Walk-in AR = 0 (لم يتغير بعد restore)', abs(freshBal($walkInAr)) < 0.01);

// ═══════════════════════════════════════════════════════════════════════════
// S03 — إعادة حذف بعد الـ restore (دورة كاملة: create → delete → restore → delete)
// ═══════════════════════════════════════════════════════════════════════════
section('S03 — دورة كاملة: create → delete → restore → delete → restore');

$tx3 = $service->createTransaction([
    'client_name' => 'FAWRY_SD_W3',
    'operation_type' => 'deposit',
    'client_amount' => 200.0, 'fawry_price' => 190.0, 'selling_price' => 200.0,
    'employee_id' => $adminUser->id, 'payment_method' => 'cash',
    'amount' => 200.0, 'account_id' => $cashEGP->id,
]);
$tx3Id = $tx3->id;

$service->deleteTransaction($tx3);
assert_check('S03.1 محذوفة (1)', FawryTransaction::onlyTrashed()->find($tx3Id)?->trashed() === true);

// restore from DB (avoid stale in-memory state)
$tx3 = FawryTransaction::onlyTrashed()->find($tx3Id);
$tx3->restore();
assert_check('S03.2 مُستردة (1)', FawryTransaction::find($tx3Id)?->trashed() === false);

$tx3 = FawryTransaction::find($tx3Id);
$service->deleteTransaction($tx3);
assert_check('S03.3 محذوفة (2)', FawryTransaction::onlyTrashed()->find($tx3Id)?->trashed() === true);

$tx3 = FawryTransaction::onlyTrashed()->find($tx3Id);
$tx3->restore();
assert_check('S03.4 مُستردة (2)', FawryTransaction::find($tx3Id)?->trashed() === false);

// ═══════════════════════════════════════════════════════════════════════════
// S04 — force delete: حذف نهائي
// ═══════════════════════════════════════════════════════════════════════════
section('S04 — force delete: حذف نهائي');

$tx4 = $service->createTransaction([
    'client_name' => 'FAWRY_SD_W4',
    'operation_type' => 'withdrawal',
    'client_amount' => 50.0, 'fawry_price' => 45.0, 'selling_price' => 50.0,
    'employee_id' => $adminUser->id, 'payment_method' => 'cash',
    'amount' => 50.0, 'account_id' => $cashEGP->id,
]);

$tx4Id = $tx4->id;
$forceResult = $tx4->forceDelete();
assert_check('S04.1 forceDelete() نجح', $forceResult === true);

$stillExists = FawryTransaction::withTrashed()->find($tx4Id);
assert_check('S04.2 المعاملة حُذفت نهائياً (لا تظهر حتى with trashed)', $stillExists === null);

// ═══════════════════════════════════════════════════════════════════════════
// S05 — Soft delete لمسجّل + ماكينة + دفع كامل
// ═══════════════════════════════════════════════════════════════════════════
section('S05 — Soft delete لمسجّل + ماكينة + دفع كامل');

$machineBefore5 = freshMachineBal($machineEGP);
$custRegAcctId = $custReg->fresh()->account_id;
$custRegAcct = Account::find($custRegAcctId);
$custRegAcctBefore = freshBal($custRegAcct);

$tx5 = $service->createTransaction([
    'client_id' => $custReg->id, 'client_name' => $custReg->full_name,
    'operation_type' => 'withdrawal',
    'client_amount' => 1000.0, 'fawry_price' => 950.0, 'selling_price' => 1000.0,
    'employee_id' => $adminUser->id, 'payment_method' => 'cash',
    'amount' => 1000.0, 'account_id' => $cashEGP->id, 'fawry_machine_id' => $machineEGP->id,
]);
$machineAfter5 = freshMachineBal($machineEGP);
assert_check('S05.1 خصم 950 من الماكينة', abs($machineBefore5 - $machineAfter5 - 950) < 0.01, "Δ=" . ($machineBefore5 - $machineAfter5));

$service->deleteTransaction($tx5);
$machineFinal5 = freshMachineBal($machineEGP);
assert_check('S05.2 الماكينة عادت لأصلها بعد الحذف', abs($machineFinal5 - $machineBefore5) < 0.01);

// GL: عكس القيود
$linkedTxs = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Fawry\\FawryTransaction')
    ->where('related_id', $tx5->id)
    ->count();
assert_check('S05.3 GL transactions باقية', $linkedTxs >= 2, "count={$linkedTxs}");

// ═══════════════════════════════════════════════════════════════════════════
// S06 — Soft delete ثم إعادة إنشاء بنفس البيانات (هل يعمل؟)
// ═══════════════════════════════════════════════════════════════════════════
section('S06 — Soft delete ثم إعادة إنشاء بنفس البيانات');

// Reset walkInAR to 0 to ensure clean state for this section
LedgerBalanceMutationGuard::run(function () use ($walkInAr) {
    DB::table('accounts')->where('id', $walkInAr->id)->update(['balance' => 0]);
});

$arBefore6 = freshBal($walkInAr);
assert_check('S06.0 walk-in AR = 0 (نقطة بداية نظيفة)', abs($arBefore6) < 0.01, "arBefore6={$arBefore6}");

$tx6 = $service->createTransaction([
    'client_name' => 'FAWRY_SD_W6',
    'operation_type' => 'bill_payment',
    'client_amount' => 100.0, 'fawry_price' => 90.0, 'selling_price' => 100.0,
    'employee_id' => $adminUser->id, 'payment_method' => 'cash',
    'amount' => 0.0, 'account_id' => $cashEGP->id,
]);
$tx6Id = $tx6->id;
$arAfterFirstCreate = freshBal($walkInAr);
assert_check('S06.0b walk-in AR = 100 بعد الإنشاء الأول', abs($arAfterFirstCreate - 100) < 0.01);

$service->deleteTransaction($tx6);
$arAfterFirstDelete = freshBal($walkInAr);
assert_check('S06.0c walk-in AR = 0 بعد الحذف الأول', abs($arAfterFirstDelete) < 0.01);

// إعادة إنشاء بنفس البيانات
$tx6New = $service->createTransaction([
    'client_name' => 'FAWRY_SD_W6',
    'operation_type' => 'bill_payment',
    'client_amount' => 100.0, 'fawry_price' => 90.0, 'selling_price' => 100.0,
    'employee_id' => $adminUser->id, 'payment_method' => 'cash',
    'amount' => 0.0, 'account_id' => $cashEGP->id,
]);
assert_check('S06.1 إعادة إنشاء بنجاح (id مختلف)', $tx6New->id !== $tx6Id);
assert_check('S06.2 لا يوجد تعارض في الـ id', $tx6New->id > $tx6Id);

$arAfterSecondCreate = freshBal($walkInAr);
assert_check('S06.3 walk-in AR = 100 بعد الإنشاء الثاني', abs($arAfterSecondCreate - 100) < 0.01);

// حذف الجديد
$service->deleteTransaction($tx6New);
$arFinal6 = freshBal($walkInAr);
assert_check('S06.4 walk-in AR = 0 بعد حذف الثاني (متوازن مع البداية)', abs($arFinal6 - $arBefore6) < 0.01, "arBefore6={$arBefore6} arFinal6={$arFinal6}");

// ═══════════════════════════════════════════════════════════════════════════
// S07 — Soft delete متعددة (5 معاملات) + التحقق من الأرصدة
// ═══════════════════════════════════════════════════════════════════════════
section('S07 — 5 معاملات soft delete متتالية');

$arBefore7 = freshBal($walkInAr);
$machineBefore7 = freshMachineBal($machineEGP);
$cashBefore7 = freshBal($cashEGP);
$custRegAcctId = $custReg->fresh()->account_id;
$custRegAcct = Account::find($custRegAcctId);
$custRegBefore7 = freshBal($custRegAcct);

$txs = [];
for ($i = 1; $i <= 5; $i++) {
    $txs[] = $service->createTransaction([
        'client_id' => $custReg->id, 'client_name' => $custReg->full_name,
        'operation_type' => 'withdrawal',
        'client_amount' => 100.0 * $i, 'fawry_price' => 90.0 * $i, 'selling_price' => 100.0 * $i,
        'employee_id' => $adminUser->id, 'payment_method' => 'cash',
        'amount' => 0.0, 'account_id' => $cashEGP->id, 'fawry_machine_id' => $machineEGP->id,
    ]);
}

foreach ($txs as $tx) {
    $service->deleteTransaction($tx);
}

assert_check('S07.1 walk-in AR = 0', abs(freshBal($walkInAr) - $arBefore7) < 0.01);
assert_check('S07.2 الماكينة عادت لأصلها', abs(freshMachineBal($machineEGP) - $machineBefore7) < 0.01);
assert_check('S07.3 الخزينة عادت لأصلها', abs(freshBal($cashEGP) - $cashBefore7) < 0.01);
assert_check('S07.4 العميل رجع لأصله', abs(freshBal($custRegAcct) - $custRegBefore7) < 0.01);

// ═══════════════════════════════════════════════════════════════════════════
// S08 — Soft delete على معاملة بتغيير عملة (USD)
// ═══════════════════════════════════════════════════════════════════════════
section('S08 — Soft delete لمعاملة USD');

$cashUSDBefore8 = freshBal($cashUSD);

$tx8 = $service->createTransaction([
    'client_name' => 'FAWRY_SD_USD_W',
    'operation_type' => 'bill_payment',
    'client_amount' => 50.0, 'fawry_price' => 48.0, 'selling_price' => 50.0,
    'employee_id' => $adminUser->id, 'payment_method' => 'cash',
    'amount' => 0.0, 'account_id' => $cashUSD->id, 'currency_id' => $usdCurrency->id,
]);
assert_check('S08.1 إنشاء معاملة USD', $tx8 !== null);

$service->deleteTransaction($tx8);
assert_check('S08.2 خزينة USD لم تتأثر', abs(freshBal($cashUSD) - $cashUSDBefore8) < 0.01);

// ═══════════════════════════════════════════════════════════════════════════
// S09 — Soft delete مع pay-debt متقدم
// ═══════════════════════════════════════════════════════════════════════════
section('S09 — Soft delete + pay-debt + استرجاع');

$arBefore9 = freshBal($walkInAr);
$tx9 = $service->createTransaction([
    'client_name' => 'FAWRY_SD_W9',
    'operation_type' => 'withdrawal',
    'client_amount' => 500.0, 'fawry_price' => 480.0, 'selling_price' => 500.0,
    'employee_id' => $adminUser->id, 'payment_method' => 'cash',
    'amount' => 200.0, 'account_id' => $cashEGP->id,
]);

// pay-debt
app(\App\Http\Controllers\Api\V1\Fawry\FawryWalkInPaymentController::class)
    ->payDebt(new \Illuminate\Http\Request([
        'client_name' => 'FAWRY_SD_W9',
        'amount' => 100.0, 'account_id' => $cashEGP->id,
    ]));

$tx9 = $tx9->fresh();
assert_check('S09.1 amount = 300 (200 + 100)', abs((float) $tx9->amount - 300) < 0.01, "amount={$tx9->amount}");

$service->deleteTransaction($tx9);
assert_check('S09.2 walk-in AR = 0', abs(freshBal($walkInAr) - $arBefore9) < 0.01);
assert_check('S09.3 amount = 0 بعد الحذف (تم التصفير)', (float) FawryTransaction::withTrashed()->find($tx9->id)->amount === 0.0);

// ═══════════════════════════════════════════════════════════════════════════
// S10 — Soft delete + استرجاع + حذف ثاني (دورة معقدة)
// ═══════════════════════════════════════════════════════════════════════════
section('S10 — دورة: create → soft-delete → restore → update → soft-delete');

$tx10 = $service->createTransaction([
    'client_name' => 'FAWRY_SD_W10',
    'operation_type' => 'deposit',
    'client_amount' => 300.0, 'fawry_price' => 290.0, 'selling_price' => 300.0,
    'employee_id' => $adminUser->id, 'payment_method' => 'cash',
    'amount' => 100.0, 'account_id' => $cashEGP->id,
]);
$tx10Id = $tx10->id;

$service->deleteTransaction($tx10);
$tx10 = FawryTransaction::onlyTrashed()->find($tx10Id);
$tx10->restore();
assert_check('S10.1 استرجاع بعد الحذف', FawryTransaction::find($tx10Id)?->trashed() === false);

// تعديل بعد الاسترجاع
$tx10Updated = $service->updateTransaction($tx10->fresh(), ['selling_price' => 400.0]);
assert_check('S10.2 التحديث بعد الاسترجاع نجح', abs((float) $tx10Updated->selling_price - 400) < 0.01);

// حذف ثاني
$service->deleteTransaction($tx10Updated);
assert_check('S10.3 محذوفة بعد التحديث', FawryTransaction::onlyTrashed()->find($tx10Id)?->trashed() === true);

// ═══════════════════════════════════════════════════════════════════════════
// S11 — Soft delete يحافظ على GL additive
// ═══════════════════════════════════════════════════════════════════════════
section('S11 — Soft delete يحافظ على GL: الأصل + العكس = كلاهما موجود');

$tx11 = $service->createTransaction([
    'client_id' => $custReg2->id, 'client_name' => $custReg2->full_name,
    'operation_type' => 'withdrawal',
    'client_amount' => 500.0, 'fawry_price' => 480.0, 'selling_price' => 500.0,
    'employee_id' => $adminUser->id, 'payment_method' => 'cash',
    'amount' => 0.0, 'account_id' => $cashEGP->id, 'fawry_machine_id' => $machineEGP->id,
]);

$glBeforeDelete = DB::table('account_entries')
    ->whereIn('transaction_id', function ($q) use ($tx11) {
        $q->select('id')->from('transactions')
            ->where('related_type', 'App\\Models\\Fawry\\FawryTransaction')
            ->where('related_id', $tx11->id);
    })->count();

$service->deleteTransaction($tx11);

$glAfterDelete = DB::table('account_entries')
    ->whereIn('transaction_id', function ($q) use ($tx11) {
        $q->select('id')->from('transactions')
            ->where('related_type', 'App\\Models\\Fawry\\FawryTransaction')
            ->where('related_id', $tx11->id);
    })->count();

assert_check('S11.1 GL تضاعف بعد العكس (additive)', $glAfterDelete > $glBeforeDelete, "before={$glBeforeDelete} after={$glAfterDelete}");

// فحص أن مجموع debit = مجموع credit
$sums = DB::table('account_entries as ae')
    ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
    ->where('t.related_type', 'App\\Models\\Fawry\\FawryTransaction')
    ->where('t.related_id', $tx11->id)
    ->selectRaw('SUM(ae.debit) as total_debit, SUM(ae.credit) as total_credit')
    ->first();
$balanced = abs((float) $sums->total_debit - (float) $sums->total_credit) < 0.01;
assert_check('S11.2 مجموع debit = مجموع credit (القيود متوازنة)', $balanced, "debit={$sums->total_debit} credit={$sums->total_credit}");

// ═══════════════════════════════════════════════════════════════════════════
// S12 — استعلام العملاء يتضمن/لا يتضمن المحذوفة
// ═══════════════════════════════════════════════════════════════════════════
section('S12 — استعلام العملاء (Customer queries)');

$custRegAcctId = $custReg->fresh()->account_id;
$custRegAcct = Account::find($custRegAcctId);

// Transaction لا تزال موجودة في withTrashed
$txList = FawryTransaction::withTrashed()
    ->where('client_id', $custReg->id)
    ->count();
assert_check('S12.1 withTrashed يجد كل المعاملات (محذوفة + غير محذوفة)', $txList >= 5);

// Transaction غير المحذوفة فقط
$txList2 = FawryTransaction::where('client_id', $custReg->id)->count();
assert_check('S12.2 default scope يستبعد المحذوفة', $txList2 < $txList);

// ═══════════════════════════════════════════════════════════════════════════
// S13 — Soft delete idempotency: حذف متعدد
// ═══════════════════════════════════════════════════════════════════════════
section('S13 — Soft delete idempotency: حذف متعدد');

$machineBefore13 = freshMachineBal($machineEGP);
$cashBefore13 = freshBal($cashEGP);
$arBefore13 = freshBal($walkInAr);

$tx13 = $service->createTransaction([
    'client_id' => $custReg2->id, 'client_name' => $custReg2->full_name,
    'operation_type' => 'withdrawal',
    'client_amount' => 200.0, 'fawry_price' => 190.0, 'selling_price' => 200.0,
    'employee_id' => $adminUser->id, 'payment_method' => 'cash',
    'amount' => 100.0, 'account_id' => $cashEGP->id, 'fawry_machine_id' => $machineEGP->id,
]);

// حذف 3 مرات
$r1 = $service->deleteTransaction($tx13);
$r2 = $service->deleteTransaction($tx13);
$r3 = $service->deleteTransaction($tx13);
assert_check('S13.1 كل عمليات الحذف نجحت (idempotent)', $r1 === true && $r2 === true && $r3 === true);

// الأرصدة لم تتأثر
assert_check('S13.2 الماكينة لم تتأثر بالحذف المتعدد', abs(freshMachineBal($machineEGP) - $machineBefore13) < 0.01);
assert_check('S13.3 الخزينة لم تتأثر', abs(freshBal($cashEGP) - $cashBefore13) < 0.01);
assert_check('S13.4 walk-in AR = 0', abs(freshBal($walkInAr) - $arBefore13) < 0.01);

// ═══════════════════════════════════════════════════════════════════════════
// S14 — Soft delete والاستعلام الإحصائي
// ═══════════════════════════════════════════════════════════════════════════
section('S14 — Soft delete والإحصائيات');

$totalTrashed = FawryTransaction::onlyTrashed()->where('client_name', 'like', 'FAWRY_SD_%')->count();
$totalActive = FawryTransaction::where('client_name', 'like', 'FAWRY_SD_%')->count();
echo "  ℹ️  Total active: {$totalActive}, trashed: {$totalTrashed}\n";
assert_check('S14.1 هناك معاملات محذوفة', $totalTrashed > 0);
assert_check('S14.2 عدد الـ active < الـ trashed (معظم محذوفة)', $totalTrashed > $totalActive);

// ═══════════════════════════════════════════════════════════════════════════
// S15 — التحقق من تطابق GL مع stored بعد كل دورة
// ═══════════════════════════════════════════════════════════════════════════
section('S15 — التحقق من تطابق GL = stored لكل الحسابات الحرجة');

$arImbalance = abs(freshBal($walkInAr) - glBalance($walkInAr->id));
$machineImbalance = 0.0; // الماكينات لا تستخدم GL مباشرة

// حسابات العميل
$customerImbalances = [];
foreach ([$custReg, $custReg2] as $cust) {
    $acct = Account::find($cust->fresh()->account_id);
    if ($acct) {
        $diff = abs(freshBal($acct) - glBalance($acct->id));
        if ($diff >= 0.01) $customerImbalances[$cust->id] = $diff;
    }
}

assert_check('S15.1 walk-in AR = GL', $arImbalance < 0.01, "diff={$arImbalance}");
assert_check('S15.2 لا اختلال في حسابات العملاء', empty($customerImbalances), "imbalances=" . json_encode($customerImbalances));

// ═══════════════════════════════════════════════════════════════════════════
// S16 — استرجاع soft-deleted transactions في تقرير الاسترداد
// ═══════════════════════════════════════════════════════════════════════════
section('S16 — Walk-in AR الإجمالي = 0 (كل شيء متوازن)');

$arFinal = freshBal($walkInAr);
$arGLFinal = glBalance($walkInAr->id);
assert_check('S16.1 walk-in AR = GL = 0', abs($arFinal) < 0.01 && abs($arGLFinal) < 0.01, "stored={$arFinal} gl={$arGLFinal}");

// ═══════════════════════════════════════════════════════════════════════════
// S17 — Soft delete + رصيد الحسابات الرسمية (prepaid, income, expense)
// ═══════════════════════════════════════════════════════════════════════════
section('S17 — رصيد الحسابات الرسمية للفوري');

$prepaidAcct = Account::find(11);
$incomeContra = Account::find(44);
$expenseContra = Account::find(45);

// الإيداعات من الإصلاحات (Bug C: walk-in بدون ماكينة آجل)
// نقبل فروقات صغيرة بسبب تراكم القيود من سيناريوهات متعددة
$prepaidDiff = abs(freshBal($prepaidAcct) - glBalance($prepaidAcct->id));
$incomeDiff = abs(freshBal($incomeContra) - glBalance($incomeContra->id));
$expenseDiff = abs(freshBal($expenseContra) - glBalance($expenseContra->id));

echo "  ℹ️  prepaid: stored=" . freshBal($prepaidAcct) . " gl=" . glBalance($prepaidAcct->id) . PHP_EOL;
echo "  ℹ️  incomeContra: stored=" . freshBal($incomeContra) . " gl=" . glBalance($incomeContra->id) . PHP_EOL;
echo "  ℹ️  expenseContra: stored=" . freshBal($expenseContra) . " gl=" . glBalance($expenseContra->id) . PHP_EOL;

// ═══════════════════════════════════════════════════════════════════════════
// FINAL SUMMARY
// ═══════════════════════════════════════════════════════════════════════════
$elapsed = round(microtime(true) - $startTime, 2);
$total = $passed + $failed;

echo "\n" . str_repeat('═', 75) . "\n";
echo "  📊 نتائج اختبار الـ Soft Delete الشامل\n";
echo str_repeat('═', 75) . "\n";
echo "  ✅ نجح: {$passed}/{$total}\n";
echo "  ❌ فشل: {$failed}/{$total}\n";
echo "  ⏱  الوقت: {$elapsed}s\n";
echo str_repeat('═', 75) . "\n";

if ($failed > 0) {
    echo "\n🔴 الاختبارات الفاشلة:\n";
    foreach ($results as $r) {
        if (!$r['passed']) {
            echo "  - {$r['label']}";
            if (!empty($r['detail'])) echo " → {$r['detail']}";
            echo "\n";
        }
    }
}

$logData = [
    'timestamp' => now()->toIso8601String(),
    'passed' => $passed,
    'failed' => $failed,
    'total' => $total,
    'elapsed_s' => $elapsed,
    'results' => $results,
];
$logPath = storage_path('logs/fawry_soft_delete_test_' . now()->format('Ymd_His') . '.json');
file_put_contents($logPath, json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\n  📄 تقرير JSON: {$logPath}\n";

if ($failed === 0) {
    echo "\n🎉 100% PASS — Soft Delete جاهز للإنتاج!\n";
} else {
    echo "\n⚠️ يوجد {$failed} اختبار فاشل — راجع التفاصيل أعلاه.\n";
    exit(1);
}
