<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightSystem;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Finance\TransactionService;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB as DBFacade;
use Illuminate\Support\Facades\Hash;

// ═══════════════════════════════════════════════════════════════════════════
// SETUP — مطابق لسيناريو المستخدم: حجز KWD، العميل دفع EGP
// ═══════════════════════════════════════════════════════════════════════════

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  فحص شامل لعملية الاسترداد — حجز KWD + دفع EGP\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// تنظيف
DBFacade::statement('PRAGMA foreign_keys = OFF');
DBFacade::table('treasury_transactions')->delete();
DBFacade::table('airline_transactions')->delete();
DBFacade::table('account_entries')->delete();
DBFacade::table('transactions')->delete();
DBFacade::table('refund_requests')->delete();
DBFacade::table('flight_refunds')->delete();
DBFacade::table('flight_payments')->delete();
DBFacade::table('airline_credits')->delete();
DBFacade::table('flight_bookings')->delete();
DBFacade::table('flight_carriers')->delete();
DBFacade::table('flight_systems')->delete();
DBFacade::table('flight_groups')->delete();
DBFacade::table('customers')->delete();
DBFacade::table('employees')->delete();
DBFacade::table('treasuries')->delete();
DBFacade::table('accounts')->where('owner_type', 'customer')->delete();
DBFacade::table('accounts')->where('module_type', 'flights')->delete();
DBFacade::table('accounts')->where('module_type', 'tourism')->delete();
DBFacade::table('users')->where('email', 'like', 'audit-refund-%')->delete();
DBFacade::statement('PRAGMA foreign_keys = ON');

// Currency
Currency::updateOrCreate(['code' => 'KWD'], ['name_ar' => 'KWD', 'name_en' => 'KWD', 'symbol' => 'KWD', 'exchange_rate' => 162.5, 'is_active' => true, 'order' => 0]);
Currency::updateOrCreate(['code' => 'EGP'], ['name_ar' => 'EGP', 'name_en' => 'EGP', 'symbol' => 'EGP', 'exchange_rate' => 1.0, 'is_active' => true, 'order' => 0]);

// User
$admin = User::create([
    'name' => 'Audit', 'email' => 'audit-refund-' . uniqid() . '@test.com',
    'password' => Hash::make('password'), 'role' => 'admin', 'is_active' => true,
]);
$employee = Employee::create(['user_id' => $admin->id, 'status' => 'active']);
\Illuminate\Support\Facades\Auth::login($admin);

// EGP Cashbox (اللي العميل هيحط فلوسه فيها)
$egpCashbox = Account::create([
    'name' => 'EGP Cashbox (للاسترداد)', 'type' => 'cashbox', 'currency' => 'EGP',
    'balance' => 0.0, 'is_active' => true,
    'owner_type' => Account::OWNER_TYPE_OFFICE,
    'module_type' => AccountModuleContract::TOURISM_MODULE_TYPE,
    'is_module_vault' => true, 'created_by' => $admin->id,
]);
$openingEgp = 100000.0;
LedgerBalanceMutationGuard::run(function () use ($egpCashbox, $openingEgp, $admin) {
    $tx = Transaction::create([
        'type' => 'transfer', 'amount' => $openingEgp,
        'module' => TransactionModule::General->value,
        'from_account_id' => $egpCashbox->id, 'to_account_id' => $egpCashbox->id,
        'created_by' => $admin->id, 'notes' => 'OPENING: EGP cashbox',
    ]);
    AccountEntry::create([
        'account_id' => $egpCashbox->id, 'transaction_id' => $tx->id,
        'debit' => 0, 'credit' => $openingEgp, 'balance_after' => $openingEgp,
    ]);
    $egpCashbox->update(['balance' => $openingEgp]);
});
$egpTreasury = Treasury::create([
    'name' => 'EGP Treasury', 'currency' => 'EGP',
    'current_balance' => $openingEgp, 'is_active' => true,
]);

// KWD carrier
$kwdSystem = FlightSystem::create([
    'name' => 'KWD System', 'code' => 'KS' . uniqid(),
    'currency' => 'KWD', 'credit_limit' => 500000.0,
    'balance' => 0.0, 'available_balance' => 0.0,
    'is_active' => true, 'created_by' => $admin->id,
]);
$kwdCarrier = FlightCarrier::create([
    'name' => 'KWD Carrier', 'code' => 'KC' . uniqid(),
    'flight_system_id' => $kwdSystem->id, 'currency' => 'KWD',
    'credit_limit' => 500000.0, 'balance' => 0.0, 'available_balance' => 0.0,
    'is_active' => true, 'created_by' => $admin->id,
]);

// Customer
$customer = Customer::create([
    'full_name' => 'عميل تدقيق', 'phone' => '01000000999',
    'is_active' => true, 'created_by' => $admin->id,
]);

echo "=== 1. الإعداد الأولي ===\n";
echo "   EGP Cashbox Opening: 100,000 EGP\n";
echo "   Customer: عميل تدقيق\n";
echo "   Carrier: KWD Carrier (رصيد 0)\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// 2. إنشاء الحجز — KWD 5.00 (تذكرة بـ 5 دينار)
// ═══════════════════════════════════════════════════════════════════════════

echo "=== 2. إنشاء الحجز ===\n";
$bookingService = app(FlightBookingService::class);
$txService = app(TransactionService::class);

$spEgp = 812.50;       // 5.0 KWD × 162.5 = 812.5 EGP
$spForeign = 5.0;      // 5.0 KWD
$ppEgp = 500.0;        // 500 EGP (تكلفة الشراء)
$ppForeign = 3.0769;   // 500 / 162.5 ≈ 3.0769 KWD

$booking = $bookingService->createBooking([
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'selling_price' => $spEgp,           // 812.5 EGP (سعر التذكرة)
    'purchase_price' => $ppEgp,          // 500 EGP (تكلفة الشراء)
    'selling_price_foreign' => $spForeign, // 5.0 KWD
    'purchase_price_foreign' => $ppForeign,
    'currency' => 'KWD',                  // عملة الحجز
    'original_currency' => 'EGP',         // العميل دفع بالجنيه
    'original_amount' => $spEgp,          // 812.5 EGP
    'account_id' => $egpCashbox->id,      // خزينة EGP
    'agent_name' => 'وكيل التدقيق',
    'origin' => 'CAI', 'destination' => 'KWI',
    'departure_date' => now()->addDays(14)->toDateString(),
    'departure_time' => '08:00:00',
    'trip_type' => 'one_way',
    'airline' => 'JK',
    'passenger_count' => 1,
    'flight_carrier_id' => $kwdCarrier->id,
    'pnr' => 'AUD' . uniqid(),
]);

echo "   Booking created: #" . $booking->id . "\n";
echo "   currency: " . $booking->currency . "\n";
echo "   original_currency: " . ($booking->original_currency ?? '(null — تمسح لأن نفس العملة)') . "\n";
echo "   original_amount: " . ($booking->original_amount ?? 'null') . "\n";
echo "   selling_price (EGP): " . $booking->selling_price . "\n";
echo "   selling_price_foreign (KWD): " . $booking->selling_price_foreign . "\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// 3. الدفع — العميل دفع 812.5 EGP في خزينة EGP
// ═══════════════════════════════════════════════════════════════════════════

echo "=== 3. الدفع ===\n";
$payment = $bookingService->addPayment($booking, [
    'amount' => $spEgp,           // 812.5 EGP
    'payment_method' => 'cash',
    'account_id' => $egpCashbox->id,
    'idempotency_key' => 'audit-' . uniqid(),
]);

$cashboxAfterPayment = $egpCashbox->fresh()->balance;
echo "   Payment: 812.50 EGP (دفع العميل كامل قيمة التذكرة)\n";
echo "   EGP Cashbox after payment: " . number_format($cashboxAfterPayment, 2) . " EGP\n\n";

// Confirm booking
$bookingService->confirmBooking($booking);

echo "=== 4. حالة الحجز بعد التأكيد ===\n";
echo "   Status: " . $booking->fresh()->status->value . "\n";
echo "   Total Paid (accessor): " . $booking->fresh()->paid_amount . "\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// 5. لقطات من الأرصدة قبل الإلغاء
// ═══════════════════════════════════════════════════════════════════════════

echo "=== 5. الأرصدة قبل الإلغاء ===\n";
$egpBalBefore = $egpCashbox->fresh()->balance;
$carrierBalBefore = $kwdCarrier->fresh()->balance;
$customerArBefore = $customer->fresh()->ledgerAccount->balance;
echo "   EGP Cashbox: " . number_format($egpBalBefore, 2) . " EGP\n";
echo "   KWD Carrier:  " . number_format($carrierBalBefore, 4) . " KWD\n";
echo "   Customer AR:  " . number_format($customerArBefore, 2) . " EGP\n";
echo "   (Customer AR عادة ما يكون سالب = العميل مديون)\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// 6. التراجع (الإلغاء) — بدون أي penalties
// ═══════════════════════════════════════════════════════════════════════════

echo "=== 6. تنفيذ الإلغاء (بدون penalties) ===\n";

try {
    $airlinePenalty = 162.50;  // 1 KWD in EGP
    $officePenalty = 81.25;    // 0.5 KWD in EGP
    $refund = $bookingService->cancelBooking($booking, [
        'airline_penalty' => $airlinePenalty,
        'office_penalty' => $officePenalty,
        'account_id' => $egpCashbox->id,  // EGP account
        'notes' => 'Audit cancellation with penalties',
    ]);

    echo "   ✅ Cancel succeeded!\n";
    echo "   Penalties: airline={$airlinePenalty} EGP, office={$officePenalty} EGP\n";
    echo "   Refund ID: #" . $refund->id . "\n";
    echo "   Refund amount: " . $refund->refund_amount . " " . ($refund->currency ?? 'EGP') . "\n";
    echo "   Expected refund: " . (812.50 - $airlinePenalty - $officePenalty) . " EGP\n";
    echo "   Status: " . $refund->status . "\n";
    echo "   Transaction ID: " . ($refund->transaction_id ?? 'null') . "\n\n";
} catch (\Throwable $e) {
    echo "   ❌ Cancel failed: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    exit(1);
}

// ═══════════════════════════════════════════════════════════════════════════
// 7. الأرصدة بعد الإلغاء
// ═══════════════════════════════════════════════════════════════════════════

echo "=== 7. الأرصدة بعد الإلغاء ===\n";
$egpBalAfter = $egpCashbox->fresh()->balance;
$carrierBalAfter = $kwdCarrier->fresh()->balance;
$customerArAfter = $customer->fresh()->ledgerAccount->balance;
echo "   EGP Cashbox: " . number_format($egpBalAfter, 2) . " EGP (Δ=" . number_format($egpBalAfter - $egpBalBefore, 2) . ")\n";
echo "   KWD Carrier:  " . number_format($carrierBalAfter, 4) . " KWD (Δ=" . number_format($carrierBalAfter - $carrierBalBefore, 4) . ")\n";
echo "   Customer AR:  " . number_format($customerArAfter, 2) . " EGP (Δ=" . number_format($customerArAfter - $customerArBefore, 2) . ")\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// 8. تحليل العمليات (transactions)
// ═══════════════════════════════════════════════════════════════════════════

echo "=== 8. الـ Transactions المنشأة أثناء الإلغاء ===\n";
$refundTransactions = Transaction::where('related_type', FlightRefund::class)
    ->where('related_id', $refund->id)
    ->get();

foreach ($refundTransactions as $tx) {
    $fromName = $tx->from_account_id ? Account::find($tx->from_account_id)?->name : '(system)';
    $toName = $tx->to_account_id ? Account::find($tx->to_account_id)?->name : '(system)';
    echo "   TX #" . $tx->id . ": " . number_format($tx->amount, 2) . " " . ($tx->currency ?? 'EGP');
    echo " | from: " . ($fromName ?? '?') . " → to: " . ($toName ?? '?');
    echo "\n   Notes: " . substr($tx->notes ?? '', 0, 80) . "\n\n";
}

// Also show the sale reversal and revenue reversal
echo "--- Sale reversal (Step 3) ---\n";
$saleReversal = Transaction::where('related_type', FlightBooking::class)
    ->where('related_id', $booking->id)
    ->where('notes', 'like', 'عكس مبيعات%')
    ->first();
if ($saleReversal) {
    echo "   TX #" . $saleReversal->id . ": " . number_format($saleReversal->amount, 2) . " EGP\n";
    echo "   Notes: " . $saleReversal->notes . "\n";
} else {
    echo "   (لم يتم إنشاء sale reversal — العميل لم يدفع عبر recordSaleToCustomer)\n";
}
echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// 9. التحقق من الـ Invariants
// ═══════════════════════════════════════════════════════════════════════════

echo "=== 9. Invariants Check ===\n";

// Customer AR should be 0 (the sale is fully reversed)
$expectedCustomerAr = 0.0;
$actualCustomerAr = $customerArAfter;
$arOk = abs($actualCustomerAr - $expectedCustomerAr) < 0.01;
echo ($arOk ? '✅' : '❌') . " Customer AR = 0: actual = " . number_format($actualCustomerAr, 2) . "\n";

// EGP cashbox should be back to opening (100,000) since refund fully reversed the payment
$expectedEgpCashbox = $openingEgp;
$egpOk = abs($egpBalAfter - $expectedEgpCashbox) < 0.01;
echo ($egpOk ? '✅' : '❌') . " EGP Cashbox back to opening (100,000): actual = " . number_format($egpBalAfter, 2) . "\n";

// Carrier balance: if booking had COGS posted, should be credited back
// Skip this for the audit - just report it

echo "\n=== 10. مقارنة المتوقَع vs الفعلي ===\n";
echo "   Opening:              100,000.00 EGP\n";
echo "   + Customer paid:        +812.50 EGP\n";
echo "   - Refund to customer:  -812.50 EGP\n";
echo "   ──────────────────────────────\n";
echo "   Expected EGP Cashbox: 100,000.00 EGP\n";
echo "   Actual EGP Cashbox:   " . number_format($egpBalAfter, 2) . " EGP\n";
echo "   Match: " . (abs($egpBalAfter - 100000) < 0.01 ? '✅' : '❌') . "\n";

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  ملخص: هل العملية سليمة محاسبياً؟\n";
echo "═══════════════════════════════════════════════════════════════════\n";
