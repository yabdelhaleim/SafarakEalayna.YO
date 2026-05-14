# 📘 دليل ربط Filament Admin Panel بالتطبيق الرئيسي (Vue.js)

## ✅ **الهدف المنجز**

ربط احترافي بين Filament v3 والتطبيق الرئيسي (Vue.js + Sanctum) مع:

1. ✅ **Authentication موحد**: نفس الجدول `users`، نفس الـ session
2. ✅ **SSO (Single Sign-On)**: لو دخلت في التطبيق متحتاجش تدخل تاني في Filament
3. ✅ **زر "لوحة التحكم" في Vue**: يظهر فقط للمديرين، يفتح Filament في تاب جديد
4. ✅ **زر "العودة للتطبيق" في Filament**: في أعلى السايدبار، يرجع للبرنامج الرئيسي
5. ✅ **19+ Filament Resource** كامل: لكل Models في المشروع (Flight, Bus, Invoice, Finance, HR, Hajj/Umra, Online, إلخ)

---

## 📁 **الهيكل النهائي**

```
app/
├── Http/
│   ├── Middleware/
│   │   └── AuthenticateWithApiToken.php    ← يربط API token بـ Session
│   └── Kernel.php                           ← مسجل الـ middleware
├── Models/
│   └── User.php                             ← role: 'admin' | 'employee'
├── Filament/
│   ├── Admin/
│   │   └── Resources/                       ← 12+ Admin Resources
│   │       ├── Customers/
│   │       ├── FlightBookings/
│   │       ├── FlightPayments/
│   │       ├── Passengers/
│   │       ├── BusTickets/                  ← ★ جديد
│   │       ├── Programs/
│   │       ├── HajjUmraBookings/
│   │       ├── VisaBookings/
│   │       ├── Accounts/                    ← ★ جديد
│   │       ├── Transactions/                ← ★ جديد
│   │       ├── ExchangeRates/               ← ★ جديد
│   │       ├── OnlineServices/              ← ★ جديد
│   │       ├── FawryTransactions/           ← ★ جديد
│   │       ├── ApprovalWorkflows/           ← ★ جديد
│   │       ├── AuditLogs/                   ← ★ جديد
│   │       └── TreasuryTransactions/
│   ├── Resources/                           ← Resources قديمة (تحديث بسيط)
│   │   ├── Employee/
│   │   ├── Flight/
│   │   └── Invoice/
│   └── AdminPanelProvider.php               ← + navigationItems
└── Providers/
    └── Filament/
        └── AdminPanelProvider.php            ← + رابط "العودة للتطبيق"

resources/
└── js/
    └── layouts/
        └── DashboardLayout.vue               ← + زر "لوحة التحكم الإدارية"

config/
└── filament.php                             ← كل resources والـ navigation groups
```

---

## 🔐 **كيف يعمل Authentication:**

### **1. Vue.js Login → API Token (Sanctum)**
```javascript
// resources/js/stores/authStore.js
POST /api/v1/auth/login → token + user
localStorage: 'auth_token'
axios headers: Authorization: Bearer {token}
```

### **2. Filament Access → Session Bridge**
```
User visits /admin
↓
\App\Http\Middleware\AuthenticateWithApiToken
↓
Checks Authorization header (Bearer token)
↓
-if token valid → Auth::login($user) → Session created
↓
Filament guard 'web' sees Auth::check() = true
↓
Access granted ✅
```

### **3. Single Sign-Out**
```javascript
Logout from Vue → DELETE token
Next visit to /admin → no token → redirect to login
```

---

## 🧩 **الـ Resources المُنشأة:**

### **الموجودة مسبقاً (كانت موجودة):**
| Resource | النموذج | الحالة |
|----------|---------|--------|
| Customers | Customer | ✅ موجود في Admin |
| FlightBookings | FlightBooking | ✅ موجود في Admin |
| FlightPayments | FlightPayment | ✅ موجود في Admin |
| Passengers | Passenger | ✅ موجود في Admin |
| Programs | Program | ✅ موجود في Admin |
| HajjUmraBookings | HajjUmraBooking | ✅ موجود في Admin |
| VisaBookings | VisaBooking | ✅ موجود في Admin |
| TreasuryTransactions | TreasuryTransaction | ✅ موجود في Admin |
| Employee | Employee | ✅ موجود (قديم) |
| EmployeeAttendance | EmployeeAttendance | ✅ موجود (قديم) |
| EmployeeBonus | EmployeeBonus | ✅ موجود (قديم) |
| Invoice | Invoice | ✅ موجود (قديم) |

### **التم إنشاؤها الجديدة:**
| Resource | النموذج | الوصف |
|----------|---------|-------|
| **BusTickets** | BusTicket | تذاكر الباصات مع موظف، سعر شراء/بيع، ربح |
| **ExchangeRates** | ExchangeRate | أسعار صرف العملات |
| **Accounts** | Account | الحسابات المالية (نقدي، بنك) |
| **Transactions** | Transaction |所有_conversions (دخل/مصروف/تحويل) |
| **OnlineServices** | OnlineService | الخدمات الإلكترونية |
| **FawryTransactions** | FawryTransaction | فواتير فوري |
| **ApprovalWorkflows** | ApprovalWorkflow | سير الموافقات |
| **AuditLogs** | AuditLog | سجل جميع العمليات |
| **Supplier** | Supplier | الموردين (قديم - حسب الكونفيك) |

---

## 🎨 **خصائص كل Resource:**

كل Resource يحتوي على:

1. **ListRecords (Index)**:
   - بحث في الحقول المهمة
   - فلتر حسب الحالة/النوع/الموديول
   - ترتيب متعدد
   - أعمدة قابلة للإخفاء/الإظهار

2. **CreateRecord**: نموذج إضافة مع all fields مناسبة

3. **EditRecord**: نموذج تعديل كامل

4. **ViewRecord**: عرض تفاصيل (خاص بـ Filament v3)

5. **Relationships**:
   - EmployeeResource → user relation
   - FlightBooking → customer, passengers, pricing, payments
   - Invoice → customer, items, payments
   - Customer → flightBookings, hajjUmraBookings, visaBookings
   - إلخ

6. **Actions**:
   - ViewAction
   - EditAction
   - DeleteAction (حسب الحاجة)

---

## 🔗 **الأزرار Mechanisms:**

### **A. Vue → Filament (زر "لوحة التحكم")**
**الملف**: `resources/js/layouts/DashboardLayout.vue` (سطر ~76-82)

```vue
<a v-if="authStore.isAdmin" href="/admin" class="nl" target="_blank">
  <span class="nl-t">لوحة التحكم الإدارية</span>
  <span class="nl-badge badge-red">إداري</span>
</a>
```

**يظهر فقط إذا**: `authStore.isAdmin` (user.role === 'admin')

**السلوك**: `target="_blank"` يفتح في تاب جديد (неукоснительно منفصل)

---

### **B. Filament → Vue (زر "العودة للتطبيق")**
**الملف**: `app/Providers/Filament/AdminPanelProvider.php` (سطر ~57)

```php
->navigationItems([
    \Filament\Navigation\NavigationItem::make('العودة للتطبيق')
        ->icon('heroicon-o-arrow-left')
        ->url(fn () => url('/dashboard'), shouldOpenInNewTab: false)
        ->isActiveWhen(fn () => request()->is('dashboard*'))
        ->sort(1),
])
```

**الموقع**: في أعلى الـ Sidebar (قبل الـ resources)
**السلوك**: يرجع للصفحة الرئيسية (`/dashboard`) في نفس التاب

---

## 📝 **كيف تضيف موديول جديد في المستقبل؟**

### **الخطوات البسيطة:**

#### **1. إنشاء النموذج (Model)**
```bash
php artisan make:model NewModule -m
# أضfillable, relations, casts
```

#### **2. إنشاء Filament Resource (النمط الجديد - Admin)**:
```bash
# إنشاء Resource كامل مع Pages
php artisan make:filament-resource --generate --no-soft-deletes NewModule

# إذا كان فيه soft deletes:
php artisan make:filament-resource --generate NewModule
```

**أو يدوياً**:
```
1. أنشئ مجلد: app/Filament/Admin/Resources/NewModules/
2. أنشئ: NewModuleResource.php (يُورث من Resource)
3. أنشئ مجلد Pages/: ManageNewModules.php
4. في ManageNewModules.php:

   <?php
   namespace App\Filament\Admin\Resources\NewModules\Pages;
   use App\Filament\Admin\Resources\NewModules\NewModuleResource;
   use Filament\Resources\Pages\ManageRecords;
   class ManageNewModules extends ManageRecords {
       protected static string $resource = NewModuleResource::class;
   }

5. عدّل NewModuleResource.php:
   - model: NewModule::class
   - navigationIcon: 'heroicon-o-...'
   - navigationGroup: 'اسم المجموعة'
   - navigationLabel: 'اسم الموديول'
   - form(): كامل schema
   - table(): كامل columns, filters, actions
```

#### **3. سجّله في config/filament.php**
```php
'resources' => [
    // ...
    \App\Filament\Admin\Resources\NewModules\NewModuleResource::class,
],
```

#### **4. أضف الـ navigation group إذا كان جديد**
```php
'navigation' => [
    'groups' => [
        // ...
        'المجموعة الجديدة',
    ],
],
```

#### **5. Clear Cache**
```bash
php artisan config:clear
```

✅ **ممتاز! الموديول الجديد ظهر في Filament.**

---

## 🔧 **الفئة الأساسية (Base Resource):**

```php
// app/Filament/Resources/BaseResource.php
abstract class BaseResource extends Resource
{
    // Actions افتراضية: View, Edit, Delete
    // جدول تفضيلي: id DESC
    // البحث والفلترة يمكن إضافتها在这里
}
```

**كيف تستخدمها**:
```php
class MyResource extends BaseResource
{
    protected static ?string $model = MyModel::class;
    //只需要define form() و table()
}
```

---

## 🎯 **التحقق من الصحة (Testing):**

### **1. Test Authentication Bridge:**
```bash
# Install Sanctum API token
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

### **2. Test Admin Access:**
```bash
# تأكد من وجود admin role
php artisan tinker
>>> App\Models\User::where('role', 'admin')->count();
# لو 0 → أنشئ admin user

# Test API
curl -H "Authorization: Bearer {token}" http://your-app.test/api/v1/auth/me
# يجب يرجع user مع role = admin
```

### **3. Test Vue Integration:**
```bash
npm run dev
# افتح: http://localhost:5173/dashboard
# تسجيل دخول كـ admin
# يجب يظهر زر "لوحة التحكم الإدارية" في السايدبار
```

### **4. Test Filament Access:**
```
Visit: http://localhost:8000/admin
- لو مسجل دخول من Vue → يدخل مباشرة
- لو لا → يطلب login
```

---

## ⚙️ **Config Changes Summary:**

| File | التغيير |
|------|---------|
| `app/Http/Middleware/AuthenticateWithApiToken.php` | NEW - Middleware |
| `app/Http/Kernel.php` | NEW - تسجيل middleware |
| `app/Providers/Filament/AdminPanelProvider.php` | + navigationItems (رابط العودة) |
| `resources/js/layouts/DashboardLayout.vue` | + زر للمديرين فقط |
| `config/filament.php` | +所有resources الجديدة + navigation groups |
| `app/Filament/Resources/BaseResource.php` | NEW - فئة أساسية |

---

## 📊 **Navigation Groups النهائية:**

```
عام
  → لوحة التحكم (Dashboard)
  → لوحة التحكم الإدارية (Admin) [仅限管理员]

العملاء
  → العملاء (Customers)
  → الموردون (Suppliers)

حجوزات
  → حجوزات الطيران (FlightBookings)
  → المسافرين (Passengers)
  → حجوزات الباصات (BusTickets)
  → برامج الحج والعمرة (Programs)
  → حجوزات الحج والعمرة (HajjUmraBookings)
  → حجوزات التأشيرات (VisaBookings)

المالية
  → الفواتير (Invoices)
  → الحسابات (Accounts)
  → ال_conversions (Transactions)
  → أسعار الصرف (ExchangeRates)
  → المعاملات الخزينة (TreasuryTransactions)

الخدمات الإلكترونية
  → الخدمات الإلكترونية (OnlineServices)
  → فواتير فوري (FawryTransactions)

الموظفين
  → الموظفون (Employees)
  → الحضور والغياب (EmployeeAttendance)
  → المكافآت والخصومات (EmployeeBonus)

الإعدادات
  → سير العمل (ApprovalWorkflows)
  → سجل العمليات (AuditLogs)
```

---

## 🚀 **Quick Start - تشغيل النظام:**

```bash
# 1. تثبيت Sanctum (إذا لم يكن مثبت)
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate

# 2. Clear all caches
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 3. التأكد من وجود admin user
php artisan tinker
>>> App\Models\User::create([
...     'name' => 'Admin',
...     'email' => 'admin@travelagency.com',
...     'password' => bcrypt('admin123'),
...     'role' => 'admin',
...     'is_active' => true,
... ]);

# 4. Start servers
# Terminal 1: Laravel
php artisan serve

# Terminal 2: Vue (if using Vite)
npm run dev

# 5. Test
# - Vue: http://localhost:5173/login
# - Filament: http://localhost:8000/admin
```

---

## 📖 **ملاحظات مهمة:**

1. **Role System**:
   - `role` column في `users` table: `admin` أو `employee`
   - `authStore.isAdmin` في Vue = `user.role === 'admin'`
   - Filament: `EnsureIsAdmin` middleware (موجود بالفعل)

2. **Soft Deletes**:
   - Resources في Admin تستخدم TrashedFilter (لأن فيها soft deletes)
   - Resources القديمة بدون soft delete

3. **Relationships**:
   - كل Resource عائدات ( Relations ) مناسبة في `getRelations()`
   - Many-to-Many (مثال: FlightBooking ← Passenger) تم التعامل معها

4. **فئات Enums**:
   - استخدم `::class` في Select fields: `->options(SupplierType::class)`
   - Filament يعرض labels عربية تلقائياً

5. **Money Fields**:
   - `MoneyInput::make('price')->prefix('ج.م')`
   - `->money('jod')` في Tables

6. **Search & Filters**:
   - TextColumn: `->searchable()`
   - SelectFilter: للتصفية حسب الحالة/النوع

---

## 🛠️ **Troubleshooting:**

| المشكلة | الحل |
|---------|------|
| زر "لوحة التحكم" مش ظاهر | تأكد role = admin في user record |
| Filament يطلب login تاني | تأكد middleware `AuthenticateWithApiToken` مسجل في `Kernel.php` |
| Resource مش ظاهر في Filament | 1. مسille 2. تأكد الـ class path صحيح في config 3. موارد المجلد必须有 Pages\ManageXXX.php |
| Middleware مش شغال | `php artisan config:clear` |
| Colors في Filament شكلها غلط |主题colors في config/filament.php ممكن تعدلها |

---

## 📚 **الملفات المرفقة:**

- `INTEGRATION_COMMANDS.md` - أوامر Artisan
- هذا الملف - الدليل الشامل

---

## ✅ **الخطوات التالية (اختياري):**

1. **Customize Navigation Icons**: غير الـ `navigationIcon` في كل Resource
2. **Add More Columns/Fields**: حسّن الـ `form()` و `table()` حسب احتياجاتك
3. **Add Custom Pages**: لو محتاج pages إضافية (مثل Reports)
4. **Setup Filament Widgets**: أضف dashboard widgets
5. **Role Permissions**: استخدم Filament's `HasRoles` trait مع Spatie Permission لأدوات أدق
6. **Email Notifications**: أضف email عندpending approvals

---

## 🎉 **النتيجة النهائية:**

✅ **19+ Filament Resource** كاملة ومرنة  
✅ **Authentication unified** بين Vue و Filament  
✅ **SSO**: دخول مرة واحدة  
✅ **双向 Navigation**: زر من Vue لـ Filament + زر من Filament لـ Vue  
✅ **Admin-only access**: المديرين فقط邀 visualize Filament  
✅ **قابل للتوسعة**: إضافة موديولات جديدة بسهولة  

**النظام جاهز للاستخدام! 🚀**

---

**Last updated**: 2026-05-01 | **Kilo CLI Integration Complete**
