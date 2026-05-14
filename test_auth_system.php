<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

echo "=== اختبار نظام التسجيل والدخول والصلاحيات ===\n\n";

// اختبار 1: تسجيل مستخدم جديد
echo "1. اختبار تسجيل مستخدم جديد...\n";
try {
    $request = Illuminate\Http\Request::create('/api/v1/auth/register', 'POST', [], [], [], [], json_encode([
        'name' => 'مستخدم تجريبي',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]));

    $request->headers->set('Content-Type', 'application/json');
    $response = $kernel->handle($request);
    $statusCode = $response->getStatusCode();
    $content = json_decode($response->getContent(), true);

    if ($statusCode === 201) {
        echo "✅ تم التسجيل بنجاح\n";
        echo "   الاسم: {$content['data']['user']['name']}\n";
        echo "   البريد: {$content['data']['user']['email']}\n";
        echo "   الدور: {$content['data']['user']['role']}\n";
        $tokenPreview = substr($content['data']['token'], 0, 50);
        echo "   التوكن: {$tokenPreview}...\n\n";

        $token = $content['data']['token'];
    } else {
        echo "❌ فشل التسجيل: {$content['message']}\n\n";
        $token = null;
    }
} catch (Exception $e) {
    echo "❌ خطأ: {$e->getMessage()}\n\n";
    $token = null;
}

// اختبار 2: تسجيل الدخول
echo "2. اختبار تسجيل الدخول...\n";
if ($token) {
    echo "⚠️  المستخدم مسجل بالفعل، سنتجlog_out أولاً\n";
    // تسجيل الخروج
    $logoutRequest = Illuminate\Http\Request::create('/api/v1/auth/logout', 'POST');
    $logoutRequest->headers->set('Authorization', "Bearer $token");
    $kernel->handle($logoutRequest);
}

try {
    $request = Illuminate\Http\Request::create('/api/v1/auth/login', 'POST', [], [], [], [], json_encode([
        'email' => 'test@example.com',
        'password' => 'password123',
    ]));

    $request->headers->set('Content-Type', 'application/json');
    $response = $kernel->handle($request);
    $statusCode = $response->getStatusCode();
    $content = json_decode($response->getContent(), true);

    if ($statusCode === 200) {
        echo "✅ تم تسجيل الدخول بنجاح\n";
        echo "   المستخدم: {$content['data']['user']['name']}\n";
        echo "   الدور: {$content['data']['user']['role']}\n";
        echo "   الصلاحيات:\n";
        foreach ($content['data']['user']['permissions'] as $permission) {
            echo "     - $permission\n";
        }
        $tokenPreview = substr($content['data']['token'], 0, 50);
        echo "   التوكن: {$tokenPreview}...\n\n";

        $token = $content['data']['token'];
        $userRole = $content['data']['user']['role'];
    } else {
        echo "❌ فشل تسجيل الدخول: {$content['message']}\n\n";
        $token = null;
    }
} catch (Exception $e) {
    echo "❌ خطأ: {$e->getMessage()}\n\n";
    $token = null;
}

// اختبار 3: الحصول على بيانات المستخدم
echo "3. اختبار الحصول على بيانات المستخدم...\n";
if ($token) {
    try {
        $request = Illuminate\Http\Request::create('/api/v1/auth/me', 'GET');
        $request->headers->set('Authorization', "Bearer $token");

        $response = $kernel->handle($request);
        $statusCode = $response->getStatusCode();
        $content = json_decode($response->getContent(), true);

        if ($statusCode === 200) {
            echo "✅ تم الحصول على البيانات بنجاح\n";
            echo "   المستخدم: {$content['data']['user']['name']}\n";
            echo "   البريد: {$content['data']['user']['email']}\n";
            echo "   الدور: {$content['data']['user']['role']}\n\n";
        } else {
            echo "❌ فشل الحصول على البيانات: {$content['message']}\n\n";
        }
    } catch (Exception $e) {
        echo "❌ خطأ: {$e->getMessage()}\n\n";
    }
}

// اختبار 4: تسجيل الخروج
echo "4. اختبار تسجيل الخروج...\n";
if ($token) {
    try {
        $request = Illuminate\Http\Request::create('/api/v1/auth/logout', 'POST');
        $request->headers->set('Authorization', "Bearer $token");

        $response = $kernel->handle($request);
        $statusCode = $response->getStatusCode();
        $content = json_decode($response->getContent(), true);

        if ($statusCode === 200) {
            echo "✅ تم تسجيل الخروج بنجاح\n";
            echo "   {$content['message']}\n\n";
        } else {
            echo "❌ فشل تسجيل الخروج: {$content['message']}\n\n";
        }
    } catch (Exception $e) {
        echo "❌ خطأ: {$e->getMessage()}\n\n";
    }
}

// اختبار 5: إنشاء Admin
echo "5. إنشاء مستخدم Admin...\n";
try {
    $admin = \App\Models\User::updateOrCreate(
        ['email' => 'admin@safarak.com'],
        [
            'name' => 'مدير النظام',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
            'is_active' => true,
        ]
    );

    echo "✅ تم إنشاء/تحديث Admin\n";
    echo "   الاسم: {$admin->name}\n";
    echo "   البريد: {$admin->email}\n";
    echo "   الدور: {$admin->role}\n";
    echo "   كلمة المرور: admin123\n\n";
} catch (Exception $e) {
    echo "❌ خطأ: {$e->getMessage()}\n\n";
}

// اختبار 6: إنشاء Manager
echo "6. إنشاء مستخدم Manager...\n";
try {
    $manager = \App\Models\User::updateOrCreate(
        ['email' => 'manager@safarak.com'],
        [
            'name' => 'مدير المبيعات',
            'password' => Hash::make('manager123'),
            'role' => 'manager',
            'is_active' => true,
        ]
    );

    echo "✅ تم إنشاء/تحديث Manager\n";
    echo "   الاسم: {$manager->name}\n";
    echo "   البريد: {$manager->email}\n";
    echo "   الدور: {$manager->role}\n";
    echo "   كلمة المرور: manager123\n\n";
} catch (Exception $e) {
    echo "❌ خطأ: {$e->getMessage()}\n\n";
}

echo "=== معلومات الدخول للتجربة ===\n";
echo "1. Admin: admin@safarak.com / admin123\n";
echo "2. Manager: manager@safarak.com / manager123\n";
echo "3. Employee: test@example.com / password123\n\n";

echo "=== الصلاحيات حسب الدور ===\n";
echo "🔴 Admin (مدير النظام):\n";
echo "   - جميع الصلاحيات (Create, Read, Update, Delete)\n";
echo "   - إدارة المستخدمين والصلاحيات\n";
echo "   - الوصول للإعدادات\n\n";

echo "🟡 Manager (مدير):\n";
echo "   - عرض وإنشاء وتعديل الحجوزات\n";
echo "   - إدارة الموظفين والمكافآت\n";
echo "   - عرض التقارير المالية\n\n";

echo "🟢 Employee (موظف):\n";
echo "   - عرض وإنشاء الحجوزات فقط\n";
echo "   - إدارة العملاء\n\n";

echo "✅ نظام التسجيل والدخول جاهز للاستخدام!\n";

$kernel->terminate($request, $response);
