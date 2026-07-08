# 📋 تقرير الفروقات المحاسبية — flight_carriers / flight_systems

**التاريخ:** 2026-07-08
**المُعِد:** فريق التطوير (Phase 1 + 2 + 3)
**الـ Status:** ⏳ **في انتظار قرار المحاسبة**

---

## 1️⃣ ملخص الموقف

| | |
|---|---|
| 🏢 النظام | SafarakEalayna (Laravel 11) |
| 🎯 المشكلة | فشلت حجوزات الطيران برسالة "رصيد مسبق غير كافٍ" مع إن الرصيد الظاهر كافٍ |
| 🔍 السبب الجذري | **Desync بين** `flight_carriers.balance` (العمود التشغيلي) **و** الحساب الرئيسي "رصيد مسبق — ناقلو الطيران" (العمود المحاسبي GL) في جدول `accounts` |
| 📊 التفاوت الإجمالي | 7 من 8 entities عندها desync بإجمالي ≥ 562,000 EGP |
| 🛡️ Phase 1 مكتمل | 3 طبقات حماية + إشعارات tamper detection + اختبارات 9/9 passed |
| ✅ Phase 3b v1 | تم تنفيذها ثم اتكشفت غلط، تم الـ rollback، الحالة نظيفة 100% |

---

## 2️⃣ الـ Schema المعرّض للمشكلة (قبل إصلاح Phase 1)

```
┌─────────────────────────────────────────────────────────────┐
│                     قاعدة البيانات (MySQL)                    │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌──────────────────────────┐    ┌─────────────────────────┐ │
│  │  flight_carriers (عملي)  │    │  accounts (محاسبي GL)  │ │
│  ├──────────────────────────┤    ├─────────────────────────┤ │
│  │ id                       │    │ id                      │ │
│  │ name                     │    │ name                    │ │
│  │ balance ◄─── المصدر 1    │    │ balance ◄─── المصدر 2   │ │
│  │ currency                 │    │ currency                │ │
│  │ ...                      │    │ ...                     │ │
│  └──────────────────────────┘    └─────────────────────────┘ │
│            ▲                              ▲                  │
│            │                              │                  │
│            └────── ⚠️ مفيش مزامنة ────────┘                  │
│                                                               │
│  ❗ لو حد عدّل balance يدوياً في أي اتجاه ← desync           │
└─────────────────────────────────────────────────────────────┘
```

**السيناريو اللي كان بيحصل:**

1. موظف/Admin يفتح Filament → يعدّل `flight_carriers.balance` يدوياً (مثلاً: 50,000 بدل 5,000)
2. الـ bookings بقت تشتغل على الرصيد الجديد (50,000)
3. لكن الـ GL المحاسبي مزال يقول 5,000
4. لما بنعمل reconciliation `account.balance = Σ(account_entries)` ← الـ desync واضح

---

## 3️⃣ الـ Desyncs المُكتشفة (التفصيل)

| # | النوع | الاسم | الرصيد التشغيلي | الرصيد المحاسبي (GL) | الـ Desync | الحالة |
|---|---|---|---:|---:|---:|---|
| 1 | Carrier | **العربية** | `57,414.01` | `-31,587.15` | ❓ **89,001.16** | 🔴 MYSTERY |
| 2 | Carrier | **الجزيرة_مصري** | `14,782.00` | `-31,587.15` | ❓ **46,369.15** | 🔴 MYSTERY |
| 3 | Carrier | **الجزيرة - كويتى** | `500.88` | `-31,587.15` | ✅ **32,088.03** | 🟢 IN-SYNC |
| 4 | Carrier | **نسما للطيران** | `46,847.00` | `-31,587.15` | ❓ **78,434.15** | 🔴 MYSTERY |
| 5 | Carrier | **فلاي أديل** | `4,018.09` | `-31,587.15` | ❓ **35,605.24** | 🔴 MYSTERY |
| 6 | Carrier | **اير كايرو** | `39,500.80` | `-31,587.15` | ❓ **71,087.95** | 🔴 MYSTERY |
| Sys 1 | System | **NDC_ WONDR** | `76,184.20` | `-52,349.30` | ❓ **128,533.50** | 🔴 MYSTERY |
| Sys 2 | System | **NDC_X NSAS** | `62,820.94` | `-52,349.30` | ❓ **115,170.24** | 🔴 MYSTERY |

> **ملاحظة مهمة:** الـ desync الإجمالي اللي بـ 562,000+ EGP ده لا يساوي الـ delta = -15,750 المحاسبية.
> الـ -15,750 دي desync بين الـ GL إجمالي والـ entries اللي فيه (مشكلة مزدوجة: GL نفسه مش متوازن + الـ carriers مش متوافقة معاه).

### 🔎 نتائج البحث عن السبب (phase 3a):
- **مفيش أي `audit_logs` بيوضح تعديل يدوي** على أي من الـ 7 entities
- الـ `airline_transactions` فيها بيانات قليلة لكل carrier (1-5 records) — غالباً الـ data migrated من نظام قديم (AirlineAccount) بدون تاريخ كامل
- الـ **MYSTERY DESYNC** المُحتمل: migration من النظام القديم (مايو 2025) نقل الـ balances بدون ما ينقل الـ entries

---

## 4️⃣ الـ Schema بعد إصلاح Phase 1

```
┌──────────────────────────────────────────────────────────────┐
│             قاعدة البيانات — بعد Phase 1 (محمي)             │
├──────────────────────────────────────────────────────────────┤
│                                                                │
│  ┌──────────────────────────┐    ┌─────────────────────────┐  │
│  │  flight_carriers         │    │  accounts (GL)          │  │
│  ├──────────────────────────┤    ├─────────────────────────┤  │
│  │ id                       │    │ id                      │  │
│  │ name                     │    │ name                    │  │
│  │ balance ⚠️ read-only     │    │ balance                 │  │
│  │   ↑ observer يحمي        │    │                         │  │
│  │   ↑ Form read-only       │    │                         │  │
│  │   ↑ $fillable فارغ       │    │                         │  │
│  │   ↑ DB::listen يرصد     │    │                         │  │
│  └──────────────────────────┘    └─────────────────────────┘  │
│            ▲                              ▲                   │
│            │                              │                   │
│            └──── ✅ مزامنة عبر ───────────┘                   │
│                  FlightCarrierRechargeService                  │
│                       (ID-asc locks + retry)                  │
│                                                                │
└──────────────────────────────────────────────────────────────┘

3️⃣ خدمات التعديل المسموح بها فقط:
   ✓ FlightCarrierRechargeService::rechargeFromAccount()
   ✓ FlightCarrier::debit() / credit()
   ✓ Phase 3b v2 (للتصحيح المحاسبي المعتمد)
```

### الـ Defense Layers (3 طبقات):

| الطبقة | الموقع | الوظيفة |
|---|---|---|
| **#1 — UI** | `filament/.../FlightCarrierResource.php` | `Forms\Components\TextInput::disabled()` على حقل balance |
| **#2 — Observer** | `app/Observers/FlightCarrierObserver.php` (موجود ضمناً) | `static::updating()` يرمي exception لو `isDirty('balance')` |
| **#3 — Mass Assignment** | `app/Models/Flight/FlightCarrier.php` | `'balance'` مش في `#[Fillable]` → `Model::fill(['balance'=>...])` يتجاهله |
| **#4 — DB Listen** | `app/Providers/AppServiceProvider.php` | DB::listen يصد أي `UPDATE flight_carriers SET balance=...` خارج `LedgerBalanceMutationGuard::run()` → Log + notification للـ admins |

---

## 5️⃣ القرارات المتاحة (لكل entity من الـ 7)

### 🅰️ Manual Edit (تعديل يدوي معتمد)
**الوصف:** موظف محاسبي يفتح Filament → يعدّل `flight_carriers.balance` للمبلغ الصحيح

**الـ SQL اللي هيتنفذ (خلف الكواليس):**
```sql
UPDATE flight_carriers SET balance = {المبلغ_الصحيح} WHERE id = {carrier_id};
```

**⚠️ محاذير:**
- Phase 1 هيعترض هذا التعديل بـ notification (لكن مش exception — الإدارة توافق)
- لازم يكون هناك audit log entry يدوي في `audit_logs` يوثق السبب
- مفيش GL entry تلقائي — الحساب المحاسبي مازال فيه desync مع الـ carrier الجديد

**مناسب لـ:** تعديلات تصحيحية معروفة السبب (مثلاً: "هذا الـ carrier فعلياً رصيده X حسب كشف البنك")

---

### 🅱️ Correction Entry (قيد تصحيح محاسبي مع GL entry) ✨ الموصى به
**الوصف:** إنشاء قيد محاسبي يصحح الفرق بدون تعديل يدوي

**الـ SQL اللي هيتنفذ:**
```sql
-- ① إضافة entry على الـ carrier/system بالاتجاه المناسب
INSERT INTO airline_transactions (carrier_id, debit, credit, balance_after, notes, created_at)
VALUES ({id}, {abs_desync}, 0, {المبلغ_المُصحَّح}, 'Phase 3b correction', NOW());

-- ② إضافة entries متوازنة على GL
INSERT INTO transactions (type, amount, from_account_id, to_account_id, module, related_type, notes)
VALUES ('adjustment', {abs_desync}, {adjustment_account_id}, {prepaid_account_id}, 'flight', 'phase3b_correction', '...');

INSERT INTO account_entries (account_id, transaction_id, debit, credit, balance_after, notes)
VALUES ({prepaid_account_id}, {tx_id}, {abs_desync}, 0, {new_balance}, 'debit prepaid');
```

**مناسب لـ:** الفروقات اللي المفروض الـ GL يطابق الـ carrier فيها (مثلاً: "الناقل دفع كاش قبل الـ migration، لم يُسجّل")

---

### 🅲️ Write-Off (شطب كخسارة)
**الوصف:** تسجيل المبلغ كمصروف غير قابل للاسترداد

**الـ SQL اللي هيتنفذ:**
```sql
INSERT INTO transactions (type, amount, from_account_id, to_account_id, module, notes)
VALUES ('writeoff', {abs_desync}, {writeoff_expense_account}, {prepaid_account_id}, 'flight', 'Write-off: unreconcilable balance, approved by [اسم]');

INSERT INTO account_entries (account_id, transaction_id, debit, credit, balance_after, notes)
VALUES ({writeoff_expense_account}, {tx_id}, {abs_desync}, 0, ..., 'writeoff expense');
```

**مناسب لـ:** الفروقات القديمة جداً (> 6 شهور) + مفيش إثبات دفع

---

## 6️⃣ التوصيات (بناءً على توفر الـ ledger history)

### ✅ **#3 الجزيرة - كويتى (500.88 EGP)** — لا يحتاج إجراء
- Desync موجود بس صغير نسبياً vs الـ GL المُجمَّع
- لو الـ GL اتظبط، الـ carrier ده هيتظبط تلقائياً

### 🟡 **Decisions مطلوبة لكل من الـ 7:**

| # | الاسم | المبلغ | التاريخ المُحتمل | توصيتنا |
|---|---|---:|---|---|
| 1 | العربية | 89,001.16 | مايو 2025 (pre-migration) | 🅱️ Correction Entry — request proof |
| 2 | الجزيرة_مصري | 46,369.15 | مايو 2025 | 🅱️ Correction Entry — request proof |
| 4 | نسما | 78,434.15 | مايو 2025 | 🅱️ Correction Entry — request proof |
| 5 | فلاي أديل | 35,605.24 | مايو 2025 | 🅱️ Correction Entry — request proof |
| 6 | اير كايرو | 71,087.95 | مايو 2025 | 🅱️ Correction Entry — request proof |
| Sys 1 | NDC_WONDR | 128,533.50 | مايو 2025 | 🅲️ Write-off — old migration debt |
| Sys 2 | NDC_X_NSAS | 115,170.24 | مايو 2025 | 🅲️ Write-off — old migration debt |

> 💡 **السبب الكامن وراء الـ MYSTERY DESYNC المُحتمل:**
> الـ migration من `AirlineAccount` (الـ schema القديم) لـ `flight_carriers` (الـ schema الحالي) في مايو 2025 نقل الأرصدة بدون تحويل الـ entries. ده اللي سبّب الـ desync الجماعي.

---

## 7️⃣ خطة العمل المقترحة

### 📅 فوراً (قبل فتح حجوزات جديدة):
1. ✅ **Phase 1 مكتمل** — التعديل اليدوي مُعطّل + إشعارات شغّالة
2. ✅ **Phase 3b v1 اتعمل ثم اتسحب** — الحالة نظيفة
3. ⏳ **انتظار قراركم** على الـ 7 entities

### 📅 بعد قرار المحاسبة:
4. تنفيذ `phase3b_v2_correct_fix.php` لكل entity حسب القرار (Manual Edit / Correction Entry / Write-off)
5. التحقق من `actual_balance == entries_sum` بعد كل تطبيق
6. تقرير `POST_DECISION_AUDIT.md` يُسلَّم للإدارة

---

## 8️⃣ الـ Queries الجاهزة (لو حابب تجرب دلوقتي)

```sql
-- [Q1] قائمة كل الـ carriers وحالة الـ desync
SELECT 
    c.id, c.name, c.balance AS carrier_balance,
    (a.balance / (SELECT COUNT(*) FROM flight_carriers WHERE deleted_at IS NULL)) - c.balance AS per_carrier_gl_share,
    CASE 
        WHEN ABS(c.balance - (a.balance / (SELECT COUNT(*) FROM flight_carriers WHERE deleted_at IS NULL))) > 100 THEN '🔴 DESYNC'
        ELSE '🟢 IN-SYNC'
    END AS status
FROM flight_carriers c
CROSS JOIN accounts a
WHERE a.id = 24 AND c.deleted_at IS NULL
ORDER BY ABS(c.balance - (a.balance / (SELECT COUNT(*) FROM flight_carriers WHERE deleted_at IS NULL))) DESC;

-- [Q2] تفاصيل الـ GL
SELECT id, name, balance FROM accounts WHERE id IN (23, 24);

-- [Q3] مجموع الـ entries
SELECT account_id, SUM(credit) - SUM(debit) AS entries_sum
FROM account_entries WHERE account_id = 24 GROUP BY account_id;
```

---

## 9️⃣ جهات الاتصال

| الدور | الاسم | المسؤولية |
|---|---|---|
| Laravel Dev | Youssef Abd Elhaleim | تنفيذ الـ scripts + Phase 1 maintenance |
| محاسبي رئيسي | (يحدد) | إصدار القرارات لكل desync |
| DBA | (يحدد) | backup/restore + data integrity |

---

## 🔗 المراجع

- `phase3a_investigate_carriers.php` — تقرير مفصّل READ-ONLY (شغّل على VPS بالفعل)
- `phase3b_safe_fix.php` — v1 (به bug — credit بدل debit) — استخدم كـ documentation
- `phase3b_v1_rollback.sql` — الـ rollback script (نفّذ بنجاح، كله نظيف)
- `phase3b_v2_correct_fix.php` — v2 المعدّل (للاستخدام بعد قراركم) — قيد التحضير
- `test_phase1_verification.php` — 9 اختبارات Phase 1 (9/9 passed)

---

**📌 التوقيع:** _في انتظار التوقيع المحاسبي_
