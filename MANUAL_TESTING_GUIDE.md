# دليل الاختبار اليدوي - SafarakEalayna

## طريقة 1: اختبار سريع باستخدام PHP Script

### تشغيل الاختبار التلقائي:
```bash
php test_manual_save.php
```

هذا السكريبت سيقوم بـ:
- ✅ إنشاء عميل جديد
- ✅ إنشاء شركة حافلات
- ✅ إنشاء خدمة
- ✅ إنشاء خدمة أونلاين
- ✅ إنشاء موظف
- ✅ إنشاء حساب مالي
- ✅ إنشاء حجز رحلة (الأهم)
- ✅ حفظ كل شيء في قاعدة البيانات

## طريقة 2: اختبار يدوي عبر API

### أولاً: تشغيل السيرفر:
```bash
php artisan serve
```

### ثانياً: إنشاء مستخدم للدخول:
```bash
php artisan tinker
```
```php
use App\Models\User;
User::create([
    'name' => 'Admin User',
    'email' => 'admin@example.com',
    'password' => bcrypt('password'),
]);
```

### ثالثاً: طلبات API للتجربة:

#### 1. إنشاء عميل جديد:
```bash
curl -X POST "http://localhost:8000/api/v1/customers" \
  -H "Content-Type: application/json" \
  -d '{
    "full_name": "محمد أحمد",
    "phone": "+966501234567",
    "national_id": "1234567890",
    "city": "الرياض",
    "customer_tier": "STANDARD"
  }'
```

#### 2. إنشاء موظف جديد:
```bash
curl -X POST "http://localhost:8000/api/v1/employees" \
  -H "Content-Type: application/json" \
  -d '{
    "full_name": "سارة محمد",
    "phone": "+966509876543",
    "position": "موظفة مبيعات",
    "department": "المبيعات",
    "salary": 5000,
    "hire_date": "2025-01-01"
  }'
```

#### 3. إنشاء حجز رحلة (Flight Booking):
```bash
curl -X POST "http://localhost:8000/api/v1/flight/bookings" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_id": 1,
    "employee_id": 1,
    "booking_reference": "FLT-001",
    "booking_number": "FLT-TEST-001",
    "booking_channel_type": "manual",
    "booking_channel_provider": "Direct",
    "system_type": "manual",
    "pnr": "ABC123",
    "status": "PENDING",
    "agent_name": "الوكيل أحمد",
    "airline": "الخطوط السعودية",
    "airline_name": "Saudia",
    "origin": "JED",
    "from_airport": "JED",
    "destination": "CAI",
    "to_airport": "CAI",
    "departure_date": "2025-05-15",
    "departure_time": "14:30",
    "return_date": "2025-05-22",
    "return_time": "16:45",
    "arrival_time": "15:45",
    "trip_type": "round_trip",
    "passenger_count": 2,
    "baggage_allowance_kg": 46,
    "trip_details": "رحلة سياحية",
    "purchase_price": 2500,
    "selling_price": 3200,
    "profit": 700,
    "currency": "SAR",
    "notes": "حجز تجريبي"
  }'
```

#### 4. عرض جميع حجوزات الرحلات:
```bash
curl -X GET "http://localhost:8000/api/v1/flight/bookings"
```

#### 5. عرض حجز محدد:
```bash
curl -X GET "http://localhost:8000/api/v1/flight/bookings/1"
```

## طريقة 3: التحقق المباشر من قاعدة البيانات

### استخدام phpMyAdmin:
1. افتح phpMyAdmin
2. اختر قاعدة البيانات `safarakealayna`
3. تحقق من الجداول التالية:
   - `customers`
   - `employees`
   - `flight_bookings`
   - `bus_companies`
   - `services`
   - `online_service_types`
   - `accounts`

### استخدام Command Line:
```bash
mysql -u root -p safarakealayna
```

```sql
-- عرض جميع العملاء
SELECT * FROM customers;

-- عرض جميع حجوزات الرحلات
SELECT * FROM flight_bookings;

-- عرض حجوزات الرحلات مع بيانات العميل
SELECT fb.*, c.full_name 
FROM flight_bookings fb 
JOIN customers c ON fb.customer_id = c.id;
```

## طريقة 4: استخدام Tinker (Laravel Console)

```bash
php artisan tinker
```

```php
// إنشاء عميل جديد
$customer = App\Models\Customer::create([
    'full_name' => 'عميل تجريبي',
    'phone' => '+966501234567',
    'national_id' => '1234567890',
    'city' => 'الرياض',
    'customer_tier' => 'STANDARD'
]);

// إنشاء حجز رحلة
$booking = App\Models\Flight\FlightBooking::create([
    'booking_reference' => 'FLT-TINKER-001',
    'booking_number' => 'FLT-' . time(),
    'booking_channel_type' => 'manual',
    'booking_channel_provider' => 'Tinker',
    'system_type' => 'manual',
    'status' => 'PENDING',
    'customer_id' => $customer->id,
    'agent_name' => 'Test Agent',
    'airline' => 'Test Airlines',
    'airline_name' => 'Test Airlines',
    'origin' => 'JED',
    'from_airport' => 'JED',
    'destination' => 'CAI',
    'to_airport' => 'CAI',
    'departure_date' => now()->addDays(7),
    'departure_time' => now()->addDays(7)->setTime(10, 0),
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'baggage_allowance_kg' => 20,
    'trip_details' => 'Test booking',
    'purchase_price' => 1000,
    'selling_price' => 1500,
    'profit' => 500,
    'currency' => 'SAR',
    'created_by' => 1
]);

// عرض النتيجة
echo "تم إنشاء العميل: {$customer->full_name}\n";
echo "تم إنشاء الحجز: {$booking->booking_number}\n";
echo "رقم الحجز: {$booking->id}\n";
```

## التحقق من النجاح

### علامات النجاح:
1. ✅ لا توجد أخطاء في Console
2. ✅ يتم إرجاع `status: true` في API responses
3. ✅ يتم إنشاء IDs جديدة للسجلات
4. ✅ يمكن رؤية البيانات في قاعدة البيانات
5. ✅ يمكن استرجاع البيانات باستخدام GET requests

### التحقق من حالة النظام:
```bash
# اختبار قاعدة البيانات
php artisan db:show

# اختبار Routes
php artisan route:list --path=api

# تشغيل الاختبارات
php artisan test
```

## ملاحظات مهمة:

1. **بيانات الاختبار**: جميع البيانات التي تنشئها ستبقى في قاعدة البيانات حتى تحذفها يدوياً

2. **حذف بيانات الاختبار**:
```bash
php artisan migrate:fresh
```

3. **النسخ الاحتياطي**: قبل عمل أي تغييرات جوهرية، خذ نسخة احتياطية:
```bash
php artisan db:backup
```

4. **المشاكل المحتملة**:
   - تأكد من أن MySQL server يعمل
   - تأكد من صلاحيات الكتابة في مجلد `storage`
   - تأكد من أن `.env` file مضبوط بشكل صحيح

## الدعم الفني:
- إذا واجهت أي مشاكل، جرب تشغيل `php artisan test` أولاً
- تحقق من Laravel logs: `storage/logs/laravel.log`
- تأكد من أن جميع migrations تم تطبيقها: `php artisan migrate:status`