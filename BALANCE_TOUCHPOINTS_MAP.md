# 🗺️ خريطة كل الأماكن اللي فيها "رصيد" في البرنامج
## Balance Touchpoints Map — SafarakEalayna

**التاريخ:** 2026-07-08
**الإصدار:** v1.1 (post-Phase 1)
**الهدف:** توثيق كل مكان في البرنامج بيقرأ أو يعدّل أو بيحسب الرصيد، لتوضيح الـ flow + تحديد الـ desync risks

---

## 1️⃣ ملخص تنفيذي (TL;DR)

| الفئة | عدد الأماكن | محمي بـ Phase 1؟ |
|---|---:|---|
| 🛡️ **Models محمية** (Phase 1) | 2 | ✅ **نعم** |
| 🟢 **Entry points تستخدم الـ services المعتمدة** | 5 | ✅ **نعم** (عبر route) |
| 🟡 **Models قديمة (Legacy)** | 1 | ⚠️ **لا** ← **GAP كبير** |
| 🟦 **Display-only widgets** | 3 | ✅ (read-only) |
| 🟪 **Frontend Vue views** | 1 | ✅ (read-only) |
| 🔧 **Filament Resources تحرر الـ balance للإدخال اليدوي** | 1 | 🟡 (disabled on edit only) |
| ⚙️ **Listeners / Services داخلية** | 4 | ✅ (تستخدم safe paths) |

---

## 2️⃣ الـ Schema الكامل (3 مفاتيح للـ desync)

```
╔══════════════════════════════════════════════════════════════════════════╗
║                   3 مفاتيح للـ desync في النظام                          ║
╠══════════════════════════════════════════════════════════════════════════╣
║                                                                          ║
║  ┌──────────────────────────┐                                            ║
║  │ KEY ① — flight_carriers  │ (العمود التشغيلي — جديد)                  ║
║  │ ├ id, name, code         │                                            ║
║  │ ├ balance ⚠️ protected   │ ← Phase 1: observer + non-fillable        ║
║  │ ├ credit_limit          │                                            ║
║  │ └ available_balance     │ (computed accessor)                        ║
║  └──────────────────────────┘                                            ║
║                                                                          ║
║  ┌──────────────────────────┐                                            ║
║  │ KEY ② — flight_systems   │ (العمود التشغيلي — جديد)                  ║
║  │ ├ id, name, code         │                                            ║
║  │ ├ balance ⚠️ protected   │ ← Phase 1: observer + non-fillable        ║
║  │ ├ credit_limit          │                                            ║
║  │ └ available_balance     │ (computed accessor)                        ║
║  └──────────────────────────┘                                            ║
║                                                                          ║
║  ┌──────────────────────────┐                                            ║
║  │ KEY ③ — AirlineAccount   │ (العمود التشغيلي — قديم ⚠️)             ║
║  │ ├ id, name, code         │                                            ║
║  │ ├ balance ⚠️ NOT PROTECTED  ← 🛑 GAP! لا فيه observer ولا معطل    ║
║  │ ├ credit_limit          │                                            ║
║  │ └ available_balance     │                                            ║
║  └──────────────────────────┘                                            ║
║                                                                          ║
║  ┌─────────────────────────────────────────────────────────┐             ║
║  │ GL — accounts (المحاسبي)                                │             ║
║  │ ├ id 24: "رصيد مسبق — ناقلو الطيران"     (carrier)    │             ║
║  │ └ id 23: "رصيد مسبق — أنظمة حجز الطيران" (system)     │             ║
║  └─────────────────────────────────────────────────────────┘             ║
║                                                                          ║
╚══════════════════════════════════════════════════════════════════════════╝
```

---

## 3️⃣ 🛡️ Filament Admin Panel — كل الشاشات اللي بتتعامل مع الأرصدة

### 3.1 — `FlightCarriers/FlightCarrierResource.php`

| الحقل/الإجراء | السطر | الوظيفة | Phase 1 Status |
|---|---:|---|---|
| `TextInput::make('balance')` | **L123** | عرض الرصيد (read-only) | ✅ `->disabled()` + `->dehydrated(false)` |
| `TextColumn::make('balance')` | **L205** | عرض في الجدول | ✅ Display-only |
| `TextColumn::make('available_balance')` | **L209** | عرض المتاح | ✅ Display-only computed |
| `Action::make('rechargeBalance')` | **L246** | **شحن رصيد** | ✅ يستخدم `FlightCarrierRechargeService` |

**الـ UI message المهم:**
```
L131 → "لا يمكن تعديل الرصيد مباشرة. استخدم زر 'شحن رصيد' في القائمة لضمان تسجيل القيد المحاسبي الصحيح."
```

---

### 3.2 — `FlightSystems/FlightSystemResource.php`

| الحقل/الإجراء | السطر | الوظيفة | Phase 1 Status |
|---|---:|---|---|
| `TextInput::make('balance')` | **L130** | عرض الرصيد (read-only) | ✅ `->disabled()` |
| `TextColumn::make('balance')` | **L219** | عرض في الجدول | ✅ Display-only |
| `TextColumn::make('available_balance')` | **L223** | عرض المتاح | ✅ Display-only |
| `Action::make('rechargeBalance')` | **L262** | **شحن رصيد** | ✅ يستخدم `FlightSystemRechargeService` |

---

### 3.3 — `Pages/FlightSystemsBalancesPage.php` (صفحة مخصصة)

| الحقل/الإجراء | السطر | الوظيفة | Phase 1 Status |
|---|---:|---|---|
| `Action::make('rechargeFlightSystem')` | **L94** | **شحن رصيد نظام** | ✅ يستخدم `FlightSystemRechargeService` |
| View: `flight-system-balances` | (Blade) | جدول بكل الأنظمة والأرصدة | ✅ Display-only |

**⚠️ ملاحظة:** الصفحة دي تعرض كل الـ FlightSystems في جدول واحد مع الأرصدة — أهم monitoring tool للموبايل.

---

### 3.4 — `Pages/FlightDashboard.php` (لوحة تحكم الطيران)

| العنصر | النوع | الوظيفة | Status |
|---|---|---|---|
| `FlightStatsWidget` | Header widget | إحصائيات (sum balance) | ✅ Read-only |
| `RecentFlightBookingsWidget` | Footer widget | آخر الحجوزات | ✅ Read-only |

**ملف الـ view:** `resources/views/filament/admin/pages/flight-dashboard.blade.php`

---

### 3.5 — ⚠️ `Resources/Accounts/AccountFormSchema.php` ⚠️

| الحقل/الإجراء | السطر | الوظيفة | Phase 1 Status |
|---|---:|---|---|
| `TextInput::make('balance')` | **L202** | رصيد افتتاحي | 🟡 `->disabledOn('edit')` — فقط على الإنشاء! |
| `Action::make('rechargeAccount')` | **L333** | إعادة شحن الحساب | 🟡 يستخدم `AccountRechargeService` (نقل + Transaction entries) |

**تحليل `L202`:**
```php
TextInput::make('balance')
    ->label('الرصيد')
    ->numeric()
    ->default(0)
    ->step(0.01)
    ->disabledOn('edit')   // 🟡 disabled بعد الإنشاء — لكن مسموح على أول إنشاء
```

**الـ helperText المهم (L200):**
```
"رصيد افتتاحي عند الإنشاء فقط. بعد الحفظ يتحرّك الرصيد عبر المعاملات والقيود والخزينة 
(نفس مصدر Laravel API). لا يُفضَّل التعديل اليدوي هنا لتفادي اختلاف الأرصدة عن دفتر الأستاذ."
```

**تحليل `L333` (recharge action):**
- بيستخدم `AccountRechargeService` → بيعمل journal transfer entries (debit + credit)
- لو الـ service ده ماشي صح (بيستخدم `LedgerBalanceMutationGuard`)، يبقى آمن ✅

**📁 الـ `AccountFormSchema` بيُستخدم في 27 Resource مختلف!** (banks, wallets, treasuries, etc. — كل حسابات البرنامج)

---

### 3.6 — `Resources/FlightBookings/FlightBookingResource.php`

| المكون | الوظيفة | Phase 1 Status |
|---|---|---|
| Booking creation form | إنشاء حجز | ✅ يستدعي `FlightBookingService` الآمن |

**Booking Flow:**
```
Filament Booking Form
  ↓
FlightBookingService::createBooking()
  ↓
FlightCarrier::debit(amount)  ← L830  (safe ✅)
FlightSystem::debit(amount)   ← L882  (safe ✅)
  ↓
debit() throws if available_balance < amount  ← المسبّب لـ "رصيد مسبق غير كافٍ"
```

---

## 4️⃣ 🟢 الـ Services/Listeners/Controllers — المسارات الداخلية الآمنة

### 4.1 — الـ Services اللي بتعدّل الأرصدة (Phase 1 Protected)

| الـ Service | الـ Method | الـ Status | ملف:سطر |
|---|---|:---:|---|
| `FlightCarrierRechargeService` | `rechargeFromAccount()` | 🛡️ Phase 1 | `app/Services/Flight/FlightCarrierRechargeService.php:38` |
| `FlightSystemRechargeService` | `rechargeFromAccount()` | 🛡️ Phase 1 | `app/Services/Flight/FlightSystemRechargeService.php:38` |

**الإجراءات اللي بتعملها الـ services:**
1. `DB::transaction` يبدأ
2. ID-ascending locks (carrier + source + prepaid GL) — deadlock prevention
3. `prepaidLedgerService->recharge()` — ينشئ tx + entries متوازنة على الـ GL
4. `FlightCarrier::credit()` أو `FlightSystem::credit()` — يرفع الرصيد التشغيلي (عبر `mutateBalanceInternal` flag)
5. Audit log
6. Commit

---

### 4.2 — الـ Methods الآمنة على الـ Models

| الـ Model | الـ Method | الـ Status | ملف:سطر |
|---|---|:---:|---|
| `FlightCarrier` | `debit(amount, bookingId, userId)` | ✅ | `app/Models/Flight/FlightCarrier.php:174` |
| `FlightCarrier` | `credit(amount, desc, userId, bookingId)` | ✅ | `app/Models/Flight/FlightCarrier.php:198` |
| `FlightCarrier` | `mutateBalanceInternal(delta, mutator)` | ✅ | `app/Models/Flight/FlightCarrier.php:88` |
| `FlightSystem` | (نفس الـ methods) | ✅ | (نفس النمط) |

**آلية الحماية:**
- `debit()/credit()` بيستدعي `mutateBalanceInternal()` اللي بيرفع `static::$internalBalanceUpdate = true`
- الـ observer في `booted()` بيشوف الـ flag → يسمح بالتعديل
- لو حد برّه الـ methods عمل `->update(['balance' => ...])` → الـ observer يرمي `RuntimeException`

---

### 4.3 — الـ Internal Callers (كلهم بيستخدموا المسارات الآمنة)

| الملف | السطر | الـ Action |
|---|---:|---|
| `app/Services/Flight/FlightBookingService.php` | **L830** | `$carrier->debit($amount, $booking->id, $userId)` |
| `app/Services/Flight/FlightBookingService.php` | **L882** | `$system->debit($amount, $booking->id, $userId)` |
| `app/Services/Flight/FlightBookingService.php` | **L1876** | `$carrier->credit($amount, $desc, ...)` |
| `app/Services/Flight/FlightBookingService.php` | **L1930** | `$system->credit($amount, $desc, ...)` |
| `app/Services/Flight/FlightCarrierRechargeService.php` | **L155** | `$carrier->credit($amount, $desc, ...)` |
| `app/Services/Flight/FlightSystemRechargeService.php` | **L120** | `$system->credit($amount, $desc, ...)` |
| `app/Services/Flight/RefundService.php` | **L202** | `$carrier->debit($debitSubLedgerAmount, $booking->id, $userId)` |
| `app/Services/Flight/RefundService.php` | **L212** | `$system->debit($debitSubLedgerAmount, $booking->id, $userId)` |

---

## 5️⃣ ⚠️ LEGACY — `AirlineAccount` (النموذج القديم — NOT Phase 1 protected)

### 5.1 — الـ Model نفسه

```php
// app/Models/Flight/AirlineAccount.php:14-26

#[Fillable([
    'name',
    'code',
    'system_type',
    'currency',
    'balance',         // ⚠️ في الـ fillable — قابل للـ mass assignment
    'credit_limit',
    'is_active',
    'notes'
])]
class AirlineAccount extends Model
{
    // ❌ مفيش booted() أو static::updating observer
    // ❌ مفيش mutateBalanceInternal flag
    // ❌ مفيش debt()/credit() routes (نفس FlightCarrier/Model — لكن بره Guard)
```

### 5.2 — الأماكن اللي بتستخدم `AirlineAccount`

| الملف | السطر | الـ Action | الخطر |
|---|---:|---|---|
| `app/Http/Controllers/Api/V1/Flight/AirlineAccountController.php` | **L152** | `$account = AirlineAccount::findOrFail($validated['airline_account_id'])` | 🟢 Read |
| `app/Http/Controllers/Api/V1/Flight/AirlineAccountController.php` | **L215** | `AirlineAccount::create([...])` | 🟡 Initial balance ممكن يدخل |
| `app/Http/Controllers/Api/V1/Flight/AirlineAccountController.php` | **L270,312** | `$account = AirlineAccount::findOrFail($id)` | 🟢 Read |
| `app/Listeners/ProcessTicketModificationAccounting.php` | **L56** | `$tx = $airlineAccount->debit($modification->airline_change_fee, $booking->id, $userId)` | ⚠️ بيعمل debit بدون تسجيل GL |
| `app/Services/Reports/FinancialReportService.php` | (multiple) | Reports using AirlineAccount.balance | 🟢 Read |
| `app/Models/Flight/FlightBooking.php` | **L128-130** | `belongsTo(AirlineAccount::class, 'airline_account_id')` | 🟢 Relation |
| `app/Services/Flight/FlightBookingService.php` | **L93, 392** | `'airlineAccount'` eager load | 🟢 Read |

### 5.3 — 🎯 THE GAP

**الـ `AirlineAccount.debit()` و `credit()` بيشتغلوا مباشرة على `airline_accounts.balance` بدون أي تسجيل في الـ GL!**

```
Refunds/Modifications on Airline Accounts:
  ✗ airlineAccount->debit() → يخفض الـ balance بس
  ✗ مفيش account_entries في الـ GL
  ✗ مفيش transactions row
  ✗ مفيش audit_log entry
  → ⚠️ ده سبب الـ "MYSTERY DESYNC" في الـ 2 flight_systems!
```

---

## 6️⃣ 🟦 Display-Only — الـ Widgets والـ Reports

### 6.1 — Filament Widgets (لوحات التحكم)

| الـ Widget | الملف | الـ Function | Status |
|---|---|---|:---:|
| `FlightStatsWidget` | `app/Filament/Admin/Widgets/FlightStatsWidget.php` | SUM(balance) — stats | ✅ |
| `FinancialStatsWidget` | `app/Filament/Admin/Widgets/FinancialStatsWidget.php` | (varied stats) | ✅ |
| `QuickStatsWidget` | `app/Filament/Admin/Widgets/QuickStatsWidget.php` | (varied stats) | ✅ |
| `DashboardChartWidget` | `app/Filament/Admin/Widgets/DashboardChartWidget.php` | (chart data) | ✅ |
| `RecentFlightBookingsWidget` | `app/Filament/Admin/Widgets/RecentFlightBookingsWidget.php` | (last bookings) | ✅ |
| `RecentActivitiesWidget` | `app/Filament/Admin/Widgets/RecentActivitiesWidget.php` | (activity log) | ✅ |
| `AdminPortalWidget` | `app/Filament/Admin/Widgets/AdminPortalWidget.php` | (portal links) | ✅ |

**الكل Safe — Read-only، بيستخدموا `->sum('balance')` فقط.**

### 6.2 — Filament Tables (Table columns)

| الـ Column type | بيظهر في | الوظيفة |
|---|---|---|
| `TextColumn::make('balance')` | FlightCarriers / FlightSystems / Accounts / Treasury / Wallet / etc. | عرض الرصيد |
| `TextColumn::make('available_balance')` | FlightCarriers / FlightSystems | عرض المتاح (computed) |
| `TextColumn::make('credit_limit')` | FlightCarriers / FlightSystems | عرض حد الائتمان |
| `BadgeColumn::make('balance')` | Accounts | عرض |

**كلها display only — آمنة.**

### 6.3 — Custom Pages (Debt statements)

| الصفحة | الملف | الوظيفة | Status |
|---|---|---|:---:|
| `AccountStatement` | `Pages/AccountStatement.php` | كشف حساب | ✅ |
| `BusCompanyDebtStatement` | `Pages/BusCompanyDebtStatement.php` | ديون شركات الباص | ✅ |
| `CurrencyTreasuryExchangePage` | `Pages/CurrencyTreasuryExchangePage.php` | صرف عملات | ✅ |
| `VisaAgentDebtStatement` | `Pages/VisaAgentDebtStatement.php` | ديون وكلاء التأشيرات | ✅ |
| `HajjUmraExecutingCompanyAdvances` | `Pages/HajjUmraExecutingCompanyAdvances.php` | سلف شركات العمرة | ✅ |
| `MaintenanceModePage` | `Pages/MaintenanceModePage.php` | وضع الصيانة | ✅ |
| `FlightSystemsBalancesPage` | `Pages/FlightSystemsBalancesPage.php` | أرصدة الأنظمة (المهم) | ✅ |

---

## 7️⃣ 🟪 Frontend (Vue — للـ Booking Flow)

### 7.1 — صفحة الحجوزات (`FlightCreate.vue` / `FlightEdit.vue`)

| العنصر | الوظيفة | الـ Source |
|---|---|---|
| اختيار الناقل (carrier) | من `flight_carriers` API | safe ✅ |
| حساب المبلغ الإجمالي | الـ Frontend | display |
| عرض الرصيد المتاح | من `available_balance` API | safe ✅ |
| إرسال الحجز | POST `/api/v1/flight/bookings` | ✅ يذهب للـ FlightBookingService الآمن |

### 7.2 — صفحة `FlightAirlineTransactions.vue`

**ده العرض اللي بيريه الـ agent / customer لحركات الـ airline account:**

```
┌────────────────────────────────────────┐
│  الرصيد الحالي:    X,XXX.XX EGP       │  ← من API (read-only)
│  رصيد الائتمان:    X,XXX.XX EGP       │
│  الرصيد المتاح:    X,XXX.XX EGP       │
│                                        │
│  الجدول:                               │
│  التاريخ | البند | الرصيد قبل | الرصيد بعد │
└────────────────────────────────────────┘
```

| الحقول المعروضة | الـ Source | Status |
|---|---|:---:|
| `account.balance` | GET API | ✅ Read |
| `account.available_balance` | GET API | ✅ Read (computed) |
| `transaction.balance_before` | جدول airline_transactions | ✅ Read |
| `transaction.balance_after` | جدول airline_transactions | ✅ Read |

**كله display only — آمن.**

### 7.3 — صفحة `FlightAirlineAccountsIndex.vue`

عرض قائمة كل الـ airline accounts والـ balance بتاع كل واحد.

### 7.4 — صفحة `FlightTreasuryOverview.vue`

عرض ملخص الـ treasury + الأرصدة.

### 7.5 — صفحة `FlightDetailedReport.vue`

تقارير تفصيلية — الأرصدة حسب الناقل/النظام.

---

## 8️⃣ 📊 ملخص Visual — كل Entry Points

```
╔═══════════════════════════════════════════════════════════════════════════╗
║                       خريطة كل Entry Points للـ Balance                     ║
╠═══════════════════════════════════════════════════════════════════════════╣
║                                                                           ║
║  📊 FILAMENT ADMIN PANEL                                                  ║
║  ────────────────────────                                                 ║
║  ┌─ Pages (روتيا) ────────────────────────────────────────────┐          ║
║  │ • FlightCarriers/FlightCarrierResource         ✅ Phase 1  │          ║
║  │   └─ TextInput balance [disabled]              ✅          │          ║
║  │   └─ Action rechargeBalance → Recharge Service ✅          │          ║
║  │                                                              │          ║
║  │ • FlightSystems/FlightSystemResource           ✅ Phase 1  │          ║
║  │   └─ TextInput balance [disabled]              ✅          │          ║
║  │   └─ Action rechargeBalance → Recharge Service ✅          │          ║
║  │                                                              │          ║
║  │ • Pages/FlightSystemsBalancesPage              ✅ Phase 1  │          ║
║  │   └─ Action rechargeFlightSystem → Service     ✅          │          ║
║  │                                                              │          ║
║  │ • Resources/FlightBookings/FlightBookingResource ✅ Safe   │          ║
║  │   └─ Booking flow → FlightBookingService → debit()         │          ║
║  │                                                              │          ║
║  │ • Resources/Accounts/AccountFormSchema (×27 resources)       │          ║
║  │   └─ TextInput balance [disabledOn edit] 🟡 OK              │          ║
║  │   └─ Action rechargeAccount → AccountRechargeService 🟡     │          ║
║  └──────────────────────────────────────────────────────────────┘          ║
║                                                                           ║
║  ┌─ Custom Pages ───────────────────────────────────────────────┐          ║
║  │ • Pages/FlightDashboard                      ✅ Read-only    │          ║
║  │ • Pages/AccountStatement                     ✅ Read-only    │          ║
║  │ • Pages/VisaAgentDebtStatement               ✅ Read-only    │          ║
║  │ • Pages/BusCompanyDebtStatement              ✅ Read-only    │          ║
║  │ • Pages/HajjUmraExecutingCompanyAdvances     ✅ Read-only    │          ║
║  │ • Pages/CurrencyTreasuryExchangePage         ✅ (currency)   │          ║
║  └──────────────────────────────────────────────────────────────┘          ║
║                                                                           ║
║  ┌─ Widgets (لوحات) ─────────────────────────────────────────────┐         ║
║  │ • FlightStatsWidget                       ✅ Read-only       │         ║
║  │ • FinancialStatsWidget                    ✅ Read-only       │         ║
║  │ • QuickStatsWidget                        ✅ Read-only       │         ║
║  │ • DashboardChartWidget                    ✅ Read-only       │         ║
║  │ • RecentFlightBookingsWidget              ✅ Read-only       │         ║
║  │ • RecentActivitiesWidget                  ✅ Read-only       │         ║
║  │ • AdminPortalWidget                       ✅ Read-only       │         ║
║  └──────────────────────────────────────────────────────────────┘          ║
║                                                                           ║
║  ⚙️ INTERNAL SERVICES (every protected)                                   ║
║  ────────────────────────────────                                        ║
║  ┌─ Services (الـ safe paths) ─────────────────────────────────────┐      ║
║  │ • FlightCarrierRechargeService::rechargeFromAccount ✅ Phase 1 │      ║
║  │ • FlightSystemRechargeService::rechargeFromAccount ✅ Phase 1  │      ║
║  │ • FlightBookingService (booking creation) ✅ Safe               │      ║
║  │ • RefundService (refunds) ✅ Safe                               │      ║
║  │ • AccountRechargeService (Account edits) 🟡 Mostly safe          │      ║
║  └─────────────────────────────────────────────────────────────────┘      ║
║                                                                           ║
║  🎧 LISTENERS / OBSERVERS                                                ║
║  ────────────────────────                                                ║
║  ┌─ Listeners ────────────────────────────────────────────────────┐       ║
║  │ • ProcessTicketModificationAccounting (LISTENER)               │       ║
║  │   └─ AirlineAccount.debit()  ⚠️ NOT PHASE 1 PROTECTED         │       ║
║  │   └─ ← this hits the gap!                                     │       ║
║  └────────────────────────────────────────────────────────────────┘       ║
║                                                                           ║
║  🌐 FRONTEND (Vue)                                                       ║
║  ──────────────────                                                      ║
║  ┌─ Pages ──────────────────────────────────────────────────────┐        ║
║  │ • FlightCreate.vue                       ✅ Read-only + API   │        ║
║  │ • FlightEdit.vue                         ✅ Read-only + API   │        ║
║  │ • FlightAirlineTransactions.vue          ✅ Read-only display │        ║
║  │ • FlightAirlineAccountsIndex.vue         ✅ Read-only display │        ║
║  │ • FlightTreasuryOverview.vue             ✅ Read-only display │        ║
║  │ • FlightDetailedReport.vue               ✅ Read-only display │        ║
║  │ • FlightDashboard.vue                    ✅ Read-only display │        ║
║  │ • FlightIndex/Show.vue                   ✅ Read-only display │        ║
║  └──────────────────────────────────────────────────────────────┘        ║
║                                                                           ║
║  🛑 LEGACY / UNPROTECTED (THE GAP)                                       ║
║  ───────────────────────────────                                         ║
║  ┌─ AirlineAccount model ──────────────────────────────────────────┐      ║
║  │ ⚠️ 'balance' في [Fillable]                                    │      ║
║  │ ⚠️ لا observer                                                  │      ║
║  │ ⚠️ لا Guard                                                     │      ║
║  │                                                                 │      ║
║  │ User:                                                           │      ║
║  │   • AirlineAccountController (API) — line 152, 215, 270, 312  │      ║
║  │   • ProcessTicketModificationAccounting — line 56               │      ║
║  │   • FlightBookingService — booking flow uses old IDs           │      ║
║  └─────────────────────────────────────────────────────────────────┘      ║
║                                                                           ║
╚═══════════════════════════════════════════════════════════════════════════╝
```

---

## 9️⃣ 🚦 Risk Assessment — حسب المكان

| الـ Place | Risk Level | الـ Reason | Phase 1 Coverage |
|---|:---:|---|:---:|
| `FlightCarrierResource.balance` input | 🟢 Low | `->disabled()` + observer | ✅ Double |
| `FlightSystemResource.balance` input | 🟢 Low | `->disabled()` + observer | ✅ Double |
| `FlightCarrierResource.rechargeBalance` action | 🟢 Low | Uses approved service | ✅ Transitive |
| `FlightSystemResource.rechargeBalance` action | 🟢 Low | Uses approved service | ✅ Transitive |
| `FlightSystemsBalancesPage.rechargeFlightSystem` | 🟢 Low | Uses approved service | ✅ Transitive |
| `FlightBookingService.debit/credit` | 🟢 Low | Safe model methods | ✅ Transitive |
| `RefundService.debit` | 🟢 Low | Safe model methods | ✅ Transitive |
| `AccountFormSchema.balance` (×27 resources) | 🟡 Medium | `->disabledOn('edit')` only — initial creation open | 🟡 Partial |
| `AirlineAccount.balance` (legacy) | 🔴 **HIGH** | Mass-assignable + no observer + used by listener | ❌ **NO** |
| `AirlineAccountController` (API) | 🟠 Medium-High | Direct create/update | ❌ NO |
| `ProcessTicketModificationAccounting.debit` | 🔴 **HIGH** | Writes to balance without GL entries | ❌ **NO** |
| Vue display pages | 🟢 Low | Read-only display | ✅ N/A |
| Widgets | 🟢 Low | Read-only stats | ✅ N/A |

---

## 🔟 التوصيات (Phase 1v2 / Phase 4 — Hardening)

### 🎯 الأولوية القصوى (HIGH):

| # | الإجراء | السبب | الجهد |
|---|---|---|---:|
| **1** | **حماية `AirlineAccount.balance`:** إزالة `balance` من `[Fillable]` + إضافة observer (نسخة من FlightCarrier) | ده سبب الـ MYSTERY DESYNC في الـ 2 flight_systems | صغير (2h) |
| **2** | **تحديث `ProcessTicketModificationAccounting.php:56`:** استخدام service بدلاً من `AirlineAccount.debit()` | الـ current code بيكسر الـ double-entry | صغير (1h) |
| **3** | **حماية `airline_account_id` data flow في booking** | التأكد إن الـ bookings الجديدة بتستخدم flight_carriers مش AirlineAccount | صغير (3h) |

### 🎯 الأولوية المتوسطة:

| # | الإجراء | السبب | الجهد |
|---|---|---|---:|
| **4** | **تدقيق `AirlineAccountController`** — التأكد إنه مفيش endpoints بتعمل direct update على balance | API endpoint مخاطرة | صغير (2h) |
| **5** | **تحويل `AccountFormSchema.balance` إلى `->readOnly()`** بدلاً من `->disabledOn('edit')` | الـ initial creation كمان ممكن يخلق desync لو الإيدخال غلط | صغير (30د) |
| **6** | **عمل consistency check روتينّي** (daily) — يقارن GL ↔ carrier balance مثل phase 3a | اكتشاف desyncs جديدة بسرعة | متوسط (4h) |

### 🎯 طويل المدى:

| # | الإجراء | السبب | الجهد |
|---|---|---|---:|
| **7** | **Migrate كل الـ legacy AirlineAccounts → flight_carriers** | إزالة الـ legacy schema تماماً | كبير (1-2 sprint) |
| **8** | **Audit log tables for balance** | تتبع history كامل | متوسط (1 sprint) |
| **9** | **Real-time balance reconciliation service** | التحقق اللحظي | كبير |

---

## 1️⃣1️⃣ Quick Reference — كل الـ Safe Paths

```php
// ✅ للشحن (recharge):
app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $account, $amount, $notes);
app(FlightSystemRechargeService::class)->rechargeFromAccount($system, $account, $amount, $notes);

// ✅ للخصم (debit — في booking/refund):
$carrier->debit($amount, $bookingId, $userId);  // throws if insufficient
$system->debit($amount, $bookingId, $userId);

// ✅ للإضافة (credit — في recharge + reversal):
$carrier->credit($amount, $description, $userId, $bookingId);
$system->credit($amount, $description, $userId, $bookingId);

// 🚫 ممنوع مباشرة (Phase 1):
$carrier->update(['balance' => 100]);         // → RuntimeException
$carrier->fill(['balance' => 100]);           // → silently ignored
DB::table('flight_carriers')->update([...]);  // → notification + Log warning

// 🚫 ممنوع (legacy — gap):
$airlineAccount->update(['balance' => 100]);  // → SUCCEEDS! ⚠️
$airlineAccount->fill(['balance' => 100]);    // → SUCCEEDS! ⚠️
```

---

## 1️⃣2️⃣ الـ Status الحالية (Production)

| المقياس | القيمة | الـ Status |
|---|---|:---:|
| FlightCarriers.balance protected | 4 layers | ✅ |
| FlightSystems.balance protected | 4 layers | ✅ |
| AirlineAccount.balance protected | 0 layers | ❌ **GAP** |
| Booking flow debit safe | Yes | ✅ |
| Refund flow debit safe | Yes | ✅ |
| Recharge flow safe | Yes (Phase 1) | ✅ |
| Display-only widgets safe | Yes | ✅ |
| Frontend Vue display safe | Yes | ✅ |

---

## 📋 التوقيع

| الدور | التاريخ | الـ Status |
|---|---|---|
| Inventory complete | 2026-07-08 | ✅ |
| Phase 1 (FlightCarriers + FlightSystems) | 2026-07-08 | ✅ Active |
| Phase 1v2 (AirlineAccount) recommended | (pending) | ⏳ **HIGH PRIORITY** |
| Phase 4 (Hardening) recommended | (pending) | ⏳ |

---

> **📌 ملاحظة:** الحاجة الأكثر إلحاحاً بعد الـ rollback هي **Phase 1v2** — حماية `AirlineAccount` من نفس الـ desync اللي حصل في `flight_carriers`. ده هيمنع أي desync جديد في الـ legacy accounts (اللي بتشمل الـ flight_systems).
