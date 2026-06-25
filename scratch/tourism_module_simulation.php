<?php

/**
 * Tourism Module Comprehensive Simulation
 * Modules: Flights (EGP + USD) | Hajj & Umrah | Visa
 * Scenarios: bookings, partial/full payments, debts, refunds, cancellations
 *
 * التشغيل:
 *   php scratch/tourism_module_simulation.php           # rollback
 *   php scratch/tourism_module_simulation.php --commit  # حفظ في DB
 */

chdir(__DIR__.'/..');
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\VisaBooking;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TreasuryService;
use App\Services\Flight\FlightBookingService;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\Visa\VisaBookingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$errors = [];
$passed = [];
$sep = str_repeat('─', 60);
$commit = in_array('--commit', $argv ?? [], true);

function assertEq(string $label, $expected, $actual, array &$errors, array &$passed): void
{
    $ok = abs((float) $expected - (float) $actual) < 0.02;
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

function checkDoubleEntry(array &$errors, array &$passed): void
{
    $row = DB::table('account_entries')
        ->selectRaw('SUM(debit) as d, SUM(credit) as c, ABS(SUM(debit)-SUM(credit)) as diff')
        ->first();
    $diff = (float) $row->diff;
    if ($diff < 0.01) {
        $passed[] = '✅ القيد المزدوج سليم';
        echo "  ✅ القيد المزدوج: مدين=".number_format($row->d, 2).' دائن='.number_format($row->c, 2)." (فرق=0)\n";
    } else {
        $errors[] = "❌ خلل قيد مزدوج: فرق=$diff";
        echo "  ❌ خلل قيد مزدوج: فرق=".number_format($diff, 4)."\n";
    }

    $imbalanced = DB::table('account_entries')
        ->selectRaw('transaction_id')
        ->whereNotNull('transaction_id')
        ->groupBy('transaction_id')
        ->havingRaw('ABS(SUM(debit)-SUM(credit)) > 0.02')
        ->count();
    if ($imbalanced === 0) {
        $passed[] = '✅ كل المعاملات متوازنة';
        echo "  ✅ كل المعاملات متوازنة\n";
    } else {
        $errors[] = "❌ $imbalanced معاملة غير متوازنة";
        echo "  ❌ $imbalanced معاملة غير متوازنة\n";
    }
}

DB::beginTransaction();

try {
    $admin = \App\Models\User::where('role', 'admin')->first() ?? \App\Models\User::first();
    if (! $admin) {
        throw new \RuntimeException('لا يوجد مستخدم. شغّل: php artisan db:seed');
    }
    Auth::login($admin);

    echo "\n══════════════════════════════════════════════════════════════\n";
    echo "  سيناريو قسم السياحة الشامل — المستخدم: {$admin->name}\n";
    echo "══════════════════════════════════════════════════════════════\n\n";

    // ── إعداد ─────────────────────────────────────────────────────────────────
    echo "0. إعداد البيانات\n$sep\n";

    foreach (['flight', 'hajj_umra', 'visa'] as $module) {
        app(LedgerClearingAccounts::class)->incomeContraIdForModule($module);
        app(LedgerClearingAccounts::class)->expenseContraIdForModule($module);
    }

    $flightCashbox = Account::query()->where('name', 'خزينة الطيران الرئيسية')->first()
        ?? Account::query()->where('module_type', 'flights')->where('type', 'cashbox')->first();
    $hajjCashbox = Account::query()->where('name', 'خزينة الحج والعمرة الرئيسية')->first();
    $visaCashbox = Account::query()->where('name', 'خزينة التأشيرات الرئيسية')->first();

    if (! $flightCashbox || ! $hajjCashbox || ! $visaCashbox) {
        throw new \RuntimeException('حسابات السياحة غير موجودة. شغّل: php artisan db:seed');
    }

    DB::table('exchange_rates')->updateOrInsert(
        ['from_currency' => 'USD', 'to_currency' => 'EGP', 'is_active' => true],
        ['rate' => 50.00, 'effective_date' => now()->toDateString(), 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]
    );

    $customer = Customer::firstOrCreate(
        ['phone' => '01088887777'],
        ['full_name' => 'عميل سيناريو السياحة', 'email' => 'tourism.sim@test.com', 'created_by' => $admin->id]
    );

    $flightSystem = FlightSystem::create([
        'name' => 'نظام حجز سيناريو السياحة',
        'code' => 'SIM-'.substr(uniqid(), -6),
        'type' => 'gds',
        'balance' => 100_000.00,
        'currency' => 'EGP',
        'is_active' => true,
        'created_by' => $admin->id,
    ]);

    $flightCarrier = FlightCarrier::create([
        'name' => 'ناقل سيناريو السياحة',
        'code' => 'SIM'.substr(uniqid(), -4),
        'flight_system_id' => $flightSystem->id,
        'balance' => 80_000.00,
        'currency' => 'EGP',
        'is_active' => true,
        'created_by' => $admin->id,
    ]);

    $usdCarrier = FlightCarrier::create([
        'name' => 'United Airlines سيناريو',
        'code' => 'UA'.substr(uniqid(), -3),
        'flight_system_id' => $flightSystem->id,
        'balance' => 5_000.00,
        'currency' => 'USD',
        'is_active' => true,
        'created_by' => $admin->id,
    ]);

    $execCompany = HajjUmraExecutingCompany::firstOrCreate(
        ['name' => 'شركة منفذة — سيناريو السياحة'],
        ['phone' => '0221112222', 'is_active' => true]
    );

    $program = Program::create([
        'program_name' => 'برنامج عمرة — سيناريو المراجعة',
        'program_type' => 'umrah',
        'executing_company_id' => $execCompany->id,
        'total_nights' => 10,
        'mecca_hotel_name' => 'فندق مكة تجريبي',
        'mecca_nights' => 5,
        'medina_nights' => 5,
        'departure_date' => now()->addDays(15)->toDateString(),
        'return_date' => now()->addDays(25)->toDateString(),
        'airline' => 'EgyptAir',
        'departure_point' => 'CAI',
        'booking_status' => 'open',
        'default_purchase_price' => 12_000,
        'default_selling_price' => 18_000,
        'is_active' => true,
    ]);

    $visaAgent = VisaAgent::firstOrCreate(
        ['company_name' => 'وكيل تأشيرات — سيناريو'],
        ['phone' => '0223334444', 'is_active' => true]
    );

    echo "  ✅ عميل: {$customer->full_name}\n";
    echo "  ✅ خزائن: طيران#{$flightCashbox->id} | حج#{$hajjCashbox->id} | فيزا#{$visaCashbox->id}\n\n";

    $flightService = app(FlightBookingService::class);
    $hajjService = app(HajjUmraBookingService::class);
    $visaService = app(VisaBookingService::class);

    // ══════════════════════════════════════════════════════════════
    // 1. FLIGHTS EGP — حجز + دفع جزئي + سداد + إلغاء باسترداد
    // ══════════════════════════════════════════════════════════════
    echo "1. الطيران (EGP) — حجز ودفع وإلغاء\n$sep\n";

    $egpBooking = $flightService->createBooking([
        'customer_id' => $customer->id,
        'purchase_price' => 2_000,
        'selling_price' => 3_000,
        'currency' => 'EGP',
        'booking_channel_type' => 'system',
        'booking_channel_provider' => 'Amadeus',
        'flight_system_id' => $flightSystem->id,
        'purchase_balance_source' => 'system',
        'departure_date' => now()->addDays(3)->toDateString(),
        'passenger_count' => 1,
        'airline_name' => 'EgyptAir',
        'from_airport' => 'CAI',
        'to_airport' => 'DXB',
        'account_id' => $flightCashbox->id,
    ]);
    $egpBooking->update(['status' => FlightBookingStatus::CONFIRMED->value]);

    assertEq('ربح حجز طيران EGP', 1_000.00, $egpBooking->profit, $errors, $passed);

    $flightService->addPayment($egpBooking, [
        'amount' => 1_500.00,
        'account_id' => $flightCashbox->id,
        'payment_method' => 'cash',
        'notes' => 'دفعة أولى طيران',
    ]);
    $egpBooking->refresh();
    assertEq('مدفوع طيران بعد دفعة جزئية', 1_500.00, $egpBooking->paid_amount, $errors, $passed);

    $flightService->addPayment($egpBooking, [
        'amount' => 1_500.00,
        'account_id' => $flightCashbox->id,
        'payment_method' => 'cash',
        'notes' => 'سداد كامل طيران',
    ]);
    $egpBooking->refresh();
    assertEq('مدفوع طيران بعد السداد الكامل', 3_000.00, $egpBooking->paid_amount, $errors, $passed);

    $egpRefund = $flightService->cancelBooking($egpBooking, [
        'airline_penalty' => 200.00,
        'office_penalty' => 100.00,
        'account_id' => $flightCashbox->id,
        'notes' => 'إلغاء طيران EGP مع غرامات',
    ]);
    $egpBooking->refresh();
    $egpStatus = $egpBooking->status instanceof FlightBookingStatus
        ? $egpBooking->status->value
        : (string) $egpBooking->status;
    assertStr('حالة حجز طيران بعد الإلغاء', 'REFUNDED', strtoupper($egpStatus), $errors, $passed);
    assertEq('مبلغ استرداد طيران EGP (3000-300)', 2_700.00, $egpRefund->refund_amount, $errors, $passed);
    echo "\n";

    // ══════════════════════════════════════════════════════════════
    // 2. FLIGHTS EGP — حجز آجل (دين) بدون دفع
    // ══════════════════════════════════════════════════════════════
    echo "2. الطيران (EGP) — حجز آجل (ذممة عميل)\n$sep\n";

    $creditFlight = $flightService->createBooking([
        'customer_id' => $customer->id,
        'purchase_price' => 1_500,
        'selling_price' => 2_200,
        'currency' => 'EGP',
        'flight_carrier_id' => $flightCarrier->id,
        'purchase_balance_source' => 'carrier',
        'departure_date' => now()->addDays(5)->toDateString(),
        'passenger_count' => 1,
        'airline_name' => 'EgyptAir',
        'from_airport' => 'CAI',
        'to_airport' => 'JED',
        'account_id' => $flightCashbox->id,
    ]);
    $creditFlight->update(['status' => FlightBookingStatus::CONFIRMED->value]);
    assertEq('مدفوع حجز آجل طيران', 0.00, $creditFlight->fresh()->paid_amount, $errors, $passed);
    assertEq('ربح حجز آجل طيران', 700.00, $creditFlight->profit, $errors, $passed);
    echo "  ✅ حجز آجل طيران #{$creditFlight->id} — دين على العميل = 2,200 EGP\n\n";

    // ══════════════════════════════════════════════════════════════
    // 3. FLIGHTS USD — حجز + دفع + إلغاء
    // ══════════════════════════════════════════════════════════════
    echo "3. الطيران (USD) — حجز ودفع وإلغاء\n$sep\n";

    $usdBooking = $flightService->createBooking([
        'customer_id' => $customer->id,
        'purchase_price_foreign' => 80.00,
        'selling_price' => 100.00,
        'currency' => 'USD',
        'exchange_rate' => 50.00,
        'booking_channel_type' => 'system',
        'booking_channel_provider' => 'UA',
        'flight_carrier_id' => $usdCarrier->id,
        'purchase_balance_source' => 'carrier',
        'departure_date' => now()->addDays(7)->toDateString(),
        'passenger_count' => 1,
        'airline_name' => 'United Airlines',
        'from_airport' => 'CAI',
        'to_airport' => 'ORD',
        'account_id' => $flightCashbox->id,
    ]);
    $usdBooking->update(['status' => FlightBookingStatus::CONFIRMED->value]);

    $flightService->addPayment($usdBooking, [
        'amount' => 5_000.00,
        'account_id' => $flightCashbox->id,
        'payment_method' => 'cash',
        'notes' => 'سداد حجز دولار (5000 جنيه)',
    ]);

    $usdRefund = $flightService->cancelBooking($usdBooking, [
        'airline_penalty' => 500.00,
        'office_penalty' => 250.00,
        'account_id' => $flightCashbox->id,
        'notes' => 'إلغاء حجز USD',
    ]);
    assertEq('استرداد حجز USD (5000-750)', 4_250.00, $usdRefund->refund_amount, $errors, $passed);
    echo "\n";

    // ══════════════════════════════════════════════════════════════
    // 4. HAJJ & UMRAH — حجز + دفع جزئي + سداد + إلغاء حجز آجل
    // ══════════════════════════════════════════════════════════════
    echo "4. الحج والعمرة — حجز وديون وإلغاء\n$sep\n";

    $hajjPaid = $hajjService->create([
        'customer_id' => $customer->id,
        'program_id' => $program->id,
        'purchase_price' => 10_000,
        'selling_price' => 15_000,
        'currency' => 'EGP',
        'status' => 'confirmed',
        'account_id' => $hajjCashbox->id,
    ]);
    assertEq('ربح حج وعمرة', 5_000.00, $hajjPaid->profit, $errors, $passed);

    $hajjService->addPayment($hajjPaid, ['amount' => 6_000, 'account_id' => $hajjCashbox->id]);
    $hajjService->addPayment($hajjPaid, ['amount' => 9_000, 'account_id' => $hajjCashbox->id]);
    $hajjPaid = $hajjService->find($hajjPaid->id);
    assertEq('إجمالي مدفوع حج وعمرة', 15_000.00, $hajjPaid->payments->sum('amount'), $errors, $passed);

    $hajjCredit = $hajjService->create([
        'customer_id' => $customer->id,
        'program_id' => $program->id,
        'purchase_price' => 8_000,
        'selling_price' => 12_000,
        'currency' => 'EGP',
        'status' => 'confirmed',
        'account_id' => $hajjCashbox->id,
    ]);
    echo "  ✅ حج وعمرة آجل #{$hajjCredit->id} — دين 12,000 EGP\n";

    $hajjCancelled = $hajjService->cancel($hajjCredit, 'إلغاء حجز آجل — سيناريو');
    assertStr('حالة حج وعمرة بعد الإلغاء', 'cancelled', $hajjCancelled->status->value ?? $hajjCancelled->status, $errors, $passed);
    echo "\n";

    // ══════════════════════════════════════════════════════════════
    // 5. VISA — حجز + دفع جزئي + سداد + إلغاء
    // ══════════════════════════════════════════════════════════════
    echo "5. التأشيرات — حجز ودفع وإلغاء\n$sep\n";

    $visaBooking = $visaService->create([
        'customer_id' => $customer->id,
        'purchase_price' => 4_000,
        'selling_price' => 5_500,
        'service_fee' => 500,
        'currency' => 'EGP',
        'status' => 'submitted',
        'account_id' => $visaCashbox->id,
        'visa_details' => [
            'country' => 'الإمارات',
            'visa_type' => 'tourist',
            'visa_agent_id' => $visaAgent->id,
        ],
    ]);
    assertEq('ربح تأشيرة (5500+500-4000)', 2_000.00, $visaBooking->profit, $errors, $passed);

    $visaService->addPayment($visaBooking, ['amount' => 3_000, 'account_id' => $visaCashbox->id]);
    $visaService->addPayment($visaBooking, ['amount' => 3_000, 'account_id' => $visaCashbox->id]);
    $visaBooking = $visaService->find($visaBooking->id);
    assertEq('إجمالي مدفوع تأشيرة', 6_000.00, $visaBooking->payments->sum('amount'), $errors, $passed);

    $visaCredit = $visaService->create([
        'customer_id' => $customer->id,
        'purchase_price' => 2_500,
        'selling_price' => 3_500,
        'service_fee' => 300,
        'currency' => 'EGP',
        'status' => 'submitted',
        'account_id' => $visaCashbox->id,
        'visa_details' => [
            'country' => 'تركيا',
            'visa_type' => 'tourist',
            'visa_agent_id' => $visaAgent->id,
        ],
    ]);
    $visaCancelled = $visaService->cancel($visaCredit, 'إلغاء تأشيرة آجلة');
    assertStr('حالة تأشيرة بعد الإلغاء', 'cancelled', $visaCancelled->status->value ?? $visaCancelled->status, $errors, $passed);
    echo "\n";

    // ══════════════════════════════════════════════════════════════
    // 6. فحص القيد المزدوج
    // ══════════════════════════════════════════════════════════════
    echo "6. فحص سلامة القيد المزدوج\n$sep\n";
    checkDoubleEntry($errors, $passed);
    echo "\n";

    // ══════════════════════════════════════════════════════════════
    // 7. ميزان السياحة
    // ══════════════════════════════════════════════════════════════
    echo "7. ميزان مراجعة قسم السياحة\n$sep\n";
    $treasury = app(TreasuryService::class);
    $tb = $treasury->getTrialBalance();

    $computedCurrent = ($tb['total_balances'] + $tb['total_liquidity'] + $tb['due_to_us']) - $tb['due_from_us'];
    $computedExpected = $tb['base_capital'] + $tb['profits'];

    assertEq('معادلة رأس المال الحالي (سياحة)', $computedCurrent, $tb['current_capital'], $errors, $passed);
    assertEq('معادلة رأس المال المتوقع (سياحة)', $computedExpected, $tb['expected_capital'], $errors, $passed);
    assertEq('معادلة الفارق (سياحة)', $tb['current_capital'] - $tb['expected_capital'], $tb['variance'], $errors, $passed);

    $tourismProfits = $treasury->calculateDynamicProfits('tourism');
    echo '  📊 أرباح السياحة (ديناميكية): '.number_format($tourismProfits, 2)." EGP\n";
    echo '  🏦 سيولة السياحة: '.number_format($tb['total_liquidity'], 2)." EGP\n";
    echo '  📈 أرصدة أنظمة/ناقلين: '.number_format($tb['details']['flight_balances'], 2)." EGP\n";
    echo '  💰 لنا (ذمم): '.number_format($tb['due_to_us'], 2)." | علينا: ".number_format($tb['due_from_us'], 2)." EGP\n";
    echo '  📋 حالة الميزان: '.$tb['status']."\n\n";

    // ══════════════════════════════════════════════════════════════
    // 7.5 الأرباح والإيرادات (Treasury ↔ P&L)
    // ══════════════════════════════════════════════════════════════
    echo "7.5 مراجعة الأرباح والإيرادات\n$sep\n";
    $plService = app(\App\Services\Reports\ProfitLossReportService::class);
    $pl = $plService->report(['category' => 'tourism']);
    $opex = $treasury->calculateOperatingExpenses('tourism');

    assertEq('gross_profits = calculateDynamicProfits', $tb['gross_profits'], $tourismProfits, $errors, $passed);
    assertEq('صافي الأرباح = gross − مصروفات تشغيلية', $tourismProfits - $opex, $tb['profits'], $errors, $passed);
    assertEq('مجمل ربح P&L = gross_profits (Treasury)', $pl['grossProfit'], $tourismProfits, $errors, $passed);

    $plNetCalc = round($pl['totalRevenues'] - $pl['totalCogs'] - $pl['totalExpenses'], 2);
    assertEq('معادلة P&L: إيرادات − COGS − مصروفات = صافي', $plNetCalc, $pl['netProfit'], $errors, $passed);

    echo '  📒 إيرادات P&L: '.number_format($pl['totalRevenues'], 2)
        .' | COGS: '.number_format($pl['totalCogs'], 2)
        .' | مجمل ربح: '.number_format($pl['grossProfit'], 2)." EGP\n\n";

    // ══════════════════════════════════════════════════════════════
    // 8. ملخص السجلات
    // ══════════════════════════════════════════════════════════════
    echo "8. ملخص السجلات في قاعدة البيانات\n$sep\n";
    echo '  حجوزات طيران: '.FlightBooking::count()."\n";
    echo '  حجوزات حج/عمرة: '.HajjUmraBooking::count()."\n";
    echo '  حجوزات تأشيرات: '.VisaBooking::count()."\n";
    echo '  معاملات مالية: '.DB::table('transactions')->count()."\n";
    echo '  سطور قيود: '.DB::table('account_entries')->count()."\n\n";

    // ══════════════════════════════════════════════════════════════
    echo "══════════════════════════════════════════════════════════════\n";
    echo "  النتيجة النهائية\n";
    echo "══════════════════════════════════════════════════════════════\n";
    echo '  ✅ اجتازت: '.count($passed)."\n";
    echo '  ❌ فشلت  : '.count($errors)."\n";
    $total = count($passed) + count($errors);
    $pct = $total > 0 ? round(count($passed) / $total * 100, 1) : 0;
    echo "  📈 نسبة النجاح: {$pct}%\n";

    if (empty($errors)) {
        echo "\n  🎉 جميع اختبارات السياحة نجحت — نسبة خطأ المحاسبة التشغيلية = 0%\n";
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
    if ($commit) {
        DB::commit();
        echo "\n=== تم حفظ حركات السياحة في قاعدة البيانات (COMMIT) ===\n";
    } else {
        DB::rollBack();
        echo "\n=== تم التراجع عن التغييرات (DB ROLLBACK) ===\n";
    }
    exit($exitCode ?? 1);
}
