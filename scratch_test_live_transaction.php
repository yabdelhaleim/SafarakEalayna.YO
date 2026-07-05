<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Customer;
use App\Models\Account;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightBooking;
use App\Models\Transaction;
use App\Services\Flight\FlightBookingService;
use App\Services\Finance\TreasuryService;
use Illuminate\Support\Facades\DB;

try {
    DB::beginTransaction();

    echo "====================================================\n";
    echo "  بدء اختبار دورة حجز طيران وسداد كامل وتوازن الميزان\n";
    echo "====================================================\n\n";

    // 1. تجهيز عميل ونظام طيران وخزينة
    $customer = Customer::first();
    if (!$customer) {
        $customer = Customer::create([
            'full_name' => 'عميل تجريبي للاختبار',
            'phone' => '01000000000',
            'currency' => 'EGP',
            'is_active' => true
        ]);
        echo "✅ تم إنشاء عميل تجريبي جديد.\n";
    } else {
        echo "✅ تم استخدام العميل الحالي: {$customer->full_name}\n";
    }

    $system = FlightSystem::first();
    if (!$system) {
        $system = FlightSystem::create([
            'name' => 'نظام تجريبي للاختبار',
            'code' => 'TEST_SYS',
            'currency' => 'EGP',
            'balance' => 10000,
            'is_active' => true
        ]);
        echo "✅ تم إنشاء نظام طيران تجريبي جديد برصيد 10,000 ج.م.\n";
    } else {
        echo "✅ تم استخدام نظام الطيران الحالي: {$system->name}\n";
    }

    $cashbox = Account::where('type', 'cashbox')
        ->where('module_type', 'flights')
        ->where('name', 'not like', '%إقفال%')
        ->where('name', 'not like', '%رصيد مسبق%')
        ->where('name', 'not like', '%تسوية%')
        ->first();

    if (!$cashbox) {
        throw new Exception("لم يتم العثور على خزينة طيران حقيقية نشطة! يرجى إنشاء خزينة طيران أولاً.");
    }
    echo "✅ تم استخدام الخزينة: {$cashbox->name} (رصيدها الحالي: {$cashbox->balance} ج.م)\n\n";

    // 2. عمل الحجز
    echo "--- 1. إنشاء الحجز بـ 5,500 ج.م (سعر التكلفة: 4,000 ج.م) ---\n";
    $bookingService = app(FlightBookingService::class);
    $bookingData = [
        'customer_id' => $customer->id,
        'system_type' => 'manual',
        'flight_system_id' => $system->id,
        'purchase_balance_source' => 'system',
        'selling_price' => 5500,
        'purchase_price' => 4000,
        'currency' => 'EGP',
        'origin' => 'CAI',
        'destination' => 'DXB',
        'departure_date' => date('Y-m-d'),
        'passenger_count' => 1,
        'pnr' => 'TEST12',
        'passengers' => [
            ['first_name' => 'Test', 'last_name' => 'User', 'title' => 'Mr']
        ]
    ];

    $booking = $bookingService->createBooking($bookingData);
    echo "✅ تم إنشاء الحجز رقم: {$booking->booking_number}\n";
    echo "حالة الحجز: {$booking->status->value}\n\n";

    // 3. التحقق من قيود الحجز
    echo "--- 2. قيود الحجز التلقائية في الدفاتر ---\n";
    $txs = Transaction::where('related_type', FlightBooking::class)
        ->where('related_id', $booking->id)
        ->with(['fromAccount:id,name', 'toAccount:id,name'])
        ->get();

    foreach ($txs as $tx) {
        printf("  قيد: من [%s] ← إلى [%s] بمبلغ %.2f ج.م | الملاحظات: %s\n",
            $tx->fromAccount?->name ?? '---',
            $tx->toAccount?->name ?? '---',
            $tx->amount,
            $tx->notes
        );
    }
    echo "\n";

    // 4. سداد الحجز
    echo "--- 3. دفع قيمة الحجز بالكامل (5,500 ج.م) نقداً ---\n";
    $payment = $bookingService->addPayment($booking, [
        'amount' => 5500,
        'account_id' => $cashbox->id,
        'payment_method' => 'cash',
        'notes' => 'سداد تجريبي كامل للحجز'
    ]);
    echo "✅ تم تسجيل عملية الدفع بنجاح.\n\n";

    // 5. التحقق من قيود الدفع
    echo "--- 4. قيود عملية الدفع في الدفاتر ---\n";
    $paymentTxs = Transaction::where('id', $payment->transaction_id)
        ->with(['fromAccount:id,name', 'toAccount:id,name'])
        ->get();

    foreach ($paymentTxs as $tx) {
        printf("  قيد الدفع: من [%s] ← إلى [%s] بمبلغ %.2f ج.م | الملاحظات: %s\n",
            $tx->fromAccount?->name ?? '---',
            $tx->toAccount?->name ?? '---',
            $tx->amount,
            $tx->notes
        );
    }
    echo "\n";

    // 6. تشخيص توازن الميزان العام الموحد
    echo "--- 5. التحقق من توازن الميزان العام الموحد ---\n";
    $treasuryService = app(TreasuryService::class);
    $trialBalance = $treasuryService->getTrialBalance();

    printf("  إجمالي السيولة (خزائن + بنوك): %.2f ج.م\n", $trialBalance['total_liquidity']);
    printf("  مستحق لنا   (due_to_us):      %.2f ج.م\n", $trialBalance['due_to_us']);
    printf("  مستحق علينا (due_from_us):    %.2f ج.م\n", $trialBalance['due_from_us']);
    printf("  الفارق المالي (Variance):     %.2f ج.م (%s)\n", 
        $trialBalance['variance'], 
        $trialBalance['status']
    );
    echo "====================================================\n";

    // تراجع عن التغييرات حتى لا تؤثر على الأرقام الفعلية للشركة
    DB::rollBack();
    echo "🔄 تم التراجع التلقائي عن المعاملات التجريبية بنجاح لإبقاء الدفاتر نظيفة.\n\n";

} catch (Exception $e) {
    DB::rollBack();
    echo "❌ حدث خطأ أثناء الاختبار: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
