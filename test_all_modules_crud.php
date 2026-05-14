<?php

/**
 * اختبار عمليات CRUD الشامل على جميع موديولات النظام
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Http\Kernel');
$kernel->bootstrap();

use Illuminate\Http\Request;
use App\Models\User;

echo "🚀 بدء اختبار عمليات CRUD على جميع الموديولات\n";
echo str_repeat("=", 80) . "\n\n";

// الحصول على مستخدم وتوكين
$user = User::first();
if (!$user) {
    die("❌ لا يوجد مستخدم في قاعدة البيانات\n");
}

$user->tokens()->delete();
$token = $user->createToken('test-all-modules')->plainTextToken;

echo "🔑 API Token: $token\n\n";

$results = [];
$createdIds = [];

// ============================================
// Helper Functions
// ============================================
function makeRequest($method, $url, $data = null, $token) {
    global $kernel;

    $request = Request::create($url, $method);
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    if ($data !== null) {
        $request->merge($data);
    }

    try {
        $response = $kernel->handle($request);
        $content = json_decode($response->getContent(), true);
        $kernel->terminate($request, $response);

        return [
            'success' => $response->isSuccessful() || $response->getStatusCode() === 201,
            'status' => $response->getStatusCode(),
            'data' => $content,
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'status' => 500,
            'data' => ['message' => $e->getMessage()],
        ];
    }
}

function testCreate($moduleName, $url, $data, $token, &$createdIds) {
    echo "📝 CREATE: $moduleName\n";
    $result = makeRequest('POST', $url, $data, $token);

    if ($result['success'] && isset($result['data']['data']['id'])) {
        $id = $result['data']['data']['id'];
        $createdIds[$moduleName] = $id;
        echo "   ✅ نجح - ID: $id\n";
        return true;
    } else {
        echo "   ❌ فشل - Status: {$result['status']}\n";
        if (isset($result['data']['message'])) {
            echo "   Message: {$result['data']['message']}\n";
        }
        if (isset($result['data']['errors'])) {
            echo "   Errors: " . json_encode($result['data']['errors'], JSON_UNESCAPED_UNICODE) . "\n";
        }
        return false;
    }
}

function testRead($moduleName, $url, $token) {
    echo "📖 READ: $moduleName\n";
    $result = makeRequest('GET', $url, null, $token);

    if ($result['success']) {
        echo "   ✅ نجح\n";
        return true;
    } else {
        echo "   ❌ فشل - Status: {$result['status']}\n";
        return false;
    }
}

function testUpdate($moduleName, $url, $data, $token) {
    echo "✏️  UPDATE: $moduleName\n";
    $result = makeRequest('PUT', $url, $data, $token);

    if ($result['success']) {
        echo "   ✅ نجح\n";
        return true;
    } else {
        echo "   ❌ فشل - Status: {$result['status']}\n";
        if (isset($result['data']['message'])) {
            echo "   Message: {$result['data']['message']}\n";
        }
        return false;
    }
}

function testDelete($moduleName, $url, $token) {
    echo "🗑️  DELETE: $moduleName\n";
    $result = makeRequest('DELETE', $url, null, $token);

    if ($result['success']) {
        echo "   ✅ نجح\n";
        return true;
    } else {
        echo "   ❌ فشل - Status: {$result['status']}\n";
        return false;
    }
}

// ============================================
// 1. Finance Module - Accounts
// ============================================
echo "\n" . str_repeat("=", 80) . "\n";
echo "📊 1. Finance Module - Accounts\n";
echo str_repeat("=", 80) . "\n";

$accountId = null;
$result = testCreate(
    'Finance Account',
    '/api/v1/finance/accounts',
    [
        'name' => 'الحساب البنكي الرئيسي',
        'type' => 'bank',
        'balance' => 50000.00,
        'currency' => 'SAR',
        'is_active' => true,
        'notes' => 'حساب البنك الأهلي',
    ],
    $token,
    $createdIds
);
$results['finance_account_create'] = $result;

if ($result) {
    $accountId = $createdIds['Finance Account'];
    $results['finance_account_read'] = testRead('Finance Account', "/api/v1/finance/accounts/$accountId", $token);
    $results['finance_account_update'] = testUpdate('Finance Account', "/api/v1/finance/accounts/$accountId", [
        'balance' => 55000.00,
        'description' => 'حساب البنك الأهلي (محدث)',
    ], $token);
    $results['finance_account_delete'] = testDelete('Finance Account', "/api/v1/finance/accounts/$accountId", $token);
}

// ============================================
// 2. Flight Module - Bookings
// ============================================
echo "\n" . str_repeat("=", 80) . "\n";
echo "✈️  2. Flight Module - Bookings\n";
echo str_repeat("=", 80) . "\n";

// إنشاء عميل أولاً لاستخدامه في حجز الرحلة
$customerResult = testCreate(
    'Flight Customer',
    '/api/v1/customers',
    [
        'full_name' => 'محمد السعيد',
        'phone' => '051' . rand(10000000, 99999999),
        'national_id' => '9' . rand(1000000000000, 9999999999999),
        'city' => 'جدة',
    ],
    $token,
    $createdIds
);

if ($customerResult) {
    $flightResult = testCreate(
        'Flight Booking',
        '/api/v1/flight/bookings',
        [
            'customer_id' => $createdIds['Flight Customer'],
            'booking_channel_type' => 'online',
            'booking_channel_provider' => 'website',
            'agent_name' => 'Agent',
            'origin' => 'RUH',
            'destination' => 'CAI',
            'departure_date' => date('Y-m-d', strtotime('+7 days')),
            'departure_time' => date('H:i:s'),
            'trip_type' => 'oneway',
            'airline' => 'SV',
            'passenger_count' => 1,
            'notes' => 'Test booking',
        ],
        $token,
        $createdIds
    );
    $results['flight_booking_create'] = $flightResult;

    if ($flightResult) {
        $flightId = $createdIds['Flight Booking'];
        $results['flight_booking_read'] = testRead('Flight Booking', "/api/v1/flight/bookings/$flightId", $token);
        $results['flight_booking_update'] = testUpdate('Flight Booking', "/api/v1/flight/bookings/$flightId", [
            'selling_price' => 2650.00,
            'notes' => 'حجز مميز',
        ], $token);
        $results['flight_booking_delete'] = testDelete('Flight Booking', "/api/v1/flight/bookings/$flightId", $token);
    }

    // حذف العميل
    testDelete('Flight Customer', "/api/v1/customers/{$createdIds['Flight Customer']}", $token);
}

// ============================================
// 3. Bus Module - Companies
// ============================================
echo "\n" . str_repeat("=", 80) . "\n";
echo "🚌 3. Bus Module - Companies\n";
echo str_repeat("=", 80) . "\n";

$busResult = testCreate(
    'Bus Company',
    '/api/v1/bus/companies',
    [
        'name' => 'شركة النقل السريع',
        'phone' => '050' . rand(10000000, 99999999),
        'address' => 'الرياض، حي الملز',
        'is_active' => true,
        'notes' => 'شركة موثوقة',
    ],
    $token,
    $createdIds
);
$results['bus_company_create'] = $busResult;

if ($busResult) {
    $busId = $createdIds['Bus Company'];
    $results['bus_company_read'] = testRead('Bus Company', "/api/v1/bus/companies/$busId", $token);
    $results['bus_company_update'] = testUpdate('Bus Company', "/api/v1/bus/companies/$busId", [
        'name' => 'شركة النقل السريع (محدث)',
        'phone' => '051' . rand(10000000, 99999999),
        'notes' => 'شركة موثوقة (محدث)',
    ], $token);
    $results['bus_company_delete'] = testDelete('Bus Company', "/api/v1/bus/companies/$busId", $token);
}

// ============================================
// 4. Service Module - Services
// ============================================
echo "\n" . str_repeat("=", 80) . "\n";
echo "🛎️  4. Service Module - Services\n";
echo str_repeat("=", 80) . "\n";

$serviceResult = testCreate(
    'Service',
    '/api/v1/service/services',
    [
        'name' => 'خدمة تأشيرة سياحية',
        'category' => 'visa',
        'description' => 'تأشيرة سياحية للدول الأوروبية',
        'cost_price' => 400.00,
        'selling_price' => 500.00,
        'is_active' => true,
        'notes' => 'خدمة مميزة',
    ],
    $token,
    $createdIds
);
$results['service_create'] = $serviceResult;

if ($serviceResult) {
    $serviceId = $createdIds['Service'];
    $results['service_read'] = testRead('Service', "/api/v1/service/services/$serviceId", $token);
    $results['service_update'] = testUpdate('Service', "/api/v1/service/services/$serviceId", [
        'selling_price' => 550.00,
        'description' => 'تأشيرة سياحية للدول الأوروبية (محدث)',
    ], $token);
    $results['service_delete'] = testDelete('Service', "/api/v1/service/services/$serviceId", $token);
}

// ============================================
// 5. Online Module - Service Types
// ============================================
echo "\n" . str_repeat("=", 80) . "\n";
echo "🌐 5. Online Module - Service Types\n";
echo str_repeat("=", 80) . "\n";

$onlineServiceResult = testCreate(
    'Online Service Type',
    '/api/v1/online/service-types',
    [
        'name' => 'فوري - تأشيرة الكترونية',
        'fee_type' => 'fixed',
        'fee_value' => 25.00,
        'is_active' => true,
        'notes' => 'خدمة فورية',
    ],
    $token,
    $createdIds
);
$results['online_service_create'] = $onlineServiceResult;

if ($onlineServiceResult) {
    $onlineServiceId = $createdIds['Online Service Type'];
    $results['online_service_read'] = testRead('Online Service Type', "/api/v1/online/service-types/$onlineServiceId", $token);
    $results['online_service_update'] = testUpdate('Online Service Type', "/api/v1/online/service-types/$onlineServiceId", [
        'fee_value' => 30.00,
        'notes' => 'خدمة فورية (محدث)',
    ], $token);
    $results['online_service_delete'] = testDelete('Online Service Type', "/api/v1/online/service-types/$onlineServiceId", $token);
}

// ============================================
// 6. Employee Module - Employees
// ============================================
echo "\n" . str_repeat("=", 80) . "\n";
echo "👥 6. Employee Module - Employees\n";
echo str_repeat("=", 80) . "\n";

$employeeResult = testCreate(
    'Employee',
    '/api/v1/employee/employees',
    [
        'name' => 'خالد عبدالله',
        'phone' => '050' . rand(10000000, 99999999),
        'national_id' => '23456789012345',
        'position' => 'مندوب مبيعات',
        'department' => 'المبيعات',
        'salary' => 5000.00,
        'hire_date' => date('Y-m-d'),
        'is_active' => true,
        'notes' => 'موظف نشيط',
    ],
    $token,
    $createdIds
);
$results['employee_create'] = $employeeResult;

if ($employeeResult) {
    $employeeId = $createdIds['Employee'];
    $results['employee_read'] = testRead('Employee', "/api/v1/employee/employees/$employeeId", $token);
    $results['employee_update'] = testUpdate('Employee', "/api/v1/employee/employees/$employeeId", [
        'salary' => 5500.00,
        'position' => 'مندوب مبيعات أول',
    ], $token);
    $results['employee_delete'] = testDelete('Employee', "/api/v1/employee/employees/$employeeId", $token);
}

// ============================================
// ملخص النتائج
// ============================================
echo "\n" . str_repeat("=", 80) . "\n";
echo "📊 ملخص النتائج النهائية\n";
echo str_repeat("=", 80) . "\n\n";

$passed = count(array_filter($results, fn($r) => $r));
$total = count($results);

echo "✅ العمليات الناجحة: $passed\n";
echo "❌ العمليات الفاشلة: " . ($total - $passed) . "\n";
echo "📝 إجمالي العمليات: $total\n";
echo "📈 نسبة النجاح: " . round(($passed / $total) * 100, 2) . "%\n\n";

// عرض النتائج حسب الموديول
echo "📈 النتائج حسب الموديول:\n";
echo str_repeat("-", 80) . "\n";

$modules = [
    'Finance' => ['finance_account_create', 'finance_account_read', 'finance_account_update', 'finance_account_delete'],
    'Flight' => ['flight_booking_create', 'flight_booking_read', 'flight_booking_update', 'flight_booking_delete'],
    'Bus' => ['bus_company_create', 'bus_company_read', 'bus_company_update', 'bus_company_delete'],
    'Service' => ['service_create', 'service_read', 'service_update', 'service_delete'],
    'Online' => ['online_service_create', 'online_service_read', 'online_service_update', 'online_service_delete'],
    'Employee' => ['employee_create', 'employee_read', 'employee_update', 'employee_delete'],
];

foreach ($modules as $moduleName => $operations) {
    $modulePassed = 0;
    $moduleTotal = 0;

    foreach ($operations as $op) {
        if (isset($results[$op])) {
            $moduleTotal++;
            if ($results[$op]) {
                $modulePassed++;
            }
        }
    }

    $rate = $moduleTotal > 0 ? round(($modulePassed / $moduleTotal) * 100, 1) : 0;
    $status = $rate === 100 ? '✅' : ($rate > 0 ? '⚠️' : '❌');

    echo sprintf("%s %s: ✅ %d/%d (%.1f%%)\n", $status, $moduleName, $modulePassed, $moduleTotal, $rate);
}

echo "\n";

// عرض التفاصيل الكاملة
echo "📝 التفاصيل الكاملة:\n";
echo str_repeat("-", 80) . "\n";

foreach ($results as $test => $result) {
    $status = $result ? '✅' : '❌';
    echo sprintf("%s %s\n", $status, strtoupper(str_replace('_', ' ', $test)));
}

echo "\n";

if ($passed === $total) {
    echo "🎉 جميع الاختبارات نجحت!\n";
} else {
    $failed = $total - $passed;
    echo "⚠️  $failed اختبار فشل، يرجى مراجعة الأخطاء\n";
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "✨ تم الانتهاء من اختبار جميع الموديولات!\n";
echo str_repeat("=", 80) . "\n";

// حفظ التقرير
$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'total_operations' => $total,
    'passed' => $passed,
    'failed' => $total - $passed,
    'success_rate' => round(($passed / $total) * 100, 2),
    'results' => $results,
    'created_ids' => $createdIds,
];

file_put_contents(__DIR__ . '/all_modules_crud_test_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "📄 تم حفظ التقرير في: all_modules_crud_test_report.json\n";
