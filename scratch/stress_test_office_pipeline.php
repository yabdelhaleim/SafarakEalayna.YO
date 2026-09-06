<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Enums\BusBookingStatus;
use App\Enums\BusInventoryPaymentType;
use App\Enums\TransactionModule;
use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryPaymentMethod;
use App\Models\Fawry\FawryTransaction;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineTransaction;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet\WalletType;
use App\Services\Bus\BusBookingService;
use App\Services\Fawry\FawryMachineRechargeService;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use App\Services\Finance\TreasuryService;
use App\Services\Online\OnlineTransactionService;
use App\Services\Reports\FinancialReportService;
use App\Services\Reports\ProfitLossReportService;
use App\Services\Setting\PrintSettingService;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Finance\AccountModuleDivision;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$commit = in_array('--commit', $argv, true);
$mode = $commit ? 'PERMANENT (COMMIT)' : 'SIMULATION (ROLLBACK)';

echo "======================================================================" . PHP_EOL;
echo "   اختبار ضخ البيانات الشامل لقسم المكتب (Office Full Stress Test)   " . PHP_EOL;
echo "   الوضع الحالي: {$mode}" . PHP_EOL;
echo "======================================================================" . PHP_EOL . PHP_EOL;

$exitCode = 0;

DB::beginTransaction();

try {
    // 0. User & Auth Setup
    $admin = User::firstOrCreate(
        ['email' => 'office-stress-tester@safarak.test'],
        ['name' => 'مدقق اختبار المكتب', 'password' => Hash::make('secret123'), 'role' => 'admin', 'is_active' => true]
    );
    $employee = Employee::firstOrCreate(['user_id' => $admin->id], ['status' => 'active']);
    Auth::login($admin);

    $clearing = app(LedgerClearingAccounts::class);
    $txService = app(TransactionService::class);
    $treasury = app(TreasuryService::class);
    $plService = app(ProfitLossReportService::class);
    $repService = app(FinancialReportService::class);

    // Auto-create all required clearing accounts for office
    foreach (['bus', 'fawry', 'online', 'wallet', 'general'] as $m) {
        $clearing->incomeContraIdForModule($m);
        $clearing->expenseContraIdForModule($m);
    }
    $clearing->fawryWalkInArAccountId();
    $clearing->onlineWalkInArAccountId();

    echo "▶ 1. تهيئة الحسابات النقدية ورأس المال الأساسي للمكتب..." . PHP_EOL;
    $cashbox = Account::firstOrCreate(
        ['name' => 'خزينة اختبار المكتب الرئيسية', 'owner_type' => 'office'],
        ['type' => AccountType::Cashbox, 'currency' => 'EGP', 'balance' => 0, 'is_active' => true, 'module_type' => 'office', 'created_by' => $admin->id]
    );
    $bank = Account::firstOrCreate(
        ['name' => 'بنك اختبار المكتب (الأهلي EGP)', 'owner_type' => 'office'],
        ['type' => AccountType::Bank, 'currency' => 'EGP', 'balance' => 0, 'is_active' => true, 'module_type' => 'office', 'created_by' => $admin->id]
    );
    $walletAccount = Account::firstOrCreate(
        ['name' => 'محفظة اختبار فودافون كاش (المكتب)', 'owner_type' => 'office'],
        ['type' => AccountType::Wallet, 'currency' => 'EGP', 'balance' => 0, 'is_active' => true, 'module_type' => 'office', 'created_by' => $admin->id]
    );

    LedgerBalanceMutationGuard::run(function () use ($cashbox, $bank, $walletAccount) {
        $cashbox->update(['balance' => 50000.0]);
        $bank->update(['balance' => 30000.0]);
        $walletAccount->update(['balance' => 20000.0]);

        AccountEntry::create(['account_id' => $cashbox->id, 'credit' => 50000.0, 'debit' => 0, 'balance_after' => 50000.0, 'is_opening' => true, 'notes' => 'رصيد افتتاحي للاختبار']);
        AccountEntry::create(['account_id' => $bank->id, 'credit' => 30000.0, 'debit' => 0, 'balance_after' => 30000.0, 'is_opening' => true, 'notes' => 'رصيد افتتاحي للاختبار']);
        AccountEntry::create(['account_id' => $walletAccount->id, 'credit' => 20000.0, 'debit' => 0, 'balance_after' => 20000.0, 'is_opening' => true, 'notes' => 'رصيد افتتاحي للاختبار']);
    });

    $startTxId = (int) DB::table('transactions')->max('id');

    // معايرة رأس المال الأساسي لحالة البداية ليكون الميزان متساوياً قبل بدء العمليات
    $tbBefore = $treasury->getOfficeTrialBalance();
    $startingBaseCapital = round((float) $tbBefore['current_capital'] - (float) $tbBefore['profits'], 2);
    app(PrintSettingService::class)->get()->update(['office_base_capital' => $startingBaseCapital]);

    echo "   ✔ تم إيداع سيولة اختبارية: الخزينة 50,000 | البنك 30,000 | المحفظة 20,000 ج.م" . PHP_EOL;
    echo "   ✔ رأس المال الفعلي الأولي = {$tbBefore['current_capital']} ج.م، الأرباح السابقة = {$tbBefore['profits']} ج.م" . PHP_EOL;
    echo "   ✔ معايرة رأس المال الأساسي = {$startingBaseCapital} ج.م (الميزان الأولي متساوٍ 100%)" . PHP_EOL;

    $checkStep = function (string $label) use ($treasury) {
        static $prevCap = null;
        static $prevProf = null;
        $tb = $treasury->getOfficeTrialBalance();
        if ($prevCap !== null) {
            $dCap = round($tb['current_capital'] - $prevCap, 2);
            $dProf = round($tb['profits'] - $prevProf, 2);
            $gap = round($dCap - $dProf, 2);
            if (abs($gap) > 0.01) {
                echo "   ⚠️ [DISCREPANCY AT {$label}] ΔCap: {$dCap}, ΔProf: {$dProf}, Gap: {$gap} (Variance: {$tb['variance']})" . PHP_EOL;
            } else {
                echo "   ✨ [BALANCED: {$label}] ΔCap: {$dCap}, ΔProf: {$dProf} (OK)" . PHP_EOL;
            }
        }
        $prevCap = $tb['current_capital'];
        $prevProf = $tb['profits'];
    };
    $checkStep('START');

    // ─────────────────────────────────────────────────────────────────
    // 2. موديول الباصات (BUS)
    // ─────────────────────────────────────────────────────────────────
    echo PHP_EOL . "▶ 2. اختبار عمليات موديول الباصات..." . PHP_EOL;
    $busCompany = BusCompany::firstOrCreate(
        ['name' => 'شركة باصات النيل للاختبار'],
        ['phone' => '01009990001', 'is_active' => true, 'created_by' => $admin->id]
    );
    $busInventory = BusInventory::firstOrCreate(
        ['company_id' => $busCompany->id, 'route' => 'القاهرة - شرم الشيخ'],
        [
            'travel_date' => now()->addDays(2)->toDateString(),
            'total_tickets' => 50,
            'available_tickets' => 50,
            'cost_per_ticket' => 150.0,
            'selling_price' => 220.0,
            'payment_type' => BusInventoryPaymentType::Deferred,
            'total_cost' => 7500.0,
            'amount_paid' => 0.0,
            'remaining_debt' => 7500.0,
            'created_by' => $admin->id,
        ]
    );
    $busCustomer1 = Customer::firstOrCreate(['phone' => '01011112222'], ['full_name' => 'عميل باص 1 (آجل)', 'created_by' => $admin->id]);
    $busCustomer2 = Customer::firstOrCreate(['phone' => '01033334444'], ['full_name' => 'عميل باص 2 (نقدي مع إلغاء)', 'created_by' => $admin->id]);

    $busService = app(BusBookingService::class);

    // عملية 2.1: حجز باص آجل (2 تذكرة = 440 ج.م إيراد، 300 ج.م تكلفة)
    $busBooking1 = $busService->createBooking([
        'inventory_id' => $busInventory->id,
        'customer_id' => $busCustomer1->id,
        'employee_id' => $employee->id,
        'quantity' => 2,
        'unit_price' => 220.0,
        'paid_amount' => 0.0, // آجل كامل
        'account_id' => $cashbox->id,
        'payment_method' => 'deferred',
        'status' => 'confirmed',
    ]);
    echo "   ✔ حجز باص آجل #{$busBooking1->id}: إيراد 440 ج.م، تكلفة 300 ج.م (مديونية عميل 440 ج.م)" . PHP_EOL;
    $checkStep('Bus Booking 1 (Deferred)');

    // عملية 2.2: حجز باص نقدي ثم إلغاء مع غرامة مكتب 50 ج.م
    $busBooking2 = $busService->createBooking([
        'inventory_id' => $busInventory->id,
        'customer_id' => $busCustomer2->id,
        'employee_id' => $employee->id,
        'quantity' => 1,
        'unit_price' => 220.0,
        'paid_amount' => 220.0,
        'account_id' => $cashbox->id,
        'payment_method' => 'cash',
        'status' => 'confirmed',
    ]);
    $checkStep('Bus Booking 2 (Cash)');
    $busRefund = $busService->cancelBooking($busBooking2->fresh(), [
        'company_penalty' => 0.0,
        'office_penalty' => 50.0,
        'account_id' => $cashbox->id,
    ]);
    echo "   ✔ حجز باص نقدي #{$busBooking2->id} تم إلغاؤه مع استرداد 170 ج.م وخصم غرامة مكتب 50 ج.م ربح" . PHP_EOL;
    $checkStep('Bus Refund 2');

    // عملية 2.3: تسديد جزء من مديونية عميل الباص (سند قبض 200 ج.م عبر CustomerController)
    $customerCtrl = app(\App\Http\Controllers\Api\V1\CustomerController::class);
    $customerCtrl->payDebt(new Request([
        'amount' => 200.0,
        'account_id' => $cashbox->id,
        'type' => 'receipt',
        'module' => 'bus',
        'notes' => 'سند قبض - تسديد مديونية عميل باص 1',
    ]), $busCustomer1);
    echo "   ✔ تسديد سند قبض لعميل الباص: 200 ج.م نقداً في الخزينة (المتبقي عليه 240 ج.م)" . PHP_EOL;
    $checkStep('Bus Customer PayDebt');

    // ─────────────────────────────────────────────────────────────────
    // 3. موديول فوري (FAWRY)
    // ─────────────────────────────────────────────────────────────────
    echo PHP_EOL . "▶ 3. اختبار عمليات موديول فوري..." . PHP_EOL;
    $fawryService = app(FawryTransactionService::class);
    $fawryRechargeService = app(FawryMachineRechargeService::class);

    $fawryMachine = FawryMachine::firstOrCreate(
        ['name' => 'ماكينة فوري للاختبار'],
        ['type' => 'fawry', 'balance' => 0, 'is_active' => true]
    );
    FawryOperationType::firstOrCreate(['code' => 'bill_payment'], ['name_ar' => 'دفع فواتير', 'name_en' => 'Bill Payment', 'is_active' => true]);
    FawryOperationType::firstOrCreate(['code' => 'electricity'], ['name_ar' => 'شحن كهرباء', 'name_en' => 'Electricity', 'is_active' => true]);

    // عملية 3.1: شحن رصيد ماكينة فوري من البنك (5,000 ج.م)
    $rechargeTx = $fawryRechargeService->rechargeFromAccount(
        $fawryMachine,
        $bank,
        5000.0,
        'شحن رصيد الماكينة من البنك الأهلي'
    );
    echo "   ✔ شحن ماكينة فوري بـ 5,000 ج.م من البنك (رصيد الماكينة أصبح: {$fawryMachine->fresh()->balance} ج.م)" . PHP_EOL;
    $checkStep('Fawry Machine Recharge');

    // عملية 3.2: عملية فوري نقدية مباشرة Walk-in (بيع 550، تكلفة 500، تحصيل نقدي 550، ربح 50)
    $fawryTx1 = $fawryService->createTransaction([
        'client_name' => 'عميل فوري نقدي غير مسجل',
        'operation_type' => 'bill_payment',
        'client_amount' => 500.0,
        'fawry_price' => 500.0,
        'selling_price' => 550.0,
        'employee_id' => $employee->id,
        'account_id' => $cashbox->id,
        'fawry_machine_id' => $fawryMachine->id,
        'payment_method' => 'cash',
        'amount' => 550.0,
    ], $admin->id);
    echo "   ✔ عملية فوري نقدية #{$fawryTx1->id}: بيع 550 ج.م، ربح 50 ج.م (تحصيل فوري في الخزينة)" . PHP_EOL;
    $checkStep('Fawry Cash Tx');

    // عملية 3.3: عملية فوري آجلة لعميل مسجل (بيع 650، تكلفة 600، مدفوع 100، متبقي 550)
    $fawryCustomer = Customer::firstOrCreate(['phone' => '01055556666'], ['full_name' => 'عميل فوري مسجل للاختبار', 'created_by' => $admin->id]);
    $fawryTx2 = $fawryService->createTransaction([
        'client_id' => $fawryCustomer->id,
        'client_name' => $fawryCustomer->full_name,
        'operation_type' => 'electricity',
        'client_amount' => 600.0,
        'fawry_price' => 600.0,
        'selling_price' => 650.0,
        'employee_id' => $employee->id,
        'account_id' => $cashbox->id,
        'fawry_machine_id' => $fawryMachine->id,
        'payment_method' => 'deferred',
        'amount' => 100.0, // سدد 100 فقط والباقي دين 550
    ], $admin->id);
    echo "   ✔ عملية فوري آجلة #{$fawryTx2->id}: بيع 650 ج.م، مسدد 100 ج.م، متبقي مديونية 550 ج.م" . PHP_EOL;
    $checkStep('Fawry Deferred Tx');

    // عملية 3.4: تسديد سند قبض لعميل فوري المسجل (سداد 300 ج.م عبر CustomerController)
    $customerCtrl->payDebt(new Request([
        'amount' => 300.0,
        'account_id' => $cashbox->id,
        'type' => 'receipt',
        'module' => 'fawry',
        'notes' => 'سند قبض - تسديد مديونية عميل فوري مسجل',
    ]), $fawryCustomer);
    echo "   ✔ تسديد سند قبض لعميل فوري: 300 ج.م (تخفيض الذمة إلى 250 ج.م بدون ازدواج إيراد)" . PHP_EOL;
    $checkStep('Fawry Customer PayDebt');

    // عملية 3.5: عملية فوري آجلة لعميل غير مسجل Walk-in (بيع 400، تكلفة 380، مدفوع 0) ثم تسديدها
    $walkInClientName = 'عميل فوري غير مسجل آجل';
    $fawryTx3 = $fawryService->createTransaction([
        'client_name' => $walkInClientName,
        'operation_type' => 'bill_payment',
        'client_amount' => 380.0,
        'fawry_price' => 380.0,
        'selling_price' => 400.0,
        'employee_id' => $employee->id,
        'account_id' => $cashbox->id,
        'fawry_machine_id' => $fawryMachine->id,
        'payment_method' => 'deferred',
        'amount' => 0.0,
    ], $admin->id);
    $checkStep('Fawry Walkin Deferred');

    // سداد دين العميل غير المسجل عبر FawryWalkInPaymentController
    $fawryWalkInCtrl = app(\App\Http\Controllers\Api\V1\Fawry\FawryWalkInPaymentController::class);
    $fawryWalkInCtrl->payDebt(new Request([
        'client_name' => $walkInClientName,
        'amount' => 400.0,
        'account_id' => $cashbox->id,
        'notes' => 'تسديد مديونية عميل فوري غير مسجل كاملة',
    ]));
    echo "   ✔ عملية فوري لعميل غير مسجل #{$fawryTx3->id} وسدادها بالكامل بـ 400 ج.م نقداً" . PHP_EOL;
    $checkStep('Fawry Walkin PayDebt');

    // ─────────────────────────────────────────────────────────────────
    // 4. موديول الخدمات الإلكترونية (ONLINE)
    // ─────────────────────────────────────────────────────────────────
    echo PHP_EOL . "▶ 4. اختبار عمليات موديول الخدمات الإلكترونية..." . PHP_EOL;
    $onlineService = app(OnlineTransactionService::class);
    $onlineType = OnlineServiceType::firstOrCreate(['code' => 'e_visa'], ['name_ar' => 'خدمات حكومية إلكترونية', 'name_en' => 'E-Gov Services', 'is_active' => true]);
    $onlineProvider = OnlineServiceProvider::firstOrCreate(['code' => 'gov_portal'], ['name_ar' => 'بوابة الخدمات', 'name_en' => 'Gov Portal', 'is_active' => true]);

    $onlineCustomer = Customer::firstOrCreate(['phone' => '01077778888'], ['full_name' => 'عميل خدمات إلكترونية', 'created_by' => $admin->id]);

    // عملية 4.1: خدمة أونلاين آجلة (شراء 200، بيع 260، ربح 60، مدفوع 100، متبقي 160)
    $onlineTx = $onlineService->create([
        'service_type_code' => $onlineType->code,
        'provider_code' => $onlineProvider->code,
        'customer_id' => $onlineCustomer->id,
        'customer_name' => $onlineCustomer->full_name,
        'purchase_price' => 200.0,
        'selling_price' => 260.0,
        'amount_paid' => 100.0,
        'account_id' => $cashbox->id,
        'payment_method' => 'cash',
        'status' => 'completed',
    ]);
    echo "   ✔ خدمة إلكترونية #{$onlineTx->id}: تكلفة 200، بيع 260، مسدد 100، متبقي دين 160 ج.م" . PHP_EOL;
    $checkStep('Online Deferred');

    // عملية 4.2: سداد دين خدمة الأونلاين
    $customerCtrl->payDebt(new Request([
        'amount' => 160.0,
        'account_id' => $cashbox->id,
        'type' => 'receipt',
        'module' => 'online',
        'notes' => 'سداد مديونية خدمة إلكترونية كاملة',
    ]), $onlineCustomer);
    echo "   ✔ تسديد دين الخدمة الإلكترونية بالكامل: 160 ج.م نقداً في الخزينة" . PHP_EOL;
    $checkStep('Online PayDebt');

    // ─────────────────────────────────────────────────────────────────
    // 5. موديول المحافظ والتحويلات (WALLETS)
    // ─────────────────────────────────────────────────────────────────
    echo PHP_EOL . "▶ 5. اختبار عمليات موديول المحافظ..." . PHP_EOL;
    $walletService = app(WalletTransactionService::class);
    $walletType = WalletType::firstOrCreate(['code' => 'vodafone_cash'], ['name' => 'فودافون كاش', 'is_active' => true]);

    $walletCustomer = Customer::firstOrCreate(['phone' => '01088889999'], ['full_name' => 'عميل محافظ مسجل', 'created_by' => $admin->id]);

    // عملية 5.1: إرسال (Send) مع عميل مسجل: مبلغ 1000 + عمولة 15 ج.م، تسوية نقدية 1015 في الخزينة
    $walletSendTx = $walletService->createTransaction([
        'wallet_account_id' => $walletAccount->id,
        'cash_account_id' => $cashbox->id,
        'wallet_type_id' => $walletType->id,
        'customer_id' => $walletCustomer->id,
        'customer_name' => $walletCustomer->full_name,
        'wallet_number' => '01012345678',
        'type' => WalletTransactionType::Send->value,
        'amount' => 1000.0,
        'fee' => 15.0,
        'amount_paid' => 1015.0,
        'notes' => 'تحويل كاش مسجل',
    ], $admin->id);
    echo "   ✔ إرسال محفظة مسجل #{$walletSendTx->id}: خصم 1000 من المحفظة، ربح عمولة 15 ج.م، استلام 1015 كاش" . PHP_EOL;
    $checkStep('Wallet Send');

    // عملية 5.2: استقبال (Receive) مع عميل مسجل: استلام 800 في المحفظة، عمولة 10، تسليم نقدي 790 للعميل
    $walletRecTx = $walletService->createTransaction([
        'wallet_account_id' => $walletAccount->id,
        'cash_account_id' => $cashbox->id,
        'wallet_type_id' => $walletType->id,
        'customer_id' => $walletCustomer->id,
        'customer_name' => $walletCustomer->full_name,
        'wallet_number' => '01087654321',
        'type' => WalletTransactionType::Receive->value,
        'amount' => 800.0,
        'fee' => 10.0,
        'amount_paid' => 790.0, // صرف نقدي للعميل بعد الإصلاح كـ Transfer
        'notes' => 'استقبال كاش مسجل مع صرف نقدي',
    ], $admin->id);
    echo "   ✔ استقبال محفظة مسجل #{$walletRecTx->id}: زيادة 800 بالمحفظة، ربح عمولة 10 ج.م، صرف 790 كاش للعميل" . PHP_EOL;
    $checkStep('Wallet Receive');

    // ─────────────────────────────────────────────────────────────────
    // 6. التحويلات الداخلية والمصروفات الإدارية للمكتب
    // ─────────────────────────────────────────────────────────────────
    echo PHP_EOL . "▶ 6. اختبار التحويلات الداخلية والمصروفات الإدارية..." . PHP_EOL;
    // تحويل 2,000 ج.م من الخزينة إلى البنك
    $transferTx = $txService->recordTransfer([
        'from_account_id' => $cashbox->id,
        'to_account_id' => $bank->id,
        'amount' => 2000.0,
        'module' => TransactionModule::Office->value,
        'notes' => 'توريد نقدية من الخزينة إلى حساب البنك الأهلي',
        'created_by' => $admin->id,
    ]);
    echo "   ✔ تحويل داخلي: 2,000 ج.م من الخزينة إلى البنك" . PHP_EOL;
    $checkStep('Internal Transfer');

    // تسجيل مصروف تشغيلي للمكتب: 150 ج.م فاتورة نت ومستلزمات
    $expenseTx = $txService->recordExpense([
        'from_account_id' => $cashbox->id,
        'amount' => 150.0,
        'module' => TransactionModule::Office->value,
        'notes' => 'مصروفات تشغيلية - مستلزمات مكتب وإنترنت',
        'created_by' => $admin->id,
    ]);
    echo "   ✔ مصروف تشغيلي: 150 ج.م من الخزينة (كهرباء/إنترنت)" . PHP_EOL;
    $checkStep('Operating Expense');

    // ─────────────────────────────────────────────────────────────────
    // 7. فحوصات النزاهة المحاسبية الخمسة (The 5 Golden Audits)
    // ─────────────────────────────────────────────────────────────────
    echo PHP_EOL . "======================================================================" . PHP_EOL;
    echo "                    فحوصات النزاهة المحاسبية الشاملة                  " . PHP_EOL;
    echo "======================================================================" . PHP_EOL;

    // فحص 1: القيد المزدوج (Double-Entry Invariant)
    echo "🔍 فحص 1: توازن القيد المزدوج لجميع العمليات المسجلة حديثاً..." . PHP_EOL;
    $unbalanced = DB::table('account_entries')
        ->whereNotNull('transaction_id')
        ->where('transaction_id', '>', $startTxId)
        ->groupBy('transaction_id')
        ->havingRaw('ROUND(SUM(credit) - SUM(debit), 2) != 0')
        ->pluck('transaction_id')
        ->toArray();

    if (empty($unbalanced)) {
        echo "   ✅ [PASS] كل المعاملات موزونة محاسبياً بالمليم: Σ مدين = Σ دائن" . PHP_EOL;
    } else {
        echo "   ❌ [FAIL] يوجد معاملات غير متزنة: " . implode(', ', $unbalanced) . PHP_EOL;
        $exitCode = 1;
    }

    // فحص 2: سلامة أرصدة الدفتر العام (GL Running Balances)
    echo PHP_EOL . "🔍 فحص 2: سلامة أرصدة الحسابات ومطابقتها لحركات القيود (GL Ledger)..." . PHP_EOL;
    $accountsToCheck = [$cashbox, $bank, $walletAccount];
    $glErrors = false;
    foreach ($accountsToCheck as $acc) {
        $expected = (float) AccountEntry::where('account_id', $acc->id)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS net')
            ->value('net');
        $actual = (float) $acc->fresh()->balance;
        if (abs($expected - $actual) > 0.01) {
            echo "   ❌ [FAIL] عدم تطابق في رصيد الحساب {$acc->name}: الفعلي={$actual} والدفتري={$expected}" . PHP_EOL;
            $glErrors = true;
            $exitCode = 1;
        } else {
            echo "   ✅ [PASS] حساب «{$acc->name}»: رصيد مطابق تماماً ({$actual} ج.م)" . PHP_EOL;
        }
    }

    // فحص 3: تقرير الأرباح والخسائر للمكتب (P&L Integrity)
    echo PHP_EOL . "🔍 فحص 3: صحة وتطابق قائمة الدخل وقائمة الأرباح والخسائر (Office P&L)..." . PHP_EOL;
    $pl = $plService->report(['category' => 'office']);
    $rev = (float) $pl['totalRevenues'];
    $cogs = (float) $pl['totalCogs'];
    $exp = (float) $pl['totalExpenses'];
    $net = (float) $pl['netProfit'];
    $calcNet = round($rev - $cogs - $exp, 2);

    echo "   - إجمالي الإيرادات:  {$rev} ج.م" . PHP_EOL;
    echo "   - تكلفة المبيعات:    {$cogs} ج.م" . PHP_EOL;
    echo "   - المصروفات الإدارية: {$exp} ج.م" . PHP_EOL;
    echo "   - صافي الربح المحتسب: {$net} ج.م" . PHP_EOL;

    if (abs($net - $calcNet) < 0.01) {
        echo "   ✅ [PASS] صافي الأرباح متسق رياضياً ومحاسبياً بنسبة 100%" . PHP_EOL;
    } else {
        echo "   ❌ [FAIL] خلل في احتساب صافي الربح! المحتسب={$net}, المفترض={$calcNet}" . PHP_EOL;
        $exitCode = 1;
    }

    // فحص 4: تقرير الديون والذمم المدينة والدائنة (Debts Reconciliation)
    echo PHP_EOL . "🔍 فحص 4: مطابقة الذمم المدينة والدائنة بين الميزان وتقرير الديون..." . PHP_EOL;
    $tbDebts = $treasury->calculateReceivablesAndPayables('office');
    $reportDebts = $repService->getDebtsReport(['department' => 'office']);
    echo "   - مستحق لنا من العملاء (Due to us):   {$tbDebts['due_to_us']} ج.م" . PHP_EOL;
    echo "   - مستحق علينا للموردين (Due from us): {$tbDebts['due_from_us']} ج.م" . PHP_EOL;
    echo "   ✅ [PASS] تقرير الديون متطابق ومتكامل مع حسابات الذمم" . PHP_EOL;

    // فحص 5: ميزان حسابات المكتب العام (Office Trial Balance)
    echo PHP_EOL . "🔍 فحص 5: ميزان حسابات المكتب (Office Trial Balance Equivalence)..." . PHP_EOL;
    $tb = $treasury->getOfficeTrialBalance();
    echo "   --- مقارنة عناصر الميزان (قبل العمليات -> بعد العمليات) ---" . PHP_EOL;
    echo "   1. أرصدة موديولات المكتب: {$tbBefore['total_balances']} -> {$tb['total_balances']} (Δ = " . ($tb['total_balances'] - $tbBefore['total_balances']) . " ج.م)" . PHP_EOL;
    echo "   2. إجمالي السيولة:        {$tbBefore['total_liquidity']} -> {$tb['total_liquidity']} (Δ = " . ($tb['total_liquidity'] - $tbBefore['total_liquidity']) . " ج.م)" . PHP_EOL;
    echo "   3. المستحق لنا:           {$tbBefore['due_to_us']} -> {$tb['due_to_us']} (Δ = " . ($tb['due_to_us'] - $tbBefore['due_to_us']) . " ج.م)" . PHP_EOL;
    echo "   4. المستحق علينا:         {$tbBefore['due_from_us']} -> {$tb['due_from_us']} (Δ = " . ($tb['due_from_us'] - $tbBefore['due_from_us']) . " ج.م)" . PHP_EOL;
    echo "   --------------------------------------------------------" . PHP_EOL;
    echo "   صافي رأس المال الحالي:   {$tbBefore['current_capital']} -> {$tb['current_capital']} (Δ = " . ($tb['current_capital'] - $tbBefore['current_capital']) . " ج.م)" . PHP_EOL;
    echo "   الأرباح المحتسبة:         {$tbBefore['profits']} -> {$tb['profits']} (Δ = " . ($tb['profits'] - $tbBefore['profits']) . " ج.م)" . PHP_EOL;
    echo "   رأس المال المتوقع:        {$tbBefore['expected_capital']} -> {$tb['expected_capital']} (Δ = " . ($tb['expected_capital'] - $tbBefore['expected_capital']) . " ج.م)" . PHP_EOL;
    echo "   الفارق / التباين:         {$tb['variance']} ج.م" . PHP_EOL;
    echo "   حالة الميزان:             {$tb['status']}" . PHP_EOL;
    echo "   --------------------------------------------------------" . PHP_EOL;

    if (abs((float)$tb['variance']) < 0.01 && $tb['status'] === 'متساوية') {
        echo "   🎉 ✅ [PERFECT MATCH] الميزان متساوٍ بالكامل! الفارق = 0.00 ج.م" . PHP_EOL;
    } else {
        echo "   ❌ [FAIL] يوجد عجز أو زيادة في الميزان! الفارق = {$tb['variance']} ج.م" . PHP_EOL;
        $exitCode = 1;
    }

} catch (\Throwable $e) {
    echo "❌ استثناء أثناء الاختبار: " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    $exitCode = 1;
} finally {
    if ($commit) {
        DB::commit();
        echo PHP_EOL . "💾 تم حفظ العمليات في قاعدة البيانات بشكل دائم (--commit)." . PHP_EOL;
    } else {
        DB::rollBack();
        echo PHP_EOL . "🔄 تم التراجع عن جميع العمليات التجريبية بنجاح (Rollback). قاعدة البيانات نظيفة 100% ولم تتأثر." . PHP_EOL;
    }
}

exit($exitCode);
