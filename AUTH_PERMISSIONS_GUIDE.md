# 📋 نظام التسجيل والدخول والصلاحيات - دليل الاستخدام

## 🔐 نظام المصادقة (Authentication)

### المسارات المتاحة:

#### 1. **تسجيل مستخدم جديد** (Public)
```http
POST /api/v1/auth/register
```

**البيانات المطلوبة:**
```json
{
  "name": "محمد أحمد",
  "email": "mohammed@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

**الاستجابة:**
```json
{
  "success": true,
  "message": "تم التسجيل بنجاح",
  "data": {
    "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "token_type": "Bearer",
    "user": {
      "id": 1,
      "name": "محمد أحمد",
      "email": "mohammed@example.com",
      "role": "employee"
    }
  }
}
```

#### 2. **تسجيل الدخول** (Public)
```http
POST /api/v1/auth/login
```

**البيانات المطلوبة:**
```json
{
  "email": "mohammed@example.com",
  "password": "password123"
}
```

**الاستجابة:**
```json
{
  "success": true,
  "message": "تم تسجيل الدخول بنجاح",
  "data": {
    "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "token_type": "Bearer",
    "user": {
      "id": 1,
      "name": "محمد أحمد",
      "email": "mohammed@example.com",
      "role": "employee",
      "permissions": [
        "flights.view",
        "flights.create",
        "buses.view",
        "buses.create",
        ...
      ]
    }
  }
}
```

#### 3. **الحصول على بيانات المستخدم** (Protected)
```http
GET /api/v1/auth/me
```

**الرأس (Header):**
```
Authorization: Bearer {token}
```

#### 4. **تسجيل الخروج** (Protected)
```http
POST /api/v1/auth/logout
```

**الرأس (Header):**
```
Authorization: Bearer {token}
```

#### 5. **تحديث بيانات المستخدم** (Protected)
```http
PUT /api/v1/auth/profile
```

**البيانات (اختيارية):**
```json
{
  "name": "الاسم الجديد",
  "email": "newemail@example.com",
  "current_password": "password123",
  "password": "newpassword123",
  "password_confirmation": "newpassword123"
}
```

---

## 🎭 نظام الصلاحيات (Permissions)

### الأدوار المتاحة:

#### 1. **Admin (مدير النظام)**
- **الصلاحيات:** جميع العمليات (Create, Read, Update, Delete)
- **المميزات:**
  - ✅ إدارة جميع الموديولات
  - ✅ إنشاء وتعديل وحذف أي سجل
  - ✅ تأكيد وإلغاء الحجوزات
  - ✅ إدارة المدفوعات
  - ✅ إدارة المستخدمين والصلاحيات
  - ✅ الوصول للإعدادات
  - ✅ عرض جميع التقارير

#### 2. **Manager (مدير)**
- **الصلاحيات:** عمليات محدودة
- **المميزات:**
  - ✅ عرض وإنشاء وتعديل الحجوزات
  - ✅ تأكيد وإلغاء الحجوزات
  - ✅ إدارة الموظفين والمكافآت
  - ✅ عرض الحسابات المالية
  - ✅ عرض التقارير

#### 3. **Employee (موظف)**
- **الصلاحيات:** عمليات أساسية
- **المميزات:**
  - ✅ عرض وإنشاء الحجوزات فقط
  - ✅ إدارة العملاء
  - ✅ عرض التقارير الأساسية

### الصلاحيات التفصيلية:

#### 🛫 Flight Module (الرحلات)
| الصلاحية | Admin | Manager | Employee |
|---------|-------|---------|----------|
| `flights.view` | ✅ | ✅ | ✅ |
| `flights.create` | ✅ | ✅ | ✅ |
| `flights.edit` | ✅ | ✅ | ❌ |
| `flights.delete` | ✅ | ❌ | ❌ |
| `flights.confirm` | ✅ | ✅ | ❌ |
| `flights.cancel` | ✅ | ✅ | ❌ |
| `flights.payments` | ✅ | ❌ | ❌ |

#### 🚌 Bus Module (الحافلات)
| الصلاحية | Admin | Manager | Employee |
|---------|-------|---------|----------|
| `buses.view` | ✅ | ✅ | ✅ |
| `buses.create` | ✅ | ✅ | ✅ |
| `buses.edit` | ✅ | ✅ | ❌ |
| `buses.delete` | ✅ | ❌ | ❌ |

#### 🏨 Service Module (الخدمات)
| الصلاحية | Admin | Manager | Employee |
|---------|-------|---------|----------|
| `services.view` | ✅ | ✅ | ✅ |
| `services.create` | ✅ | ✅ | ✅ |
| `services.edit` | ✅ | ✅ | ❌ |
| `services.delete` | ✅ | ❌ | ❌ |

#### 💰 Finance Module (المالية)
| الصلاحية | Admin | Manager | Employee |
|---------|-------|---------|----------|
| `finance.view` | ✅ | ✅ | ❌ |
| `finance.create` | ✅ | ❌ | ❌ |
| `finance.edit` | ✅ | ❌ | ❌ |
| `finance.delete` | ✅ | ❌ | ❌ |
| `accounts.manage` | ✅ | ❌ | ❌ |
| `transactions.manage` | ✅ | ❌ | ❌ |

#### 👥 Employee Module (الموظفين)
| الصلاحية | Admin | Manager | Employee |
|---------|-------|---------|----------|
| `employees.view` | ✅ | ✅ | ❌ |
| `employees.create` | ✅ | ✅ | ❌ |
| `employees.edit` | ✅ | ✅ | ❌ |
| `employees.delete` | ✅ | ❌ | ❌ |
| `employees.bonuses` | ✅ | ✅ | ❌ |

#### 👨‍👩‍👧‍👦 Customer Module (العملاء)
| الصلاحية | Admin | Manager | Employee |
|---------|-------|---------|----------|
| `customers.view` | ✅ | ✅ | ✅ |
| `customers.create` | ✅ | ✅ | ✅ |
| `customers.edit` | ✅ | ✅ | ❌ |
| `customers.delete` | ✅ | ❌ | ❌ |

---

## 🚀 التجربة السريعة:

### 1. تشغيل الاختبار الشامل:
```bash
php test_auth_system.php
```

### 2. استخدام حسابات جاهزة:
```bash
# Admin
Email: admin@safarak.com
Password: admin123

# Manager
Email: manager@safarak.com
Password: manager123

# Employee
Email: test@example.com
Password: password123
```

### 3. تجربة عبر cURL:

#### تسجيل الدخول والحصول على Token:
```bash
curl -X POST "http://localhost:8000/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@safarak.com",
    "password": "admin123"
  }'
```

#### استخدام Token للوصول للمحميات:
```bash
# عرض جميع حجوزات الرحلات
curl -X GET "http://localhost:8000/api/v1/flight/bookings" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"

# إنشاء حجز رحلة جديد
curl -X POST "http://localhost:8000/api/v1/flight/bookings" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_id": 1,
    "booking_reference": "FLT-001",
    "airline": "Saudia",
    "from_airport": "JED",
    "to_airport": "CAI"
  }'
```

---

## 🛡️ استخدام Middleware للصلاحيات:

### في الـ Routes:
```php
// فقط Admin يمكنه الحذف
Route::delete('/bookings/{id}', function () {
    // حذف الحجز
})->middleware('permission:flights.delete');

// Manager و Admin يمكنهم التعديل
Route::put('/bookings/{id}', function () {
    // تعديل الحجز
})->middleware('permission:flights.edit');

// جميع المستخدمين يمكنهم العرض
Route::get('/bookings', function () {
    // عرض الحجوزات
})->middleware('permission:flights.view');
```

### التحقق من الصلاحيات في الكود:
```php
// في Controller
public function someAction(Request $request)
{
    $user = $request->user();

    // التحقق من الصلاحية
    if (!$user->hasPermission('flights.delete')) {
        return response()->json([
            'success' => false,
            'message' => 'ليس لديك صلاحية الحذف'
        ], 403);
    }

    // تنفيذ العملية
}
```

---

## 📊 مثال عملي:

### سيناريو: نظام حجز الرحلات

#### 1. **موظف عادي** يمكنه:
```php
// ✅ عرض جميع الحجوزات
GET /api/v1/flight/bookings

// ✅ إنشاء حجز جديد
POST /api/v1/flight/bookings

// ✅ عرض العملاء
GET /api/v1/customers

// ❌ تعديل حجز (403 Forbidden)
PUT /api/v1/flight/bookings/1

// ❌ حذف حجز (403 Forbidden)
DELETE /api/v1/flight/bookings/1
```

#### 2. **مدير** يمكنه:
```php
// ✅ جميع عمليات الموظف +
// ✅ تعديل الحجوزات
PUT /api/v1/flight/bookings/1

// ✅ تأكيد الحجوزات
POST /api/v1/flight/bookings/1/confirm

// ✅ إلغاء الحجوزات
POST /api/v1/flight/bookings/1/cancel

// ❌ حذف الحجوزات (403 Forbidden)
DELETE /api/v1/flight/bookings/1
```

#### 3. **مدير النظام** يمكنه:
```php
// ✅ كل شيء بدون قيود
// ✅ حذف الحجوزات
DELETE /api/v1/flight/bookings/1

// ✅ إدارة المستخدمين
POST /api/v1/users

// ✅ الوصول للإعدادات
PUT /api/v1/settings
```

---

## 🔧 التحقق من حالة النظام:

```bash
# اختبار جميع الـ Routes
php artisan route:list --path=auth

# اختبار نظام التسجيل
php test_auth_system.php

# التحقق من المستخدمين
php artisan tinker
```

```php
// في Tinker
use App\Models\User;

// عرض جميع المستخدمين
User::all(['id', 'name', 'email', 'role', 'is_active'])

// إنشاء Admin
User::create([
    'name' => 'Admin User',
    'email' => 'admin@example.com',
    'password' => bcrypt('admin123'),
    'role' => 'admin',
    'is_active' => true
])
```

---

## 📝 ملاحظات مهمة:

1. **جميع الـ APIs المحمية تتطلب Token** في الـ Header:
   ```
   Authorization: Bearer {your_token}
   ```

2. **Tokens تنتهي صلاحيتها** عند تسجيل الخروج

3. **المستخدم غير النشط** لا يمكنه تسجيل الدخول

4. **الصلاحيات تُحدد تلقائياً** حسب دور المستخدم

5. **يمكن توسيع الصلاحيات** بإضافة المزيد في `CheckPermission` middleware

---

## ✅ النظام جاهز للاستخدام!

جميع الميزات تعمل:
- ✅ تسجيل مستخدمين جدد
- ✅ تسجيل الدخول والخروج
- ✅ نظام صلاحيات متكامل
- ✅ 3 أدوار (Admin, Manager, Employee)
- ✅ حماية الـ APIs
- ✅ تحديث بيانات المستخدم