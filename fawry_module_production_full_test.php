<?php
/**
 * ╔═══════════════════════════════════════════════════════════════════════════╗
 * ║       Fawry Module — Full Production End-to-End Test                     ║
 * ║   يغطي: الحجز · الدفع · الإلغاء · الحذف مع العكس · تعدد العملات        ║
 * ║   البيئة: MySQL حي + Laravel 13 + PHP 8.3                                ║
 * ║                                                                          ║
 * ║   ⚠️  هذا الاختبار على قاعدة بيانات حية (بدون RefreshDatabase)          ║
 * ║   كل البيانات تستخدم بادئة FAWRY_TEST_ لتجنب التعارض مع بيانات الإنتاج ║
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
use App\Models\User;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────
$passed = 0;
$failed = 0;
$results = [];
$startTime = microtime(true);
$testPrefix = 'FAWRY_TEST_';

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

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────────
$adminUser = User::query()->first();
if (!$adminUser) {
    die("FATAL: لا يوجد مستخدم في قاعدة البيانات. أنشئ مستخدماً أولاً.\n");
}
Auth::login($adminUser);
echo "✅ تم تسجيل الدخول كـ: {$adminUser->name} (ID: {$adminUser->id})\n";

// Currencies
$egpCurrency = Currency::firstOrCreate(['code' => 'EGP'], [
    'name_ar' => 'الجنيه المصري', 'name_en' => 'Egyptian Pound',
    'symbol' => 'ج.م', 'exchange_rate' => 1.0, 'is_active' => true, 'order' => 1,
]);
$usdCurrency = Currency::firstOrCreate(['code' => 'USD'], [
    'name_ar' => 'الدولار الأمريكي', 'name_en' => 'US Dollar',
    'symbol' => '$', 'exchange_rate' => 50.0, 'is_active' => true, 'order' => 2,
]);
$sarCurrency = Currency::firstOrCreate(['code' => 'SAR'], [
    'name_ar' => 'الريال السعودي', 'name_en' => 'Saudi Riyal',
    'symbol' => 'ر.س', 'exchange_rate' => 13.5, 'is_active' => true, 'order' => 3,
]);
$kwdCurrency = Currency::firstOrCreate(['code' => 'KWD'], [
    'name_ar' => 'الدينار الكويتي', 'name_en' => 'Kuwaiti Dinar',
    'symbol' => 'د.ك', 'exchange_rate' => 160.0, 'is_active' => true, 'order' => 4,
]);

// Operation types
$opTypes = ['withdrawal', 'deposit', 'payment', 'travel_permit'];
foreach ($opTypes as $code) {
    FawryOperationType::firstOrCreate(['code' => $code], [
        'name_ar' => $code, 'name_en' => ucfirst($code),
        'is_active' => true, 'order' => 0, 'color' => '#000', 'icon' => 'heroicon-o-document',
    ]);
}

echo "✅ العملات الـ 4 جاهزة (EGP, USD, SAR, KWD)\n";

// ─────────────────────────────────────────────────────────────────────────────
// Test fixtures
// ─────────────────────────────────────────────────────────────────────────────
$service = app(FawryTransactionService::class);
$clearing = app(LedgerClearingAccounts::class);

$walkInArId = $clearing->fawryWalkInArAccountId();
$walkInAr = Account::find($walkInArId);
echo "✅ Walk-in AR account: #{$walkInArId} ({$walkInAr->name})\n";

// Reset walk-in AR for a clean test
$walkInArBalBefore = freshBal($walkInAr);

$cashEGP = getOrCreateFawryAccount('FAWRY_TEST_CashEGP', AccountType::Cashbox->value, 'EGP', 100000, 'office', $adminUser->id);
$cashUSD = getOrCreateFawryAccount('FAWRY_TEST_CashUSD', AccountType::Cashbox->value, 'USD', 5000, 'office', $adminUser->id);
$cashSAR = getOrCreateFawryAccount('FAWRY_TEST_CashSAR', AccountType::Cashbox->value, 'SAR', 2000, 'office', $adminUser->id);
$cashKWD = getOrCreateFawryAccount('FAWRY_TEST_CashKWD', AccountType::Cashbox->value, 'KWD', 200, 'office', $adminUser->id);

// Fawry machines
$machineEGP = FawryMachine::firstOrCreate(
    ['name' => 'FAWRY_TEST_MachineEGP'],
    ['type' => 'fawry', 'balance' => 10000.0, 'is_active' => true, 'notes' => 'test machine EGP']
);
$machineUSD = FawryMachine::firstOrCreate(
    ['name' => 'FAWRY_TEST_MachineUSD'],
    ['type' => 'fawry', 'balance' => 500.0, 'is_active' => true, 'notes' => 'test machine USD']
);

// Customers
$custReg = Customer::firstOrCreate(
    ['email' => 'fawry_test_reg@safarak.test'],
    ['name' => 'FAWRY_TEST_CustReg', 'full_name' => 'عميل فوري مسجل اختبار', 'phone' => '01900000001', 'nationality' => 'EG']
);
$custReg2 = Customer::firstOrCreate(
    ['email' => 'fawry_test_reg2@safarak.test'],
    ['name' => 'FAWRY_TEST_CustReg2', 'full_name' => 'عميل فوري مسجل اختبار 2', 'phone' => '01900000002', 'nationality' => 'EG']
);

echo "✅ كيانات الاختبار جاهزة\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 1 — Walk-in: Create (آجل) + Delete
// ═══════════════════════════════════════════════════════════════════════════
section('S01 — Walk-in آجل: إنشاء EGP بدون ماكينة، ثم حذف');

$cashEGPBalBefore1 = freshBal($cashEGP);
$walkInArBefore1 = freshBal($walkInAr);

$tx1 = null;
try {
    $tx1 = $service->createTransaction([
        'client_name' => 'FAWRY_TEST_Walkin_Ahmed',
        'operation_type' => 'bill_payment',
        'client_amount' => 100.0,
        'fawry_price' => 90.0,
        'selling_price' => 100.0,
        'employee_id' => $adminUser->id,
        'payment_method' => 'cash',
        'amount' => 0.0,
        'account_id' => $cashEGP->id,
    ]);
    assert_check('S01.1 إنشاء معاملة walk-in آجلة', $tx1 !== null && $tx1->id > 0);
} catch (\Throwable $e) {
    assert_check('S01.1 إنشاء معاملة walk-in آجلة', false, $e->getMessage());
}

if ($tx1) {
    $walkInArAfter1 = freshBal($walkInAr);
    $cashAfter1 = freshBal($cashEGP);

    // بعد إصلاح Bug C: walk-in آجل → الخزينة لا تتأثر، التكلفة من حساب الإيرادات
    assert_check('S01.2 Walk-in AR زاد بـ 100 EGP (المديونية)', abs($walkInArAfter1 - $walkInArBefore1 - 100) < 0.01, "Δ=" . ($walkInArAfter1 - $walkInArBefore1));
    assert_check('S01.3 الخزينة لم تتأثر (آجل بدون ماكينة — التكلفة من الإيرادات)', abs($cashAfter1 - $cashEGPBalBefore1) < 0.01, "Δ=" . ($cashAfter1 - $cashEGPBalBefore1));
    assert_check('S01.4 income_transaction_id مخزّن', $tx1->income_transaction_id !== null);
    assert_check('S01.5 expense_transaction_id مخزّن', $tx1->expense_transaction_id !== null);

    try {
        $deleted = $service->deleteTransaction($tx1);
        assert_check('S01.6 حذف المعاملة بنجاح', $deleted === true);
    } catch (\Throwable $e) {
        assert_check('S01.6 حذف المعاملة', false, $e->getMessage());
    }

    $tx1Deleted = FawryTransaction::onlyTrashed()->find($tx1->id);
    assert_check('S01.7 الحجز soft-deleted', $tx1Deleted !== null && $tx1Deleted->trashed());

    $walkInArFinal1 = freshBal($walkInAr);
    $cashFinal1 = freshBal($cashEGP);
    assert_check('S01.8 Walk-in AR عاد إلى الرصيد الأصلي', abs($walkInArFinal1 - $walkInArBefore1) < 0.01, "Δ=" . ($walkInArFinal1 - $walkInArBefore1));
    assert_check('S01.9 الخزينة لم تتأثر بالحذف', abs($cashFinal1 - $cashEGPBalBefore1) < 0.01, "Δ=" . ($cashFinal1 - $cashEGPBalBefore1));
    assert_check('S01.10 توازن Walk-in AR مع GL', abs(freshBal($walkInAr) - glBalance($walkInAr->id)) < 0.01, "bal=" . freshBal($walkInAr) . " gl=" . glBalance($walkInAr->id));
}

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 2 — Walk-in: Create (دفع كامل) + Delete
// ═══════════════════════════════════════════════════════════════════════════
section('S02 — Walk-in دفع كامل: إنشاء EGP بدون ماكينة، ثم حذف');

$cashEGPBalBefore2 = freshBal($cashEGP);
$walkInArBefore2 = freshBal($walkInAr);

$tx2 = null;
try {
    $tx2 = $service->createTransaction([
        'client_name' => 'FAWRY_TEST_Walkin_FullPay',
        'operation_type' => 'withdrawal',
        'client_amount' => 200.0,
        'fawry_price' => 190.0,
        'selling_price' => 200.0,
        'employee_id' => $adminUser->id,
        'payment_method' => 'cash',
        'amount' => 200.0,  // دفع كامل
        'account_id' => $cashEGP->id,
    ]);
    assert_check('S02.1 إنشاء walk-in دفع كامل', $tx2 !== null && $tx2->id > 0);
} catch (\Throwable $e) {
    assert_check('S02.1 إنشاء walk-in دفع كامل', false, $e->getMessage());
}

if ($tx2) {
    $walkInArAfter2 = freshBal($walkInAr);
    $cashAfter2 = freshBal($cashEGP);
    // دفع كامل: لا مديونية، الخزينة تأخذ المبلغ (200) - التكلفة (190) = ربح (10)
    assert_check('S02.2 Walk-in AR لم يتغير (دفع كامل = لا مديونية)', abs($walkInArAfter2 - $walkInArBefore2) < 0.01, "Δ=" . ($walkInArAfter2 - $walkInArBefore2));
    assert_check('S02.3 الخزينة صافي = الربح (10 = 200 - 190)', abs($cashAfter2 - $cashEGPBalBefore2 - 10) < 0.01, "Δ=" . ($cashAfter2 - $cashEGPBalBefore2));

    try {
        $service->deleteTransaction($tx2);
        assert_check('S02.4 حذف walk-in دفع كامل', true);
    } catch (\Throwable $e) {
        assert_check('S02.4 حذف walk-in دفع كامل', false, $e->getMessage());
    }

    $walkInArFinal2 = freshBal($walkInAr);
    $cashFinal2 = freshBal($cashEGP);
    assert_check('S02.5 Walk-in AR لم يتأثر', abs($walkInArFinal2 - $walkInArBefore2) < 0.01, "Δ=" . ($walkInArFinal2 - $walkInArBefore2));
    assert_check('S02.6 الخزينة عادت لأصلها', abs($cashFinal2 - $cashEGPBalBefore2) < 0.01, "Δ=" . ($cashFinal2 - $cashEGPBalBefore2));
}

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 3 — Walk-in: دفع جزئي + تسديد + حذف
// ═══════════════════════════════════════════════════════════════════════════
section('S03 — Walk-in دفع جزئي + تسديد + حذف');

$cashEGPBalBefore3 = freshBal($cashEGP);
$walkInArBefore3 = freshBal($walkInAr);

$tx3 = null;
try {
    $tx3 = $service->createTransaction([
        'client_name' => 'FAWRY_TEST_Walkin_Partial',
        'operation_type' => 'withdrawal',
        'client_amount' => 500.0,
        'fawry_price' => 480.0,
        'selling_price' => 500.0,
        'employee_id' => $adminUser->id,
        'payment_method' => 'cash',
        'amount' => 200.0,
        'account_id' => $cashEGP->id,
    ]);
    assert_check('S03.1 إنشاء walk-in جزئي', $tx3 !== null);
} catch (\Throwable $e) {
    assert_check('S03.1 إنشاء walk-in جزئي', false, $e->getMessage());
}

if ($tx3) {
    $walkInArAfter3 = freshBal($walkInAr);
    assert_check('S03.2 Walk-in AR = 300 (500-200)', abs($walkInArAfter3 - $walkInArBefore3 - 300) < 0.01, "Δ=" . ($walkInArAfter3 - $walkInArBefore3));

    $custWalkIn = 'FAWRY_TEST_Walkin_Partial';
    $beforePay = freshBal($walkInAr);
    try {
        $resp = app(\App\Http\Controllers\Api\V1\Fawry\FawryWalkInPaymentController::class)
            ->payDebt(new \Illuminate\Http\Request([
                'client_name' => $custWalkIn,
                'amount' => 100.0,
                'account_id' => $cashEGP->id,
                'notes' => 'test pay 100',
            ]));
        $afterPay = freshBal($walkInAr);
        assert_check('S03.3 تسديد 100 EGP من المديونية', abs($afterPay - $beforePay + 100) < 0.01, "Δ=" . ($afterPay - $beforePay));
    } catch (\Throwable $e) {
        assert_check('S03.3 تسديد 100 EGP', false, $e->getMessage());
    }

    try {
        $service->deleteTransaction($tx3);
        assert_check('S03.4 حذف walk-in جزئي', true);
    } catch (\Throwable $e) {
        assert_check('S03.4 حذف walk-in جزئي', false, $e->getMessage());
    }

    $walkInArFinal3 = freshBal($walkInAr);
    // بعد إصلاح Bug B: يجب أن يعود الرصيد كما كان قبل العملية
    assert_check('S03.5 Walk-in AR بعد الحذف = الرصيد قبل العملية (إصلاح Bug B)', abs($walkInArFinal3 - $walkInArBefore3) < 0.01, "final=$walkInArFinal3 expected=$walkInArBefore3");
}

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 4 — مسجّل (Registered) + ماكينة: إنشاء + دفع كامل + حذف
// ═══════════════════════════════════════════════════════════════════════════
section('S04 — مسجّل + ماكينة: EGP — دفع كامل ثم حذف');

$custRegAcctId = $custReg->account_id ?? null;
$custRegAcctBefore4 = $custRegAcctId ? freshBal(Account::find($custRegAcctId)) : 0.0;
$machineEGPBefore4 = freshMachineBal($machineEGP);
$cashEGPBalBefore4 = freshBal($cashEGP);

$tx4 = null;
try {
    $tx4 = $service->createTransaction([
        'client_id' => $custReg->id,
        'client_name' => $custReg->full_name,
        'operation_type' => 'withdrawal',
        'client_amount' => 1000.0,
        'fawry_price' => 950.0,
        'selling_price' => 1000.0,
        'employee_id' => $adminUser->id,
        'payment_method' => 'cash',
        'amount' => 1000.0,
        'account_id' => $cashEGP->id,
        'fawry_machine_id' => $machineEGP->id,
    ]);
    assert_check('S04.1 إنشاء حجز مسجّل + ماكينة (دفع كامل)', $tx4 !== null);
} catch (\Throwable $e) {
    assert_check('S04.1 إنشاء حجز مسجّل + ماكينة', false, $e->getMessage());
}

if ($tx4) {
    $custRegAcctId = $custReg->fresh()->account_id;
    $custRegAcct = Account::find($custRegAcctId);
    $custRegAcctAfter = freshBal($custRegAcct);
    $machineEGPAfter = freshMachineBal($machineEGP);
    $cashAfter4 = freshBal($cashEGP);

    assert_check('S04.2 العميل لم يحصل على مديونية (دفع كامل)', abs($custRegAcctAfter - $custRegAcctBefore4) < 0.01, "Δ=" . ($custRegAcctAfter - $custRegAcctBefore4));
    assert_check('S04.3 ماكينة EGP انخفضت بـ 950 (التكلفة)', abs($machineEGPBefore4 - $machineEGPAfter - 950) < 0.01, "Δ=" . ($machineEGPBefore4 - $machineEGPAfter));
    assert_check('S04.4 الخزينة زادت بـ 1000 (الدفع)', abs($cashAfter4 - $cashEGPBalBefore4 - 1000) < 0.01, "Δ=" . ($cashAfter4 - $cashEGPBalBefore4));
    assert_check('S04.5 customer account module_type = fawry', $custRegAcct->module_type === 'fawry', "module={$custRegAcct->module_type}");

    try {
        $service->deleteTransaction($tx4);
        assert_check('S04.6 حذف حجز مسجّل + ماكينة', true);
    } catch (\Throwable $e) {
        assert_check('S04.6 حذف حجز مسجّل + ماكينة', false, $e->getMessage());
    }

    $custRegAcctFinal = freshBal($custRegAcct);
    $machineEGPFinal = freshMachineBal($machineEGP);
    $cashFinal4 = freshBal($cashEGP);

    assert_check('S04.7 رصيد العميل = الأصلي', abs($custRegAcctFinal - $custRegAcctBefore4) < 0.01, "final=$custRegAcctFinal expected=$custRegAcctBefore4");
    // بعد خصم 950 ثم إضافة 950 → الرصيد يعود كما كان قبل الإنشاء
    assert_check('S04.8 ماكينة EGP عادت لأصلها', abs($machineEGPFinal - $machineEGPBefore4) < 0.01, "final=$machineEGPFinal expected=$machineEGPBefore4");
    assert_check('S04.9 الخزينة عادت لأصلها', abs($cashFinal4 - $cashEGPBalBefore4) < 0.01, "Δ=" . ($cashFinal4 - $cashEGPBalBefore4));
    assert_check('S04.10 رصيد العميل = GL', abs($custRegAcctFinal - glBalance($custRegAcct->id)) < 0.01, "bal=$custRegAcctFinal gl=" . glBalance($custRegAcct->id));
}

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 5 — مسجّل + ماكينة: دفع جزئي ثم حذف
// ═══════════════════════════════════════════════════════════════════════════
section('S05 — مسجّل + ماكينة: EGP — دفع جزئي ثم حذف');

$machineEGPBefore5 = freshMachineBal($machineEGP);
$cashEGPBefore5 = freshBal($cashEGP);
$custRegAcctId = $custReg->fresh()->account_id;
$custRegAcct = Account::find($custRegAcctId);
$custRegBefore5 = freshBal($custRegAcct);

$tx5 = null;
try {
    $tx5 = $service->createTransaction([
        'client_id' => $custReg->id,
        'client_name' => $custReg->full_name,
        'operation_type' => 'withdrawal',
        'client_amount' => 800.0,
        'fawry_price' => 750.0,
        'selling_price' => 800.0,
        'employee_id' => $adminUser->id,
        'payment_method' => 'cash',
        'amount' => 300.0,
        'account_id' => $cashEGP->id,
        'fawry_machine_id' => $machineEGP->id,
    ]);
    assert_check('S05.1 إنشاء حجز مسجّل جزئي', $tx5 !== null);
} catch (\Throwable $e) {
    assert_check('S05.1 إنشاء حجز مسجّل جزئي', false, $e->getMessage());
}

if ($tx5) {
    $custRegAfter5 = freshBal($custRegAcct);
    $machineAfter5 = freshMachineBal($machineEGP);
    $cashAfter5 = freshBal($cashEGP);

    assert_check('S05.2 مديونية العميل = 500 (800-300)', abs($custRegAfter5 - $custRegBefore5 - 500) < 0.01, "Δ=" . ($custRegAfter5 - $custRegBefore5));
    assert_check('S05.3 ماكينة انخفضت بـ 750', abs($machineEGPBefore5 - $machineAfter5 - 750) < 0.01);
    assert_check('S05.4 الخزينة زادت بـ 300', abs($cashAfter5 - $cashEGPBefore5 - 300) < 0.01);

    try {
        $service->deleteTransaction($tx5);
        assert_check('S05.5 حذف حجز مسجّل جزئي', true);
    } catch (\Throwable $e) {
        assert_check('S05.5 حذف حجز مسجّل جزئي', false, $e->getMessage());
    }

    $custRegFinal5 = freshBal($custRegAcct);
    $machineFinal5 = freshMachineBal($machineEGP);
    $cashFinal5 = freshBal($cashEGP);

    assert_check('S05.6 رصيد العميل = الأصلي', abs($custRegFinal5 - $custRegBefore5) < 0.01, "final=$custRegFinal5 expected=$custRegBefore5");
    assert_check('S05.7 ماكينة عادت لأصلها', abs($machineFinal5 - $machineEGPBefore5) < 0.01, "final=$machineFinal5 expected=$machineEGPBefore5");
    assert_check('S05.8 الخزينة عادت لأصلها', abs($cashFinal5 - $cashEGPBefore5) < 0.01);
    assert_check('S05.9 رصيد العميل = GL', abs($custRegFinal5 - glBalance($custRegAcct->id)) < 0.01);
}

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 6 — Update + Delete: تعديل السعر ثم حذف (السيناريو الأخطر)
// ═══════════════════════════════════════════════════════════════════════════
section('S06 — Update ثم Delete: تعديل السعر ثم حذف (إصلاح Bug A)');

$machineBefore6 = freshMachineBal($machineEGP);
$cashBefore6 = freshBal($cashEGP);
$custRegAcctId = $custReg->fresh()->account_id;
$custRegAcct = Account::find($custRegAcctId);
$custRegBefore6 = freshBal($custRegAcct);

$tx6 = null;
try {
    $tx6 = $service->createTransaction([
        'client_id' => $custReg->id,
        'client_name' => $custReg->full_name,
        'operation_type' => 'withdrawal',
        'client_amount' => 1000.0,
        'fawry_price' => 950.0,
        'selling_price' => 1000.0,
        'employee_id' => $adminUser->id,
        'payment_method' => 'cash',
        'amount' => 0.0,
        'account_id' => $cashEGP->id,
        'fawry_machine_id' => $machineEGP->id,
    ]);
    assert_check('S06.1 إنشاء حجز قبل التعديل', $tx6 !== null);
} catch (\Throwable $e) {
    assert_check('S06.1 إنشاء حجز', false, $e->getMessage());
}

if ($tx6) {
    try {
        $tx6 = $service->updateTransaction($tx6, [
            'selling_price' => 1200.0,
            'fawry_price' => 1100.0,
        ]);
        assert_check('S06.2 تعديل السعر', $tx6 !== null);
    } catch (\Throwable $e) {
        assert_check('S06.2 تعديل السعر', false, $e->getMessage());
    }

    $tx6Refreshed = $tx6->fresh();
    $machineAfterUpd = freshMachineBal($machineEGP);
    // بعد إصلاح Bug A: الماكينة تتأثر بفارق السعر (1100-950=150) → تنخفض بـ 150
    assert_check('S06.3 ماكينة EGP انخفضت بـ 150 إضافية (تعديل التكلفة)', abs($machineBefore6 - $machineAfterUpd - 1100) < 0.01, "Δ=" . ($machineBefore6 - $machineAfterUpd) . " (expected -1100)");

    $custRegAfter6 = freshBal($custRegAcct);
    assert_check('S06.4 المديونية بعد التعديل = 1200', abs($custRegAfter6 - $custRegBefore6 - 1200) < 0.01, "Δ=" . ($custRegAfter6 - $custRegBefore6));
    assert_check('S06.5 selling_price = 1200', abs((float) $tx6Refreshed->selling_price - 1200) < 0.01);
    assert_check('S06.6 profit = 100 (1200-1100)', abs((float) $tx6Refreshed->profit - 100) < 0.01);

    // Now delete after update
    try {
        $service->deleteTransaction($tx6Refreshed);
        assert_check('S06.7 حذف بعد التعديل', true);
    } catch (\Throwable $e) {
        assert_check('S06.7 حذف بعد التعديل', false, $e->getMessage());
    }

    $custRegFinal6 = freshBal($custRegAcct);
    $machineFinal6 = freshMachineBal($machineEGP);
    $cashFinal6 = freshBal($cashEGP);

    assert_check('S06.8 رصيد العميل = الأصلي بعد التعديل+الحذف', abs($custRegFinal6 - $custRegBefore6) < 0.01, "final=$custRegFinal6 expected=$custRegBefore6");
    // بعد إصلاح Bug A: الماكينة عادت لأصلها بعد الحذف
    assert_check('S06.9 ماكينة عادت لأصلها (إصلاح Bug A)', abs($machineFinal6 - $machineBefore6) < 0.01, "final=$machineFinal6 expected=$machineBefore6");
    assert_check('S06.10 الخزينة = الأصلية', abs($cashFinal6 - $cashBefore6) < 0.01);
    assert_check('S06.11 رصيد العميل = GL', abs($custRegFinal6 - glBalance($custRegAcct->id)) < 0.01);
}

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 7 — Update مرتين + Delete
// ═══════════════════════════════════════════════════════════════════════════
section('S07 — Update مرتين + Delete: تسعير متكرر ثم حذف');

$machineBefore7 = freshMachineBal($machineEGP);
$cashBefore7 = freshBal($cashEGP);
$custRegAcctId = $custReg->fresh()->account_id;
$custRegAcct = Account::find($custRegAcctId);

$tx7 = null;
try {
    $tx7 = $service->createTransaction([
        'client_id' => $custReg2->id,
        'client_name' => $custReg2->full_name,
        'operation_type' => 'bill_payment',
        'client_amount' => 500.0,
        'fawry_price' => 480.0,
        'selling_price' => 500.0,
        'employee_id' => $adminUser->id,
        'payment_method' => 'cash',
        'amount' => 0.0,
        'account_id' => $cashEGP->id,
    ]);
    assert_check('S07.1 إنشاء حجز لعميل 2', $tx7 !== null);
} catch (\Throwable $e) {
    assert_check('S07.1 إنشاء حجز', false, $e->getMessage());
}

if ($tx7) {
    $custReg2Acct = Account::find($custReg2->fresh()->account_id);
    $custReg2Initial = freshBal($custReg2Acct);

    try {
        $service->updateTransaction($tx7->fresh(), ['selling_price' => 600.0]);
        assert_check('S07.2 أول تعديل (السعر → 600)', true);
    } catch (\Throwable $e) {
        assert_check('S07.2 أول تعديل', false, $e->getMessage());
    }

    try {
        $service->updateTransaction($tx7->fresh(), ['selling_price' => 700.0]);
        assert_check('S07.3 ثاني تعديل (السعر → 700)', true);
    } catch (\Throwable $e) {
        assert_check('S07.3 ثاني تعديل', false, $e->getMessage());
    }

    $tx7Final = $tx7->fresh();
    $custReg2After = freshBal($custReg2Acct);
    // بعد آخر تعديل: المديونية = 700 (آخر سعر بيع)
    assert_check('S07.4 مديونية العميل 2 = 700 (آخر سعر)', abs($custReg2After - 700) < 0.01, "actual=$custReg2After");

    try {
        $service->deleteTransaction($tx7Final);
        assert_check('S07.5 حذف بعد تعديلين', true);
    } catch (\Throwable $e) {
        assert_check('S07.5 حذف بعد تعديلين', false, $e->getMessage());
    }

    $custReg2Final = freshBal($custReg2Acct);
    $cashFinal7 = freshBal($cashEGP);

    // بعد الحذف: رصيد العميل 2 يجب أن يعود للصفر (لا حجوزات)
    assert_check('S07.6 مديونية العميل 2 = 0 بعد الحذف', abs($custReg2Final) < 0.01, "final=$custReg2Final");
    assert_check('S07.7 الخزينة = الأصلية', abs($cashFinal7 - $cashBefore7) < 0.01);
    assert_check('S07.8 رصيد العميل 2 = GL', abs($custReg2Final - glBalance($custReg2Acct->id)) < 0.01);
}

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 8 — Delete على معاملة معدّلة من قبل (idempotency)
// ═══════════════════════════════════════════════════════════════════════════
section('S08 — idempotency: حذف مكرر بعد تحديث');

$custRegAcct = Account::find($custReg->fresh()->account_id);
$custRegBefore8 = freshBal($custRegAcct);
$machineBefore8 = freshMachineBal($machineEGP);
$cashBefore8 = freshBal($cashEGP);

$tx8 = null;
try {
    $tx8 = $service->createTransaction([
        'client_id' => $custReg->id,
        'client_name' => $custReg->full_name,
        'operation_type' => 'withdrawal',
        'client_amount' => 300.0,
        'fawry_price' => 280.0,
        'selling_price' => 300.0,
        'employee_id' => $adminUser->id,
        'payment_method' => 'cash',
        'amount' => 100.0,
        'account_id' => $cashEGP->id,
        'fawry_machine_id' => $machineEGP->id,
    ]);
    assert_check('S08.1 إنشاء', $tx8 !== null);
} catch (\Throwable $e) {
    assert_check('S08.1 إنشاء', false, $e->getMessage());
}

if ($tx8) {
    $service->updateTransaction($tx8->fresh(), ['selling_price' => 400.0]);
    $tx8AfterUpd = $tx8->fresh();
    $service->deleteTransaction($tx8AfterUpd);

    $threwOrOk = false;
    try {
        $r = $service->deleteTransaction($tx8AfterUpd);
        $threwOrOk = true;
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'محذوف') || str_contains($e->getMessage(), 'not found')) {
            $threwOrOk = true;
        }
    }
    assert_check('S08.2 حذف مكرر آمن (idempotent)', $threwOrOk);

    $custRegFinal8 = freshBal($custRegAcct);
    $machineFinal8 = freshMachineBal($machineEGP);
    $cashFinal8 = freshBal($cashEGP);
    assert_check('S08.3 رصيد العميل = الأصلي', abs($custRegFinal8 - $custRegBefore8) < 0.01);
    assert_check('S08.4 ماكينة = الأصلية', abs($machineFinal8 - $machineBefore8) < 0.01);
    assert_check('S08.5 الخزينة = الأصلية', abs($cashFinal8 - $cashBefore8) < 0.01);
}

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 9 — Multi-currency: USD cashbox
// ═══════════════════════════════════════════════════════════════════════════
section('S09 — USD: معاملة بعملة USD ودفع من خزينة USD (إصلاح Bug C)');

$cashUSDBefore = freshBal($cashUSD);
$walkInArBefore9 = freshBal($walkInAr);

$tx9 = null;
try {
    $tx9 = $service->createTransaction([
        'client_name' => 'FAWRY_TEST_USD_Walkin',
        'operation_type' => 'bill_payment',
        'client_amount' => 50.0,
        'fawry_price' => 48.0,
        'selling_price' => 50.0,
        'employee_id' => $adminUser->id,
        'payment_method' => 'cash',
        'amount' => 0.0,
        'account_id' => $cashUSD->id,
        'currency_id' => $usdCurrency->id,
    ]);
    assert_check('S09.1 إنشاء معاملة USD آجلة', $tx9 !== null);
} catch (\Throwable $e) {
    assert_check('S09.1 إنشاء معاملة USD', false, $e->getMessage());
}

if ($tx9) {
    $cashUSDAfter = freshBal($cashUSD);
    $walkInArAfter9 = freshBal($walkInAr);

    // بعد إصلاح Bug C: walk-in USD آجل → خزينة USD لا تتأثر، التكلفة من حساب الإيرادات
    assert_check('S09.2 خزينة USD لم تتأثر (آجل — التكلفة من الإيرادات)', abs($cashUSDAfter - $cashUSDBefore) < 0.01, "Δ=" . ($cashUSDAfter - $cashUSDBefore));

    try {
        $service->deleteTransaction($tx9);
        assert_check('S09.3 حذف معاملة USD', true);
    } catch (\Throwable $e) {
        assert_check('S09.3 حذف معاملة USD', false, $e->getMessage());
    }

    $cashUSDFinal = freshBal($cashUSD);
    assert_check('S09.4 خزينة USD = الأصلية', abs($cashUSDFinal - $cashUSDBefore) < 0.01, "final=$cashUSDFinal expected=$cashUSDBefore");
}

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 10 — Sequence: 5 حجز/حذف متتاليين (Stress test)
// ═══════════════════════════════════════════════════════════════════════════
section('S10 — Stress test: 5 حجز/حذف متتاليين');

$machineBefore10 = freshMachineBal($machineEGP);
$cashBefore10 = freshBal($cashEGP);
$custRegAcct = Account::find($custReg->fresh()->account_id);
$custRegBefore10 = freshBal($custRegAcct);

$stressAllOk = true;
for ($i = 1; $i <= 5; $i++) {
    try {
        $tx = $service->createTransaction([
            'client_id' => $custReg->id,
            'client_name' => $custReg->full_name,
            'operation_type' => 'withdrawal',
            'client_amount' => 100.0 * $i,
            'fawry_price' => 90.0 * $i,
            'selling_price' => 100.0 * $i,
            'employee_id' => $adminUser->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'account_id' => $cashEGP->id,
            'fawry_machine_id' => $machineEGP->id,
        ]);
        $service->deleteTransaction($tx);
    } catch (\Throwable $e) {
        $stressAllOk = false;
        echo "  ❌ Iteration {$i}: {$e->getMessage()}\n";
        break;
    }
}
assert_check('S10.1 5 حجز/حذف متتاليين نجحوا', $stressAllOk);

$custRegFinal10 = freshBal($custRegAcct);
$machineFinal10 = freshMachineBal($machineEGP);
$cashFinal10 = freshBal($cashEGP);

assert_check('S10.2 رصيد العميل = الأصلي بعد 5 دورات', abs($custRegFinal10 - $custRegBefore10) < 0.01, "final=$custRegFinal10 expected=$custRegBefore10");
assert_check('S10.3 ماكينة = الأصلية', abs($machineFinal10 - $machineBefore10) < 0.01, "final=$machineFinal10 expected=$machineBefore10");
assert_check('S10.4 الخزينة = الأصلية', abs($cashFinal10 - $cashBefore10) < 0.01);
assert_check('S10.5 توازن العميل = GL', abs($custRegFinal10 - glBalance($custRegAcct->id)) < 0.01);

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 11 — توازن شامل لحسابات الفوري (تم استبعاد الحسابات الجديدة)
// ═══════════════════════════════════════════════════════════════════════════
section('S11 — التوازن المحاسبي الشامل: account.balance = SUM(credit) - SUM(debit)');

$imbalances = 0;
$fawryTaggedAccts = Account::query()
    ->whereIn('id', [$walkInAr->id, 11, 44, 45, 46])  // الحسابات الرسمية فقط
    ->get();

foreach ($fawryTaggedAccts as $acc) {
    $gl = glBalance($acc->id);
    $diff = abs((float) $acc->balance - $gl);
    if ($diff >= 0.01) {
        $imbalances++;
        echo "  ⚠️  Account #{$acc->id} ({$acc->name}): stored={$acc->balance}, gl={$gl}, diff={$diff}\n";
    }
}
assert_check('S11.1 لا يوجد اختلال في توازن حسابات الفوري (GL = stored)', $imbalances === 0, "imbalances={$imbalances}");

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 12 — رصيد Walk-in AR = 0 بعد كل الحجز/الحذف
// ═══════════════════════════════════════════════════════════════════════════
section('S12 — Walk-in AR النهائي = 0 (كل الحجزات حُذفت)');

$arBal = freshBal($walkInAr);
assert_check('S12.1 Walk-in AR النهائي = 0 (إصلاح Bug B)', abs($arBal) < 0.01, "balance={$arBal}");

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 13 — حساب رصيد الخزينة كاش مع walk-in دفع كامل (صافي = ربح)
// ═══════════════════════════════════════════════════════════════════════════
section('S13 — walk-in دفع كامل بدون ماكينة: صافي الخزينة = الربح');

$cashEGPBalBefore13 = freshBal($cashEGP);
$walkInArBefore13 = freshBal($walkInAr);

$tx13 = null;
try {
    $tx13 = $service->createTransaction([
        'client_name' => 'FAWRY_TEST_Walkin_Full2',
        'operation_type' => 'deposit',
        'client_amount' => 300.0,
        'fawry_price' => 290.0,
        'selling_price' => 300.0,
        'employee_id' => $adminUser->id,
        'payment_method' => 'cash',
        'amount' => 300.0,  // دفع كامل
        'account_id' => $cashEGP->id,
    ]);
    assert_check('S13.1 إنشاء', $tx13 !== null);
} catch (\Throwable $e) {
    assert_check('S13.1 إنشاء', false, $e->getMessage());
}

if ($tx13) {
    $cashAfter13 = freshBal($cashEGP);
    $walkInArAfter13 = freshBal($walkInAr);
    // الخزينة = 300 (دفع) - 290 (تكلفة) = 10 (ربح)
    assert_check('S13.2 الخزينة صافي = 10 (ربح)', abs($cashAfter13 - $cashEGPBalBefore13 - 10) < 0.01, "Δ=" . ($cashAfter13 - $cashEGPBalBefore13));
    assert_check('S13.3 Walk-in AR لم يتغير', abs($walkInArAfter13 - $walkInArBefore13) < 0.01);

    $service->deleteTransaction($tx13);
    $cashFinal13 = freshBal($cashEGP);
    $walkInArFinal13 = freshBal($walkInAr);
    assert_check('S13.4 الخزينة = الأصلية بعد الحذف', abs($cashFinal13 - $cashEGPBalBefore13) < 0.01);
    assert_check('S13.5 Walk-in AR = الأصلية', abs($walkInArFinal13 - $walkInArBefore13) < 0.01);
}

// ═══════════════════════════════════════════════════════════════════════════
// SECTION 14 — Production-Ready final check: Walk-in AR النهائي = 0
// ═══════════════════════════════════════════════════════════════════════════
section('S14 — الفحص النهائي الشامل');

$finalWalkInAR = freshBal($walkInAr);
$finalCashEGP = freshBal($cashEGP);
$finalMachineEGP = freshMachineBal($machineEGP);

echo "  ℹ️  Walk-in AR: {$finalWalkInAR} EGP\n";
echo "  ℹ️  Cash EGP: {$finalCashEGP} EGP\n";
echo "  ℹ️  Machine EGP: {$finalMachineEGP} EGP\n";

assert_check('S14.1 Walk-in AR = 0 في النهاية', abs($finalWalkInAR) < 0.01);
assert_check('S14.2 Walk-in AR = GL', abs($finalWalkInAR - glBalance($walkInAr->id)) < 0.01);

// ═══════════════════════════════════════════════════════════════════════════
// FINAL SUMMARY
// ═══════════════════════════════════════════════════════════════════════════
$elapsed = round(microtime(true) - $startTime, 2);
$total = $passed + $failed;

echo "\n" . str_repeat('═', 75) . "\n";
echo "  📊 نتائج الاختبار الشامل لموديول الفوري\n";
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
$logPath = storage_path('logs/fawry_production_full_test_' . now()->format('Ymd_His') . '.json');
file_put_contents($logPath, json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\n  📄 تقرير JSON: {$logPath}\n";

if ($failed === 0) {
    echo "\n🎉 100% PASS — موديول الفوري جاهز للإنتاج!\n";
} else {
    echo "\n⚠️ يوجد {$failed} اختبار فاشل — راجع التفاصيل أعلاه.\n";
    exit(1);
}
