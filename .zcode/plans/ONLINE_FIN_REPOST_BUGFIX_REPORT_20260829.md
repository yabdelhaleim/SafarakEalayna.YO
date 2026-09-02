# تقرير إصلاح باج repostCashPaymentTransaction — موديول الخدمات الإلكترونية (Online)
**التاريخ:** 2026-08-29
**الموديول:** Online Services
**شدة الباج:** High — كان يسبب انحراف في رصيد العميل (AR drift) عند تعديل `amount_paid` أكثر من مرة
**الملف:** `app/Services/Online/OnlineTransactionService.php` — `repostCashPaymentTransaction()` السطور 690-754

---

## 1) ملخص الباج

عند تعديل حقل `amount_paid` على معاملة خدمات إلكترونية مسجلة أكثر من مرتين، التحديث الثالث وما بعده كان يفشل في عكس قيد التحويل النشط الحالي (cash-payment transfer)، فكان يتم عكس قيد قديم مُعكَس مسبقاً (no-op) ويُضاف قيد جديد، فيتراكم قيد سابق "حي" مع قيد جديد، وينحرف رصيد ذمة العميل (AR).

### السيناريو المعيب (مُعاد إنتاجه قبل الإصلاح):
| خطوة | فعل | ما يجب أن يحدث | ما كان يحدث فعلاً | رصيد AR الفعلي |
|------|------|------------------|---------------------|----------------|
| إنشاء | tx بـ selling=500, paid=0 | قيد ربح فقط | قيد ربح فقط | **500** (مدين العميل) |
| Update #1 | paid=100 | عكس لا شيء، قيد 100 من AR→vault | ✓ تم بنجاح | **400** |
| Update #2 | paid=200 | عكس قيد 100، قيد 200 من AR→vault | ✓ تم بنجاح | **300** |
| Update #3 | paid=500 | **عكس قيد 200**، قيد 500 من AR→vault | ❌ عكس قيد 100 المُعكَس مسبقاً (no-op)، قيد 500 جديد → قيد 200 لا يزال حي + 500 جديد = سالب | **-200** ❌ |

النتيجة: رصيد العميل ينجذب إلى السالب (دائن وهمي / ghost credit) عند كل تعديل `amount_paid` بعد التعديل الثاني.

---

## 2) السبب الجذري (Root Cause)

في `repostCashPaymentTransaction()`، الاستعلام الذي يجلب قيد التحويل النقدي المراد عكسه كان يعاني من خللين:

```php
// ❌ الكود المعيب (قبل)
$cashPaymentTx = Transaction::where('related_type', OnlineTransaction::class)
    ->where('related_id', $tx->id)
    ->where(function ($q) use ($candidatePairs) {
        foreach ($candidatePairs as $pair) {
            $q->orWhere(function ($inner) use ($pair) {
                $inner->where('from_account_id', $pair['from'])
                    ->where('to_account_id', $pair['to']);
            });
        }
    })
    ->orderBy('id', 'asc')   // ❌ الخلل 1: أقدم قيد، ليس الأحدث
    ->first();               // ❌ الخلل 2: لا يستبعد القيود المعكوسة مسبقاً
```

**الخلل 1:** `orderBy('id', 'asc')->first()` كان يجلب **أول** قيد تحويل تم تسجيله على المعاملة، وليس **آخر** قيد نشط. فإذا كان القيد الأول قد تم عكسه في تحديث سابق، الاستعلام يعيده ويطلب عكسه مرة أخرى (no-op، عكس لعكس).

**الخلل 2:** الاستعلام لم يكن يستبعد القيود التي تم عكسها مسبقاً. وحيث أن `TransactionService::reverseTransaction()` يضع بادئة `عكس القيد #…` على ملاحظات الـ `AccountEntry` (في `app/Services/Finance/TransactionService.php:359`)، كان من الممكن تمييز القيود المعكوسة بهذه البادئة.

---

## 3) الإصلاح (Fix)

```php
// ✅ الكود المُصلَح
$cashPaymentTx = Transaction::where('related_type', OnlineTransaction::class)
    ->where('related_id', $tx->id)
    ->where(function ($q) use ($candidatePairs) {
        foreach ($candidatePairs as $pair) {
            $q->orWhere(function ($inner) use ($pair) {
                $inner->where('from_account_id', $pair['from'])
                    ->where('to_account_id', $pair['to']);
            });
        }
    })
    ->whereDoesntHave('entries', fn ($q) => $q->where('notes', 'like', 'عكس القيد#%'))
    ->orderBy('id', 'desc')  // ✅ أحدث قيد نشط (لا يزال حيّاً)
    ->first();
```

**سطر التغيير الفعلي (2 سطر):**
- أضفنا `->whereDoesntHave('entries', fn ($q) => $q->where('notes', 'like', 'عكس القيد#%'))` لاستبعاد القيود المعكوسة مسبقاً.
- غيّرنا `->orderBy('id', 'asc')` إلى `->orderBy('id', 'desc')` لنختار آخر قيد نشط.

**التأثير:** الآن التحديث الثاني وما بعده يستهدف القيد النشط الحالي (الأحدث وغير المعكوس)، فيتم عكسه بشكل صحيح ثم تسجيل القيد الجديد. الرصيد يستقر عند القيمة الصحيحة.

---

## 4) التحقق (Verification)

### Test Suite: OnlineDebtPaymentDeletionAudit20260829Test (الجلسة الحالية)
```
Tests:    21 passed (57 assertions)
Duration: 6.21s
```

أبرز الاختبارات التي تغطي سيناريو الإصلاح:
- ✅ `partial payment via update reduces debt exactly` — تعديل واحد، AR صحيح
- ✅ `multiple partial payments sum correctly` — **3 تعديلات متتالية، AR=0 بعد السداد الكامل (كان هذا الاختبار يُوثّق الباج، الآن يُثبت الإصلاح)**
- ✅ `overpayment via update creates credit` — تعديل إلى قيمة أكبر من الدين، رصيد دائن صحيح
- ✅ `partial payment updates debtor list correctly` — قائمة المدينين محدّثة بشكل صحيح
- ✅ `property customer ar equals selling minus paid` — خاصية: AR = selling − paid (ثابتة لكل 30 قيمة عشوائية)

### لوج الاختبار (تأكيد سلوك الإصلاح):
```
Online transaction created  {selling:500, paid:0}      → AR=500
Journal transfer recorded  {tx:3, 100.0}               ← التحديث #1: paid=100 → AR=400
Transaction reversed       {tx:3, entries_reversed:2}  ← التحديث #2: paid=200 يعكس tx:3 ويضيف tx:4 بـ200
Journal transfer recorded  {tx:4, 200.0}               → AR=300
Transaction reversed       {tx:4, entries_reversed:2}  ← التحديث #3: paid=500 يعكس tx:4 ويضيف tx:5 بـ500 ✅
Journal transfer recorded  {tx:5, 500.0}               → AR=0 ✅
```

لاحظ: التحديث #3 يعكس `tx_id=4` (آخر قيد نشط، الذي سُجّل في التحديث #2 بـ200) — وليس `tx_id=3` كما كان يحدث في الباج.

---

## 5) اختبار عدم الانحدار (Regression Check)

| Suite | قبل الإصلاح | بعد الإصلاح | الحكم |
|-------|-------------|-------------|-------|
| `Online/OnlineDebtPaymentDeletionAudit20260829Test` | 21 passed | **21 passed** | ✅ متطابق + السلوك الجديد مُتحقَّق منه |
| `Bus/` | 21 failed, 330 passed | 21 failed, 330 passed | ✅ **متطابق تماماً** — لا انحدار |
| `HajjUmra/` + `Online/` (المتبقي) | 213 failed, 764 passed | 212 failed, 765 passed | ✅ **أخفّ** — فشل واحد أقل (بسبب تحويل اختبار توثيق الباج إلى اختبار سلوك صحيح) |

كل حالات الفشل المتبقية (150 في Online، 21 في Bus، 62 في HajjUmra) هي أخطاء **`نوع الخدمة مطلوب`** من `OnlineTransactionService::create()` (سطر 195) — وهي موجودة **قبل الإصلاح** ولا علاقة لها بالكود الذي تم تغييره في `repostCashPaymentTransaction()`. كل فشل تم التحقق منه بالـ git stash أن الباج غير موجود بدون تغييري.

---

## 6) الحالة الحالية للتعديل

✅ تم تعديل ملف واحد فقط في كود الإنتاج:
- `app/Services/Online/OnlineTransactionService.php` — `+10 / -4`

✅ تم تعديل اختبار واحد فقط (ملف audit جديد، غير مرفوع على git):
- `tests/Feature/Online/OnlineDebtPaymentDeletionAudit20260829Test.php` — اختبار 8 `test_multiple_partial_payments_sum_correctly` يُثبت الآن السلوك الصحيح (AR=0) بدلاً من توثيق الباج (AR<0)

✅ التعديل الجاهز للـ commit على stage:
```
M  app/Services/Online/OnlineTransactionService.php
```

⚠️ **ملاحظة للمستخدم:** ملف الاختبار `OnlineDebtPaymentDeletionAudit20260829Test.php` (21 اختبار) **غير مُضاف** إلى git عمداً حسب طلبك السابق ("ارفع التعديل علي الاستدج التعديل فقط من غير ملف التيست"). إذا أردت رفعه ضمن PR منفصل لاختبار regression، أبلغني.

---

## 7) مخاطر الإصلاح

**المخاطر:** منخفضة.
- التعديل محصور في استعلام قاعدة البيانات مع شرطَي تصفية إضافيين (DESC + whereDoesntHave).
- لا تغيير في منطق التسجيل أو التسويات.
- لا تغيير في العقود (signatures، return types).
- تم التحقق من عدم الانحدار في Bus + HajjUmra.

**حالات لا يغطيها الإصلاح:**
- إذا تم تعديل نفس حقل `amount_paid` بشكل متزامن (race condition) على نفس المعاملة من two users في نفس الوقت، لا يوجد قفل (lock) — هذا خارج نطاق الباج المُكشَف.
- قيد الـ idempotency_key على `created_by` لا يحمي ضد هذا النوع من التعديلات المتتابعة.

---

## 8) التوصيات (Future Hardening)

1. **إضافة Lock على التحديث:** يمكن تغليف `update()` بـ `DB::transaction` + `lockForUpdate()` على الـ transaction row لمنع races.
2. **تعميم الإصلاح:** نفس النمط (reverse + repost) موجود في `BusBookingService`, `FlightBookingService`, `HajjUmraService`, `FawryTransactionService` — يجب التحقق من استخدام نفس المنطق (`orderBy('id', 'asc')->first()`) في هذه الخدمات أيضاً.
3. **اختبار متعمق:** ملف `OnlineDebtPaymentDeletionAudit20260829Test.php` يحتوي 21 اختبار شامل — يُنصح برفعه كـ regression suite رسمي للموديول.

---

**انتهى التقرير.**