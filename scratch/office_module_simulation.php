<?php

/**
 * Office Module Comprehensive Simulation
 * Modules: Bus | Fawry | Online Services | Wallets | Transfers
 *
 * التشغيل: php scratch/office_module_simulation.php
 */

chdir(__DIR__.'/..');
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Fawry\FawryMachine;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Wallet\WalletType;
use App\Services\Bus\BusBookingService;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Online\OnlineTransactionService;
use App\Services\Wallet\WalletTransactionService;
use App\Services\Finance\TransactionService;
use App\Services\Finance\TreasuryService;

$errors = [];
$passed = [];
$sep = str_repeat('─', 60);

function assertEq(string $label, $expected, $actual, array &$errors, array &$passed): void
{
    $ok = abs((float) $expected - (float) $actual) < 0.01;
    if ($ok) {
        $passed[] = "✅ $label";
        echo "  ✅ $label = ".number_format((float) $actual, 2)."\n";
    } else {
        $errors[] = "❌ $label: expected=".number_format((float) $expected, 2).' got='.number_format((float) $actual, 2);
        echo "  ❌ $label: expected=".number_format((float) $expected, 2).' got='.number_format((float) $actual, 2)."\n";
    }
}

function assertStr(string $label, $expected, $actual, array &$errors, array &$passed): void
{
    $ok = (string) $expected === (string) $actual;
    if ($ok) {
        $passed[] = "✅ $label";
        echo "  ✅ $label = $actual\n";
    } else {
        $errors[] = "❌ $label: expected=$expected got=$actual";
        echo "  ❌ $label: expected=$expected got=$actual\n";
    }
}

$commit = in_array('--commit', $argv ?? [], true);

DB::beginTransaction();

try {
    $admin = \App\Models\User::where('role', 'admin')->first() ?? \App\Models\User::first();
    if (! $admin) {
        throw new \RuntimeException('لا يوجد مستخدم. أضف مستخدم أولاً.');
    }
    Auth::login($admin);

    echo "\n══════════════════════════════════════════════════════════════\n";
    echo "  سيناريو قسم المكتب الشامل — المستخدم: {$admin->name}\n";
    echo "══════════════════════════════════════════════════════════════\n\n";

    // ── 1. إعداد البيانات ────────────────────────────────────────────────────
    echo "1. إعداد البيانات الأساسية\n$sep\n";

    $officeAccount = Account::firstOrCreate(
        ['name' => 'خزينة المكتب الرئيسية - سيناريو'],
        [
            'type' => 'cashbox',
            'balance' => 500_000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $admin->id,
        ]
    );

    $customer = Customer::firstOrCreate(
        ['phone' => '01099991111'],
        [
            'full_name' => 'أحمد محمد سيناريو',
            'name' => 'أحمد محمد سيناريو',
            'email' => 'ahmed.sim@example.com',
            'created_by' => $admin->id,
        ]
    );

    echo '  ✅ خزينة المكتب: '.number_format($officeAccount->balance, 2)." EGP (ID#{$officeAccount->id})\n";
    echo "  ✅ العميل: {$customer->full_name} (ID#{$customer->id})\n\n";

    // ══════════════════════════════════════════════════════════════
    // 2. BUS MODULE
    // ══════════════════════════════════════════════════════════════
    echo "2. موديول الباصات\n$sep\n";

    $busBookingService = app(BusBookingService::class);

    $busCompany = BusCompany::firstOrCreate(
        ['name' => 'شركة الرحلات السريعة - اختبار'],
        ['phone' => '0221234567', 'address' => 'القاهرة', 'is_active' => true, 'created_by' => $admin->id]
    );

    $inventory = BusInventory::create([
        'company_id' => $busCompany->id,
        'route' => 'القاهرة - الإسكندرية',
        'travel_date' => now()->addDays(7)->toDateString(),
        'departure_time' => '08:00',
        'total_tickets' => 50,
        'available_tickets' => 50,
        'cost_per_ticket' => 80.00,
        'selling_price' => 120.00,
        'payment_type' => 'deferred',
        'total_cost' => 80.00 * 50,
        'amount_paid' => 0,
        'remaining_debt' => 80.00 * 50,
        'account_id' => $officeAccount->id,
        'is_auto_created' => false,
        'created_by' => $admin->id,
    ]);
    echo "  ✅ مخزون: {$inventory->route} — {$inventory->available_tickets} مقعد × {$inventory->selling_price} EGP\n";

    $booking = $busBookingService->createBooking([
        'inventory_id' => $inventory->id,
        'customer_id' => $customer->id,
        'quantity' => 3,
        'account_id' => $officeAccount->id,
        'notes' => 'حجز اختبار - 3 مقاعد',
    ]);

    $expectedTotal = 3 * 120.00;
    $expectedProfit = (120.00 - 80.00) * 3;

    assertEq('إجمالي حجز الباص', $expectedTotal, $booking->total_price, $errors, $passed);
    assertEq('ربح الحجز', $expectedProfit, $booking->profit, $errors, $passed);
    assertEq('المبلغ المدفوع عند الحجز', 0.00, $booking->paid_amount, $errors, $passed);

    $booking = $busBookingService->payBooking($booking, [
        'amount' => 200.00,
        'account_id' => $officeAccount->id,
        'notes' => 'دفعة أولى',
    ]);
    $booking->refresh();
    assertEq('المدفوع بعد الدفعة الأولى', 200.00, $booking->paid_amount, $errors, $passed);

    $booking = $busBookingService->payBooking($booking, [
        'amount' => 160.00,
        'account_id' => $officeAccount->id,
        'notes' => 'دفعة ثانية - سداد كامل',
    ]);
    $booking->refresh();
    assertEq('إجمالي المدفوع بعد الدفعتين', 360.00, $booking->paid_amount, $errors, $passed);

    // إلغاء آجل بغرامات فقط → partially_refunded
    $creditCancelBooking = $busBookingService->createBooking([
        'inventory_id' => $inventory->id,
        'customer_id' => $customer->id,
        'quantity' => 1,
        'account_id' => $officeAccount->id,
        'notes' => 'حجز آجل للإلغاء بغرامات',
    ]);

    $creditRefund = $busBookingService->cancelBooking($creditCancelBooking, [
        'company_penalty' => 10.00,
        'office_penalty' => 15.00,
    ]);
    $creditCancelBooking->refresh();
    $creditStatus = $creditCancelBooking->status instanceof \App\Enums\BusBookingStatus
        ? $creditCancelBooking->status->value
        : (string) $creditCancelBooking->status;
    assertStr('حالة الحجز الآجل بعد الإلغاء بغرامات', 'partially_refunded', $creditStatus, $errors, $passed);
    assertStr('حالة طلب الاسترداد (آجل)', 'processed', (string) $creditRefund->status, $errors, $passed);
    assertEq('مبلغ الاسترداد (آجل)', 0.00, $creditRefund->refund_amount, $errors, $passed);

    // إلغاء مدفوع → refunded (يجب الدفع أولاً — createBooking لا يقبل paid_amount)
    $paidCancelBooking = $busBookingService->createBooking([
        'inventory_id' => $inventory->id,
        'customer_id' => $customer->id,
        'quantity' => 2,
        'account_id' => $officeAccount->id,
        'notes' => 'حجز للإلغاء مع استرداد نقدي',
    ]);

    $busBookingService->payBooking($paidCancelBooking, [
        'amount' => 240.00,
        'account_id' => $officeAccount->id,
        'notes' => 'سداد كامل قبل الإلغاء',
    ]);

    $refund = $busBookingService->cancelBooking($paidCancelBooking->fresh(), [
        'company_penalty' => 0,
        'office_penalty' => 40.00,
        'account_id' => $officeAccount->id,
    ]);
    $paidCancelBooking->refresh();
    $cancelStatus = $paidCancelBooking->status instanceof \App\Enums\BusBookingStatus
        ? $paidCancelBooking->status->value
        : (string) $paidCancelBooking->status;

    assertStr('حالة الحجز بعد الإلغاء المدفوع', 'refunded', $cancelStatus, $errors, $passed);
    assertStr('حالة طلب الاسترداد', 'processed', (string) $refund->status, $errors, $passed);
    assertEq('مبلغ الاسترداد', 200.00, $refund->refund_amount, $errors, $passed);
    assertEq('الغرامة = office_penalty', 40.00, $refund->office_penalty, $errors, $passed);
    echo "  ✅ إلغاء مدفوع: حالة الحجز = {$cancelStatus} | مسترد = {$refund->refund_amount} | غرامة = {$refund->office_penalty}\n\n";

    // ══════════════════════════════════════════════════════════════
    // 3. FAWRY MODULE
    // ══════════════════════════════════════════════════════════════
    echo "3. موديول فوري\n$sep\n";

    $fawryService = app(FawryTransactionService::class);
    $machine = FawryMachine::where('is_active', true)->first();
    if (! $machine) {
        throw new \RuntimeException('لا يوجد جهاز فوري نشط');
    }

    $opType = DB::table('fawry_operation_types')->first();
    $opCode = $opType ? $opType->code : 'recharge';
    $payM = DB::table('fawry_payment_methods')->first();
    $payCode = $payM ? $payM->code : 'cash';
    $currId = DB::table('fawry_currencies')->value('id');

    $fTx1 = $fawryService->createTransaction([
        'operation_type' => $opCode,
        'client_name' => $customer->full_name,
        'client_id' => $customer->id,
        'client_amount' => 500.00,
        'fawry_price' => 480.00,
        'selling_price' => 510.00,
        'amount' => 510.00,
        'employee_id' => $admin->id,
        'account_id' => $officeAccount->id,
        'currency_id' => $currId,
        'payment_method' => $payCode,
        'reference_number' => 'FAW-SIM-001',
        'fawry_machine_id' => $machine->id,
        'notes' => 'فوري اختبار 1',
    ]);

    assertEq('ربح فوري #1 (510-480)', 30.00, $fTx1->profit, $errors, $passed);

    $fTx2 = $fawryService->createTransaction([
        'operation_type' => $opCode,
        'client_name' => 'عميل عابر فوري',
        'client_id' => null,
        'client_amount' => 1000.00,
        'fawry_price' => 950.00,
        'selling_price' => 1020.00,
        'amount' => 1020.00,
        'employee_id' => $admin->id,
        'account_id' => $officeAccount->id,
        'currency_id' => $currId,
        'payment_method' => $payCode,
        'reference_number' => 'FAW-SIM-002',
        'fawry_machine_id' => $machine->id,
        'notes' => 'فوري اختبار 2',
    ]);

    assertEq('ربح فوري #2 (1020-950)', 70.00, $fTx2->profit, $errors, $passed);
    echo '  ✅ إجمالي أرباح فوري: '.number_format($fTx1->profit + $fTx2->profit, 2)." EGP\n\n";

    // ══════════════════════════════════════════════════════════════
    // 4. ONLINE SERVICES MODULE
    // ══════════════════════════════════════════════════════════════
    echo "4. موديول الخدمات الإلكترونية\n$sep\n";

    $onlineService = app(OnlineTransactionService::class);
    $serviceType = OnlineServiceType::where('is_active', true)->first();
    $serviceProvider = OnlineServiceProvider::first();
    if (! $serviceType) {
        throw new \RuntimeException('لا يوجد نوع خدمة إلكترونية نشط');
    }

    $oTx1 = $onlineService->create([
        'service_type_id' => $serviceType->id,
        'provider_id' => $serviceProvider?->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->full_name,
        'customer_phone' => $customer->phone,
        'customer_country' => 'EG',
        'purchase_price' => 90.00,
        'selling_price' => 110.00,
        'amount_paid' => 110.00,
        'account_id' => $officeAccount->id,
        'payment_method' => 'cash',
        'reference_number' => 'ONL-SIM-001',
        'status' => 'completed',
        'notes' => 'خدمة إلكترونية اختبار 1',
    ]);
    assertEq('ربح خدمة إلكترونية #1 (110-90)', 20.00, $oTx1->profit, $errors, $passed);

    $oTx2 = $onlineService->create([
        'service_type_id' => $serviceType->id,
        'provider_id' => $serviceProvider?->id,
        'customer_id' => null,
        'customer_name' => 'عميل نقدي عابر',
        'customer_phone' => '01000000000',
        'customer_country' => 'EG',
        'purchase_price' => 200.00,
        'selling_price' => 230.00,
        'amount_paid' => 230.00,
        'account_id' => $officeAccount->id,
        'payment_method' => 'cash',
        'reference_number' => 'ONL-SIM-002',
        'status' => 'completed',
        'notes' => 'خدمة إلكترونية اختبار 2',
    ]);
    assertEq('ربح خدمة إلكترونية #2 (230-200)', 30.00, $oTx2->profit, $errors, $passed);
    echo '  ✅ إجمالي أرباح الخدمات الإلكترونية: '.number_format($oTx1->profit + $oTx2->profit, 2)." EGP\n\n";

    // ══════════════════════════════════════════════════════════════
    // 5. WALLET MODULE
    // ══════════════════════════════════════════════════════════════
    echo "5. موديول المحافظ\n$sep\n";

    $walletService = app(WalletTransactionService::class);
    $walletType = WalletType::first();
    if (! $walletType) {
        throw new \RuntimeException('لا يوجد نوع محفظة');
    }

    $walletAccount = Account::where('type', 'wallet')->where('is_active', true)->first();
    if (! $walletAccount) {
        $walletAccount = Account::create([
            'name' => 'محفظة إنستاباي - اختبار',
            'type' => 'wallet',
            'balance' => 10_000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'wallet_transfer',
            'created_by' => $admin->id,
        ]);
    }

    $wTx1 = $walletService->createTransaction([
        'wallet_type_id' => $walletType->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->full_name,
        'wallet_number' => '01099991111',
        'type' => 'send',
        'amount' => 500.00,
        'service_fee' => 15.00,
        'wallet_account_id' => $walletAccount->id,
        'cash_account_id' => $officeAccount->id,
        'notes' => 'إرسال محفظة اختبار',
        'created_by' => $admin->id,
    ]);
    assertEq('إجمالي معاملة المحفظة #1 (send = amount+fee)', 515.00, $wTx1->total_amount, $errors, $passed);

    $wTx2 = $walletService->createTransaction([
        'wallet_type_id' => $walletType->id,
        'customer_name' => 'مرسل خارجي',
        'wallet_number' => '01155552222',
        'type' => 'receive',
        'amount' => 800.00,
        'service_fee' => 20.00,
        'wallet_account_id' => $walletAccount->id,
        'cash_account_id' => $officeAccount->id,
        'notes' => 'استلام محفظة اختبار',
        'created_by' => $admin->id,
    ]);
    assertEq('إجمالي معاملة المحفظة #2 (receive = amount-fee)', 780.00, $wTx2->total_amount, $errors, $passed);
    echo '  ✅ إجمالي رسوم المحافظ: '.number_format($wTx1->service_fee + $wTx2->service_fee, 2)." EGP\n\n";

    // ══════════════════════════════════════════════════════════════
    // 6. TRANSFERS
    // ══════════════════════════════════════════════════════════════
    echo "6. موديول التحويلات المالية\n$sep\n";

    $financeService = app(TransactionService::class);
    $secondAccount = Account::firstOrCreate(
        ['name' => 'خزينة فرع 2 - سيناريو'],
        [
            'type' => 'cashbox',
            'balance' => 50_000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $admin->id,
        ]
    );

    $officeAccount->refresh();
    $secondAccount->refresh();
    $officeBalanceBefore = (float) $officeAccount->balance;
    $secondBalanceBefore = (float) $secondAccount->balance;

    $financeService->recordTransfer([
        'from_account_id' => $officeAccount->id,
        'to_account_id' => $secondAccount->id,
        'amount' => 5_000.00,
        'notes' => 'تحويل اختبار من المكتب للفرع 2',
        'module' => 'office',
    ]);

    $officeAccount->refresh();
    $secondAccount->refresh();
    assertEq('رصيد خزينة المكتب بعد التحويل', $officeBalanceBefore - 5_000.00, $officeAccount->balance, $errors, $passed);
    assertEq('رصيد خزينة الفرع 2 بعد التحويل', $secondBalanceBefore + 5_000.00, $secondAccount->balance, $errors, $passed);
    echo "  ✅ تحويل 5,000 EGP: مكتب → فرع 2\n\n";

    // ══════════════════════════════════════════════════════════════
    // 7. DOUBLE-ENTRY INTEGRITY
    // ══════════════════════════════════════════════════════════════
    echo "7. فحص سلامة القيد المزدوج\n$sep\n";

    $ledger = DB::table('account_entries')
        ->selectRaw('SUM(debit) as d, SUM(credit) as c, ABS(SUM(debit)-SUM(credit)) as diff')
        ->first();

    $diff = (float) $ledger->diff;
    if ($diff < 0.01) {
        $passed[] = '✅ القيد المزدوج سليم 100%';
        echo "  ✅ القيد المزدوج متزن (فرق = ".number_format($diff, 4).")\n";
    } else {
        $errors[] = '❌ خلل في القيد المزدوج! الفرق='.number_format($diff, 4);
        echo '  ❌ خلل في القيد المزدوج! الفرق='.number_format($diff, 4)."\n";
    }

    $imbalanced = DB::table('account_entries')
        ->selectRaw('transaction_id, ABS(SUM(debit)-SUM(credit)) as diff')
        ->groupBy('transaction_id')
        ->havingRaw('ABS(SUM(debit)-SUM(credit)) > 0.01')
        ->count();

    if ($imbalanced === 0) {
        $passed[] = '✅ لا توجد معاملات غير متوازنة';
        echo "  ✅ كل المعاملات متوازنة\n\n";
    } else {
        $errors[] = "❌ يوجد {$imbalanced} معاملة غير متوازنة!";
        echo "  ❌ يوجد {$imbalanced} معاملة غير متوازنة!\n\n";
    }

    // ══════════════════════════════════════════════════════════════
    // 8. OFFICE TRIAL BALANCE
    // ══════════════════════════════════════════════════════════════
    echo "8. ميزان مراجعة قسم المكتب\n$sep\n";

    $treasury = app(TreasuryService::class);
    $officeTB = $treasury->getOfficeTrialBalance();

    $computedCurrent = ($officeTB['total_balances'] + $officeTB['total_liquidity'] + $officeTB['due_to_us']) - $officeTB['due_from_us'];
    $computedExpected = $officeTB['base_capital'] + $officeTB['profits'];

    assertEq('معادلة رأس المال الحالي', $computedCurrent, $officeTB['current_capital'], $errors, $passed);
    assertEq('معادلة رأس المال المتوقع', $computedExpected, $officeTB['expected_capital'], $errors, $passed);
    assertEq('معادلة الفارق', $officeTB['current_capital'] - $officeTB['expected_capital'], $officeTB['variance'], $errors, $passed);

    echo '  📊 أرباح المكتب (ديناميكية): '.number_format($officeTB['profits'], 2)." EGP\n";
    echo '  🏦 إجمالي السيولة: '.number_format($officeTB['total_liquidity'], 2)." EGP\n";
    echo '  📈 حالة الميزان: '.$officeTB['status']."\n\n";

    // ══════════════════════════════════════════════════════════════
    // 9. FINAL RESULT
    // ══════════════════════════════════════════════════════════════
    echo "══════════════════════════════════════════════════════════════\n";
    echo "  النتيجة النهائية\n";
    echo "══════════════════════════════════════════════════════════════\n";
    echo '  ✅ اجتازت: '.count($passed)." اختبار\n";
    echo '  ❌ فشلت  : '.count($errors)." اختبار\n\n";

    $total = count($passed) + count($errors);
    $percentage = $total > 0 ? round(count($passed) / $total * 100, 1) : 0;
    echo "  📈 نسبة النجاح: {$percentage}%\n";

    if (empty($errors)) {
        echo "\n  🎉 جميع الاختبارات نجحت — نسبة خطأ المحاسبة = 0%\n";
    } else {
        echo "\n  ⚠️  الأخطاء:\n";
        foreach ($errors as $e) {
            echo "     $e\n";
        }
    }

    echo "\n══════════════════════════════════════════════════════════════\n";

    $exitCode = empty($errors) ? 0 : 1;
} catch (\Throwable $e) {
    echo "\n❌ استثناء: ".$e->getMessage()."\n";
    echo 'at '.$e->getFile().':'.$e->getLine()."\n";
    $exitCode = 1;
} finally {
    if ($commit ?? false) {
        DB::commit();
        echo "\n=== تم حفظ الحركات في قاعدة البيانات (COMMIT) ===\n";
    } else {
        DB::rollBack();
        echo "\n=== تم التراجع عن التغييرات (DB ROLLBACK) ===\n";
    }
    exit($exitCode ?? 1);
}
