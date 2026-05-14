<?php

/**
 * اختبار CRUD سريع
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Http;
use App\Models\User;

// الحصول على API token
$user = User::first();
if (!$user) {
    die("❌ لا يوجد مستخدم في قاعدة البيانات\n");
}

// حذف جميع التوكن القديمة وإنشاء جديد
$user->tokens()->delete();
$token = $user->createToken('test')->plainTextToken;

echo "🚀 API Token: $token\n\n";

// اختبار إنشاء عميل
$response = Http::withToken($token)->post('http://127.0.0.1:8000/api/v1/customers', [
    'name' => 'أحمد محمد',
    'email' => 'ahmed' . rand(1000, 9999) . '@example.com',
    'phone' => '0501234567',
    'national_id' => '12345678901234',
    'address' => 'الرياض',
    'is_active' => true
]);

echo "📊 CREATE Customer:\n";
echo "Status: " . $response->status() . "\n";
echo "Body: " . $response->body() . "\n\n";

if ($response->successful()) {
    $customer = $response->json()['data'];
    $customerId = $customer['id'];

    // اختبار قراءة العميل
    $response = Http::withToken($token)->get("http://127.0.0.1:8000/api/v1/customers/$customerId");
    echo "📖 READ Customer:\n";
    echo "Status: " . $response->status() . "\n";
    echo "Body: " . $response->body() . "\n\n";

    // اختبار تحديث العميل
    $response = Http::withToken($token)->put("http://127.0.0.1:8000/api/v1/customers/$customerId", [
        'name' => 'أحمد محمد (محدث)',
        'phone' => '0517654321'
    ]);
    echo "✏️  UPDATE Customer:\n";
    echo "Status: " . $response->status() . "\n";
    echo "Body: " . $response->body() . "\n\n";

    // اختبار حذف العميل
    $response = Http::withToken($token)->delete("http://127.0.0.1:8000/api/v1/customers/$customerId");
    echo "🗑️  DELETE Customer:\n";
    echo "Status: " . $response->status() . "\n";
    echo "Body: " . $response->body() . "\n\n";
}

echo "✨ تم الانتهاء من الاختبار!\n";
