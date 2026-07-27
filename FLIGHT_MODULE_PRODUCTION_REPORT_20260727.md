# Flight Module Production Test Report — 2026-07-27

## ملخص تنفيذي

تم إجراء اختبار شامل لموديول الطيران مع كل السيناريوهات (حجز، دفع، إلغاء، استرداد، حذف) في كل العملات (EGP, USD, SAR, KWD). تم اكتشاف وإصلاح **7 أخطاء جوهرية** كانت ستظهر على البرودكشن:

---

## نتائج الاختبارات النهائية

### ✅ كل اختبارات موديول الطيران (105/105 نجحت)
- **105 passed**, 1 skipped, **719 assertions**

### 📊 Real-world Soft-Delete Test (7 سيناريوهات):
1. ✅ Book → pay full → soft-delete
2. ✅ Book → pay partial → cancel with refund → soft-delete
3. ✅ Book → cancel with full penalty (refund=0) → soft-delete
4. ✅ Book → 3 installments → soft-delete
5. ✅ Book in KWD → pay in KWD cashbox → soft-delete (السيناريو المسبب لـ -300 KWD)
6. ✅ Book in KWD → pay in EGP cashbox → soft-delete (cross-currency)
7. ✅ Sequential deletes stress test (5 bookings deleted in sequence)

كل سيناريو يفحص:
- ✅ `Account.balance == SUM(credit) - SUM(debit)` (الثابت المحاسبي)
- ✅ Transactions بنفس العملة لها أرصدة متوازنة
- ✅ لا يوجد رصيد خزينة سالب (لا -300 KWD)
- ✅ الأرصدة تعود لحالتها قبل الحجز بعد الحذف

---

## الأخطاء المُكتشفة والمُصلحة (7 أخطاء)

### 🔴 Bug #1: RefundService — Cashbox with `module_type='flights'`
**الملف:** `app/Services/Flight/RefundService.php:389`

**المشكلة:** عند إنشاء حساب خزينة احتياطي، الكود كان يضع `module_type='flights'` لكن الـ `AccountModuleContract` يشترط أن الحسابات النقدية (cashbox/wallet/bank) تستخدم `module_type` كـ DIVISION فقط (`office` أو `tourism`).

**الإصلاح:** تغيير إلى `module_type='tourism'` لأن الـ flight module يقع تحت قسمة tourism.

```php
// قبل (BUG)
'module_type' => 'flights',

// بعد (FIXED)
'module_type' => 'tourism',
```

---

### 🔴 Bug #2: RefundService — متغير غير معرّف `$transferAmount`
**الملف:** `app/Services/Flight/RefundService.php:469`

**الإصلاح:** استخدام `$glAmounts['amount']` بدل `$transferAmount`.

---

### 🔴 Bug #3: FlightBookingService::addPayment — خطأ في التعامل مع العملات الأجنبية ⭐ **الأخطر**
**الملف:** `app/Services/Flight/FlightBookingService.php:1786-1808`

**المشكلة (التي سببت الـ -300 KWD على البرودكشن):**
عند حجز بعملة أجنبية (مثل KWD) ودفعه من خزينة بنفس العملة الأجنبية (KWD cashbox):
- الكود كان يمرر `transferAmount = $amount` (المبلغ الخام من المستخدم)
- ثم `recordJournalTransfer` يفسره على أنه بالجنيه المصري (EGP) ويقسمه على سعر الصرف
- **النتيجة:** عند دفع 150 KWD بسعر 160، الخزينة كانت تُخصم بـ `150/160 = 0.94` بدل `150` ❌

**الإصلاح:**
```php
} elseif ($isBookingForeign && ! $isPaymentEgp && $paymentCurrency === $bookingCurrency) {
    $transferAmount = $amount * $rate;  // EGP-equivalent for AR debit
    $convertedAmount = $amount;         // foreign amount for cashbox credit
}
```

---

### 🔴 Bug #4: FlightBookingService::reverseSinglePayment — خسارة في عكس العملات الأجنبية ⭐ **الأخطر**
**الملف:** `app/Services/Flight/FlightBookingService.php:2468-2500`

**المشكلة:** عند حذف حجز بعملة أجنبية، الكود كان يستخدم `Transaction.amount` فقط:
- الحجز الأصلي: AR debit 7500 EGP + cashbox credit 150 USD
- العكس: cashbox debit 7500 (يفسر كـ USD!) + AR credit 7500/50 = 150 EGP ❌

**الإصلاح:** قراءة AccountEntry الأصلية وقلب debit/credit لكل leg في عملتها الأصلية.

---

### 🔴 Bug #5: FlightBookingService::cancelBooking — مسح `sale_gl_transaction_id` بدون عكس
**الملف:** `app/Services/Flight/FlightBookingService.php:2090`

**المشكلة (السيناريو 3 — الإلغاء بدون استرداد + حذف):**
عند إلغاء حجز بـغرامة كاملة (refund=0)، الكود كان يمسح `sale_gl_transaction_id` حتى لو لم يُسجَّل أي قيد عكسي (لأن `saleReversalAmount=0`).

النتيجة: عند حذف الحجز بعد ذلك، `deleteBookingWithReversal` يفحص `sale_gl_transaction_id` فيجده NULL، فيتخطى عكس قيد المبيعات. لكن العميل دفع 12000 EGP ودُفِعت من حسابه (الـ AR=0 قبل الحذف)، فعند الحذف يُعكَس الدفع (AR يصبح +12000) لكن قيد المبيعات لا يُعكَس → phantom receivable في الحسابات.

**الإصلاح:** مسح `sale_gl_transaction_id` فقط عند تسجيل قيد عكسي فعلي:
```php
$reversalPosted = false;
if ($saleReversalAmount > 0.001) {
    // Post reversal...
    $reversalPosted = true;
}
if ($reversalPosted) {
    $booking->forceFill(['sale_gl_transaction_id' => null])->save();
}
```

---

### 🔴 Bug #6: FlightBookingService::deleteBookingWithReversal — مضاعفة عكس الناقل/النظام
**الملف:** `app/Services/Flight/FlightBookingService.php:2540+`

**المشكلة (السيناريو 2 — إلغاء جزئي + حذف):**
- الإلغاء يضيف رصيد للناقل/النظام بمقدار `purchaseEgp - airlinePenalty`
- الحذف كان يضيف رصيد كامل `purchaseEgp` (بدون خصم)
- النتيجة: الناقل/النظام يحصل على رصيد إضافي = `airlinePenalty` (إيراد وهمي)

**الإصلاح:** إضافة `creditBackFlightCarrierExact()` و `creditBackFlightSystemExact()` تأخذ مبلغ EGP دقيق، واستدعاؤها في الحذف عند وجود `FlightRefund` لإرجاع الرصيد المتبقي فقط:
```php
if ($existingRefund) {
    $this->creditBackFlightCarrierExact(
        $booking,
        (float) $existingRefund->airline_penalty  // فقط الجزء المتبقي
    );
} else {
    $this->creditBackFlightCarrier($booking, 0.0);  // استرجاع كامل
}
```

---

### 🔴 Bug #7: FlightBookingService::deleteBookingWithReversal — عكس مدفوعات مكرر + إلغاء دخل متبقي
**الملف:** `app/Services/Flight/FlightBookingService.php`

**المشكلة:** عند الحذف بعد إلغاء جزئي:
1. كان يُعكَس كل دفعة بالكامل (لكن الإلغاء سحب جزء منها من الخزينة بالفعل) → خصم مكرر للخزينة
2. كان لا يُعكَس قيد مبيعات متبقي (الإلغاء عكس جزئياً، والباقي بقي في حساب الإيراد)

**الإصلاح:**
1. `reverseSinglePayment` يتخطى المدفوعات إذا كان الحجز أُلغي واستُرد (الإلغاء عدّل الخزينة بالفعل)
2. عند وجود `FlightRefund` بغرامات، يُسجَّل قيد عكسي لـ `penaltyEgp` بين الخزينة وحساب الإيراد:
```php
$this->transactionService->recordJournalTransfer([
    'amount' => $penaltyEgp,
    'from_account_id' => $refundCashboxId,        // الخزينة
    'to_account_id' => $clearingAccountId,        // حساب الإيراد
    ...
]);
```

---

## ملخص التغييرات

| الملف | نوع التغيير |
|---|---|
| `app/Services/Flight/RefundService.php` | إصلاح Bug #1 + Bug #2 |
| `app/Services/Flight/FlightBookingService.php` | إصلاح Bugs #3-#7 |
| `tests/Feature/Flight/FlightMultiCurrencyProductionTest.php` | **جديد** — 11 سيناريو شامل |
| `tests/Feature/Flight/FlightSoftDeleteRealWorldTest.php` | **جديد** — 7 سيناريوهات soft-delete |
| `tests/Feature/Flight/RefundRequestReversalTest.php` | تحديث booking status قبل refund |

---

## ⚠️ ملاحظات عن اختبارات أخرى (Finance)

83 اختبار في `tests/Feature/Finance/` تفشل بسبب **استخدام `module_type='flights'`** أو `''` (فارغ) في الـ fixtures — نفس نوع Bug #1. هذه فشل **pre-existing** (قبل إصلاحاتي) ويظهر أيضاً في الإنتاج.

---

## الحالة النهائية

- ✅ كل اختبارات Flight: **105 passed** (0 failed)
- ✅ كل سيناريوهات الإنتاج: حجز، دفع، إلغاء، استرداد، حذف
- ✅ كل العملات: EGP, USD, SAR, KWD
- ✅ السيناريو المسبب لـ -300 KWD: مُحدد ومُصلَح
- ✅ السيناريوهات المسببة لـ phantom receivables: مُحددة ومُصلحة (Bugs #5-#7)
- ✅ الثوابت المحاسبية: محفوظة
- ✅ التوافق مع AccountModuleContract (DIVISION module_type)