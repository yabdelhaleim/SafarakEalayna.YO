# خطة إصلاح فلاتر صفحات كشف الحساب

## المشكلة
صفحة **كشف حساب شركة الباص** (والصفحات المشابهة) تعرض آخر المعاملات فقط ولا يوجد بها فلاتر لتحديد التاريخ أو نوع الحركة أو البحث في البيان. الكود لا يحترم `from_date` / `to_date` / `type` / `search` على الـ backend رغم أنها متوقعة من قِبَل المستخدم.

## الحل القياسي (نفس النمط في كل الصفحات)

### الـ Backend (نفس النمط في كل controller)

في كل `…statement` method، اقرأ الفلاتر وطبّقها على query الـ `Transaction`:

```php
$from   = $request->query('from_date');
$to     = $request->query('to_date');
$type   = $request->query('type');   // 'credit' | 'debit' | null
$search = $request->query('search');
$perPage = min((int) $request->query('per_page', 30), 100);

$query = \App\Models\Transaction::query()
    ->where('module', $module)
    ->where(function ($q) use ($accountId) {
        $q->where('from_account_id', $accountId)
          ->orWhere('to_account_id', $accountId);
    });

if ($from)   $query->where('created_at', '>=', $from . ' 00:00:00');
if ($to)     $query->where('created_at', '<=', $to   . ' 23:59:59');
if ($type === 'credit') $query->where('to_account_id',   $accountId);
if ($type === 'debit')  $query->where('from_account_id', $accountId);
if ($search) {
    $query->where(function ($q) use ($search) {
        $q->where('notes', 'like', "%{$search}%")
          ->orWhere('id', 'like', "%{$search}%");
    });
}
$paginator = $query->latest()->paginate($perPage);
```

### الـ Frontend (شريط فلاتر موحد)

شريط فلاتر في نفس صفحات الـ frontend باستخدام Tailwind نفس الـ tokens الموجودة في كل صفحة:
- حقل **بحث** (search) — placeholder "بحث في البيان والملاحظات..."
- قائمة **نوع الحركة** — الكل / إيداع / سحب
- حقلا **من تاريخ / إلى تاريخ**
- زر **إعادة ضبط** (Reset) — يفرغ الفلاتر ويعيد التحميل
- صحفحة Vue: `filters` reactive `{ search, from_date, to_date, type, page, per_page }` + `onFiltersChange()` يفرغ `page=1` ويستدعي `loadPage(1)`.

---

## المرحلة 1 — الأولوية العالية (الصفحة المبلَّغ عنها + الصفحات المشابهة)

### (1) `BusCompanyController::statement` + `BusCompanyStatement.vue` — الصفحة المُبلَّغ عنها
- **ملف backend**: `app/Http/Controllers/Api/V1/Bus/BusCompanyController.php` السطور 153–202 — تطبيق النمط القياسي أعلاه.
- **ملف frontend**: `resources/js/views/bus/BusCompanyStatement.vue` — إضافة شريط فلاتر في الـ `Filters/Actions` block (السطور 46–54) + تعديل `loadPage` (السطور 483–504) لإرسال `filters.value` بدلاً من `{ page }`.

### (2) `CustomerDetailsModal.vue` — تبويب "كشف الحساب التفصيلي"
- **ملف**: `resources/js/views/customers/CustomerDetailsModal.vue`، قسم financial tab (السطور 483–540).
- **backend**: `CustomerController::statement` (`routes/api.php:482`) يدعم بالفعل كل الفلاتر عبر `AccountService::getAccountStatement` — لا يحتاج تعديل.
- **frontend**: إضافة شريط فلاتر بنفس النمط، يستدعي نفس endpoint مع `params` إضافية.

### (3) صفحات كشف الحساب في كل مديول (5 صفحات)
كل واحدة عبارة عن modal داخل صفحة customers-balances يعرض حركات حساب العميل:

| الصفحة | Controller + المسار | الملاحظات |
|---|---|---|
| `hajjUmra/HajjUmraCustomerBalances.vue` | `HajjUmraController::customerStatement` (`routes/api.php:517`) | الـ backend يقرأ `client_id` فقط — يحتاج قبول `from_date`/`to_date`/`type`/`search` |
| `visa/VisaCustomerBalances.vue` | `VisaController::customerStatement` (`routes/api.php:568`) | نفس النمط |
| `fawry/FawryCustomerBalances.vue` | `Fawry\FawryTransactionController::customerStatement` (`routes/api.php:380`) | نفس النمط |
| `wallet/WalletCustomerBalances.vue` | `Wallet\WalletTransactionController::customerStatement` (`routes/api.php:310`) | نفس النمط |
| `online/OnlineCustomerBalances.vue` | `Online\OnlineTransactionController::customerStatement` (`routes/api.php:360`) | الـ backend يدعم `from_date`/`to_date` — يحتاج إضافة `type`/`search` ودعم للـ UI |

لكل صفحة:
- **frontend**: إضافة شريط فلاتر فوق الجدول داخل الـ modal (المواقع محددة في الـ audit).
- **backend**: تطبيق النمط القياسي وإضافة `type`/`search` حيث ينقص.

### (4) `FlightCustomersIndex.vue` — كلا الـ modals (عميل + مجموعة)
- **customer modal** (`CustomerController::statement`): الـ UI فيه حقل بحث لكن الفلترة client-side فقط — تعديل `fetchStatement()` لإرسال `search` للـ backend (الـ controller يدعمه).
- **group modal** (`FlightGroupController::statement`، `routes/api.php:196`): لا يوجد شريط فلاتر حالياً + الـ controller لا يدعم فلاتر على الإطلاق — إضافة كل شيء.

---

## المرحلة 2 — صفحات الـ Treasury + Filament + التنظيف

### (5) ست صفحات treasury modal
نفس النمط بالضبط على modal حركات الحساب في كل صفحة treasury:

| الصفحة | Controller |
|---|---|
| `bus/BusTreasury.vue` | `Bus\BusTreasuryController::accountBusTransactions` |
| `visa/VisaTreasury.vue` | `Visa\VisaTreasuryController::accountVisaTransactions` |
| `hajjUmra/HajjUmraTreasury.vue` | `HajjUmra\HajjUmraTreasuryController::accountHajjUmraTransactions` |
| `fawry/FawryTreasury.vue` | `Fawry\FawryTreasuryController::accountTransactions` |
| `online/OnlineTreasury.vue` | `Online\OnlineTransactionController::customerStatement` (يحتاج إصلاح ليستخدم account-scoped endpoint) |
| `wallet/TransferTreasury.vue` | `Wallet\TransferTreasuryController::accountTransactions` |
| `flights/FlightTreasuryOverview.vue` (3 modals) | `Flight\FlightTreasuryController::{system,carrier,accountFlight}Transactions` |

### (6) Filament `AccountStatement.php`
- إضافة فلتر `type` (credit/debit) في الـ `filters()` block (السطور 73–84).

### (7) تنظيف تم اكتشافه خلال الـ audit
- ملف يتيم `resources/js/views/customers/GroupDebtBalancesSection.vue` (643 سطر، لا يُستورد في أي مكان): استيراده في `FlightGroupsIndex.vue` أو حذفه.
- ملف `routes/finance.php` (لا يُحمَّل في `bootstrap/app.php`): حذف أو إصلاح.
- bug خفي: `AccountService::getFlightGroupStatementForAccount` يُرجع `$firstEntry` بدون تعيين (PHP undefined notice) — تعريفه.

---

## معايير النجاح
- في كل صفحة من الصفحات أعلاه: عند إدخال تاريخ في `from_date`/`to_date` أو اختيار نوع حركة أو كتابة كلمة بحث، الجدول يعرض الحركات المطابقة فقط.
- زر **إعادة ضبط** يفرغ كل الفلاتر ويعيد عرض كل الحركات.
- الـ pagination يحتفظ بصفحة 1 عند تغيير الفلاتر.
- لا breaking change على الصفحات التي كانت تعمل (AccountStatement.vue, AccountStatement Filament, FlightAirlineTransactions, TransactionsIndex, FinanceOperationsLedger).

## ملاحظات تنفيذ
- لا توجد حاجة لإنشاء component Vue مركزي للفلاتر في هذه المرحلة — inline Tailwind أنظف لتفادي تعديل نمط كل صفحة.
- ترتيب التنفيذ داخل كل مرحلة: backend أولاً (testable via Postman/curl) ثم frontend.
- اختبار سريع بعد كل صفحة: تحميل http://localhost:8000/url → تغيير الفلتر → نتيجة صحيحة.