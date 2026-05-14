<?php

/**
 * اختبار عمليات CRUD الشاملة على العملاء
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Http\Kernel');
$kernel->bootstrap();

use Illuminate\Http\Request;
use App\Models\User;

echo "🚀 بدء اختبار عمليات CRUD على العملاء\n";
echo str_repeat("=", 80) . "\n\n";

// الحصول على مستخدم وتوكين
$user = User::first();
if (!$user) {
    die("❌ لا يوجد مستخدم في قاعدة البيانات\n");
}

$user->tokens()->delete();
$token = $user->createToken('test-crud')->plainTextToken;

echo "🔑 API Token: $token\n\n";

$testResults = [];
$customerId = null;

// ============================================
// 1. CREATE - إنشاء عميل جديد
// ============================================
echo "📝 1. CREATE - إنشاء عميل جديد\n";
echo str_repeat("-", 80) . "\n";

$request = Request::create('/api/v1/customers', 'POST');
$request->headers->set('Content-Type', 'application/json');
$request->headers->set('Accept', 'application/json');
$request->headers->set('Authorization', 'Bearer ' . $token);
$request->merge([
    'full_name' => 'أحمد محمد العلي',
    'phone' => '050' . rand(10000000, 99999999),
    'national_id' => '12345678901234',
    'city' => 'الرياض',
    'customer_tier' => 'STANDARD',
]);

try {
    $response = $kernel->handle($request);
    $content = json_decode($response->getContent(), true);

    if ($response->getStatusCode() === 201) {
        echo "✅ نجح إنشاء العميل\n";
        $customerId = $content['data']['id'];
        echo "   ID: $customerId\n";
        echo "   الاسم: {$content['data']['full_name']}\n";
        echo "   الهاتف: {$content['data']['phone']}\n";
        $testResults['create'] = true;
    } else {
        echo "❌ فشل إنشاء العميل\n";
        echo "   Status: {$response->getStatusCode()}\n";
        echo "   Message: {$content['message']}\n";
        $testResults['create'] = false;
    }
    $kernel->terminate($request, $response);
} catch (\Exception $e) {
    echo "❌ استثناء: {$e->getMessage()}\n";
    $testResults['create'] = false;
}

echo "\n";

// ============================================
// 2. READ - قراءة بيانات العميل
// ============================================
echo "📖 2. READ - قراءة بيانات العميل\n";
echo str_repeat("-", 80) . "\n";

if ($customerId) {
    $request = Request::create("/api/v1/customers/$customerId", 'GET');
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    try {
        $response = $kernel->handle($request);
        $content = json_decode($response->getContent(), true);

        if ($response->getStatusCode() === 200) {
            echo "✅ نجح قراءة العميل\n";
            echo "   ID: {$content['data']['id']}\n";
            echo "   الاسم: {$content['data']['full_name']}\n";
            echo "   الهاتف: {$content['data']['phone']}\n";
            echo "   المدينة: {$content['data']['city']}\n";
            $testResults['read'] = true;
        } else {
            echo "❌ فشل قراءة العميل\n";
            echo "   Status: {$response->getStatusCode()}\n";
            $testResults['read'] = false;
        }
        $kernel->terminate($request, $response);
    } catch (\Exception $e) {
        echo "❌ استثناء: {$e->getMessage()}\n";
        $testResults['read'] = false;
    }
} else {
    echo "⏭️  تم التخطي (لم يتم إنشاء العميل)\n";
    $testResults['read'] = false;
}

echo "\n";

// ============================================
// 3. UPDATE - تحديث بيانات العميل
// ============================================
echo "✏️  3. UPDATE - تحديث بيانات العميل\n";
echo str_repeat("-", 80) . "\n";

if ($customerId) {
    $request = Request::create("/api/v1/customers/$customerId", 'PUT');
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    $request->merge([
        'full_name' => 'أحمد محمد العلي (محدث)',
        'city' => 'جدة',
        'customer_tier' => 'PREMIUM',
        'notes' => 'عميل VIP',
    ]);

    try {
        $response = $kernel->handle($request);
        $content = json_decode($response->getContent(), true);

        if ($response->getStatusCode() === 200) {
            echo "✅ نجح تحديث العميل\n";
            echo "   ID: {$content['data']['id']}\n";
            echo "   الاسم الجديد: {$content['data']['full_name']}\n";
            echo "   المدينة الجديدة: {$content['data']['city']}\n";
            echo "   الفئة الجديدة: {$content['data']['customer_tier']}\n";
            $testResults['update'] = true;
        } else {
            echo "❌ فشل تحديث العميل\n";
            echo "   Status: {$response->getStatusCode()}\n";
            echo "   Message: {$content['message']}\n";
            $testResults['update'] = false;
        }
        $kernel->terminate($request, $response);
    } catch (\Exception $e) {
        echo "❌ استثناء: {$e->getMessage()}\n";
        $testResults['update'] = false;
    }
} else {
    echo "⏭️  تم التخطي (لم يتم إنشاء العميل)\n";
    $testResults['update'] = false;
}

echo "\n";

// ============================================
// 4. READ بعد التحديث - التحقق من التحديث
// ============================================
echo "🔍 4. READ بعد التحديث - التحقق من التحديث\n";
echo str_repeat("-", 80) . "\n";

if ($customerId) {
    $request = Request::create("/api/v1/customers/$customerId", 'GET');
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    try {
        $response = $kernel->handle($request);
        $content = json_decode($response->getContent(), true);

        if ($response->getStatusCode() === 200) {
            $nameUpdated = $content['data']['full_name'] === 'أحمد محمد العلي (محدث)';
            $cityUpdated = $content['data']['city'] === 'جدة';
            $tierUpdated = $content['data']['customer_tier'] === 'PREMIUM';

            if ($nameUpdated && $cityUpdated && $tierUpdated) {
                echo "✅ تم التحقق من التحديث بنجاح\n";
                echo "   الاسم: {$content['data']['full_name']}\n";
                echo "   المدينة: {$content['data']['city']}\n";
                echo "   الفئة: {$content['data']['customer_tier']}\n";
                $testResults['verify_update'] = true;
            } else {
                echo "⚠️  التحديث لم يتم بشكل صحيح\n";
                echo "   name updated: " . ($nameUpdated ? '✅' : '❌') . "\n";
                echo "   city updated: " . ($cityUpdated ? '✅' : '❌') . "\n";
                echo "   tier updated: " . ($tierUpdated ? '✅' : '❌') . "\n";
                $testResults['verify_update'] = false;
            }
        } else {
            echo "❌ فشل القراءة\n";
            $testResults['verify_update'] = false;
        }
        $kernel->terminate($request, $response);
    } catch (\Exception $e) {
        echo "❌ استثناء: {$e->getMessage()}\n";
        $testResults['verify_update'] = false;
    }
} else {
    echo "⏭️  تم التخطي (لم يتم إنشاء العميل)\n";
    $testResults['verify_update'] = false;
}

echo "\n";

// ============================================
// 5. DELETE - حذف العميل
// ============================================
echo "🗑️  5. DELETE - حذف العميل\n";
echo str_repeat("-", 80) . "\n";

if ($customerId) {
    $request = Request::create("/api/v1/customers/$customerId", 'DELETE');
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    try {
        $response = $kernel->handle($request);
        $content = json_decode($response->getContent(), true);

        if ($response->getStatusCode() === 200) {
            echo "✅ نجح حذف العميل\n";
            echo "   Message: {$content['message']}\n";
            $testResults['delete'] = true;
        } else {
            echo "❌ فشل حذف العميل\n";
            echo "   Status: {$response->getStatusCode()}\n";
            echo "   Message: {$content['message']}\n";
            $testResults['delete'] = false;
        }
        $kernel->terminate($request, $response);
    } catch (\Exception $e) {
        echo "❌ استثناء: {$e->getMessage()}\n";
        $testResults['delete'] = false;
    }
} else {
    echo "⏭️  تم التخطي (لم يتم إنشاء العميل)\n";
    $testResults['delete'] = false;
}

echo "\n";

// ============================================
// 6. READ بعد الحذف - التحقق من الحذف
// ============================================
echo "🔍 6. READ بعد الحذف - التحقق من الحذف\n";
echo str_repeat("-", 80) . "\n";

if ($customerId) {
    $request = Request::create("/api/v1/customers/$customerId", 'GET');
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    try {
        $response = $kernel->handle($request);
        $content = json_decode($response->getContent(), true);

        if ($response->getStatusCode() === 404) {
            echo "✅ تم التحقق من الحذف بنجاح (العميل غير موجود)\n";
            $testResults['verify_delete'] = true;
        } else {
            echo "⚠️  العميل ما زال موجوداً أو خطأ غير متوقع\n";
            echo "   Status: {$response->getStatusCode()}\n";
            $testResults['verify_delete'] = false;
        }
        $kernel->terminate($request, $response);
    } catch (\Exception $e) {
        echo "❌ استثناء: {$e->getMessage()}\n";
        $testResults['verify_delete'] = false;
    }
} else {
    echo "⏭️  تم التخطي (لم يتم إنشاء العميل)\n";
    $testResults['verify_delete'] = false;
}

echo "\n";

// ============================================
// ملخص النتائج
// ============================================
echo str_repeat("=", 80) . "\n";
echo "📊 ملخص النتائج\n";
echo str_repeat("=", 80) . "\n\n";

$passed = count(array_filter($testResults, fn($r) => $r));
$total = count($testResults);

echo "✅ نجح: $passed\n";
echo "❌ فشل: " . ($total - $passed) . "\n";
echo "📝 المجموع: $total\n";
echo "📈 نسبة النجاح: " . round(($passed / $total) * 100, 2) . "%\n\n";

echo "التفاصيل:\n";
foreach ($testResults as $test => $result) {
    $status = $result ? '✅' : '❌';
    echo sprintf("   %s %s\n", $status, strtoupper($test));
}

echo "\n";

if ($passed === $total) {
    echo "🎉 جميع الاختبارات نجحت!\n";
} else {
    echo "⚠️  بعض الاختبارات فشلت، يرجى مراجعة الأخطاء\n";
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "✨ تم الانتهاء من اختبار عمليات CRUD!\n";
echo str_repeat("=", 80) . "\n";
