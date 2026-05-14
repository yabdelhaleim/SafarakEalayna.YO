<?php

/**
 * اختبار شامل لجميع موديولات نظام SafarakEalayna
 * هذا الملف يختبر جميع API endpoints في الموديولات المختلفة
 */

$baseUrl = 'http://127.0.0.1:8000/api/v1';
$results = [];
$errors = [];

function testEndpoint($name, $method, $url, $data = null) {
    global $baseUrl, $results, $errors;

    $fullUrl = $baseUrl . $url;
    $ch = curl_init($fullUrl);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $success = ($httpCode >= 200 && $httpCode < 300);

    $results[] = [
        'name' => $name,
        'method' => $method,
        'url' => $url,
        'http_code' => $httpCode,
        'success' => $success,
        'response' => $response ? json_decode($response, true) : null,
        'curl_error' => $curlError
    ];

    if (!$success) {
        $errors[] = [
            'name' => $name,
            'url' => $url,
            'http_code' => $httpCode,
            'error' => $curlError ?: "HTTP $httpCode",
            'response' => $response
        ];
    }

    return $success;
}

// تشغيل الخادم أولاً
echo "🚀 بدء اختبار النظام...\n\n";

// اختبار الصحة الأساسية
testEndpoint('Health Check', 'GET', '/health');

// 1. اختبار موديول الحسابات والمالية
echo "📊 اختبار موديول الحسابات والمالية...\n";
testEndpoint('List Accounts', 'GET', '/finance/accounts');
testEndpoint('List Transactions', 'GET', '/finance/transactions');
testEndpoint('List Transfers', 'GET', '/finance/transfers');

// 2. اختبار موديول الرحلات (Flight)
echo "\n✈️  اختبار موديول الرحلات...\n";
testEndpoint('List Flight Bookings', 'GET', '/flight/bookings');
testEndpoint('List Flight Passengers', 'GET', '/flight/passengers');
testEndpoint('List Flight Payments', 'GET', '/flight/payments');

// 3. اختبار موديول الحافلات (Bus)
echo "\n🚌 اختبار موديول الحافلات...\n";
testEndpoint('List Bus Companies', 'GET', '/bus/companies');
testEndpoint('List Bus Inventory', 'GET', '/bus/inventories');
testEndpoint('List Bus Bookings', 'GET', '/bus/bookings');

// 4. اختبار موديول الخدمات (Service)
echo "\n🛎️  اختبار موديول الخدمات...\n";
testEndpoint('List Services', 'GET', '/service/services');
testEndpoint('List Service Orders', 'GET', '/service/orders');
testEndpoint('List Service Payments', 'GET', '/service/payments');

// 5. اختبار موديول الخدمات أونلاين (Online)
echo "\n🌐 اختبار موديول الخدمات أونلاين...\n";
testEndpoint('List Online Service Types', 'GET', '/online/service-types');
testEndpoint('List Online Transactions', 'GET', '/online/transactions');

// 6. اختبار موديول الموظفين (Employee)
echo "\n👥 اختبار موديول الموظفين...\n";
testEndpoint('List Employees', 'GET', '/employee/employees');
testEndpoint('List Employee Bonuses', 'GET', '/employee/bonuses');

// 7. اختبار موديول التقارير (Reports)
echo "\n📈 اختبار موديول التقارير...\n";
testEndpoint('Financial Summary', 'GET', '/reports/financial/summary');
testEndpoint('Transaction Report', 'GET', '/reports/transactions');

// 8. اختبار موديول العملاء (Customer)
echo "\n👤 اختبار موديول العملاء...\n";
testEndpoint('List Customers', 'GET', '/customers');

// عرض النتائج
echo "\n" . str_repeat("=", 80) . "\n";
echo "📊 ملخص النتائج\n";
echo str_repeat("=", 80) . "\n";

$passed = count(array_filter($results, fn($r) => $r['success']));
$failed = count($results) - $passed;

echo "✅ نجح: $passed\n";
echo "❌ فشل: $failed\n";
echo "📝 المجموع: " . count($results) . "\n\n";

if (!empty($errors)) {
    echo str_repeat("=", 80) . "\n";
    echo "❌ الأخطاء:\n";
    echo str_repeat("=", 80) . "\n\n";

    foreach ($errors as $error) {
        echo "❌ {$error['name']}\n";
        echo "   URL: {$error['url']}\n";
        echo "   HTTP Code: {$error['http_code']}\n";
        echo "   Error: {$error['error']}\n";
        if ($error['response']) {
            $resp = json_decode($error['response'], true);
            if ($resp && isset($resp['message'])) {
                echo "   Message: {$resp['message']}\n";
            }
        }
        echo "\n";
    }
}

echo "\n✨ تم الانتهاء من الاختبار!\n";

// حفظ التقرير
$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'total_tests' => count($results),
    'passed' => $passed,
    'failed' => $failed,
    'results' => $results,
    'errors' => $errors
];

file_put_contents(__DIR__ . '/test_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "📄 تم حفظ التقرير في test_report.json\n";
