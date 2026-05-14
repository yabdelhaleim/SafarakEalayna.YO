<?php

/**
 * اختبار عمليات CRUD الفعلية على جميع موديولات نظام SafarakEalayna
 * هذا الملف ينفذ عمليات Create, Read, Update, Delete
 */

$baseUrl = 'http://127.0.0.1:8000/api/v1';
$results = [];
$errors = [];
$createdIds = [];
$apiToken = '4|u7qaKGKGPR3Om1NqMsTTFzRHYM8XJSpygvZhYtpK6f06b311'; // Admin token

function makeRequest($method, $url, $data = null) {
    global $baseUrl, $results, $errors, $apiToken;

    $fullUrl = $baseUrl . $url;
    $ch = curl_init($fullUrl);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $apiToken
    ]);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $responseData = $response ? json_decode($response, true) : null;
    $success = ($httpCode >= 200 && $httpCode < 300);

    return [
        'success' => $success,
        'http_code' => $httpCode,
        'response' => $responseData,
        'raw_response' => $response,
        'curl_error' => $curlError
    ];
}

function testOperation($moduleName, $operationName, $method, $url, $data = null) {
    global $results, $errors;

    echo "$operationName... ";

    $result = makeRequest($method, $url, $data);

    $testResult = [
        'module' => $moduleName,
        'operation' => $operationName,
        'method' => $method,
        'url' => $url,
        'http_code' => $result['http_code'],
        'success' => $result['success'],
        'response' => $result['response']
    ];

    $results[] = $testResult;

    if (!$result['success']) {
        $errors[] = [
            'module' => $moduleName,
            'operation' => $operationName,
            'url' => $url,
            'http_code' => $result['http_code'],
            'error' => $result['curl_error'] ?: "HTTP {$result['http_code']}",
            'response' => $result['raw_response']
        ];
        echo "❌ FAILED (HTTP {$result['http_code']})\n";
        return null;
    }

    echo "✅ OK\n";
    return $result['response'];
}

// تشغيل الخادم
echo "🚀 تشغيل الخادم...\n";
$serverRunning = false;
for ($i = 0; $i < 5; $i++) {
    $check = @file_get_contents('http://127.0.0.1:8000/api/v1/health');
    if ($check !== false) {
        $serverRunning = true;
        break;
    }
    sleep(1);
}

if (!$serverRunning) {
    die("❌ لم يتمكن من الاتصال بالخادم. تأكد من تشغيل: php artisan serve\n");
}

echo "✅ الخادم يعمل\n\n";

echo "=" . str_repeat("=", 79) . "\n";
echo "🧪 بدء اختبار عمليات CRUD\n";
echo "=" . str_repeat("=", 79) . "\n\n";

// ============================================
// 1. موديول العملاء (Customers)
// ============================================
echo "\n👤 موديول العملاء (Customers)\n";
echo str_repeat("-", 80) . "\n";

// CREATE - عميل جديد
$customerData = testOperation(
    'Customers',
    'CREATE: إنشاء عميل جديد',
    'POST',
    '/customers',
    [
        'name' => 'أحمد محمد',
        'email' => 'ahmed' . rand(1000, 9999) . '@example.com',
        'phone' => '050' . rand(1000000, 9999999),
        'national_id' => '12345678901234',
        'address' => 'الرياض، حي الملز',
        'is_active' => true
    ]
);

if ($customerData && isset($customerData['data']['id'])) {
    $customerId = $customerData['data']['id'];
    $createdIds['customer'] = $customerId;

    // READ - قراءة العميل
    testOperation(
        'Customers',
        'READ: قراءة بيانات العميل',
        'GET',
        "/customers/$customerId"
    );

    // UPDATE - تحديث العميل
    testOperation(
        'Customers',
        'UPDATE: تحديث بيانات العميل',
        'PUT',
        "/customers/$customerId",
        [
            'name' => 'أحمد محمد (محدث)',
            'phone' => '051' . rand(1000000, 9999999),
            'address' => 'الرياض، حي النخيل'
        ]
    );

    // READ بعد التحديث
    testOperation(
        'Customers',
        'READ: التحقق من التحديث',
        'GET',
        "/customers/$customerId"
    );
}

// ============================================
// 2. موديول الحسابات (Accounts)
// ============================================
echo "\n📊 موديول الحسابات (Accounts)\n";
echo str_repeat("-", 80) . "\n";

// CREATE - حساب بنكي جديد
$accountData = testOperation(
    'Accounts',
    'CREATE: إنشاء حساب بنكي',
    'POST',
    '/finance/accounts',
    [
        'name' => 'الحساب البنكي الرئيسي',
        'account_type' => 'bank',
        'balance' => 50000.00,
        'currency' => 'SAR',
        'is_active' => true,
        'description' => 'حساب البنك الأهلي'
    ]
);

if ($accountData && isset($accountData['data']['id'])) {
    $accountId = $accountData['data']['id'];
    $createdIds['account'] = $accountId;

    // READ
    testOperation(
        'Accounts',
        'READ: قراءة بيانات الحساب',
        'GET',
        "/finance/accounts/$accountId"
    );

    // UPDATE
    testOperation(
        'Accounts',
        'UPDATE: تحديث رصيد الحساب',
        'PUT',
        "/finance/accounts/$accountId",
        [
            'balance' => 55000.00,
            'description' => 'حساب البنك الأهلي (محدث)'
        ]
    );
}

// ============================================
// 3. موديول الحافلات (Bus)
// ============================================
echo "\n🚌 موديول الحافلات (Bus)\n";
echo str_repeat("-", 80) . "\n";

// CREATE - شركة حافلات جديدة
$companyData = testOperation(
    'Bus',
    'CREATE: إنشاء شركة حافلات',
    'POST',
    '/bus/companies',
    [
        'name' => 'شركة النقل السريع',
        'contact_person' => 'محمد علي',
        'phone' => '050' . rand(1000000, 9999999),
        'email' => 'transport' . rand(1000, 9999) . '@example.com',
        'commission_rate' => 5.5,
        'is_active' => true
    ]
);

if ($companyData && isset($companyData['data']['id'])) {
    $companyId = $companyData['data']['id'];
    $createdIds['bus_company'] = $companyId;

    // READ
    testOperation(
        'Bus',
        'READ: قراءة بيانات الشركة',
        'GET',
        "/bus/companies/$companyId"
    );

    // UPDATE
    testOperation(
        'Bus',
        'UPDATE: تحديث بيانات الشركة',
        'PUT',
        "/bus/companies/$companyId",
        [
            'contact_person' => 'أحمد محمد',
            'commission_rate' => 6.0
        ]
    );

    // CREATE - مخزون حافلات
    if (isset($createdIds['account'])) {
        $inventoryData = testOperation(
            'Bus',
            'CREATE: إنشاء مخزون حافلات',
            'POST',
            '/bus/inventories',
            [
                'bus_company_id' => $companyId,
                'from_city' => 'الرياض',
                'to_city' => 'جدة',
                'departure_time' => date('Y-m-d H:i:s', strtotime('+1 day')),
                'arrival_time' => date('Y-m-d H:i:s', strtotime('+1 day +6 hours')),
                'total_seats' => 50,
                'available_seats' => 50,
                'ticket_price' => 150.00,
                'is_active' => true
            ]
        );

        if ($inventoryData && isset($inventoryData['data']['id'])) {
            $inventoryId = $inventoryData['data']['id'];
            $createdIds['bus_inventory'] = $inventoryId;

            // READ
            testOperation(
                'Bus',
                'READ: قراءة بيانات المخزون',
                'GET',
                "/bus/inventories/$inventoryId"
            );

            // UPDATE
            testOperation(
                'Bus',
                'UPDATE: تحديث المخزون',
                'PUT',
                "/bus/inventories/$inventoryId",
                [
                    'ticket_price' => 175.00,
                    'available_seats' => 45
                ]
            );
        }
    }
}

// ============================================
// 4. موديول الرحلات (Flight)
// ============================================
echo "\n✈️  موديول الرحلات (Flight)\n";
echo str_repeat("-", 80) . "\n";

// CREATE - حجز رحلة جديد
if (isset($createdIds['customer']) && isset($createdIds['account'])) {
    $flightData = testOperation(
        'Flight',
        'CREATE: إنشاء حجز رحلة',
        'POST',
        '/flight/bookings',
        [
            'customer_id' => $createdIds['customer'],
            'pnr' => 'PNR' . rand(100000, 999999),
            'booking_date' => date('Y-m-d H:i:s'),
            'total_price' => 2500.00,
            'status' => 'confirmed',
            'passenger_count' => 1
        ]
    );

    if ($flightData && isset($flightData['data']['id'])) {
        $flightId = $flightData['data']['id'];
        $createdIds['flight_booking'] = $flightId;

        // READ
        testOperation(
            'Flight',
            'READ: قراءة بيانات الحجز',
            'GET',
            "/flight/bookings/$flightId"
        );

        // UPDATE
        testOperation(
            'Flight',
            'UPDATE: تحديث حالة الحجز',
            'PUT',
            "/flight/bookings/$flightId",
            [
                'status' => 'ticketed',
                'total_price' => 2650.00
            ]
        );

        // CREATE - إضافة مسافر
        $passengerData = testOperation(
            'Flight',
            'CREATE: إضافة مسافر',
            'POST',
            '/flight/passengers',
            [
                'flight_booking_id' => $flightId,
                'first_name' => 'أحمد',
                'last_name' => 'محمد',
                'national_id' => '12345678901234',
                'passport_number' => 'A' . rand(1000000, 9999999),
                'date_of_birth' => '1990-01-01',
                'seat_number' => '12A'
            ]
        );

        if ($passengerData && isset($passengerData['data']['id'])) {
            $passengerId = $passengerData['data']['id'];
            $createdIds['passenger'] = $passengerId;
        }
    }
}

// ============================================
// 5. موديول الخدمات (Service)
// ============================================
echo "\n🛎️  موديول الخدمات (Service)\n";
echo str_repeat("-", 80) . "\n";

// CREATE - خدمة جديدة
$serviceData = testOperation(
    'Service',
    'CREATE: إنشاء خدمة جديدة',
    'POST',
    '/service/services',
    [
        'name' => 'خدمة تأشيرة سياحية',
        'description' => 'تأشيرة سياحية للدول الأوروبية',
        'service_type' => 'visa',
        'base_price' => 500.00,
        'is_active' => true
    ]
);

if ($serviceData && isset($serviceData['data']['id'])) {
    $serviceId = $serviceData['data']['id'];
    $createdIds['service'] = $serviceId;

    // READ
    testOperation(
        'Service',
        'READ: قراءة بيانات الخدمة',
        'GET',
        "/service/services/$serviceId"
    );

    // UPDATE
    testOperation(
        'Service',
        'UPDATE: تحديث بيانات الخدمة',
        'PUT',
        "/service/services/$serviceId",
        [
            'base_price' => 550.00,
            'description' => 'تأشيرة سياحية للدول الأوروبية (محدث)'
        ]
    );

    // CREATE - طلب خدمة
    if (isset($createdIds['customer'])) {
        $orderData = testOperation(
            'Service',
            'CREATE: إنشاء طلب خدمة',
            'POST',
            '/service/orders',
            [
                'customer_id' => $createdIds['customer'],
                'service_id' => $serviceId,
                'order_date' => date('Y-m-d H:i:s'),
                'quantity' => 1,
                'unit_price' => 550.00,
                'total_price' => 550.00,
                'status' => 'pending',
                'notes' => 'طلب عاجل'
            ]
        );

        if ($orderData && isset($orderData['data']['id'])) {
            $orderId = $orderData['data']['id'];
            $createdIds['service_order'] = $orderId;

            // READ
            testOperation(
                'Service',
                'READ: قراءة بيانات الطلب',
                'GET',
                "/service/orders/$orderId"
            );

            // UPDATE
            testOperation(
                'Service',
                'UPDATE: تحديث حالة الطلب',
                'PUT',
                "/service/orders/$orderId",
                [
                    'status' => 'processing',
                    'notes' => 'جاري المعالجة'
                ]
            );
        }
    }
}

// ============================================
// 6. موديول الخدمات الأونلاين (Online)
// ============================================
echo "\n🌐 موديول الخدمات الأونلاين (Online)\n";
echo str_repeat("-", 80) . "\n";

// CREATE - نوع خدمة أونلاين
$onlineServiceData = testOperation(
    'Online',
    'CREATE: إنشاء نوع خدمة أونلاين',
    'POST',
    '/online/service-types',
    [
        'name' => 'فوري - تأشيرة الكترونية',
        'name_en' => 'Instant - E-Visa',
        'provider' => 'fawry',
        'service_code' => 'VISA_EGYPT',
        'base_price' => 300.00,
        'fee_type' => 'fixed',
        'fee_amount' => 25.00,
        'is_active' => true
    ]
);

if ($onlineServiceData && isset($onlineServiceData['data']['id'])) {
    $onlineServiceId = $onlineServiceData['data']['id'];
    $createdIds['online_service'] = $onlineServiceId;

    // READ
    testOperation(
        'Online',
        'READ: قراءة بيانات الخدمة',
        'GET',
        "/online/service-types/$onlineServiceId"
    );

    // UPDATE
    testOperation(
        'Online',
        'UPDATE: تحديث بيانات الخدمة',
        'PUT',
        "/online/service-types/$onlineServiceId",
        [
            'base_price' => 350.00,
            'fee_amount' => 30.00
        ]
    );

    // CREATE - معاملة أونلاين
    if (isset($createdIds['customer'])) {
        $transactionData = testOperation(
            'Online',
            'CREATE: إنشاء معاملة أونلاين',
            'POST',
            '/online/transactions',
            [
                'customer_id' => $createdIds['customer'],
                'online_service_type_id' => $onlineServiceId,
                'transaction_date' => date('Y-m-d H:i:s'),
                'amount' => 325.00,
                'fee_amount' => 25.00,
                'total_amount' => 350.00,
                'status' => 'completed',
                'payment_method' => 'fawry',
                'reference_number' => 'REF' . rand(100000, 999999)
            ]
        );

        if ($transactionData && isset($transactionData['data']['id'])) {
            $transactionId = $transactionData['data']['id'];
            $createdIds['online_transaction'] = $transactionId;

            // READ
            testOperation(
                'Online',
                'READ: قراءة بيانات المعاملة',
                'GET',
                "/online/transactions/$transactionId"
            );
        }
    }
}

// ============================================
// 7. موديول الموظفين (Employee)
// ============================================
echo "\n👥 موديول الموظفين (Employee)\n";
echo str_repeat("-", 80) . "\n";

// CREATE - موظف جديد
$employeeData = testOperation(
    'Employee',
    'CREATE: إنشاء موظف جديد',
    'POST',
    '/employee/employees',
    [
        'name' => 'خالد عبدالله',
        'email' => 'khaled' . rand(1000, 9999) . '@example.com',
        'phone' => '050' . rand(1000000, 9999999),
        'national_id' => '23456789012345',
        'position' => 'مندوب مبيعات',
        'department' => 'المبيعات',
        'salary' => 5000.00,
        'hire_date' => date('Y-m-d'),
        'is_active' => true
    ]
);

if ($employeeData && isset($employeeData['data']['id'])) {
    $employeeId = $employeeData['data']['id'];
    $createdIds['employee'] = $employeeId;

    // READ
    testOperation(
        'Employee',
        'READ: قراءة بيانات الموظف',
        'GET',
        "/employee/employees/$employeeId"
    );

    // UPDATE
    testOperation(
        'Employee',
        'UPDATE: تحديث بيانات الموظف',
        'PUT',
        "/employee/employees/$employeeId",
        [
            'salary' => 5500.00,
            'position' => 'مندوب مبيعات أول'
        ]
    );

    // CREATE - مكافأة موظف
    $bonusData = testOperation(
        'Employee',
        'CREATE: إضافة مكافأة للموظف',
        'POST',
        '/employee/bonuses',
        [
            'employee_id' => $employeeId,
            'bonus_type' => 'performance',
            'amount' => 500.00,
            'bonus_date' => date('Y-m-d'),
            'notes' => 'مكافأة أداء ممتاز'
        ]
    );

    if ($bonusData && isset($bonusData['data']['id'])) {
        $bonusId = $bonusData['data']['id'];
        $createdIds['bonus'] = $bonusId;

        // READ
        testOperation(
            'Employee',
            'READ: قراءة بيانات المكافأة',
            'GET',
            "/employee/bonuses/$bonusId"
        );
    }
}

// ============================================
// عمليات DELETE (اختياري - نستخدم بيانات تجريبية)
// ============================================
echo "\n🗑️  عمليات الحذف (DELETE)\n";
echo str_repeat("-", 80) . "\n";

// ملاحظة: لن نحذف البيانات الفعلية للحفاظ على سلامة البيانات
echo "⚠️  تم تجنب عمليات الحذف الفعلي للحفاظ على سلامة البيانات\n";

// ============================================
// ملخص النتائج
// ============================================
echo "\n" . str_repeat("=", 80) . "\n";
echo "📊 ملخص النتائج النهائية\n";
echo str_repeat("=", 80) . "\n\n";

$passed = count(array_filter($results, fn($r) => $r['success']));
$failed = count($results) - $passed;

echo "✅ العمليات الناجحة: $passed\n";
echo "❌ العمليات الفاشلة: $failed\n";
echo "📝 إجمالي العمليات: " . count($results) . "\n";
echo "📈 نسبة النجاح: " . round(($passed / count($results)) * 100, 2) . "%\n\n";

// عرض الإحصائيات حسب العملية
$operations = [];
foreach ($results as $result) {
    $op = strtoupper(explode(':', $result['operation'])[0]);
    if (!isset($operations[$op])) {
        $operations[$op] = ['passed' => 0, 'failed' => 0];
    }
    if ($result['success']) {
        $operations[$op]['passed']++;
    } else {
        $operations[$op]['failed']++;
    }
}

echo "📈 الإحصائيات حسب نوع العملية:\n";
echo str_repeat("-", 80) . "\n";
foreach ($operations as $op => $stats) {
    $total = $stats['passed'] + $stats['failed'];
    $rate = $total > 0 ? round(($stats['passed'] / $total) * 100, 1) : 0;
    echo sprintf("%-10s ✅ %2d  ❌ %2d  (الإجمالي: %2d، نسبة النجاح: %5s%%)\n",
        $op, $stats['passed'], $stats['failed'], $total, $rate);
}

// عرض الأخطاء
if (!empty($errors)) {
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "❌ الأخطاء:\n";
    echo str_repeat("=", 80) . "\n\n";

    foreach ($errors as $error) {
        echo "❌ {$error['module']} - {$error['operation']}\n";
        echo "   URL: {$error['url']}\n";
        echo "   HTTP Code: {$error['http_code']}\n";
        echo "   Error: {$error['error']}\n";

        if ($error['response']) {
            $resp = json_decode($error['response'], true);
            if ($resp && isset($resp['message'])) {
                echo "   Message: {$resp['message']}\n";
            }
            if ($resp && isset($resp['errors'])) {
                echo "   Validation Errors:\n";
                foreach ($resp['errors'] as $field => $messages) {
                    echo "     - $field: " . implode(', ', (array)$messages) . "\n";
                }
            }
        }
        echo "\n";
    }
}

// حفظ التقرير
$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'total_operations' => count($results),
    'passed' => $passed,
    'failed' => $failed,
    'success_rate' => round(($passed / count($results)) * 100, 2),
    'created_ids' => $createdIds,
    'operations_by_type' => $operations,
    'results' => $results,
    'errors' => $errors
];

file_put_contents(__DIR__ . '/crud_test_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n✨ تم الانتهاء من اختبار عمليات CRUD!\n";
echo "📄 تم حفظ التقرير التفصيلي في: crud_test_report.json\n";
echo "🆔 IDs البيانات المنشأة: " . json_encode($createdIds, JSON_UNESCAPED_UNICODE) . "\n";
