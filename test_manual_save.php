<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Flight\FlightBooking;
use App\Models\Customer;
use App\Models\Bus\BusCompany;
use App\Models\Service\Service;
use App\Models\Online\OnlineServiceType;
use App\Models\Employee;
use App\Models\Account;
use App\Models\User;

echo "=== اختبار الحفظ اليدوي في قاعدة البيانات ===\n\n";

// 1. إنشاء عميل جديد
echo "1. إنشاء عميل جديد...\n";
$customer = Customer::create([
    'full_name' => 'أحمد محمد العلي',
    'phone' => '+966501234567',
    'national_id' => '1234567890',
    'passport_number' => 'A12345678',
    'passport_expiry' => '2025-12-31',
    'date_of_birth' => '1990-01-01',
    'city' => 'الرياض',
    'affiliation' => 'شركة التكنولوجيا',
    'customer_tier' => 'STANDARD',
    'notes' => 'عميل تجريبي',
    'created_by' => 1,
]);
echo "✅ تم إنشاء العميل: {$customer->full_name} (ID: {$customer->id})\n\n";

// 2. إنشاء شركة حافلات جديدة
echo "2. إنشاء شركة حافلات جديدة...\n";
$busCompany = BusCompany::create([
    'name' => 'شركة النقل السريع',
    'phone' => '+966509876543',
    'address' => 'الرياض، حي الملز',
    'is_active' => true,
    'notes' => 'شركة نقل تجريبية',
    'created_by' => 1,
]);
echo "✅ تم إنشاء شركة الحافلات: {$busCompany->name} (ID: {$busCompany->id})\n\n";

// 3. إنشاء خدمة جديدة
echo "3. إنشاء خدمة جديدة...\n";
$service = Service::create([
    'name' => 'باقة عمرة مميزة 2025',
    'category' => 'umrah',
    'description' => 'باقة عمرة شاملة فندق 5 نجوم',
    'cost_price' => 3000.00,
    'selling_price' => 4500.00,
    'is_active' => true,
    'notes' => 'باقة تجريبية',
    'created_by' => 1,
]);
echo "✅ تم إنشاء الخدمة: {$service->name} (ID: {$service->id})\n\n";

// 4. إنشاء نوع خدمة أونلاين جديد
echo "4. إنشاء نوع خدمة أونلاين جديدة...\n";
$onlineService = OnlineServiceType::create([
    'name' => 'تجديد تأشيرة زيارة',
    'fee_type' => 'fixed',
    'fee_value' => 500.00,
    'is_active' => true,
    'notes' => 'خدمة تجديد تأشيرة تجريبية',
    'created_by' => 1,
]);
echo "✅ تم إنشاء خدمة أونلاين: {$onlineService->name} (ID: {$onlineService->id})\n\n";

// 5. إنشاء موظف جديد
echo "5. إنشاء موظف جديد...\n";
$employee = Employee::create([
    'user_id' => null,
    'full_name' => 'محمد عبدالله السالم',
    'phone' => '+966501111222',
    'national_id' => '9876543210',
    'position' => 'موظف مبيعات',
    'department' => 'المبيعات',
    'salary' => 6000.00,
    'hire_date' => now(),
    'employment_status' => 'active',
]);
echo "✅ تم إنشاء الموظف: {$employee->full_name} (ID: {$employee->id})\n\n";

// 6. إنشاء حساب مالي جديد
echo "6. إنشاء حساب مالي جديد...\n";
$account = Account::create([
    'name' => 'الصندوق الرئيسي',
    'type' => 'bank',
    'balance' => 50000.00,
    'currency' => 'SAR',
    'is_active' => true,
    'owner_type' => 'owner',
    'notes' => 'حساب تجريبي',
]);
echo "✅ تم إنشاء الحساب: {$account->name} (ID: {$account->id})\n\n";

// 7. إنشاء حجز رحلة جديدة (الاختبار الأهم)
echo "7. إنشاء حجز رحلة جديدة...\n";
$flightBooking = FlightBooking::create([
    'booking_reference' => 'FLT-MANUAL-001',
    'booking_number' => 'FLT-TEST-' . time(),
    'booking_channel_type' => 'manual',
    'booking_channel_provider' => 'Manual Booking',
    'system_type' => 'manual',
    'pnr' => 'ABC123',
    'status' => 'PENDING',
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'account_id' => $account->id,
    'agent_name' => 'أحمد الوكيل',
    'airline' => 'الخطوط الجوية السعودية',
    'airline_name' => 'Saudia',
    'origin' => 'JED',
    'from_airport' => 'JED',
    'destination' => 'CAI',
    'to_airport' => 'CAI',
    'departure_date' => now()->addDays(10),
    'departure_time' => now()->addDays(10)->setTime(14, 30),
    'return_date' => now()->addDays(17),
    'return_time' => now()->addDays(17)->setTime(16, 45),
    'arrival_time' => now()->addDays(10)->setTime(15, 45),
    'trip_type' => 'round_trip',
    'passenger_count' => 2,
    'baggage_allowance_kg' => 46,
    'trip_details' => 'رحلة ذهاب وعودة من جدة إلى القاهرة',
    'purchase_price' => 2500.00,
    'selling_price' => 3200.00,
    'profit' => 700.00,
    'currency' => 'SAR',
    'notes' => 'حجز تجريبي للنظام',
    'created_by' => 1,
]);
echo "✅ تم إنشاء حجز الرحلة: {$flightBooking->booking_number} (ID: {$flightBooking->id})\n";
echo "   - العميل: {$customer->full_name}\n";
echo "   - شركة الطيران: {$flightBooking->airline_name}\n";
echo "   - من: {$flightBooking->from_airport} إلى: {$flightBooking->to_airport}\n";
echo "   - تاريخ السفر: {$flightBooking->departure_date->format('Y-m-d H:i')}\n";
echo "   - السعر: {$flightBooking->selling_price} {$flightBooking->currency}\n";
echo "   - الربح: {$flightBooking->profit} {$flightBooking->currency}\n\n";

echo "=== تم الانتهاء من الاختبار بنجاح! ===\n";
echo "جميع البيانات تم حفظها في قاعدة البيانات '{$app['config']->get('database.connections.mysql.database')}'\n";

// التحقق من البيانات المحفوظة
echo "\n=== التحقق من البيانات المحفوظة ===\n";
echo "عدد العملاء: " . Customer::count() . "\n";
echo "عدد شركات الحافلات: " . BusCompany::count() . "\n";
echo "عدد الخدمات: " . Service::count() . "\n";
echo "عدد خدمات الأونلاين: " . OnlineServiceType::count() . "\n";
echo "عدد الموظفين: " . Employee::count() . "\n";
echo "عدد الحسابات: " . Account::count() . "\n";
echo "عدد حجوزات الرحلات: " . FlightBooking::count() . "\n";

echo "\n✅ يمكنك الآن التحقق من البيانات في قاعدة البيانات مباشرة!\n";
