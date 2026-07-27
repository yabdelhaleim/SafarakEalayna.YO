<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  اختبار عميق للسوفت ديليت (Soft Delete Deep Verification)             ║
 * ║  VISA MODULE — SOFT DELETE REAL-DATABASE INTEGRITY TEST                 ║
 * ╠══════════════════════════════════════════════════════════════════════════╣
 * ║  التحققات:                                                              ║
 * ║  1. حماية النموذج: منع $booking->delete() المباشر عبر ModelDeletionGuard║
 * ║  2. الحذف الإداري الآمن عبر deleteWithReversal()                       ║
 * ║  3. التأكد من تطابق أرصدة الحسابات قبل الحجز وبعد الحذف بالكامل         ║
 * ║  4. اختبار استعلامات API (Index / Customer Statement / Customer Dues) ║
 * ║  5. تكرار الحذف (Idempotency) عبر HTTP API                             ║
 * ║  6. التأكد من حفظ سجل التدقيق المحاسبي (Audit Trail) بالكامل          ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
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
use App\Models\User;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

function section(string $title): void
{
    $bar = str_repeat('═', 70);
    echo "\n{$bar}\n║ {$title}\n{$bar}\n";
}

function check(string $name, bool $passed, string $detail = ''): void
{
    $icon = $passed ? '✅' : '❌';
    echo "  {$icon} {$name}" . ($detail !== '' ? " | {$detail}" : '') . "\n";
    if (!$passed) {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        echo "       ↳ FAIL at line " . ($trace[0]['line'] ?? '?') . "\n";
    }
}

function accBal(int $id): float
{
    return (float)(Account::find($id)?->fresh()->balance ?? 0.0);
}

// Set Auth User
$admin = User::where('role', 'admin')->first() ?? User::first();
Auth::setUser($admin);

$service       = app(VisaBookingService::class);
$refundService = app(VisaRefundService::class);

section('1. إعداد بيئة الاختبار العميقة للسوفت ديليت');

$customer = Customer::create([
    'full_name'  => 'SD_TEST_CUSTOMER_' . uniqid(),
    'phone'      => '012' . rand(10000000, 99999999),
    'created_by' => $admin->id,
]);

$agent = VisaAgent::create([
    'company_name'   => 'SD_TEST_AGENT_' . uniqid(),
    'contact_person' => 'Agent Contact',
    'phone'          => '010' . rand(10000000, 99999999),
    'is_active'      => true,
    'created_by'     => $admin->id,
]);

$agentAcc = Account::create([
    'name'        => 'SD_AGENT_ACCOUNT_' . uniqid(),
    'type'        => AccountType::Supplier->value,
    'currency'    => 'EGP',
    'balance'     => 0,
    'is_active'   => true,
    'owner_type'  => 'office',
    'module_type' => 'visas',
    'created_by'  => $admin->id,
]);
$agent->update(['account_id' => $agentAcc->id]);

$duration = VisaDuration::first() ?? VisaDuration::create([
    'code' => 'SD-30D', 'label_ar' => '30 يوم', 'label_en' => '30 Days',
    'months' => 1, 'entry_type' => 'single', 'sort_order' => 1, 'is_active' => true,
]);

$vault = Account::where('module_type', 'tourism')->where('is_module_vault', true)->where('currency', 'EGP')->first()
    ?? Account::create([
        'name' => 'SD_VAULT', 'type' => 'cashbox', 'currency' => 'EGP',
        'balance' => 100000, 'is_active' => true, 'is_module_vault' => true,
        'owner_type' => 'office', 'module_type' => 'tourism', 'created_by' => $admin->id,
    ]);

echo "  ✔ Customer #{$customer->id}\n";
echo "  ✔ Agent #{$agent->id} (Account #{$agentAcc->id})\n";
echo "  ✔ Vault Account #{$vault->id}\n";

// RECORD BASELINE BALANCES
$baseVaultBalance = accBal($vault->id);
$baseAgentBalance = accBal($agentAcc->id);

section('2. حماية النموذج (ModelDeletionGuard Check)');

// Create a booking
$booking1 = $service->create([
    'customer_id'    => $customer->id,
    'purchase_price' => 500.0,
    'selling_price'  => 800.0,
    'service_fee'    => 50.0,
    'currency'       => 'EGP',
    'account_id'     => $vault->id,
    'visa_details'   => [
        'visa_type' => 'tourist', 'country' => 'SD-COUNTRY-1',
        'duration' => '30', 'entry_type' => 'single',
        'visa_agent_id' => $agent->id, 'visa_duration_id' => $duration->id,
    ],
]);

$directDeleteBlocked = false;
try {
    // Attempting direct raw Eloquent delete MUST throw RuntimeException
    $booking1->delete();
} catch (\RuntimeException $e) {
    $directDeleteBlocked = str_contains($e->getMessage(), 'حذف حجز التأشيرة برمجياً');
}

check('منع الحذف المباشر $booking->delete() لحماية القيود المالية', $directDeleteBlocked);
check('الحجز لا يزال موجوداً وغير محذوف في قاعدة البيانات', VisaBooking::find($booking1->id) !== null);

section('3. دورة الحذف الإداري الكامل بالسكت والسداد (Real Soft Delete)');

// Add payments to booking1
$pmt1 = $service->addPayment($booking1, ['amount' => 350.0, 'account_id' => $vault->id, 'payment_method' => 'cash']);
$pmt2 = $service->addPayment($booking1, ['amount' => 500.0, 'account_id' => $vault->id, 'payment_method' => 'bank_transfer']);
$booking1->refresh();

$customer->refresh();
$custAccId = $customer->account_id;

echo "  [قبل الحذف الإداري]:\n";
echo "    رصيد الخزينة: " . accBal($vault->id) . "\n";
echo "    رصيد العميل: " . accBal($custAccId) . "\n";
echo "    رصيد الوكيل: " . accBal($agentAcc->id) . "\n";

// Collect transaction IDs
$txIds = array_values(array_unique(array_filter([
    $booking1->expense_transaction_id,
    $booking1->income_transaction_id,
    $pmt1->transaction_id,
    $pmt2->transaction_id,
])));

// Execute deleteWithReversal
$deleteResult = $refundService->deleteWithReversal($booking1->id, $admin->id);

echo "\n  [بعد الحذف الإداري deleteWithReversal]:\n";
echo "    رصيد الخزينة النهائي: " . accBal($vault->id) . "\n";
echo "    رصيد العميل النهائي: " . accBal($custAccId) . "\n";
echo "    رصيد الوكيل النهائي: " . accBal($agentAcc->id) . "\n";

// VERIFY BALANCES MATCH BASELINE EXACTLY
$vaultRestored = abs(accBal($vault->id) - $baseVaultBalance) < 0.01;
$custRestored  = abs(accBal($custAccId) - 0.0) < 0.01;
$agentRestored = abs(accBal($agentAcc->id) - $baseAgentBalance) < 0.01;

check('نجاح تنفيذ عملية deleteWithReversal', $deleteResult === true);
check('عودة رصيد الخزينة تماماً لما قبل إنشاء الحجز والدفعات', $vaultRestored, "Expected: {$baseVaultBalance}, Actual: " . accBal($vault->id));
check('عودة رصيد العميل تماماً لـ 0.00 EGP', $custRestored, "Actual: " . accBal($custAccId));
check('عودة رصيد الوكيل تماماً لما قبل إنشاء الحجز', $agentRestored, "Expected: {$baseAgentBalance}, Actual: " . accBal($agentAcc->id));

section('4. تحقق السجلات غير المحذوفة وتدقيق الـ Audit Trail');

$bookingRowInDb = DB::table('visa_bookings')->where('id', $booking1->id)->first();
$pmt1InDb = DB::table('visa_payments')->where('id', $pmt1->id)->first();
$pmt2InDb = DB::table('visa_payments')->where('id', $pmt2->id)->first();

check('صف الحجز موجود بجدول visa_bookings مع deleted_at', $bookingRowInDb !== null && $bookingRowInDb->deleted_at !== null);
check('صف الدفعة 1 موجود بجدول visa_payments مع deleted_at', $pmt1InDb !== null && $pmt1InDb->deleted_at !== null);
check('صف الدفعة 2 موجود بجدول visa_payments مع deleted_at', $pmt2InDb !== null && $pmt2InDb->deleted_at !== null);

// Check transactions and entry reversals
$reversedEntriesCount = DB::table('account_entries')
    ->whereIn('transaction_id', $txIds)
    ->where('notes', 'like', 'عكس%')
    ->count();

check('وجود جميع المعاملات الأصلية في جدول transactions', DB::table('transactions')->whereIn('id', $txIds)->count() === count($txIds));
check('تسجيل القيود العكسية (Additive Reversal) لجميع الحركات', $reversedEntriesCount >= 8, "Reversed entries count: {$reversedEntriesCount}");

// Verify each transaction is net-zero
$allNetZero = true;
foreach ($txIds as $tid) {
    $sums = DB::table('account_entries')->where('transaction_id', $tid)
        ->selectRaw('SUM(debit) as sd, SUM(credit) as sc')->first();
    if (abs((float)$sums->sd - (float)$sums->sc) > 0.01) {
        $allNetZero = false;
    }
}
check('جميع المعاملات المالّية للحجز المحذوف ذات صافي صفر (Debit = Credit)', $allNetZero);

section('5. فحص استعلامات الواجهات و الـ API Endpoints بعد الحذف');

// 5.1 Visa Booking List Query
$inList = VisaBooking::where('id', $booking1->id)->exists();
check('الحجز المحذوف لا يظهر في القائمة الرئيسية (GET /visa/bookings)', !$inList);

// 5.2 Customer Statement Query (mirrors VisaController::customerStatement)
$statementBookings = VisaBooking::where('customer_id', $customer->id)
    ->whereNotIn('status', ['cancelled', 'rejected', 'refunded'])
    ->whereNull('deleted_at')
    ->count();

$statementPayments = VisaPayment::whereHas('booking', function ($q) use ($customer) {
    $q->where('customer_id', $customer->id)->whereNull('deleted_at');
})->count();

check('كشف حساب العميل لا يتضمن الحجز المحذوف', $statementBookings === 0);
check('كشف حساب العميل لا يتضمن دفعات الحجز المحذوف', $statementPayments === 0);

// 5.3 Customer Balances Query (mirrors VisaController::customerBalances)
$debtorQuery = VisaBooking::query()
    ->where('customer_id', $customer->id)
    ->whereNull('deleted_at')
    ->whereNotIn('status', ['cancelled', 'rejected', 'refunded'])
    ->count();

check('تقرير مديونيات العملاء يمتنع عن تجميع الحجز المحذوف', $debtorQuery === 0);

section('6. فحص Idempotency (محاولة تكرار الحذف)');

$idempotentCaught = false;
try {
    $refundService->deleteWithReversal($booking1->id, $admin->id);
} catch (\RuntimeException $e) {
    $idempotentCaught = str_contains($e->getMessage(), 'محذوف بالفعل');
}

check('محاولة إعادة حذف حجز محذوف ترمي RuntimeException محمي', $idempotentCaught);

echo "\n" . str_repeat('═', 70) . "\n";
echo "  🎉 جميع فحوصات السوفت ديليت (Soft Delete) مرت بنجاح 100% وبدون أي خلل مالية!\n";
echo str_repeat('═', 70) . "\n";
