# خطة اختبار موديول المكتب (Office Module)

> **تاريخ التنفيذ:** 2026-07-22  
> **النطاق:** موديولات "المكتب" في النظام — وهي:
> - `بنوك وبريد المكتب` (TransferBankResource — type=bank, module_type=office)
> - `خزائن المكتب النقدية` (TransferCashboxResource — type=cashbox, module_type=office)
> - `محافظ المكتب` (TransferWalletResource — type=wallet, module_type=office)
>
> **منفذ الاختبار:** نموذج MiniMax-M3 كـ Fullstack Agent  
> **المنهجية:** Tinker (Backend) + API calls (Vue Frontend) + Filament Schemas (الواجهة الإدارية)  

---

## 1) التحقق من حالة الـ Database الحالية

| الجدول | عدد السجلات المتوقعة قبل | عدد السجلات قبل الاختبار |
|---|---|---|
| `accounts` | > 0 (3 أنواع × 5 عملات) | **0** (فارغ) |
| `account_entries` | > 0 (لكل رصيد افتتاحي) | 0 |
| `transactions` | > 0 (لكل عملية) | 0 |
| `customers` | > 0 (للحجز والديون) | 0 |
| `suppliers` | > 0 (للمديونيات) | 0 |
| `banks` | > 0 (اختياري) | 0 |
| `exchange_rates` | > 0 (لكل عملة) | 0 |

> ⚠️ **اكتشاف مهم:** الـ Database الحالي **فارغ** فعلياً، فلا يوجد أي حسابات أو بيانات سابقة. يجب إنشاء كل شيء من الصفر.

---

## 2) خطة الاختبار الكاملة

### المرحلة 1 — إنشاء البيانات الأساسية (Setup)
- [x] 1.1 إدخال أسعار الصرف لـ 5 عملات (EGP, USD, SAR, AED, KWD)
- [ ] 1.2 إدخال العملاء (customers) باستخدام Filament
- [ ] 1.3 إدخال الموردين (suppliers) باستخدام Filament
- [ ] 1.4 إدخال **بنوك المكتب** (Bank) — بـ 4 عملات مختلفة
- [ ] 1.5 إدخال **خزائن المكتب** (Cashbox) — بـ 3 عملات (EGP, USD, SAR)
- [ ] 1.6 إدخال **محافظ المكتب** (Wallet) — 5 محافظ × 5 أنواع → 5 × 5 = 25 محفظة

### المرحلة 2 — اختبار Schema الخاصة بـ Filament
- [ ] 2.1 اختبار `AccountFormSchema::configure()` لكل نوع (`bank`, `cashbox`, `wallet`)
- [ ] 2.2 التأكد أن `module_type=office` ثابت ولا يمكن للمستخدم تغييره
- [ ] 2.3 التأكد من ظهور Dropdown الـ wallet_provider عند type=wallet
- [ ] 2.4 التأكد من منع تعديل الرصيد بعد الحفظ (`disabledOn('edit')`)
- [ ] 2.5 اختبار الفلاتر في الجدول: `is_active`, `currency`, `wallet_provider` (للمحافظ)

### المرحلة 3 — اختبار APIs (Backend Endpoints)
- [ ] 3.1 `POST /api/v1/finance/accounts` → إنشاء حسابات بـ 5 أنواع × 5 عملات
- [ ] 3.2 `GET /api/v1/finance/accounts` → عرض كل الحسابات + فلاتر `account_type`, `currency`, `module_type=office`, `is_active`
- [ ] 3.3 `GET /api/v1/finance/accounts/{id}/statement` → كشف حساب بنكي (رصيد افتتاحي + تحويلات)
- [ ] 3.4 `POST /api/v1/finance/transfers` → تحويل بين بنك ↔ خزينة ↔ محفظة
- [ ] 3.5 `POST /api/v1/finance/accounts/{id}/deactivate` → تعطيل (يجب ألا يكون عليه رصيد)
- [ ] 3.6 `GET /api/v1/reports/debts?department=office` → تقرير مديونيات المكتب
- [ ] 3.7 `GET /api/v1/reports/office-trial-balance` → ميزان مراجعة المكتب

### المرحلة 4 — اختبار Vue Frontend (لوحة إدارة المكتب)
- [ ] 4.1 فتح `/finance/office` → `OfficeManagement.vue` → تأكد ظهور الكروت الأربعة:
  - إجمالي المستحقات لنا
  - إجمالي المستحق علينا
  - صافي الميزان
  - إجمالي الإيرادات
- [ ] 4.2 فتح Tab "المركز المالي" → عرض Module Breakdown bus/wallet/online/fawry/general
- [ ] 4.3 فتح Tab "المستحق لنا" → search box + فلتر الجهة (customer/bus_company/supplier)
- [ ] 4.4 فتح Tab "المستحق علينا" → search box + قائمة الموردين
- [ ] 4.5 فتح `/finance/office/operations` → `OfficeOperations.vue` → نفس القالب

### المرحلة 5 — اختبار القيود المحاسبية (Critical Accounting Tests)
- [ ] 5.1 توازن القيد: مجموع debit = مجموع credit لكل `transaction`
- [ ] 5.2 توازن الميزان لكل حساب: `balance = SUM(debit) - SUM(credit)`
- [ ] 5.3 صفرية الحسابات: `total_debits_per_currency == total_credits_per_currency` عبر الـ ledger كله
- [ ] 5.4 صفرية الميزان على مستوى الـ Module Office فقط

### المرحلة 6 — اختبار التحويلات بين الأنواع
- [ ] 6.1 تحويل bank→cashbox بنفس العملة (EGP)
- [ ] 6.2 تحويل bank→bank مع عملات مختلفة (EGP → USD) — اختبار سعر الصرف
- [ ] 6.3 تحويل wallet→bank عبر `vaultTransfer` action في Filament
- [ ] 6.4 فرض overspending — رفض التحويل لو الرصيد غير كافي

### المرحلة 7 — اختبار الحذف
- [ ] 7.1 حذف حساب بدون رصيد وبدون entries → ينجح
- [ ] 7.2 محاولة حذف حساب عليه رصيد → `RuntimeException` "لا يمكن حذف حساب مالي يحتوي على حركات أو رصيد"
- [ ] 7.3 bulk delete مختلط (بعضها عليها رصيد والبعض لا) → يعدّ `deleted` و `blocked` بشكل منفصل

### المرحلة 8 — اختبار الكانسل والإرجاع
- [ ] 8.1 إلغاء عملية تحويل → يجب أن تُضاف inverse entries (عكس:) على نفس `transaction_id`
- [ ] 8.2 التأكد أن `account.balance` يعود لقيمته الأصلية بعد الإلغاء (Additive Reversal Invariant)
- [ ] 8.3 التحقق من `Transaction.notes` يبدأ بـ "عكس:" بعد الإلغاء

### المرحلة 9 — اختبار الخزنة الرسمية (Module Vault)
- [ ] 9.1 تفعيل `is_module_vault=true` على cashbox واحد
- [ ] 9.2 التأكد أنه يظهر كأساسي في dropdown الـ Program interface
- [ ] 9.3 التأكد أنه يسحب منه عند Booking لكل module تابع للقسم

### المرحلة 10 — اختبارات الـ Unified Accounts (Phase 6/7)
- [ ] 10.1 لوحدة موحدة: حساب bank بـ `module_type=office` يظهر في dropdown لكل قسم فرعي:
  - bus, wallet_transfer, online, fawry, general
- [ ] 10.2 الحساب نفسه يظهر في **كل** قسم (single source of truth)

---

## 3) اختبارات الحساب (Mathematical Precision)

### 3.1 صحة الرصيد الافتتاحي

```
Opening_Balance_Account = SUM(account_entries.credit - account_entries.debit) where account_id = X
```

✅ المتوقع: `Account.balance == Opening_Balance_Account` بعد كل قيد.

### 3.2 توازن كل Transaction

```
For every transaction_id:
  SUM(debit where account_entries.transaction_id = X) == SUM(credit)
```

✅ المتوقع: لا يوجد `transaction_id` ينتهك هذا.

### 3.3 صفرية الميزان

```
For each (currency):
  TOTAL_DEBIT across all AccountEntry == TOTAL_CREDIT across all AccountEntry
```

✅ المتوقع: لا يوجد عملة ينحرف فيها المجموع.

---

## 4) مؤشرات النجاح (Pass/Fail Criteria)

| الفحص | Pass | Fail |
|---|---|---|
| Account created with type=bank, module_type=office | ✅ | ❌ |
| Account created with type=wallet, requires wallet_provider + wallet_number | ✅ | ❌ |
| Account with module_type=office accepts currency EGP, USD, SAR, AED, KWD | ✅ | ❌ |
| `/api/v1/finance/accounts` returns accounts with module_type=office filter | ✅ | ❌ |
| `/api/v1/reports/debts?department=office` returns receivables + payables | ✅ | ❌ |
| Transfer between two office accounts updates both balances correctly | ✅ | ❌ |
| Delete blocked when balance ≠ 0 or entries exist | ✅ | ❌ |
| Vue `/finance/office` shows 4 KPI cards with numeric values | ✅ | ❌ |
| Vue search and filters work correctly | ✅ | ❌ |
| Currency conversion (USD→EGP) correct | ✅ | ❌ |
| Module vault (cashbox office) marked as primary for booking flows | ✅ | ❌ |

---

## 5) ما سيُسلَّم بعد الاختبار

1. **تقرير نصي شامل** بـ:
   - قائمة كاملة بـ Routes المُختبرة
   - الـ Payloads والـ Responses الفعلية من الـ API
   - نتائج كل سيناريو (نجح / فشل / به خلل)
   - أي Bugs أو قيود تم رصدها
2. **جدول Pass/Fail** محدّث بحسب الفحوصات أعلاه
3. **توصيات إصلاح** (إن وُجد)
4. **JSON Audit file** على نفس نمط الملفات الموجودة في الـ root
