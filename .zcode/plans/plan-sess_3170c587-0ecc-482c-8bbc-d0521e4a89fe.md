## تشخيص المشكلة (Diagnosis)

عندك مشكلتين منفصلتين ظهرتا بعد رفع ملفات حديثة:

### المشكلة ١ — سند صرف بيضيف بدل ما يخصم
**الموقع:** `app/Http/Controllers/Api/V1/CustomerController.php` السطور ٢٤٥–٢٤٦ في دالة `payDebt()`

```php
$fromId = $type === 'payment' ? $toAccount->id : $fromAccount->id;
$toId   = $type === 'payment' ? $fromAccount->id : $toAccount->id;
```

**اتفاقية الرصيد في النظام:** رصيد العميل الموجب = مديونية عنده (دائنون). موثّق في `app/Models/Account.php` و`FinancialReportService.php:664-666`.

**النتيجة:** في حالة `type='payment'` (سند صرف)، الـ journal بيضع الخزينة كـ `from` والعميل كـ `to`، فالرصيد بيزيد ٥٠ ألف بدل ما ينقص (١١٩،٠٤٧ → ١٦٩،٠٤٧).

**التأكيد:** نفس النمط في `FlightGroupController::payDebt` **صحيح** لأن حساب المجموعات مورد (sign convention معكوس). البق مقتصر على `CustomerController::payDebt`.

### المشكلة ٢ — صفحة عملاء الطيران بتعرض ٠ لكل العملاء
**السبب:** كوميت `89f6dff` أضاف في `app/Services/CustomerService.php` السطور ٣٢–٤٧:

```php
->withSum([
    'flightBookings as total_flight_amount' => ...,
    'busBookings    as total_bus_amount'    => ...,
], 'total_price')
->withSum([
    'flightBookings as total_flight_paid' => ...,
    'busBookings    as total_bus_paid'    => ...,
], 'paid_amount')
```

الأعمدة `total_price` و`paid_amount` **موجودة في `bus_bookings` لكن مش موجودة في `flight_bookings`** — صفقة copy-paste. جدول `flight_bookings` يستخدم `selling_price` للمجموع و`flight_payments.amount` للمدفوع. الـ Vue يعرض `customer.flight_remaining_debt` فيحصل على NULL/0 دائماً، فيظهر "٠.٠٠ — مستوفى — لا يوجد نشاط طيران" للجميع.

ملاحظة جانبية: الفلتر `whereNotIn('status', ['cancelled'])` بحروف صغيرة ما بيطابقش enum الـ `flight_bookings` (اللي بيستخدم uppercase) — فحتى لو الأعمدة موجودة كان هيطابق صفر.

---

## خطة الإصلاح الآمن (Safe Fix Plan)

سأطبّق ٣ تغييرات فقط، مع script تصحيح بيانات منفصل:

### التغيير ١ — إصلاح اتجاه قيد سند صرف
**ملف:** `app/Http/Controllers/Api/V1/CustomerController.php` السطور ٢٤٥–٢٤٦

استبدال:
```php
$fromId = $type === 'payment' ? $toAccount->id   : $fromAccount->id;
$toId   = $type === 'payment' ? $fromAccount->id : $toAccount->id;
```
بـ:
```php
// Both receipt & payment reduce customer balance (AR convention):
// we either receive cash from customer (سند قبض) or pay them out (سند صرف).
// In both legs customer is the journal's "from" — only the label differs.
$fromId = $fromAccount->id;
$toId   = $toAccount->id;
```

**الأمان:** لا تغيير لمسار الـ receipt (سند قبض) — كان صحيحاً ويبقى صحيحاً. مسار الـ payment (سند صرف) كان معكوساً وسنصلحه ليطابق التوقعات والاتفاقية. الـ `allow_from_negative => true` بيبقى كما هو (يحمي لو رصيد العميل كان سالب).

### التغيير ٢ — إعادة عرض `customer.balance` بدل `flight_remaining_debt` في صفحة عملاء الطيران
**ملف:** `resources/js/views/flights/FlightCustomersIndex.vue` السطور ١٩٠، ٢٩٣

تغيير:
```vue
formatBalance(customer.flight_remaining_debt, 'customer')
```
إلى:
```vue
formatBalance(customer.balance, 'customer')
```

وحذف الـ conditional الجديد اللي بيعرض "لا يوجد نشاط طيران" (اللي كان ظاهر بسبب البق). هذا يرجع الصفحة لسلوك ما قبل `89f6dff` اللي كان شغّال.

**الأمان:** هذا فقط ما يغيّر الواجهة — ما يعدّلش البيانات ولا يكسر أي endpoint آخر. العملاء هيرجعوا يشوفوا أرصدتهم الفعلية من الـ ledger.

### التغيير ٣ — script تصحيح بيانات آمن
**ملف جديد:** `scripts/fix_payment_voucher_balance.php` (يضاف لـ `.gitignore` لو مؤقت)

سكربت PHP يشتغل عبر `php artisan tinker --execute=...` أو standalone:
1. **وضع dry-run أولاً** (الافتراضي): يعرض كل المعاملات المتأثرة بدون ما يعدّل
2. يبحث عن كل `Transaction` بنوع `payment` (سند صرف للعميل) ملاحظاتها فيها "دفع للعميل" أو "إرجاع مبلغ العميل"، أُنشئت بعد نشر الكود البقّي
3. لكل عملية، يحسب الفرق: للعمل حساب العميل، رصيده ناقص `(2 × amount)` (لأن البق ضاف `amount` بدل ما يخصمه، فالفرق `2 × amount`)
4. يعرض:
   - رقم العملية
   - اسم العميل والـ account_id
   - الرصيد الحالي
   - المبلغ اللي لازم ينخصم لتصحيح الخطأ
   - الرصيد بعد التصحيح
5. بعد موافقتك، يكتب:
   - `UPDATE accounts SET balance = balance - :amount WHERE id = :customer_account_id`
   - `INSERT INTO account_entries` بـ notes `"تصحيح: عكس قيد سند صرف #<tx_id> — تم استعادة الرصيد الصحيح"`، بدون transaction_id (تصحيح إدخال مباشرة)
6. **أول تشغيل** يستهدف العميل `خلف الاعصر` فقط (عن طريق الـ id أو الاسم) للتحقق

**الأمان:**
- يبدأ بـ `--dry-run`
- يطبع كل تغيير قبل تطبيقه وينتظر تأكيد
- يستخدم DB transaction
- لو انفتح transaction على أكتر من سند صرف لنفس العميل، يعرضهم كلهم معاً ويخصم المبلغ المجمّع مرة واحدة
- يمكن تشغيله مرة لكل عميل على حدة

### ما لن أغيّره (متعمداً)
- `withSum` البقّي في `CustomerService` — لو شيلته قد يكسر أي مستهلك آخر. تركه آمن طالما الـ Vue ما يقرأش منه
- `flight_remaining_debt` و`bus_remaining_debt` في `CustomerResource` — نتركها كحقول متاحة لكن لا أحد يستهلكها بعد التغيير ٢
- `FlightGroupController::payDebt` — صحيح كما هو
- `VisaController::payCustomerDebt` — receipt-only صحيح
- `BusRefundService` — لا يخص العميل أصلاً

---

## الخطوات التنفيذية

1. قراءة الملفات المعنية للتأكد من حالة الـ Edit (الـ CustomerController + الـ Vue + ربما `AccountStatement.php` للتأكد)
2. تطبيق التغيير ١ (سطرين في CustomerController)
3. تطبيق التغيير ٢ (Vue display)
4. إنشاء `scripts/fix_payment_voucher_balance.php` مع dry-run
5. اختبار محلي لو فيه DB شغّال (لو لأ، سنعتمد على الـ dry-run + فحص يدوي في الإنتاج)
6. تجميع الـ diffs في commit واحد واضح:
   ```
   fix(customer-payDebt): correct سند صرف direction (subtract instead of add)
   
   revert(flights-ui): show customer.balance on flight customers page
   (revert flight_remaining_debt usage from 89f6dff whose withSum referenced
   non-existent flight_bookings columns)
   ```

7. سكريبت التصحيح: تشغيل `--dry-run` على staging/local، مشاركة المخرجات معك للموافقة، ثم التطبيق على production يدوياً

## معايير القبول (Acceptance)
- في صفحة عملاء الطيران: أرصدة العملاء تظهر بقيمها الحقيقية من الـ ledger (مش كلها ٠)
- في صفحة كشف حساب أي عميل: سند صرف يقلل الرصيد
- خلف الاعصر: رصيده يعود لـ ٦٩،٠٤٧ (بدل ١٦٩،٠٤٧)
- لا أخطاء SQL جديدة في `storage/logs/laravel.log`
- لم تتأثر أي endpoint أخرى (Receipt/payment للحج/التأشيرات/المجموعات لا تزال تعمل كما هي)
