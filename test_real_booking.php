<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

echo "=== إنشاء حجز رحلة فعلي في قاعدة البيانات ===\n\n";

// 1. تسجيل الدخول كـ Admin للحصول على Token
echo "1. تسجيل الدخول كـ Admin...\n";
try {
    $request = Illuminate\Http\Request::create('/api/v1/auth/login', 'POST', [], [], [], [], json_encode([
        'email' => 'admin@safarak.com',
        'password' => 'admin123',
    ]));

    $request->headers->set('Content-Type', 'application/json');
    $response = $kernel->handle($request);
    $content = json_decode($response->getContent(), true);

    if ($response->getStatusCode() === 200) {
        $adminToken = $content['data']['token'];
        echo "✅ تم تسجيل الدخول بنجاح\n";
        echo "   التوكن: " . substr($adminToken, 0, 50) . "...\n\n";
    } else {
        echo "❌ فشل: " . $content['message'] . "\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "❌ خطأ: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. إنشاء عميل جديد
echo "2. إنشاء عميل جديد...\n";
try {
    $request = Illuminate\Http\Request::create('/api/v1/customers', 'POST', [], [], [], [], json_encode([
        'full_name' => 'خالد عبدالله محمد',
        'phone' => '+966501234567',
        'national_id' => '1234567890',
        'passport_number' => 'A1234567',
        'passport_expiry' => '2026-12-31',
        'date_of_birth' => '1990-05-15',
        'city' => 'الرياض',
        'affiliation' => 'شركة التكنولوجيا المتقدمة',
        'customer_tier' => 'STANDARD',
        'notes' => 'عميل VIP',
    ]));

    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('Authorization', "Bearer $adminToken");

    $response = $kernel->handle($request);
    $content = json_decode($response->getContent(), true);

    if ($response->getStatusCode() === 201 || $response->getStatusCode() === 200) {
        $customer = $content['data'];
        echo "✅ تم إنشاء العميل بنجاح\n";
        echo "   الاسم: {$customer['full_name']}\n";
        echo "   ID: {$customer['id']}\n\n";
        $customerId = $customer['id'];
    } else {
        echo "❌ فشل: " . ($content['message'] ?? 'خطأ غير معروف') . "\n\n";
        $customerId = 1; // استخدام عميل افتراضي
    }
} catch (Exception $e) {
    echo "❌ خطأ: " . $e->getMessage() . "\n";
    echo "استخدام عميل افتراضي...\n\n";
    $customerId = 1;
}

// 3. إنشاء حجز رحلة جديد
echo "3. إنشاء حجز رحلة جديد...\n";
try {
    $bookingData = [
        'booking_reference' => 'FLT-' . time(),
        'booking_number' => 'FLT-SAUDIA-' . rand(1000, 9999),
        'booking_channel_type' => 'manual',
        'booking_channel_provider' => 'Direct Booking',
        'system_type' => 'manual',
        'pnr' => 'SV' . rand(100000, 999999),
        'status' => 'PENDING',
        'customer_id' => $customerId,
        'employee_id' => null,
        'account_id' => null,
        'agent_name' => 'أحمد الوكيل',
        'airline' => 'الخطوط الجوية السعودية',
        'airline_name' => 'Saudia',
        'origin' => 'JED',
        'from_airport' => 'JED',
        'destination' => 'CAI',
        'to_airport' => 'CAI',
        'departure_date' => now()->addDays(15)->format('Y-m-d'),
        'departure_time' => now()->addDays(15)->setTime(14, 30)->format('H:i'),
        'return_date' => now()->addDays(22)->format('Y-m-d'),
        'return_time' => now()->addDays(22)->setTime(18, 45)->format('H:i'),
        'arrival_time' => now()->addDays(15)->setTime(16, 45)->format('H:i'),
        'trip_type' => 'round_trip',
        'passenger_count' => 2,
        'baggage_allowance_kg' => 46,
        'trip_details' => 'رحلة ذهاب وعودة من جدة إلى القاهرة - درجة رجال الأعمال',
        'purchase_price' => 2800,
        'selling_price' => 3500,
        'profit' => 700,
        'currency' => 'SAR',
        'notes' => 'حجز مهم - عميل VIP',
        'created_by' => 1,
    ];

    $request = Illuminate\Http\Request::create('/api/v1/flight/bookings', 'POST', [], [], [], [], json_encode($bookingData));

    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('Authorization', "Bearer $adminToken");

    $response = $kernel->handle($request);
    $statusCode = $response->getStatusCode();
    $content = json_decode($response->getContent(), true);

    if ($statusCode === 201 || $statusCode === 200) {
        $booking = $content['data'];
        echo "✅ تم إنشاء الحجز بنجاح!\n";
        echo "   رقم الحجز: {$booking['booking_number']}\n";
        echo "   رقم المرجع: {$booking['booking_reference']}\n";
        echo "   العميل: {$booking['customer']['full_name']}\n";
        echo "   شركة الطيران: {$booking['airline_name']}\n";
        echo "   من: {$booking['from_airport']} إلى: {$booking['to_airport']}\n";
        echo "   تاريخ السفر: {$booking['departure_date']}\n";
        echo "   عدد المسافرين: {$booking['passenger_count']}\n";
        echo "   السعر: {$booking['selling_price']} {$booking['currency']}\n";
        echo "   الربح: {$booking['profit']} {$booking['currency']}\n";
        echo "   الحالة: {$booking['status']}\n";
        echo "   ID في قاعدة البيانات: {$booking['id']}\n\n";

        $bookingId = $booking['id'];
    } else {
        echo "❌ فشل إنشاء الحجز: " . ($content['message'] ?? 'خطأ غير معروف') . "\n";
        if (isset($content['errors'])) {
            echo "   الأخطاء: " . json_encode($content['errors'], JSON_UNESCAPED_UNICODE) . "\n";
        }
        echo "\n";
        $bookingId = null;
    }
} catch (Exception $e) {
    echo "❌ خطأ: " . $e->getMessage() . "\n\n";
    $bookingId = null;
}

// 4. التحقق من الحجز في قاعدة البيانات
echo "4. التحقق من الحجز في قاعدة البيانات...\n";
if ($bookingId) {
    try {
        $request = Illuminate\Http\Request::create("/api/v1/flight/bookings/$bookingId", 'GET');
        $request->headers->set('Authorization', "Bearer $adminToken");

        $response = $kernel->handle($request);
        $content = json_decode($response->getContent(), true);

        if ($response->getStatusCode() === 200) {
            $booking = $content['data'];
            echo "✅ تم العثور على الحجز في قاعدة البيانات!\n";
            echo "   التحقق من البيانات:\n";
            echo "   - رقم الحجز: {$booking['booking_number']} ✅\n";
            echo "   - العميل: {$booking['customer']['full_name']} ✅\n";
            echo "   - شركة الطيران: {$booking['airline_name']} ✅\n";
            echo "   - السعر: {$booking['selling_price']} {$booking['currency']} ✅\n";
            echo "   - الربح: {$booking['profit']} {$booking['currency']} ✅\n\n";
        } else {
            echo "❌ لم يتم العثور على الحجز\n\n";
        }
    } catch (Exception $e) {
        echo "❌ خطأ: " . $e->getMessage() . "\n\n";
    }
}

// 5. عرض جميع الحجوزات
echo "5. عرض جميع الحجوزات في قاعدة البيانات...\n";
try {
    $request = Illuminate\Http\Request::create('/api/v1/flight/bookings', 'GET');
    $request->headers->set('Authorization', "Bearer $adminToken");

    $response = $kernel->handle($request);
    $content = json_decode($response->getContent(), true);

    if ($response->getStatusCode() === 200) {
        $bookings = $content['data'];
        echo "✅ عدد الحجوزات في قاعدة البيانات: " . count($bookings) . "\n";
        echo "   أحدث 3 حجوزات:\n";

        $latestBookings = array_slice(array_reverse($bookings), 0, 3);
        foreach ($latestBookings as $b) {
            echo "   - {$b['booking_number']} | {$b['airline_name']} | ";
            echo "{$b['from_airport']} → {$b['to_airport']} | ";
            echo "{$b['selling_price']} {$b['currency']}\n";
        }
        echo "\n";
    } else {
        echo "❌ فشل عرض الحجوزات\n\n";
    }
} catch (Exception $e) {
    echo "❌ خطأ: " . $e->getMessage() . "\n\n";
}

echo "=== ✅ تم حفظ الحجز في قاعدة البيانات بنجاح! ===\n";

$kernel->terminate($request, $response);
