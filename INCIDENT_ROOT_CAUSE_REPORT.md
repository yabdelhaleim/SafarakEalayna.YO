# تقرير تحليل السبب الجذري للحادثة (Root Cause Analysis)

**التاريخ**: 2026-07-07
**اسم الحادثة**: فشل إنشاء حجز طيران — "رصيد مسبق غير كافٍ"
**رقم الحجز المتأثر**: حجز في الواجهة الأمامية (تكلفة ~6,291.70 EGP)
**الحالة**: ✅ تم التشخيص الكامل

---

## 1. ملخص تنفيذي (Executive Summary)

المستخدم حاول إنشاء حجز طيران جديد عبر الواجهة الأمامية (Vue/React frontend) بتكلفة **6,291.70 EGP**. النظام رفض إنشاء الحجز وأظهر رسالة خطأ طويلة تحتوي على معلومات داخلية. السبب الجذري **ليس خطأ برمجي** — بل **عجز مالي** في الحساب المسبق الخاص بأنظمة الطيران، والذي يعمل كـ "credit limit" (سقف ميزانية) على مستوى النظام المحاسبي.

**النتيجة**: النظام منع الحجز بنجاح (سلوك صحيح)، لكن تصميم الـ UX سمح بتسريب معلومات حساسة للمستخدم.

---

## 2. الأعراض الظاهرة للمستخدم (Symptoms)

### 2.1 في الواجهة الأمامية (Frontend)

```
┌────────────────────────────────────────────────────────┐
│ ✕  فشل إنشاء الحجز: فشل إنشاء الحجز                  │
│                                                        │
│ يرجى مسح ذاكرة التخزين المؤقت على حسابك               │
│ 'صحة' مس - أنظمة حجز الطيران.                         │
│                                                        │
│ المتاح: EGP 52349.30-                                  │
│ تنزيل 6291.70 /إنشاء قسم الإقامة/...                  │
│ تنزيل 3/تقصير /قسم حجز الإقامة/...                    │
│ تثبيت الرحلة / ختم: تم إنشاء رقم الرحلة/...           │
└────────────────────────────────────────────────────────┘
```

### 2.2 في الـ Backend (عند التشخيص)

```
✗ فشل بعد 25.18ms
────────────────────────────────────────────────────────
نوع الاستثناء:    Exception
الرسالة (raw):    فشل إنشاء الحجز: رصيد مسبق غير كافٍ على حساب
                  "رصيد مسبق — أنظمة حجز الطيران". المتاح: -52349.30 EGP،
                  المطلوب: 6291.70. يرجى شحن رصيد الناقل/النظام من
                  زر "شحن رصيد" قبل إجراء الحجز.
الملف:            app/Services/Flight/FlightBookingService.php:409
```

### 2.3 الفجوة بين العرضين

| اللي شافه المستخدم | اللي شافه المطور |
|---|---|
| رسالة مشفرة بـ "صحة' مس" (ختامية/مقتطعة) | اسم الحساب الكامل "رصيد مسبق — أنظمة حجز الطيران" |
| نص طويل من TBO (صيني/تقني) | رسالة Laravel واضحة |
| رصيد `EGP 52349.30-` | الرصيد الفعلي `-52,349.30 EGP` |

---

## 3. التحليل التقني للسبب الجذري

### 3.1 الـ Flow الفعلي (عند محاولة الحجز)

```php
// app/Services/Flight/FlightBookingService.php - createBooking()
DB::transaction(function () use ($data) {
    // Step 1-3: حساب الأسعار، توليد booking_number، إنشاء السجل
    // ...
    
    // Step 4: Debit pool الرصيد (carrier أو system)
    if ($purchaseBalanceSource === 'system' && !empty($data['flight_system_id'])) {
        $this->debitFlightSystem(  // ← هنا المشكلة
            $booking,
            $data['flight_system_id'],
            $purchasePriceEGP,    // 6,291.70
            ...
        );
    }
    
    // Step 6: قيد مبيعات للعميل
    $this->recordSaleToCustomer(...);
});


// app/Services/Flight/FlightBookingService.php:801 - debitFlightSystem()
protected function debitFlightSystem(...) {
    // 1) Debit الرصيد التشغيلي (flight_systems.available_balance)
    $system->debit($debitAmount, ...);   // ← ينجح (الرصيد 76,184 موجب)
    
    // 2) Check + consumeCogs على الحساب المسبق (Prepaid GL Account)
    $this->prepaidLedgerService->consumeCogs(
        prepaidKey: 'flight_system',        // ← الحساب "رصيد مسبق — أنظمة حجز الطيران"
        amount: $purchasePriceEGP,          // 6,291.70
        ...
    );
    // ← الحساب المسبق رصيده -52,349.30 < 6,291.70 → 💥 THROW
}
```

```php
// app/Services/Finance/PrepaidLedgerService.php:128 - consumeCogs()
if ($prepaidAccount && (float) $prepaidAccount->balance < $amount) {
    throw new InsufficientBalanceException(
        sprintf(
            'رصيد مسبق غير كافٍ على حساب "%s". المتاح: %.2f %s، المطلوب: %.2f. '
            .'يرجى شحن رصيد الناقل/النظام من زر "شحن رصيد" قبل إجراء الحجز.',
            $prepaidAccount->name,    // "رصيد مسبق — أنظمة حجز الطيران"
            $available,                // -52,349.30
            $prepaidAccount->currency, // EGP
            $amount                    // 6,291.70
        )
    );
}
```

### 3.2 الـ Stack Trace الفعلي

```
#00  FlightBookingService->createBooking()    ← الخطأ المُلتقَط هنا
#01  Illuminate\Database\Connection->transaction()  ← إغلاق الـ DB transaction
#02  DatabaseManager->__call()
#03  Facade::__callStatic()
#04  diag_create_flight_booking.php:114        ← السكربت التشخيصي
#05  Artisan Tinker → Psy eval
```

### 3.3 الفشل في الـ Validation Sequence

النظام يستخدم **طبقات متعددة من الـ validation**:

```
الطبقة 1: flight_systems.available_balance = 76,184 EGP ✓ (PASS)
    ↓
الطبقة 2: PrepaidLedgerService::consumeCogs() ← 💥 FAIL
    ↓
رمي InsufficientBalanceException
    ↓
DB::transaction → ROLLBACK تلقائي ✓ (لا يوجد حجز فاسد)
    ↓
إعادة تغليف في FlightBookingService.createBooking():409
    ↓
"فشل إنشاء الحجز: رصيد مسبق غير كافٍ..."
```

---

## 4. الجدول الزمني للحادثة (Timeline)

### 4.1 قبل الحادثة (الأيام 1-7)

```
📅 2026-07-04 (يوم 1) — بداية النظام
─────────────────────────────────────────
   تسوية opening balance: +7,723 EGP
   (رصيد ابتدائي للحساب المسبق - فتح المشروع)
   رصيد المسبق: 7,723 → 7,723

📅 2026-07-05 (يوم 2)
─────────────────────────────────────────
   حجز FLT-20260705-5996E2   -7,723 EGP
   رصيد المسبق: 7,723 → 0
   الرصيد التشغيلي (system): -7,723

   حجز FLT-20260705-E9AF83   -6,590 EGP
   رصيد المسبق: 0 → -6,590 ⚠️ دخل السالب!

   حجز FLT-20260705-002BE6  -12,534 EGP
   رصيد المسبق: -6,590 → -19,124

   حجز FLT-20260705-B51FEA   -7,521 EGP
   رصيد المسبق: -19,124 → -26,645

   حجز FLT-20260705-EE9B30  -11,251 EGP
   رصيد المسبق: -26,645 → -37,896

📅 2026-07-06 (يوم 3)
─────────────────────────────────────────
   حجز FLT-20260706-2CB859   -6,309 EGP
   رصيد المسبق: -37,896 → -44,204

   حجز FLT-20260706-293898   -8,145 EGP
   رصيد المسبق: -44,204 → -52,349 ⚠️ (الحجز الحالي)

📅 2026-07-07 (يوم 4) — محاولة الحجز الجديدة
─────────────────────────────────────────
   محاولة حجز جديد بتكلفة 6,291.70 EGP
   فحص المسبق: -52,349.30 < 6,291.70 → 💥 REJECTED
```

### 4.2 ملخص الـ 8 حجوزات اللي سبّبت العجز

| # | مرجع الحجز | المبلغ | رصيد المسبق بعد |
|---|---|---|---|
| 1 | FLT-...5996E2 | 7,723.00 | 0 |
| 2 | FLT-...E9AF83 | 6,590.00 | -6,590 |
| 3 | FLT-...002BE6 | 12,533.60 | -19,124 |
| 4 | FLT-...B51FEA | 7,521.20 | -26,645 |
| 5 | FLT-...EE9B30 | 11,250.80 | -37,896 |
| 6 | FLT-...2CB859 | 6,308.70 | -44,204 |
| 7 | FLT-...293898 | 8,145.00 | -52,349 ⚠️ |

**المجموع**: -60,072.30 EGP تم خصمها من رصيد 7,723 = عجز -52,349

### 4.3 ملاحظة مهمة عن الـ Desync

| الحساب | القيمة | لماذا؟ |
|---|---|---|
| `flight_systems[1].available_balance` (NDC_WONDR) | +76,184 EGP | موجب! |
| `account: رصيد مسبق — أنظمة` | -52,349 EGP | سالب! |
| **الفرق** | **128,533 EGP** | شحن يدوي سابق أو manual adjustment |

**الاستنتاج**: الحسابان خرجا من الـ sync في وقت ما خلال الـ 7 أيام (شحن رصيد `flight_systems.available_balance` بدون شحن مقابل للحساب المسبق، أو العكس).

---

## 5. العوامل المسبّبة (Contributing Factors)

### 5.1 عوامل تقنية

| # | العامل | التأثير |
|---|---|---|
| 1 | **لا يوجد Initial Seeding** للحسابات المسبقة | تُنشأ بـ `balance=0` تلقائياً عند أول استخدام |
| 2 | **لا يوجد Observer** على `FlightCarrier/FlightSystem::created()` | الناقلين/الأنظمة الجديدة بدون setup |
| 3 | **لا يوجد Sync** بين `recharge()` والشحن المسبق | الـ desync وارد بدون manual intervention |
| 4 | **لا يوجد Cron Job** للمراقبة | العجز يُكتشف عند محاولة الحجز فقط |
| 5 | **رسالة الخطأ المفصّلة** تصل للـ Frontend | تسريب معلومات حساسة (الرصيد، اسم الحساب) |

### 5.2 عوامل تشغيلية

| # | العامل | التأثير |
|---|---|---|
| 6 | **لا توجد عملية شحن دورية** | المسؤول مش متى يشحن الرصيد المسبق |
| 7 | **لا تنبيه قبل السالب** | المسؤول يفاجئ بالمشكلة |
| 8 | **لا يوجد سقف افتراضي** | كل مشروع يبدأ بـ 0 EGP |
| 9 | **لا توجد وثائق** للـ flow المالي | المسؤول الجديد مش فاهم المنطق |

### 5.3 عوامل UX (تجربة المستخدم)

| # | العامل | التأثير |
|---|---|---|
| 10 | **رسالة خطأ طويلة** (تحتوي تفاصيل الحساب) | المستخدم يشوف معلومات مالية |
| 11 | **رسالة TBO مسربة** (نص صيني/إنجليزي تقني) | المستخدم يفهم خطأ تقني |
| 12 | **لا توجد رسالة واضحة** "اشحن الرصيد X من هنا" | الموظف مش عارف يروح فين |

---

## 6. الأنظمة اللي اشتغلت صح (What Worked)

| الآلية | الحالة | التوضيح |
|---|---|---|
| **DB::transaction** | ✅ | الـ rollback التلقائي لم يخلق أي حجز فاسد |
| **Prepaid ledger check** | ✅ | منع الـ overspend رغم أن الرصيد التشغيلي كان موجب |
| **InsufficientBalanceException** | ✅ | رمى استثناء واضح بمعلومات كافية للمطور |
| **Service layer separation** | ✅ | `FlightBookingService` و `PrepaidLedgerService` منفصلين بـ responsibility واضح |

---

## 7. الأنظمة اللي كان ممكن تمسك المشكلة بدري (Missing Controls)

| الضمان المفقود | كان ممكن يعمل إيه |
|---|---|
| **Pre-flight balance check** | فحص الرصيد المسبق عند فتح صفحة الحجز (قبل الإرسال) |
| **Daily cron monitor** | اكتشاف العجز خلال ساعات بدل أيام |
| **Email notification** | تنبيه `admin@` لما الرصيد يقل عن عتبة |
| **Filament widget** | عرض رصيد المسبق في الـ Dashboard |
| **UI message customization** | رسالة خطأ واضحة بالعربي + CTA (call-to-action) |

---

## 8. الأثر الفعلي للحادثة

| النوع | التفاصيل |
|---|---|
| **أثر تشغيلي** | توقف إنشاء حجوزات جديدة للطيران (يوم كامل على الأقل) |
| **أثر مالي** | لا يوجد ضرر مالي (rollback تلقائي) |
| **أثر UX** | رسالة خطأ مربكة للمستخدم العادي |
| **أثر أمني** | تسريب أسماء حسابات وأرصدة مالية للـ Frontend |
| **سمعة** | تضرر ثقة العميل بسبب فشل الحجز |

---

## 9. هل المشكلة قابلة للتكرار؟ (Reoccurrence Risk)

| السيناريو | الاحتمالية | الأثر |
|---|---|---|
| **شحن `flight_systems.available_balance` بدون شحن المسبق** | 🔴 عالية | نفس المشكلة بالظبط |
| **إنشاء ناقل/نظام جديد** | 🟠 متوسطة | الحساب المسبق يبدأ من 0، أول حجز يفشل |
| **الحجوزات تصرف أسرع من الشحن** | 🔴 عالية | بدون monitor، العجز يتراكم بهدوء |
| **تعديل يدوي على الـ balance من Filament** | 🟡 منخفضة | الـ prepaid check لسه شغّال |

**بدون إصلاح معماري**: المشكلة شبه محتومة تتكرر خلال شهر.

---

## 10. الإصلاح العاجل (Immediate Fix)

### 10.1 شحن فوري للحسابات المسبقة (الحل اللي نُفّذ)

```bash
# السكربت: fix_carrier_balance_apply.php
$prepaidKey = 'flight_system';
$rechargeAmount = 58,641.0;  # يغطي -52,349 + هامش 6,291 للحجز القادم
```

**النتيجة**: الحجوزات ترجع تشتغل خلال دقائق.

### 10.2 الإصلاح المعماري الكامل (التفصيل في PREPAID_BALANCE_ARCHITECTURE.md)

| # | المكون | الغرض |
|---|---|---|
| ① | Observer | إنشاء المسبق عند إضافة ناقل/نظام |
| ② | Sync تلقائي | كل recharge للناقل/النظام = recharge للمسبق |
| ③ | Migration seed | إنشاء كل الحسابات تلقائياً |
| ④ | Migration إصلاح | إصلاح الـ desync الحالي |
| ⑤ | Cron job | فحص يومي + تنبيه |
| ⑥ | Notification | Email + جرس |

---

## 11. الـ Lessons Learned

| الدرس | التطبيق |
|---|---|
| **"Balance checks should be at multiple layers"** | ✅ الكود فعلاً بيعمل كده (الحساب المسبق + التشغيلي) |
| **"Sensitive info should NEVER leak to frontend"** | ⚠️ يحتاج تحسين الـ error wrapping |
| **"Background sync between related ledgers"** | ⚠️ مفقود — سبب الـ desync |
| **"Proactive monitoring > Reactive fixes"** | ⚠️ مفقود — السبب في تأخر الاكتشاف |
| **"Always seed initial data in migrations"** | ⚠️ مفقود — الحسابات تبدأ من 0 |
| **"Observers for cross-cutting concerns"** | ⚠️ مفقود — الـ auto-setup للميزانية |
| **"Documentation for financial flows"** | ⚠️ مفقود — المسؤولين الجدد مش فاهمين |

---

## 12. الـ Recommendation النهائي

| الأولوية | الإجراء | الـ Owner | الموعد |
|---|---|---|---|
| 🔴 P0 | الشحن الفوري للحسابات المسبقة (مُنجز) | المطور | خلال ساعات |
| 🔴 P0 | تحسين الـ error wrapping في `createBooking()` | المطور | خلال يوم |
| 🟠 P1 | Observer + Sync (معماري) | فريق Backend | خلال أسبوع |
| 🟠 P1 | Cron Job + Notification | فريق DevOps | خلال أسبوع |
| 🟡 P2 | UI Widget للرصيد المسبق | فريق Frontend | خلال شهر |
| 🟡 P2 | Pre-flight balance check | فريق Backend | خلال شهر |
| 🟢 P3 | Documentation للحسابات المسبقة | Tech Writer | خلال شهرين |

---

## 13. الملاحق (Appendices)

### A. تفاصيل الحسابات المسبقة

```
accounting.clearing.prepaid:
├── flight_carrier → "رصيد مسبق — ناقلو الطيران"  (ID: 24)
├── flight_system  → "رصيد مسبق — أنظمة حجز الطيران"  (ID: 23)
└── fawry          → "رصيد مسبق — ماكينات فوري"  (ID: 42)
```

| الحساب | الرصيد الحالي | الحالة |
|---|---|---|
| flight_carrier | -32,387.15 EGP | 🔴 سالب |
| flight_system  | -52,349.30 EGP | 🔴 سالب |
| fawry          | -1,855.64 EGP  | 🔴 سالب |

### B. الملفات ذات الصلة

```
app/Services/Flight/FlightBookingService.php          ← createBooking() line 210
app/Services/Flight/FlightBookingService.php          ← debitFlightCarrier() line 801
app/Services/Flight/FlightBookingService.php          ← debitFlightSystem() line 859
app/Services/Finance/PrepaidLedgerService.php         ← consumeCogs() line 109
app/Services/Finance/LedgerClearingAccounts.php       ← ensurePrepaidAccountExists() line 196
config/accounting.php                                  ← clearing.prepaid.* lines 120-123
diag_create_flight_booking.php                         ← السكربت التشخيصي
fix_carrier_balance_diag.php                           ← السكربت التشخيصي v2
fix_carrier_balance_apply.php                          ← السكربت العلاجي
PREPAID_BALANCE_ARCHITECTURE.md                        ← تقرير الحل المعماري
```

### C. السكربتات المُستخدمة في التشخيص

| السكربت | الـ Output الرئيسي |
|---|---|
| `diag_create_flight_booking.php` | تأكيد إن `createBooking()` يفشل برسالة `InsufficientBalanceException` |
| `fix_carrier_balance_diag.php` | جدول كل الأرصدة (تشغيلي + مسبقة) + القيود الأخيرة |

---

**توقيع**: الفريق التقني
**التاريخ**: 2026-07-07
**الحالة**: مكتمل ✓
