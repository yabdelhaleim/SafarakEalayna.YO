# ✈️ Flight Module — Operational Scenarios Report

> **الـ Reference التشغيلي للوحدة السياحة / الطيران**
> يوضح بالتفصيل **كل عملية** في الـ Flight module عبر **3 واجهات:**
> - **Frontend (Vue.js SPA)**: اللي بيشوفه الـ agent/المستخدم النهائي
> - **Filament Admin Panel**: اللي بيشوفه الـ admin في `/admin`
> - **Backend (API + Services)**: اللي بيشتغل في الـ Laravel
>
> **وكمان: ازاي الحسابات بترحل** بالتفصيل، و**فين كل function** موجودة.
>
> **📅 آخر تحديث:** 2026-07-10

---

## 📖 جدول المحتويات

| # | السيناريو | الوصف |
|---|---|---|
| **0** | [Architecture Overview — فين كل function](#0--architecture-overview--فين-كل-function) | الـ 3 واجهات + الـ layers |
| **1** | [إنشاء حجز طيران (End-to-End)](#scenario-1--إنشاء-حجز-طيران--end-to-end-booking) | من الـ Vue Wizard → DB |
| **2** | [شحن رصيد ناقل/نظام (Recharge)](#scenario-2--شحن-رصيد-ناقل-نظام--recharge) | Filament action + الـ prepaid flow |
| **3** | [استرداد إلى خزينة الوكالة (Refund to Treasury)](#scenario-3--استرداد-إلى-خزينة-الوكالة--refund-to-treasury) | RefundService processing |
| **4** | [استرداد إلى رصيد طيران (Refund to Airline Credit)](#scenario-4--استرداد-إلى-رصيد-طيران--refund-to-airline-credit) | Voucher creation |
| **5** | [تعديل تذكرة (Ticket Modification)](#scenario-5--تعديل-تذكرة--ticket-modification) | Filament modify action — ⚠️ GAP |
| **6** | [لوحة تحكم الطيران (Dashboard & Reports)](#scenario-6--لوحة-تحكم-الطيران--dashboard--reports) | Vue + Filament widgets |
| **7** | [حالات الـ Edge والـ Conflicts](#scenario-7--حالات-ال-edge-والـ-conflicts) | الـ edge cases الحرجة |
| **8** | [Where to Find What — Index](#8--where-to-find-what--index) | البحث السريع |

---

# 0 — Architecture Overview: فين كل function

> **قبل أي سيناريو:** لازم تفهم **3 واجهات** في النظام + **فين كل function** بيشتغل فيها.

## 0.1 الـ 3 واجهات (Layers)

```
┌────────────────────────────────────────────────────────────────────────────┐
│                          SafarakEalayna — Flight Module                    │
├────────────────────────────────────────────────────────────────────────────┤
│                                                                            │
│  ╔═══════════════════════════════════════════════════════════════════╗    │
│  ║ LAYER 1: FRONTEND (Vue.js SPA) — resources/js/                    ║    │
│  ║   • Vue 3, Composition API                                       ║    │
│  ║   • Tailwind CSS                                                 ║    │
│  ║   • يقوم بالـ booking flow عبر 7 خطوات                            ║    │
│  ║   • يستدعي /api/v1/*                                           ║    │
│  ╚═══════════════════════════════════════════════════════════════════╝    │
│                                  ↓ HTTP + Sanctum                          │
│  ╔═══════════════════════════════════════════════════════════════════╗    │
│  ║ LAYER 2: BACKEND API (Laravel) — app/Http/                         ║    │
│  ║   • Controllers في Api/V1/Flight/                                 ║    │
│  ║   • Form Requests للـ validation                                  ║    │
│  ║   • Middlewares: CaptureFinancialPostingContext + Security        ║    │
│  ║   → يستدعي الـ Services                                           ║    │
│  ╚═══════════════════════════════════════════════════════════════════╝    │
│                                  ↓                                        │
│  ╔═══════════════════════════════════════════════════════════════════╗    │
│  ║ LAYER 3: BUSINESS LOGIC (Services) — app/Services/Flight/          ║    │
│  ║   • FlightBookingService (the orchestrator)                       ║    │
│  ║   • RefundService / ModificationService                           ║    │
│  ║   • FlightCarrierRechargeService / FlightSystemRechargeService    ║    │
│  ╚═══════════════════════════════════════════════════════════════════╝    │
│                                  ↓                                        │
│  ╔═══════════════════════════════════════════════════════════════════╗    │
│  ║ LAYER 4: CORE ACCOUNTING — app/Services/Finance/                  ║    │
│  ║   • TransactionService (الـ canonical posting)                    ║    │
│  ║   • PrepaidLedgerService (recharge + COGS)                        ║    │
│  ║   • LedgerClearingAccounts (resolvers)                            ║    │
│  ║   • 🛡️ LedgerBalanceMutationGuard (حماية الرصيد)                  ║    │
│  ╚═══════════════════════════════════════════════════════════════════╝    │
│                                  ↓                                        │
│  ╔═══════════════════════════════════════════════════════════════════╗    │
│  ║ LAYER 5: DATABASE (MySQL)                                         ║    │
│  ║   • accounts / transactions / account_entries (الـ GL)              ║    │
│  ║   • flight_bookings / flight_carriers / flight_systems / ...      ║    │
│  ║   • flight_payments / flight_refunds / flight_passengers / ...    ║    │
│  ╚═══════════════════════════════════════════════════════════════════╝    │
│                                                                            │
│  ╔═══════════════════════════════════════════════════════════════════╗    │
│  ║ PARALLEL LAYER: FILAMENT ADMIN — app/Filament/Admin/               ║    │
│  ║   • للموظفين / الـ admin                                          ║    │
│  ║   • يدير الـ entities + recharge actions + approvals              ║    │
│  ║   • نفس الـ backend services + forms                              ║    │
│  ║   • موجود بـ /admin/flight-carriers, /admin/flight-systems, إلخ  ║    │
│  ╚═══════════════════════════════════════════════════════════════════╝    │
│                                                                            │
└────────────────────────────────────────────────────────────────────────────┘
```

## 0.2 الـ Components الرئيسية في الـ Flight Module

### Frontend (Vue.js) — `resources/js/`

| الـ Component | الملف | الـ Function |
|---|---|---|
| **`FlightCreate.vue`** | `views/flights/FlightCreate.vue` | **Wizard حجز طيران بـ 7 خطوات** |
| `FlightEdit.vue` | `views/flights/FlightEdit.vue` | تعديل حجز موجود |
| `FlightIndex.vue` | `views/flights/FlightIndex.vue` | قائمة الحجوزات مع filters |
| `FlightShow.vue` | `views/flights/FlightShow.vue` | عرض تفاصيل حجز |
| `FlightDashboard.vue` | `views/flights/FlightDashboard.vue` | لوحة تحكم Vue |
| `FlightAirlineAccountsIndex.vue` | `views/flights/FlightAirlineAccountsIndex.vue` | 🟡 Legacy (AirlineAccount) |
| `FlightAirlineTransactions.vue` | `views/flights/FlightAirlineTransactions.vue` | 🟡 حركات AirlineAccount |
| `FlightTreasuryOverview.vue` | `views/flights/FlightTreasuryOverview.vue` | نظرة عامة على خزائن الطيران |
| `FlightCustomersIndex.vue` | `views/flights/FlightCustomersIndex.vue` | عملاء الطيران |
| `FlightSegmentForm.vue` | `components/flights/FlightSegmentForm.vue` | إدخال القطاع (GUC-CAI, إلخ) |
| `PassengerForm.vue` | `components/flights/PassengerForm.vue` | إدخال بيانات راكب |
| `BookingSummary.vue` | `components/flights/BookingSummary.vue` | ملخص الحجز في الـ sidebar |
| `RefundWizard.vue` | `components/flights/RefundWizard.vue` | wizard الاسترداد |
| `ModificationWizard.vue` | `components/flights/ModificationWizard.vue` | wizard التعديل |
| `PricingBox.vue` | `components/flights/PricingBox.vue` | عرض الأسعار (purchase/selling/profit) |
| `TreasuryCard.vue` | `components/flights/TreasuryCard.vue` | بطاقة رصيد |
| `AirportSearchInput.vue` | `components/flights/AirportSearchInput.vue` | البحث عن المطارات |

### Filament Admin — `app/Filament/Admin/`

| الـ Resource | الملف | الـ Function |
|---|---|---|
| **`FlightCarrierResource`** | `Resources/FlightCarriers/FlightCarrierResource.php` | CRUD لـ carriers + **action شحن رصيد** |
| **`FlightSystemResource`** | `Resources/FlightSystems/FlightSystemResource.php` | CRUD لـ systems + **action شحن رصيد** + Relation managers |
| **`FlightBookingResource`** | `Resources/FlightBookings/FlightBookingResource.php` | CRUD لـ bookings + **action طلب تعديل** |
| **`FlightSystemsBalancesPage`** | `Pages/FlightSystemsBalancesPage.php` | جدول أرصدة كل الأنظمة + bulk recharge |
| **`FlightDashboard`** | `Pages/FlightDashboard.php` | لوحة تحكم Filament |

### Backend API — `app/Http/Controllers/Api/V1/Flight/`

| الـ Controller | الـ Lines | الـ Function |
|---|---|---|
| **`FlightController`** | 336 | CRUD + prices + confirm + payments + cancel |
| `FlightCarrierController` | ~150 | carriers + balance + recharge |
| `FlightSystemController` | ~120 | systems CRUD |
| `FlightTreasuryController` | ~250 | treasury overview + transactions + system recharge |
| `FlightGroupController` | ~150 | groups + statement + pay-debt |
| `FlightDashboardController` | ~80 | KPIs |
| `AirportController` | ~200 | airports search/list |
| `RefundController` | ~180 | refund workflow |
| `ModificationController` | ~150 | ticket modification |
| `PassengerController` | ~120 | passenger directory |
| `FlightPassengerController` | ~120 | — |
| `AirlineAccountController` | ~300 | 🟡 Legacy (AirlineAccount) |
| `AviationController` | ~200 | 🟡 Legacy booking entry |

### Business Logic — `app/Services/Flight/`

| الـ Service | الـ Lines | الـ Function |
|---|---|---|
| **`FlightBookingService`** | **2297** ⭐ | الـ booking orchestrator — كل الـ lifecycle هنا |
| `RefundService` | 273 | refund request workflow |
| `ModificationService` | 160 | ticket modification state machine |
| **`FlightCarrierRechargeService`** | 175 | 🛡️ Phase 1 — recharge carrier مع retry + ID-asc locks |
| **`FlightSystemRechargeService`** | 138 | 🛡️ Phase 1 — recharge system |
| `AviationService` | ~200 | 🟡 Legacy booking |
| `AirlineAccountDebitService` | ~80 | 🟡 Legacy — debit من AirlineAccount |

### الـ Models — `app/Models/Flight/`

```
14 model:
├── FlightBooking (الأهم — فيه dual currency + sale_gl_transaction_id)
├── FlightCarrier (🛡️ Phase 1 protected)
├── FlightSystem (🛡️ Phase 1 protected)
├── AirlineAccount (🟡 Legacy — NOT protected)
├── FlightPassenger, FlightSegment, FlightPayment
├── FlightRefund, RefundRequest
├── AirlineCredit (رصيد دائن للعميل)
├── AirlineTransaction, FlightSystemTransaction
├── FlightGroup, FlightGroupTransaction
├── FlightTicket, TicketModification
```

## 0.3 ملخص: فين كل function بيشتغل؟

| الـ Function | الـ Primary | الـ Also via | الـ ملاحظات |
|---|---|---|---|
| **عرض قائمة bookings** | Vue `FlightIndex.vue` | Filament `FlightBookingResource` table | API: GET /v1/flight/bookings |
| **إنشاء booking** | Vue `FlightCreate.vue` | Filament `CreateFlightBooking` | API: POST /v1/flight/bookings → FlightBookingService::createBooking |
| **تعديل booking** | Vue `FlightEdit.vue` | Filament EditFlightBooking | PATCH /v1/flight/bookings/{id} |
| **تأكيد booking** | Vue button | Filament Edit | POST /v1/flight/bookings/{id}/confirm |
| **إضافة payment** | Vue wizard step 7 | Filament Edit | POST /v1/flight/bookings/{id}/payments |
| **إلغاء booking** | Vue refund step | Filament refund action | POST /v1/flight/bookings/{id}/cancel |
| **استرداد (refund)** | Vue `RefundWizard.vue` | Filament (currently via API only) | POST /v1/flight/refunds/{id}/process |
| **تعديل تذكرة** | Vue `ModificationWizard.vue` | Filament action → redirects to TicketModificationResource | POST /v1/flight/modifications/{id}/confirm |
| **شحن carrier balance** | ❌ Frontend only | ✅ Filament action | POST /v1/flight/carriers/{id}/recharge → FlightCarrierRechargeService |
| **شحن system balance** | ❌ Frontend only | ✅ Filament action OR Page | POST /v1/flight/treasury/systems/{id}/recharge → FlightSystemRechargeService |
| **عرض carrier balance** | ✅ Vue (read-only) | ✅ Filament table column | GET /v1/flight/carriers → reads `balance` |
| **عرض system balance** | ✅ Vue (read-only) | ✅ Filament + FlightSystemsBalancesPage | GET /v1/flight/treasury/systems |
| **إنشاء carrier جديد** | Vue (probably no) | ✅ Filament CreateFlightCarrier | POST /v1/flight/carriers |
| **مشاهدة passenger directory** | ✅ Vue `FlightCustomersIndex.vue` | ✅ API | GET /v1/flight/passengers |
| **تقارير الطيران** | ✅ Vue `FlightDetailedReport.vue` | ✅ Filament Widgets | API + Report Services |

---

# Scenario 1: إنشاء حجز طيران (End-to-End Booking)

> **السيناريو الأشمل** — بيشمل كل layer. الموظف/الـ agent بيفتح الـ Vue wizard → يحجز تذكرة → الحسابات بتترحل في الـ backend.

## 1.1 الـ Big Picture (Overview)

```
User opens FlightCreate.vue
    ↓
Completes 7-step wizard
    ↓
Submits via axios POST /api/v1/flight/bookings
    ↓
FlightController::store() validates with StoreFlightBookingRequest
    ↓
FlightBookingService::createBooking($data)  ← THE BRAIN (2297 lines)
    ↓
Within ONE DB::transaction:
    ├── Validate + compute prices
    ├── Generate booking_number (FLT-YYYYMMDD-XXXXXX)
    ├── FlightBooking::create()
    ├── Debit purchase pool:
    │   ├── if 'carrier' → debitFlightCarrier() → FlightCarrier::debit() + PrepaidLedgerService::consumeCogs()
    │   ├── if 'system'  → debitFlightSystem() → FlightSystem::debit() + PrepaidLedgerService::consumeCogs()
    │   └── if 'group'   → recordPurchaseFromGroup() → FlightGroupTransaction + recordJournalTransfer()
    ├── Create passengers (FlightPassenger::create × N)
    ├── Record sale on customer ledger → recordSaleToCustomer() → recordJournalTransfer()
    ├── Create flight tickets (FlightTicket::create × N)
    ├── Create segments (FlightSegment::create × M)
    └── Process initial payment → addPayment() → recordIncome() + TreasuryLedgerMirror::mirrorFlightInboundReceipt()
    ↓
Returns FlightBooking + full eager-loaded graph
    ↓
Vue shows success toast + redirects to /flights/{id}
    ↓
Filament table also shows the new row on /admin/flight-bookings
```

## 1.2 الـ Frontend Flow (Vue) — `FlightCreate.vue`

### الـ 7 Steps من الـ Wizard

**ملف:** [`resources/js/views/flights/FlightCreate.vue`](#)

الـ wizard بيتكون من **7 خطوات** (L38 — `currentStep / 7`):

| Step | الـ Label التقريبي | الـ Component |
|---|---|---|
| **1** | نوع الرحلة (One-way / Round-trip) | Select dropdown |
| **2** | العميل (Customer picker) | `CustomerSelect.vue` |
| **3** | شركات الطيران / النظام (Source pool) | `FlightSegmentForm.vue` |
| **4** | المسار والقطاعات (Segments) | `FlightSegmentForm.vue` × M |
| **5** | الركاب (Passengers) | `PassengerForm.vue` × N |
| **6** | التسعير (Pricing: purchase, selling, currency) | `PricingBox.vue` |
| **7** | الدفع والتأكيد (Payment + Confirm) | `BookingSummary.vue` + payment |

> **ملاحظات مهمة:**
> - الـ user بيقدر يرجع لأي خطوة سابقة (`goToStep(step)`)
> - كل خطوة ليها validation محلي قبل ما يسمح بـ progress
> - الـ progress circle في الـ hero بيدور `currentStep / 7`

### عند الـ Submit (الخطوة 7)

الـ Vue بيبعت **POST /api/v1/flight/bookings** بـ `data` payload فيه كل الحقول المجمعة من الـ wizard.

## 1.3 الـ API Layer (Validation)

### الـ Controller

**ملف:** [`app/Http/Controllers/Api/V1/Flight/FlightController.php:151-202`](#)

```php
public function store(StoreFlightBookingRequest $request): JsonResponse
{
    // Validation happens automatically via FormRequest
    $booking = $this->flightBookingService->createBooking($request->validated());
    
    // Eager-load + return
    return ApiResponse::success(data: $booking->fresh()->load([...]), ...);
}
```

### الـ Form Request

**ملف:** [`app/Http/Requests/Flight/StoreFlightBookingRequest.php`](#)

فيه validation لكل الـ fields المطلوبة من الـ wizard. الـ rules بتختلف لو الـ booking فيه `flight_system_id` vs `flight_carrier_id` vs `flight_group_id`.

### الـ Middleware Chain

كل request الـ `/api/v1/*` بيعدي على:
1. **`auth:sanctum`** — لازم user عامل login
2. **`active`** — لازم الـ user active
3. **`CaptureFinancialPostingContext`** — بيخزن الـ HTTP context (IP, route, etc.) للـ audit
4. **`RejectBannedFinancialBypassMarkers`** — بيرفض أي bypass markers ممنوعة (X-Allow-Direct-Ledger, direct_financial_write query param)

## 1.4 الـ Business Logic — `FlightBookingService::createBooking`

> **الـ method الأهم في كل الـ project.** 200 سطر من الـ logic المالي.

**ملف:** [`app/Services/Flight/FlightBookingService.php:210-411`](#)

### الـ Steps بالتفصيل

#### Step 1: تطبيع الـ Payload
`prepareFlightBookingPayload($data)` [L427]:
- يحول IATA codes لـ uppercase
- ينظف الـ segments array
- يحسب الـ settlement info (`currency_used`, `exchange_rate_used`)

#### Step 2: حساب الأسعار [L218-233]
```php
$purchasePriceEGP = ... // بتحسب من الـ purchase_price + exchange rate
$sellingPriceEGP  = ... // بتحسب من الـ selling_price
$profit           = $sellingPriceEGP - $purchasePriceEGP
```

> **⚠️ ملاحظة:** في الـ booking في الـ `FALLBACK_EGP_PER_UNIT` constants (L49-55) لو الـ `Currency` row مش موجود.

#### Step 3: تحديد مصدر الخصم [L235]
`resolvePurchaseBalanceSource($data)` [L661]:
- لو فيه `flight_carrier_id` → `'carrier'`
- لو فيه `flight_system_id` (بـ system مختلف) → `'system'`
- لو فيه `flight_group_id` → `'group'`
- throws لو مفيش أي واحد منهم

#### Step 4: Snapshot الـ Settlement [L236]
`persistedSettlementSnapshot()` [L604]: يحفظ الـ currency_used, exchange_rate_used على الـ booking row نفسه عشان الـ refund بعدين يستخدم نفس الـ rate.

#### Step 5: توليد رقم الحجز [L416]
```php
public function generateBookingNumber(): string {
    return 'FLT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
}
```

#### Step 6: إنشاء الـ Booking Row [L248]
```php
$booking = FlightBooking::create([
    'booking_reference' => ...,
    'booking_number'   => $this->generateBookingNumber(),
    'status'           => $this->initialBookingStatus($data),  // PENDING لو مفيش PNR, CONFIRMED لو فيه
    'customer_id'      => $data['customer_id'],
    'employee_id'      => $data['employee_id'] ?? null,
    'airline'          => $data['airline'],
    'origin'           => $data['origin'],
    'destination'      => $data['destination'],
    'departure_date'   => ...,
    'purchase_price'   => ...,
    'selling_price'    => ...,
    'profit'           => ...,
    'currency'         => $data['currency'],
    'purchase_balance_source' => $this->resolvePurchaseBalanceSource($data),
    'flight_carrier_id' => $data['flight_carrier_id'] ?? null,
    'flight_system_id'  => $data['flight_system_id'] ?? null,
    'flight_group_id'   => $data['flight_group_id'] ?? null,
]);
```

#### Step 7: Debit الـ Purchase Pool (الـ Critical Step)

**الـ 3 احتمالات:**

##### Option A: لو `purchase_balance_source === 'carrier'`

**`debitFlightCarrier()`** [`L801-857`](#):

```php
protected function debitFlightCarrier(
    FlightBooking $booking,
    int $carrierId,
    float $purchasePriceEGP,
    string $currency,
    ?float $purchasePriceForeign,
    int $userId,
    ?float $lockedEgpPerBalanceUnit = null
): void {
    $carrier = FlightCarrier::lockForUpdate()->findOrFail($carrierId);
    
    // 1) حساب المبلغ بالعملة بتاعت الـ carrier (ممكن foreign currency)
    $amountInBalanceCurrency = $this->purchaseAmountInBalanceCurrency(
        balanceCurrency: $carrier->currency,
        bookingCurrency: $currency,
        purchasePriceEGP: $purchasePriceEGP,
        purchasePriceForeign: $purchasePriceForeign,
        lockedEgpPerBalanceUnit: $lockedEgpPerBalanceUnit,
    );
    
    // 2) FlightCarrier::debit() — model method آمن (بيستخدم mutateBalanceInternal)
    $carrier->debit(
        amount: $amountInBalanceCurrency,
        bookingId: $booking->id,
        userId: $userId
    );
    // ↑ الـ method ده بيثير RuntimeException لو balance < amount
    
    // 3) 🛡️ الـ GL Guard — PrepaidLedgerService::consumeCogs()
    $this->prepaidLedgerService->consumeCogs(
        prepaidKey: 'flight_carrier',
        module: TransactionModule::Flight,
        amount: $purchasePriceEGP,
        notes: "حجز طيران #{$booking->booking_number} — خصم تكلفة شراء",
        relatedType: FlightBooking::class,
        relatedId: $booking->id,
    );
    // ↑ ده بيأثر على:
    //    - flight_carriers[carrierId].balance -= amount
    //    - accounts[prepaid_carrier].balance -= purchasePriceEGP
    //    - accounts[expense_contra].balance += purchasePriceEGP
    // ويكتب Transaction + AccountEntry × 2
}
```

##### Option B: لو `purchase_balance_source === 'system'`

**`debitFlightSystem()`** [`L859-908`](#) — نفس النمط بالظبط بس مع `FlightSystem` بدل `FlightCarrier`.

##### Option C: لو `purchase_balance_source === 'group'`

**`recordPurchaseFromGroup()`** [`L2144+`](#):
- ينشئ `FlightGroupTransaction` row (debt)
- يستدعي `TransactionService::recordJournalTransfer()` من group account → expense contra

#### Step 8: إنشاء الركاب [L357]

```php
foreach ($data['passengers'] as $passengerData) {
    FlightPassenger::create([
        'flight_booking_id' => $booking->id,
        'full_name' => $passengerData['full_name'],
        'passport_number' => $passengerData['passport_number'],
        'passenger_type' => $passengerData['type'] ?? PassengerType::Adult,
        // ...
    ]);
}
```

#### Step 9: تسجيل البيع على حساب العميل [L361]

**`recordSaleToCustomer()`** [`L2106+`](#):

```php
protected function recordSaleToCustomer(
    FlightBooking $booking,
    int $customerId,
    float $sellingPrice,
    int $userId,
    array $passengers = []
): void {
    $customerAccount = $this->ensureCustomerAccount($customerId);  // create if missing
    
    // Posting: clearing → customer account (debt)
    $tx = $this->transactionService->recordJournalTransfer([
        'amount' => $sellingPrice,
        'from_account_id' => $this->ensureFlightIncomeClearingAccount($userId),  // "إيراد طيران - clearing"
        'to_account_id' => $customerAccount->id,
        'module' => 'flight',
        'related_type' => FlightBooking::class,
        'related_id' => $booking->id,
        'notes' => "بيع تذكرة طيران — حجز #{$booking->booking_number}",
        'created_by' => $userId,
    ]);
    
    $booking->sale_gl_transaction_id = $tx->id;
    $booking->save();
}
```

> **`ensureCustomerAccount()`** [L2066]: لو العميل مفيش له Account، بينشئ له واحد جديد في الـ GL تلقائياً.

> **`ensureFlightIncomeClearingAccount()`** [L994]: لو الحساب "إيراد طيران - clearing" مش موجود، بينشئه باستخدام `LedgerBalanceMutationGuard`.

#### Step 10: إنشاء Flight Tickets [L370]

`createFlightTickets()` [L1123]:
- ينشئ `FlightTicket` row لكل passenger + لكل segment (لو round-trip)
- الـ ticket number بيتبني من الـ booking_number + suffix (`FLT-...-T1`, `-T2`, إلخ)

#### Step 11: إنشاء Segments [L373]

`createSegments()` [L1193]:
- ينشئ `FlightSegment` rows من الـ segments array
- normalized departure_at + arrival_at

#### Step 12: معالجة الـ Payment الأولي [L378]

**`addPayment()`** [`L1546+`](#):

```php
public function addPayment(FlightBooking $booking, array $data): FlightPayment {
    // validation: total paid ≤ selling price
    $payment = FlightPayment::create([
        'flight_booking_id' => $booking->id,
        'amount' => $data['amount'],
        'currency' => $data['currency'],
        'payment_method' => $data['payment_method'],
        'treasury_id' => $data['treasury_id'] ?? null,  // لو كاش
        // ...
    ]);
    
    // 🛡️ posting: contra_account (income clearing) → cash account
    $tx = $this->transactionService->recordIncome([
        'amount' => $data['amount'],
        'to_account_id' => $data['to_account_id'],  // الخزينة / البنك / المحفظة
        'contra_account_id' => $this->ensureFlightIncomeClearingAccount($userId),
        'module' => 'flight',
        'related_type' => FlightPayment::class,
        'related_id' => $payment->id,
        'notes' => "دفعة على حجز #{$booking->booking_number}",
        'created_by' => $userId,
    ]);
    
    // 🪞 Mirror on treasury side (avoids double-posting)
    TreasuryLedgerMirror::mirrorFlightInboundReceipt($payment, $tx);
    
    return $payment;
}
```

#### Step 13: Eager-load + Return [L398]
```php
return $booking->fresh()->load(['customer', 'passengers', 'segments', 'tickets', 'payments', 'flightCarrier', 'flightSystem', 'flightGroup', 'employee']);
```

## 1.5 ازاي الحسابات بترحل (الـ GL Entries بالتفصيل)

> **القيد الكامل للحجز** — كل الحسابات اللي بتتحرك:

### الحالة الافتراضية: Booking بـ 6,000 EGP من العربية (carrier) بدفع كاش 6,000 EGP

**Inputs:**
- `customer_id = 42` (عميل "أحمد")
- `flight_carrier_id = 1` (العربية)
- `purchase_price = 5500 EGP`, `selling_price = 6000 EGP`
- `currency = 'EGP'`, `passenger_count = 1`
- `paid_by = 'cashbox_main'` (كاش)

**النتيجة: 4 قيود متوازنة (3 transactions، 6 account entries)**

| # | الـ Transaction | From Account | To Account | Amount | المدين | الدائن |
|---|---|---|---|---:|---|---|
| **1** | `consumeCogs('flight_carrier', ...)` | رصيد مسبق — ناقلو الطيران (account 24) | إقفال تكاليف طيران | 5,500 EGP | 5,500 | 0 (مدين) |
| | | | | | 0 | 5,500 (دائن) |
| | | | | | **+ ينشئ AirlineTransaction** (carrier.balance -= 5,500) |
| **2** | `recordSaleToCustomer(...)` | إيراد طيران - clearing (account...) | customer[42].account | 6,000 EGP | 6,000 | 0 |
| | | | | | 0 | 6,000 |
| **3** | `addPayment(...)` `recordIncome(...)` | إيراد طيران - clearing (نفس الحساب) | خزينة الكاش (cashbox_main) | 6,000 EGP | 6,000 | 0 |
| | | | | | 0 | 6,000 |

### الـ Tables اللي بتتأثر

**Models created:**
- `FlightBooking` (1 row، جديد)
- `FlightPassenger` (1 row)
- `FlightSegment` (1 row)
- `FlightTicket` (1 row)
- `FlightPayment` (1 row)
- `Transaction` (3 rows)
- `AccountEntry` (6 rows)
- `AirlineTransaction` (1 row)
- `airline_transactions` debit entry

**Balance changes:**
- `flight_carriers[1].balance` -= 5,500
- `accounts[24].balance` (رصيد مسبق — ناقلو) -= 5,500
- `accounts[expense_contra].balance` += 5,500
- `accounts[income_clearing].balance` += 6,000 (من البيع) → -6,000 (من الدفع) = **0 net** ✅
- `customers[42].account.balance` += 6,000 (sale debt)
- `accounts[cashbox_main].balance` += 6,000
- `treasury_transactions` (mirror): receipt +6,000 to cashbox_main

> **🔑 ملاحظة:** الـ income clearing account بيشتغل كـ bridge — إجمالي الـ entries عليه = 0 (مدين بنفس الدائن). ده معمارياً صحيح.

## 1.6 الـ Output لـ Vue

الـ API بيرجع JSON فيه الـ booking + كل الـ relations. الـ Vue بيعرض:

```
✓ Success toast: "تم إنشاء الحجز بنجاح"
✓ Booking number: FLT-20260710-ABC123
✓ QR code أو رابط التذكرة
✓ Redirect to /flights/{id}
```

وكمان في الـ Filament:
- الـ booking الجديد بيظهر في `FlightBookingResource` table → `/admin/flight-bookings`

## 1.7 الـ Failure Scenarios

| الفشل | الـ Where | الـ Response |
|---|---|---|
| **رصيد الـ carrier أقل من المطلوب** | `FlightCarrier::debit()` [model L174] | RuntimeException: "رصيد غير كافٍ" |
| **رصيد الـ prepaid أقل من المطلوب** | `PrepaidLedgerService::consumeCogs()` [L149] | InsufficientBalanceException: "رصيد مسبق غير كافٍ" |
| **Currency mismatch** | `recordJournalTransfer()` [L531] | ValidationException: "رصيد الحساب غير كافٍ" |
| **DB lock failure (deadlock)** | `recordJournalTransfer()` | PDOException (1213) → reraises |
| **Customer not found** | `FlightBooking::create()` validation | 422 error |

---

# Scenario 2: شحن رصيد ناقل / نظام (Recharge)

> **السيناريو الأكثر أهمية بعد الـ booking** — ده اللي عملناه في الـ Phase 1 للحماية. لو عايز تفهم الـ `LedgerBalanceMutationGuard` و الـ ID-asc locks، هذا الـ scenario.

## 2.1 الـ Big Picture

```
Admin يفتح Filament → FlightCarriers page
    ↓
يضغط "شحن رصيد" على carrier معيّن
    ↓
Modal يفتح فيه:
  - قائمة الحسابات المتاحة (نفس العملة)
  - amount
  - notes (اختياري)
    ↓
Submit → FlightCarrierRechargeService::rechargeFromAccount()
    ↓
في retry loop (max 3 attempts) للأخطاء 1020/1213:
    │
    ├── executeRechargeTransaction() داخل DB::transaction:
    │   ├── Currency match check (فشل سريع)
    │   ├── 🛡️ ID-asc locks: carrier + source + prepaid GL
    │   ├── Re-fetch locked entities
    │   ├── PrepaidLedgerService::recharge()  ← القيد المحاسبي
    │   ├── FlightCarrier::credit()          ← زيادة الرصيد التشغيلي
    │   └── Log + return array
    ↓
Notification: success or failure with error message
    ↓
🎉 Filament auto-refreshes the table
```

## 2.2 الواجهات الممكنة للشحن

### **الواجهة 1: Filament FlightCarrierResource Table Action**

**ملف:** [`app/Filament/Admin/Resources/FlightCarriers/FlightCarrierResource.php:246-301`](#)

```
الـ Admin يفتح: /admin/flight-carriers
يختار carrier → Action menu → "شحن رصيد"
modal يفتح بـ 3 حقول:
  - from_account_id (Select — حسابات module_type='flights' ونفس العملة)
  - amount (Number — suffix = العملة)
  - notes (اختياري)
submit → 
```

**الـ Action implementation** [L277-301]:
```php
->action(function (array $data, FlightCarrier $record): void {
    $account = Account::findOrFail($data['from_account_id']);
    $amount = (float) $data['amount'];
    $notes = ...;
    
    try {
        app(FlightCarrierRechargeService::class)->rechargeFromAccount($record, $account, $amount, $notes);
        Notification::make()->title('تم شحن رصيد الناقل بنجاح')->success()->send();
    } catch (\Throwable $e) {
        Notification::make()->title('تعذر تنفيذ الشحن')->body($e->getMessage())->danger()->send();
    }
});
```

### **الواجهة 2: Filament FlightSystemsBalancesPage (الـ Bulk UI)**

**ملف:** [`app/Filament/Admin/Pages/FlightSystemsBalancesPage.php:94-183`](#)

```
الـ Admin يفتح: /admin/flight-system-balances
ده صفحة مخصصة بجدول كل الـ systems + أرصدتها
في الـ header: button "شحن رصيد نظام"
modal:
  - flight_system_id (Select — كل systems الـ active)
  - from_account_id (Select — يعتمد على system.currency)
  - amount (suffix = currency)
  - notes
submit →
```

> **الفرق عن الـ FlightCarrier:** الـSystemsBalancesPage بيسمح بـ recharge سريع من أي مكان — مهم للـ operations.

### **الواجهة 3: REST API** (لو Frontend Vue أو Postman)

**ملف:** [`routes/api.php`](#) + `FlightCarrierController::recharge()`

```bash
POST /api/v1/flight/carriers/{id}/recharge
{
    "from_account_id": 7,
    "amount": 50000,
    "notes": "Test recharge"
}
```

### **ما فيش Vue recharge UI** ❌

الـ Vue عنده read-only على الـ balance، مش بيشحن. الشحن بيكون من Filament بس.

## 2.3 الـ Business Logic بالتفصيل — `FlightCarrierRechargeService::rechargeFromAccount`

**ملف:** [`app/Services/Flight/FlightCarrierRechargeService.php:38-173`](#)

### Phase A: الـ Retry Loop (الـ Outer Layer) [L44-80]

```php
public function rechargeFromAccount(FlightCarrier $carrier, Account $source, float $amount, ?string $notes = null): array {
    $maxAttempts = 3;
    $attempt = 0;
    
    while (true) {
        $attempt++;
        try {
            return $this->executeRechargeTransaction($carrier, $source, $amount, $notes);
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            
            // Retryable errors:
            // 1020 — snapshot conflict
            // 1213 — deadlock
            $isRetryable = str_contains($msg, '1020') || 
                           str_contains($msg, 'Record has changed') ||
                           str_contains($msg, '1213') ||
                           str_contains($msg, 'Deadlock');
            
            if ($isRetryable && $attempt < $maxAttempts) {
                Log::warning('Recharge race conflict', [...]);
                usleep(50000 * $attempt);  // 50ms, 100ms, 150ms backoff
                continue;
            }
            
            throw $e;
        }
    }
}
```

### Phase B: `executeRechargeTransaction()` [L85-173]

#### Step B1: Currency Match Check [L95-101]

```php
if (strtoupper($source->currency) !== strtoupper($carrier->currency)) {
    throw new \RuntimeException(
        "تضارب في العملة: الحساب المصدر بعملة ({$source->currency}) "
        . "لا يتطابق مع عملة الناقل ({$carrier->currency}). "
        . 'يرجى اختيار حساب بنفس عملة الناقل.'
    );
}
```

> **⚠️ الـ FX مش بيتم هنا.** لو محتاج تشحن بـ currency مختلف → استخدم `AccountRechargeService` بدلاً منه (بس ده بيشحن الـ GL فقط، مش الـ carrier balance).

#### Step B2: 🛡️ ID-Asc Locks (الـ Defense-in-Depth #1) [L108-125]

```php
$prepaidId = $this->ledgerClearingAccounts->prepaidAccountId('flight_carrier');

$idsToLock = [
    'carrier' => $carrier->id,
    'source'  => $source->id,
    'prepaid' => $prepaidId,
];
asort($idsToLock);  // Sort ascending by ID

foreach ($idsToLock as $type => $id) {
    if ($type === 'carrier') {
        FlightCarrier::whereKey($id)->lockForUpdate()->firstOrFail();
    } else {
        Account::whereKey($id)->lockForUpdate()->firstOrFail();
    }
}
```

> **🔑 الفلسفة:** في MySQL InnoDB مع REPEATABLE READ، لو 2 transactions بياخدوا locks على نفس الـ rows بترتيب مختلف → deadlock. الحل: دايماً نفس الترتيب (ID ascending). ده مدروس في الـ Phase 1.

#### Step B3: Re-fetch الـ Locked Entities [L128-129]

```php
$carrier = FlightCarrier::whereKey($carrier->id)->firstOrFail();  // fresh state
$source  = Account::whereKey($source->id)->firstOrFail();
```

#### Step B4: وصف القيد [L134-137]

```php
$desc = sprintf('شحن رصيد ناقل %s (%s) من حساب: %s', 
    $carrier->name, $carrier->code, $source->name);
if ($notes) $desc .= ' — ' . $notes;
```

#### Step B5: 🛡️ PrepaidLedgerService::recharge() [L142-150]

**ده القيد المحاسبي الفعلي:**

```php
$this->prepaidLedgerService->recharge(
    prepaidKey: 'flight_carrier',
    source: $source,
    amount: $amount,
    module: TransactionModule::Flight,
    notes: $desc,
    relatedType: FlightCarrier::class,
    relatedId: $carrier->id,
);
```

**`PrepaidLedgerService::recharge()`** [`app/Services/Finance/PrepaidLedgerService.php:29-100`](#):

```php
public function recharge(
    string $prepaidKey,
    Account $source,
    float $amount,
    TransactionModule $module,
    ?string $notes = null,
    ?string $relatedType = null,
    ?int $relatedId = null
): Transaction {
    // Validation
    if ($amount <= 0) throw new \InvalidArgumentException('مبلغ الشحن يجب أن يكون أكبر من صفر.');
    
    $prepaidId = $this->clearingAccounts->prepaidAccountId($prepaidKey);  // account 24 for flight_carrier
    if ($prepaidId === $source->id) throw new \InvalidArgumentException('حساب المصدر يطابق حساب الرصيد المسبق.');
    
    // Cross-currency handling
    $prepaidAccount = Account::find($prepaidId);
    $sameCurrency = $source->currency === $prepaidAccount->currency;
    
    $transferData = [
        'amount' => $amount,
        'from_account_id' => $source->id,
        'to_account_id' => $prepaidId,
        'module' => $module->value,
        'related_type' => $relatedType,
        'related_id' => $relatedId,
        'notes' => ($notes ?? 'شحن رصيد مسبق') . ' [رصيد مسبق]',
        'created_by' => Auth::id() ?? 1,
    ];
    
    if (! $sameCurrency) {
        try {
            $conversion = $this->currencyService->convert($amount, $source->currency, $prepaidAccount->currency);
            $transferData['converted_amount'] = $conversion['to_amount'];
            $transferData['exchange_rate'] = $conversion['rate'];
        } catch (\Throwable $e) {
            // Fallback to 1:1 — Log warning
        }
    }
    
    $transaction = $this->transactionService->recordJournalTransfer($transferData);
    
    return $transaction;
}
```

> **النتيجة:** قيد متوازن واحد:
> - **مدين**: `source` (البنك/المحفظة/الخزينة) بـ amount
> - **دائن**: `prepaid_carrier_account` بـ amount (أو converted_amount لو currencies differ)

#### Step B6: زيادة رصيد الناقل — FlightCarrier::credit() [L155]

```php
$carrierTx = $carrier->credit($amount, $desc, Auth::id() ?: 1, null);
```

**`FlightCarrier::credit()`** [`app/Models/Flight/FlightCarrier.php:198-230+`](#):

```php
public function credit(float $amount, string $description, int $userId = null, ?int $bookingId = null): AirlineTransaction {
    return $this->mutateBalanceInternal(
        delta: $amount,  // positive = credit
        mutator: function (FlightCarrier $carrier) use ($description, $userId, $bookingId) {
            return AirlineTransaction::create([
                'flight_carrier_id' => $carrier->id,
                'user_id' => $userId,
                'flight_booking_id' => $bookingId,
                'description' => $description,
                'debit' => 0,
                'credit' => $amount,
                'balance_after' => $carrier->balance,
                'transaction_date' => now(),
            ]);
        }
    );
}

protected function mutateBalanceInternal(float $delta, callable $mutator): self {
    $previous = static::$internalBalanceUpdate;
    static::$internalBalanceUpdate = true;  // ← THE BYPASS FLAG
    try {
        $this->balance += $delta;
        $result = $mutator($this);
        $this->save();
        return $result;
    } finally {
        static::$internalBalanceUpdate = $previous;
    }
}
```

> **🔑 الـ `mutateBalanceInternal` بيثبت `static::$internalBalanceUpdate = true`** قبل الـ `save()` — كده الـ Observer في `booted()` بيشوف الـ flag ويسمح بالتعديل. لو حد بره الـ methods عمل `->update(['balance' => ...])` → الـ flag = false → `RuntimeException`.

#### Step B7: Log + Return [L157-172]

```php
Log::info('Flight carrier recharged from account', [
    'flight_carrier_id' => $carrier->id,
    'from_account_id' => $source->id,
    'amount' => $amount,
    'currency' => $carrier->currency,
    'airline_transaction_id' => $carrierTx->id,
    'user_id' => Auth::id(),
]);

return [
    'carrier' => $carrier->fresh(),
    'source_account' => $source->fresh(),
    'airline_transaction' => $carrierTx,
];
```

## 2.4 الـ Flavor الخاص بـ FlightSystemsBalancesPage

**[`app/Filament/Admin/Pages/FlightSystemsBalancesPage.php:94-183`](#)** — نفس الـ FlightCarrierRechargeService لكن مع:
1. الـ modal فيه Select للنظام (لازم تختار system الأول)
2. الـ account dropdown بيعتمد على system.currency (live)
3. الـ success message بيزود "للنظام X"
4. بعد النجاح بيستدعي `$this->refreshSystems()` — الـ Live component بيرجّع البيانات

## 2.5 الـ Ledger في Recharge

### الـ Tables اللي بتتأثر في Recharge بـ 50,000 EGP من بنك العربي (account 5) للعربية (carrier 1)

**Inputs:**
- `carrier_id = 1` (العربية، EGP)
- `source_account_id = 5` (بنك العربي، EGP)
- `amount = 50000`

**القيود المُنشأة:**

| # | Transaction | From | To | Amount |
|---|---|---|---|---:|
| **1** | `journal transfer` (من recharge) | bank_arab (acc 5) | prepaid_flight_carrier (acc 24) | 50,000 EGP |
| | ↳ AccountEntry: | acc 5 | | debit 50,000, balance_after -= |
| | ↳ AccountEntry: | acc 24 | | credit 50,000, balance_after += |
| | + AirlineTransaction: | carrier[1] | | credit 50,000, balance_after += |
| **2** | (Auto-created في mirrored treasury) | | | — |

**الـ Balance Changes:**
- `bank_arab_account.balance` -= 50,000
- `prepaid_flight_carrier_account.balance` += 50,000
- `flight_carriers[1].balance` += 50,000

## 2.6 الـ Edge Cases في Recharge

| الـ Case | الـ Behavior |
|---|---|
| **Currency mismatch** | RuntimeException: "تضارب في العملة" — الـ Filament Notification يعرضه |
| **Amount ≤ 0** | Validation قبل ما يدخل الـ service: `minValue(0.01)` على الـ form |
| **Source account inactive** | مش بيظهر في الـ dropdown (filter: `where('is_active', true)`) |
| **Source account not flights-module** | مش بيظهر (filter: `where('module_type', 'flights')`) |
| **Deadlock (1213)** | Retry تلقائي 3 مرات، لو فضل fail → Exception |
| **Snapshot conflict (1020)** | نفس المعاملة |
| **في RechargeSystem: currency اختلف** | بتتدعم — `PrepaidLedgerService::recharge` بيحول currency |
| **في RechargeCarrier: currency اختلف** | ❌ throws — لازم نفس العملة بالظبط |

---

# Scenario 3: استرداد إلى خزينة الوكالة (Refund to Treasury)

> **السيناريو الأكثر تعقيداً** — بيشمل treasury + GL + carrier/system balance.

## 3.1 الـ Big Picture

```
Booking مر على الحياة. دلوقتي العميل عايز يلغي.
    ↓
الـ Refund يبتدئ إمّا من:
    - Vue FlightShow.vue → button "طلب استرداد" → RefundWizard.vue
    - Filament (مش متاح حالياً كـ dedicated button)
    - API call مباشر لـ RefundController
    ↓
POST /api/v1/flight/refunds  → RefundController::store()
    ↓
RefundService::createRefundRequest($data, $userId)
    ↓
حساب الـ refund_amount + currency_difference
RefundRequest::create({ status: 'pending' })
    ↓
بعدها (المراجعة):
POST /api/v1/flight/refunds/{id}/process  → RefundController::process()
    ↓
RefundService::processRefundRequest($id, $userId)
    ↓
Within DB::transaction:
    ├── Idempotency check (lock + status check)
    ├── Lock FlightBooking row
    ├── Branch by destination ('agency_treasury' vs 'airline_credit')
    │
    ├─── if 'agency_treasury':
    │   ├── Lock Treasury row
    │   ├── Validate currency match (treasury.currency == refund_currency)
    │   ├── Treasury::credit(amount) (مش الـ BalanceMutationGuard — legacy)
    │   ├── TreasuryTransaction::create({ receipt })
    │   ├── Resolve destination Account (Cashbox fallback → create if missing)
    │   ├── Determine source pool (carrier or system)
    │   ├── FlightCarrier::debit or FlightSystem::debit (الـ EGP adjustment)
    │   ├── LedgerClearingAccounts::prepaidAccountId($prepaidKey)
    │   └── TransactionService::recordJournalTransfer(prepaid → cashbox_account)
    │
    └─── Update booking.status (REFUNDED or PARTIALLY_REFUNDED)
        Mark RefundRequest.status = 'processed'
```

## 3.2 الـ Frontend Flow (Vue)

### الـ Component الرئيسي

**`resources/js/components/flights/RefundWizard.vue`** — wizard الـ refund.

### الـ Steps التقريبية

| Step | الـ Label | الـ Field |
|---|---|---|
| 1 | اختيار الحجز | Select booking |
| 2 | نوع الاسترداد | Radio: treasury OR airline_credit |
| 3 | تفاصيل المبلغ | refund_amount, cancellation_fee, currency, exchange_rate |
| 4 | الخزينة (لو treasury) | Select treasury_id |
| 5 | Confirmation | notes, submit |

### الـ Submit → API

```
POST /api/v1/flight/refunds
{
    "flight_booking_id": 123,
    "destination": "agency_treasury",
    "treasury_id": 5,
    "refund_currency": "EGP",
    "refund_exchange_rate": 1.0,
    "cancellation_fee": 200,
    "notes": "Customer requested refund"
}
```

## 3.3 الـ Business Logic — `RefundService::createRefundRequest` [L29-78]

**ملف:** [`app/Services/Flight/RefundService.php:29-78`](#)

```php
public function createRefundRequest(array $data, int $userId): RefundRequest {
    $booking = FlightBooking::findOrFail($data['flight_booking_id']);
    
    // Validate not already fully refunded
    if ($booking->status === FlightBookingStatus::REFUNDED) {
        throw new \RuntimeException('هذا الحجز تم استرداده بالكامل مسبقاً...');
    }
    
    // Read booking's original currency + exchange rate
    $originalCurrency = $booking->original_currency ?: $booking->currency ?: 'EGP';
    $originalAmount = $booking->original_amount ?: $booking->selling_price;
    $bookingExchangeRate = $booking->booking_exchange_rate ?: $booking->exchange_rate ?: 1.0;
    
    // Compute refund amount
    $cancellationFee = $data['cancellation_fee'] ?? 0;
    $refundAmount = $originalAmount - $cancellationFee;
    if ($refundAmount < 0) throw new \InvalidArgumentException('...');
    
    // Compute currency difference
    $refundCurrency = $data['refund_currency'] ?? $originalCurrency;
    $refundExchangeRate = $data['refund_exchange_rate'] ?? 1.0;
    $baseCurrencyRefund = $refundAmount * $refundExchangeRate;
    $currencyDifference = $baseCurrencyRefund - ($refundAmount * $bookingExchangeRate);
    
    $destination = $data['destination'] ?? 'agency_treasury';
    $refundType = $data['refund_type'] ?? ($destination === 'airline_credit' ? 'airline_credit_only' : 'cash_to_agency');
    
    return RefundRequest::create([
        'flight_booking_id' => $booking->id,
        'refund_type' => $refundType,
        'original_currency' => $originalCurrency,
        'original_amount' => $originalAmount,
        'cancellation_fee' => $cancellationFee,
        'refund_amount' => $refundAmount,
        'refund_currency' => $refundCurrency,
        'refund_exchange_rate' => $refundExchangeRate,
        'base_currency_refund' => $baseCurrencyRefund,
        'currency_difference' => $currencyDifference,
        'destination' => $destination,
        'treasury_id' => $destination === 'agency_treasury' ? ($data['treasury_id'] ?? null) : null,
        'airline_credit_balance' => $destination === 'airline_credit' ? $refundAmount : null,
        'status' => 'pending',
        'notes' => $data['notes'] ?? null,
        'created_by' => $userId,
    ]);
}
```

> **النتيجة:** `RefundRequest` row بـ status=`pending`. الـ refund لسه ما اتنفذش.

## 3.4 `RefundService::processRefundRequest` [L83-272] — الـ Big One

**ملف:** [`app/Services/Flight/RefundService.php:83-272`](#)

### Phase 1: الـ Setup [L85-93]

```php
return DB::transaction(function () use ($refundRequestId, $userId) {
    // Lock RefundRequest row (idempotency)
    $refundRequest = RefundRequest::lockForUpdate()->findOrFail($refundRequestId);
    
    // Idempotency: bail if already processed
    if ($refundRequest->status === 'processed') {
        return $refundRequest;
    }
    
    // Lock FlightBooking
    $booking = FlightBooking::lockForUpdate()->findOrFail($refundRequest->flight_booking_id);
    
    // ...
});
```

### Phase 2: Branch on Destination [L95-258]

**لو 'agency_treasury'** [L119-258]:

#### Step A: Validate Treasury [L121-138]

```php
$treasury = Treasury::lockForUpdate()->find($refundRequest->treasury_id);

if (! $treasury) throw new \RuntimeException('خزينة الوجهة المحددة غير موجودة.');
if (! $treasury->is_active) throw new \RuntimeException("الخزينة المحددة ({$treasury->name}) غير نشطة حالياً.");

// Currency match required
if (strtoupper($treasury->currency) !== strtoupper($refundRequest->refund_currency)) {
    throw new \RuntimeException(
        "تضارب في العملة: لا يمكن إيداع استرجاع بعملة ({$refundRequest->refund_currency}) "
        . "في خزينة تعمل بعملة ({$treasury->currency})..."
    );
}
```

#### Step B: Credit the Treasury [L140-158]

```php
$treasury->credit((float) $refundRequest->refund_amount);  // ← ⚠️ بدون Guard!

$treasury->transactions()->create([
    'transaction_type' => 'receipt',
    'amount' => $refundRequest->refund_amount,
    'currency' => $refundRequest->refund_currency,
    'balance_before' => $treasury->current_balance - $refundRequest->refund_amount,
    'balance_after' => $treasury->current_balance,
    'reason' => 'استرجاع تذكرة طيران',
    'flight_booking_id' => $booking->id,
    'refund_request_id' => $refundRequest->id,
    'type' => 'credit',
    'exchange_rate' => $refundRequest->refund_exchange_rate,
    'base_amount' => $refundRequest->base_currency_refund,
    'description' => "إيداع استرجاع تذكرة #{$booking->booking_number}" . ($refundRequest->currency_difference != 0 ? " (فروقات عملة: {$refundRequest->currency_difference})" : ''),
    'agent_name' => $booking->agent_name ?: 'System',
]);
```

> **⚠️ ملاحظة:** `Treasury::credit()` بيشتغل **مباشرة على balance بدون `LedgerBalanceMutationGuard`** — ده legacy path. بيشتغل عشان الـ Treasury model هو اللي يعمل الـ transaction لنفسه. لكن في نفس الوقت مفيش AccountEntry في الـ GL العام هنا. (ده فرق مهم عن باقي الـ services.)

#### Step C: Resolve Destination Account [L166-189]

```php
$account = Account::where('name', $treasury->name)->first();
if (! $account) {
    $account = Account::where('type', 'cashbox')
        ->where('currency', $refundRequest->refund_currency)
        ->whereIn('module_type', ['flights', 'tourism'])
        ->first();
}
if (! $account) {
    $account = Account::getModuleVault('flights');  // ← الباك أب
}
if (! $account) {
    // Create new Cashbox Account if none exists
    $account = Account::create([
        'name' => $treasury->name,
        'type' => AccountType::Cashbox,
        'currency' => $refundRequest->refund_currency,
        'is_active' => true,
        'owner_type' => 'office',
        'module_type' => 'flights',
        'created_by' => $userId,
    ]);
}
```

> **⚠️ ملاحظة:** مفيش `LedgerBalanceMutationGuard` حوالين الـ Account::create() — الـ creation مش بيعدّل balance، فالـ Guard مش محتاج هنا.

#### Step D: Debit the Source Pool (Carrier أو System) [L191-215]

```php
$prepaidKey = 'flight_system';  // default
$debitSubLedgerAmount = $refundRequest->refund_amount;

if ($booking->purchase_balance_source === 'carrier') {
    $prepaidKey = 'flight_carrier';
    if ($booking->flight_carrier_id) {
        $carrier = FlightCarrier::lockForUpdate()->find($booking->flight_carrier_id);
        if ($carrier) {
            if (strtoupper($carrier->currency) === 'EGP') {
                // لو EGP، استخدم base_currency_refund
                $debitSubLedgerAmount = $refundRequest->base_currency_refund;
            }
            $carrier->debit($debitSubLedgerAmount, $booking->id, $userId);
            // ↑ ده بيزود رصيد الناقل (عكس الـ booking الأصلي)
        }
    }
} else {  // 'system'
    if ($booking->flight_system_id) {
        $system = FlightSystem::lockForUpdate()->find($booking->flight_system_id);
        if ($system) {
            if (strtoupper($system->currency) === 'EGP') {
                $debitSubLedgerAmount = $refundRequest->base_currency_refund;
            }
            $system->debit($debitSubLedgerAmount, $booking->id, $userId);
            // ↑ ده بيزود رصيد النظام
        }
    }
}

$fromAccountId = $this->clearingAccounts->prepaidAccountId($prepaidKey);
```

> **🔑 المهم:** الـ `debit()` على `FlightCarrier`/`FlightSystem` بيستخدم `mutateBalanceInternal` (الـ Observer safe) — **ده الشرح الوحيد لـ credit-debit balance في carriers/systems.** كل تعديل balance لازم يمر من هنا.

#### Step E: GL Journal Entry [L216-257]

```php
if ($fromAccountId && $account && $fromAccountId !== $account->id) {
    $transferAmount = $refundRequest->refund_amount;
    $fromAccount = Account::find($fromAccountId);
    
    // EGP adjustment for cross-currency
    if (($fromAccount && $fromAccount->currency === 'EGP') || ($account->currency === 'EGP')) {
        $transferAmount = $refundRequest->base_currency_refund ?? $refundRequest->refund_amount;
    }
    
    $convertedAmount = null;
    $exchangeRate = null;
    if ($fromAccount && $fromAccount->currency !== $account->currency) {
        // Cross-currency conversion
        if ($fromAccount->currency === 'EGP') {
            $convertedAmount = $refundRequest->refund_amount;
            $exchangeRate = $refundRequest->refund_exchange_rate;
        } else {
            $convertedAmount = $refundRequest->base_currency_refund;
            $exchangeRate = $refundRequest->refund_exchange_rate;
        }
    }
    
    $this->transactionService->recordJournalTransfer([
        'amount' => $transferAmount,
        'from_account_id' => $fromAccountId,           // prepaid_flight_system/carrier
        'to_account_id' => $account->id,               // cashbox_account (destination treasury)
        'allow_from_negative' => true,                 // ← prepaid ممكن يكون سالب فعلاً
        'module' => TransactionModule::Flight->value,
        'related_type' => FlightBooking::class,
        'related_id' => $booking->id,
        'notes' => "إيداع استرجاع تذكرة حجز طيران — حجز #{$booking->booking_number}",
        'created_by' => $userId,
        'converted_amount' => $convertedAmount,
        'exchange_rate' => $exchangeRate,
    ]);
}
```

### Phase 3: Update States [L260-268]

```php
$isPartial = $refundRequest->cancellation_fee > 0 || 
             $refundRequest->refund_amount < $refundRequest->original_amount;
$booking->status = $isPartial ? FlightBookingStatus::PARTIALLY_REFUNDED : FlightBookingStatus::REFUNDED;
$booking->save();

$refundRequest->status = 'processed';
$refundRequest->processed_at = now();
$refundRequest->save();
```

## 3.5 الحسابات في Refund Scenario

### السيناريو: Booking بـ 6,000 EGP → Refund بـ 5,500 EGP → Treasury

| Item | المبلغ |
|---|---:|
| Original selling_price | 6,000 EGP |
| Cancellation fee | 500 EGP |
| Refund amount | 5,500 EGP |
| Treasury destination | خزينة الكاش EGP |

### الـ GL Transactions المُنشأة:

| # | Transaction Type | From (debit) | To (credit) | Amount |
|---|---|---|---|---:|
| **1** | Refund Transfer | prepaid_flight_carrier (acc 24) | cashbox_egypt (treasury GL account) | 5,500 EGP |
| | ↳ AccountEntry × 2 | | | |
| | + AirlineTransaction | | | debit 5,500 (carrier.balance += 5,500) |
| | + TreasuryTransaction | | | receipt 5,500 |

### الـ Balance Changes:

- `flight_carriers[1].balance` += 5,500 (الرصيد التشغيلي بيرجع)
- `accounts[prepaid_flight_carrier].balance` -= 5,500
- `treasury.cashbox_egypt.balance` += 5,500
- `treasury_transactions` (1 receipt entry)

> **🔑 الأثر على الربح:** الـ `profit` كان 500 EGP (6,000 - 5,500). الـ cancellation_fee (500) = الـ profit. **يعني: العميل دفع 6,000، شركته دفعت 5,500 للـ carrier، Agency ربحت 500 = الـ cancellation_fee.** ده الـ business logic.

---

# Scenario 4: استرداد إلى رصيد طيران (Refund to Airline Credit)

> **السيناريو البديل للاسترداد** — بدل ما يرجع فلوس كاش للعميل، يعمل "رصيد دائن" (voucher) يستخدمه في حجز future.

## 4.1 الـ Big Picture

```
نفس الـ flow بتاع Scenario 3، لكن destination = 'airline_credit'
    ↓
RefundService::processRefundRequest() — Branch A (airline_credit) [L95-117]
    ↓
Within DB::transaction:
    ├── Lock RefundRequest
    ├── Lock FlightBooking
    ├── Validate booking has flight_carrier_id
    ├── AirlineCredit::create()  ← Voucher
    └── Done — no GL entries!
```

> **🔑 الفرق الرئيسي:** الـ Airline Credit **مفيش له GL posting** — ده voucher خارج الـ balance الرئيسي. العميل عنده "credit" عند شركة الطيران يقدر يستخدمه في حجز future.

## 4.2 الـ Frontend Flow (Vue)

**نفس الـ `RefundWizard.vue`** لكن بـ:
- Destination = `airline_credit` بدل `agency_treasury`
- مفيش treasury_id step
- الـ wizard step type يعرض "رصيد طيران (voucher)"

## 4.3 `RefundService::processRefundRequest` — Branch A [L95-117]

**ملف:** [`app/Services/Flight/RefundService.php:95-117`](#)

```php
if ($refundRequest->destination === 'airline_credit') {
    // Scenario A: رصيد طيران فقط
    if (! $booking->flight_carrier_id) {
        throw new \RuntimeException(
            'لا يمكن إصدار رصيد طيران لحجز لا يحتوي على شركة طيران (Carrier) محددة.'
        );
    }
    
    // إنشاء أو تحديث رصيد الطيران
    AirlineCredit::create([
        'flight_carrier_id' => $booking->flight_carrier_id,
        'customer_id' => $booking->customer_id,
        'currency' => $refundRequest->refund_currency,
        'amount' => $refundRequest->refund_amount,
        'expiry_date' => now()->addYear()->toDateString(),  // افتراضي سنة
        'flight_booking_id' => $booking->id,
        'refund_request_id' => $refundRequest->id,
        'status' => 'active',
    ]);
    
    Log::info('تم إصدار رصيد طيران بنجاح', [...]);
    
    // ⚠️ لا يوجد GL posting! الـ AirlineCredit خارج الـ balance العام
}
```

> **🔑 ملاحظة مهمة جداً:** في الـ `airline_credit` path:
> - **لا يوجد `FlightCarrier::credit()` call** — الـ balance بتاع الـ carrier مفيش تغيير
> - **لا يوجد GL posting** — مفيش transaction/account_entries
> - الـ AirlineCredit row بس بينشأ
> - ده لأن الـ "credit" ده "وعد مستقبلي" مش "نقود رجعت"

## 4.4 الـ AirlineCredit Model

**ملف:** [`app/Models/Flight/AirlineCredit.php`](#)

```
الحقول:
- flight_carrier_id  → FK to FlightCarrier
- customer_id        → FK to Customer
- currency           → string (EGP, USD, إلخ)
- amount             → decimal
- expiry_date        → date (افتراضي: سنة من الإنشاء)
- flight_booking_id  → الـ booking اللي جاي منه
- refund_request_id  → الـ refund اللي أنشأه
- status             → 'active' | 'used' | 'expired'
```

## 4.5 أين يُستخدم الـ AirlineCredit؟

### Filament UI (read-only)
**`FlightAirlineTransactions.vue`** (Vue read-only) + **`AirlineCreditBadge.vue`** (component)

### في الـ Refund Status:
```php
$isPartial = ...;
$booking->status = $isPartial ? 
    FlightBookingStatus::PARTIALLY_REFUNDED : 
    FlightBookingStatus::REFUNDED;
```

> **⚠️ ملاحظة:** الـ booking status بيتحدث لـ REFUNDED حتى لو الـ destination airline_credit — العميل "حصل على حقه" حتى لو voucher.

## 4.6 الـ Use Cases

- العميل عنده voucher من رحلة ملغية → يستخدمه في رحلة future
- Carrier عنده airline credit custom → بيشتري تذاكر بخصم

## 4.7 مقارنة: Refund to Treasury vs Refund to Airline Credit

| الميزة | Refund to Treasury | Refund to Airline Credit |
|---|---|---|
| **Cash flow** | 💸 فلوس فعلية للعميل | 🎫 voucher (صك رحلة future) |
| **GL entries** | ✅ 2 AccountEntry | ❌ مفيش |
| **FlightCarrier/System balance** | ✅ +refund_amount | ❌ بدون تغيير |
| **Customer debt** | ✅ -original_amount | ❌ بدون تغيير |
| **حساب الـ treasury** | ✅ +refund_amount | ❌ بدون تغيير |
| **Ledger posting** | ✅ journal transfer | ❌ بدون (مفيش transaction) |
| **Audit trail** | ✅ Transaction row | ✅ AirlineCredit row |
| **RefundRequest status** | 'processed' | 'processed' |
| **Booking.status** | REFUNDED | REFUNDED |
| **في business**: | "استرجاع كامل" | "تحويل لرصيد دائن" |

> **🔑 الـ business logic:** الـ airline_credit path بيوفر سيولة للـ agency (مش محتاج تدفع cash) + الـ customer مبيروحش.

---

# Scenario 5: تعديل تذكرة (Ticket Modification)

> **⚠️ ده السيناريو اللي فيه الـ GAP** — الـ `ProcessTicketModificationAccounting` Listener بيخصم من `AirlineAccount.balance` **بدون GL posting**. ده اللي عمل الـ desync في 2 flight_systems.

## 5.1 الـ Big Picture

```
الـ agent يفتح FlightBooking في Filament
    ↓
Action "طلب تعديل" → Redirects to TicketModificationResource::create?booking_id=X
    ↓
Filament Edit Form → Agent fills new dates, fees, etc.
    ↓
Submit → TicketModification::create({ status: 'draft' })
    ↓
بعد المراجعة (admin):
Click "Confirm" on TicketModification
    ↓
ModificationService::confirmModification() [L89]
    ├── Lock TicketModification + FlightBooking
    ├── Validate booking.airline_account_id NOT NULL
    ├── Snapshot fees + rate
    ├── Update Booking (departure_date, mod_count)
    └── dispatch event(new TicketModified($modification))
        ↓
        ProcessTicketModificationAccounting listener
            ↓ ⚠️ THE GAP
            AirlineAccountDebitService::debitForModification()
                ├── Locks airline_account
                ├── Decrements balance (بدون Guard!)
                └── Returns AirlineTransaction
```

## 5.2 الواجهات (Layer Breakdown)

### Frontend (Vue)
**`ModificationWizard.vue`** [`resources/js/components/flights/ModificationWizard.vue`](#) — wizard التعديل.

### Filament
**`FlightBookingResource` action `modify`** [L318-322]:
```php
Action::make('modify')
    ->label('طلب تعديل')
    ->icon('heroicon-o-adjustments-horizontal')
    ->color('info')
    ->url(fn ($record): string => 
        \App\Filament\Admin\Resources\TicketModifications\TicketModificationResource::getUrl('create') 
        . '?booking_id=' . $record->id
    ),
```

> الـ Filament بيروح للـ TicketModificationResource.

### API
**`ModificationController`** [app/Http/Controllers/Api/V1/Flight/ModificationController.php]:
- `POST /v1/flight/modifications` (create)
- `POST /v1/flight/modifications/{id}/confirm` ⭐
- `POST /v1/flight/modifications/{id}/reconcile`

## 5.3 الـ Business Logic — `ModificationService::confirmModification`

**ملف:** [`app/Services/Flight/ModificationService.php:89-140`](#)

```php
public function confirmModification(int $id, int $userId): TicketModification {
    return DB::transaction(function () use ($id, $userId) {
        // Lock
        $modification = TicketModification::lockForUpdate()->findOrFail($id);
        $booking = FlightBooking::lockForUpdate()->findOrFail($modification->flight_booking_id);
        
        // Idempotency
        if ($modification->status === 'confirmed') {
            return $modification;
        }
        
        // ⚠️ Fixed financial rule: airline_account_id required
        if (! $booking->airline_account_id) {
            throw new RuntimeException('لا يمكن تأكيد تعديل على حجز بدون airline_account_id');
        }
        
        // Snapshots
        $modification->airline_change_fee_snapshot = $modification->airline_change_fee;
        $modification->commission_snapshot = $modification->agency_commission;
        $modification->exchange_rate_snapshot = $modification->exchange_rate;
        
        // Status
        $modification->status = 'confirmed';
        $modification->confirmed_at = now();
        $modification->confirmed_by = $userId;
        $modification->save();
        
        // Update Booking
        $booking->departure_date = $modification->new_departure_date ?? $booking->departure_date;
        $booking->destination = $modification->new_destination ?? $booking->destination;
        $booking->last_modified_at = now();
        $booking->modification_count = ($booking->modification_count ?? 0) + 1;
        $booking->save();
        
        // 🔥 Event — the GL posting happens in LISTENER
        event(new TicketModified($modification));
        
        return $modification;
    });
}
```

> **🔑 المهم:** الـ `confirmModification` نفسه **ما يعملش الـ GL posting.** الـ event بياخد الـ modification ويوديه للـ listener اللي بيعمل الـ debit من `AirlineAccount`.

## 5.4 ⚠️ الـ GAP — `ProcessTicketModificationAccounting` Listener

**ملف:** [`app/Listeners/ProcessTicketModificationAccounting.php`](#) — الـ listener اللي بيتعامل مع الـ `TicketModified` event.

**الـ problem:** الـ listener بيشتغل بـ:
```php
$airlineAccount = AirlineAccount::find($booking->airline_account_id);
// ...
$tx = $airlineAccount->debit($modification->airline_change_fee, $booking->id, $userId);
// ⚠️ debits balance DIRECTLY without GL entries
```

> **🔴 النتيجة:**
> - `airline_accounts[id].balance` -= fee
> - **مفيش `transactions` row**
> - **مفيش `account_entries` row**
> - **مفيش GL posting**
>
> ده معناه:
> - الـ balance الفعلي للنظام (AirlineAccount) ← صح
> - الـ GL (accounts.balance) ← خطأ (مش متأثر)
> - **`balance_guard.block_unauthorized_updates` في الـ `Account` model مش بيأثر على `AirlineAccount`** ← لأن `AirlineAccount` model مفيهوش Observer!
>
> **الحل:** شوف [`docs/ARCHITECTURE.md § 8.5`](#) — Phase 1v2 + Phase 4 plan.

## 5.5 الـ Reconciliation — `reconcileModification`

**ملف:** [`app/Services/Flight/ModificationService.php:142-160`](#)

```php
public function reconcileModification(int $id, string $invoiceNumber): TicketModification {
    // Only allowed on confirmed modifications
    // Sets reconciliation_status = 'matched', reconciled_invoice_number, reconciled_at
    
    $modification = TicketModification::findOrFail($id);
    if ($modification->status !== 'confirmed') {
        throw new RuntimeException('لا يمكن تأكيد التسوية لتعديل غير مؤكد');
    }
    
    $modification->update([
        'reconciliation_status' => 'matched',
        'reconciled_invoice_number' => $invoiceNumber,
        'reconciled_at' => now(),
    ]);
    
    return $modification;
}
```

## 5.6 الـ TicketModification Model

**ملف:** [`app/Models/Flight/TicketModification.php`](#)

الحقول الرئيسية:
- `flight_booking_id` (FK)
- `status` (state machine: draft → pending → quoted → approved → confirmed)
- `airline_change_fee`
- `agency_commission`
- `exchange_rate`
- `airline_change_fee_snapshot`
- `commission_snapshot`
- `exchange_rate_snapshot`
- `deducted_from_airline_balance` (always true now — fixed financial rule)
- `total_charged_to_customer`
- `reconciliation_status` (`pending` → `matched`)
- `confirmed_at`, `confirmed_by`

## 5.7 الـ Files المرتبطة

```
Modification Flow:
├── Filament:
│   ├── FlightBookingResource.php:318 (action modify → redirect)
│   ├── TicketModifications/TicketModificationResource.php (filament resource)
│   └── Forms (CreateTicketModification)
├── API:
│   └── ModificationController (store, show, updateStatus, confirm, reconcile)
├── Service:
│   └── ModificationService (الـ state machine + confirmation)
├── Event:
│   └── TicketModified
├── Listener:
│   └── ProcessTicketModificationAccounting  ⚠️ THE GAP
├── Model:
│   └── TicketModification (المواصفة)
└── Tests:
    └── (مش موجودة بعد — Phase 8 plan يضيفها)
```

---

# Scenario 6: لوحة تحكم الطيران (Dashboard & Reports)

## 6.1 Filament Flight Dashboard

**ملف:** [`app/Filament/Admin/Pages/FlightDashboard.php`](#)

```
URL: /admin/flight-dashboard
Widgets:
  • FlightStatsWidget (header)    ← Total balance, total bookings, revenue sparklines
  • RecentFlightBookingsWidget (footer) ← آخر 5 حجوزات
```

### `FlightStatsWidget`

**ملف:** [`app/Filament/Admin/Widgets/FlightStatsWidget.php`](#)

**الـ Computations:**

```php
// 1) Total balance across flight carriers + systems
$carriersTotal = FlightCarrier::sum('balance');
$systemsTotal = FlightSystem::sum('balance');

// 2) Total bookings this month
$bookingsThisMonth = FlightBooking::whereMonth('created_at', now()->month)->count();

// 3) Revenue (sum of selling_price × exchange rate → EGP)
$revenue = FlightBooking::whereMonth('created_at', now()->month)
    ->selectRaw('SUM(selling_price * booking_exchange_rate) as total')
    ->value('total');

// 4) Sparkline data: last 7 days
$sparkline = FlightBooking::where('created_at', '>=', now()->subDays(7))
    ->selectRaw('DATE(created_at) as day, SUM(selling_price * booking_exchange_rate) as revenue')
    ->groupBy('day')
    ->get();
```

## 6.2 Filament FlightSystemsBalancesPage

**ملف:** [`app/Filament/Admin/Pages/FlightSystemsBalancesPage.php`](#)

```
URL: /admin/flight-system-balances
View: resources/views/filament/pages/flight-system-balances.blade.php
```

**الـ View:**
- جدول بكل FlightSystems وأرصدتها (`balance`, `credit_limit`, `available_balance`)
- الـ Header Action: "شحن رصيد نظام" → يفتح modal كامل

## 6.3 Vue FlightDashboard

**ملف:** [`resources/js/views/flights/FlightDashboard.vue`](#)

```
URL: /flights/dashboard (probably, router-based)
```

> ⚠️ **Vue Dashboard مش موجود دلوقتي** كصفحة dedicated. الـ dashboards في الـ Vue بتبقى عادة في الـ "Dashboard.vue" العام على `/dashboard` أو من خلال `/v1/dashboard`.

## 6.4 الـ FinancialReportController Endpoints

**ملف:** [`app/Http/Controllers/Api/V1/Reports/FinancialReportController.php`](#)

```
GET /v1/reports/flights/detailed      ← تقرير طيران مفصل
GET /v1/reports/treasury/summary      ← ملخص الخزائن
GET /v1/reports/profit-loss           ← الأرباح والخسائر
GET /v1/reports/customer-debts        ← ذمم العملاء
GET /v1/reports/supplier-debts        ← ذمم الموردين
GET /v1/reports/trial-balance         ← ميزان مراجعة
```

> **الـ FinancialReportService** [`app/Services/Reports/FinancialReportService.php`](#) بيبني الـ reports دي (الـ file 95KB+).

## 6.5 تقارير Vue

**`FlightTreasuryOverview.vue`** [`resources/js/views/flights/FlightTreasuryOverview.vue`](#) — treasury overview.

**`FlightDetailedReport.vue`** [`resources/js/views/reports/FlightDetailedReport.vue`](#) — تقرير مفصل.

## 6.6 الـ KPIs الشائعة في الـ Flight Module

| الـ KPI | الـ Source | الـ Calculation |
|---|---|---|
| Total Carrier Balance | `SUM(flight_carriers.balance)` | EGP normal + others native |
| Total System Balance | `SUM(flight_systems.balance)` | EGP normal + others native |
| Total Bookings | `COUNT(flight_bookings)` | كل الحالات |
| Revenue This Month | `SUM(selling_price × booking_exchange_rate)` | بعد normalization للـ EGP |
| Profit This Month | `SUM(profit × exchange_rate)` | — |
| Avg Booking Value | `AVG(selling_price)` | — |
| Refund Rate | `COUNT(refunded) / COUNT(all)` | — |
| Top Carrier | `GROUP BY flight_carrier_id ORDER BY revenue DESC LIMIT 1` | — |
| Pending Bookings | `COUNT WHERE status = 'pending'` | — |
| Prepaid Balance | `accounts WHERE name LIKE 'رصيد مسبق%'` | الـ source of truth |

---

# Scenario 7: حالات الـ Edge والـ Conflicts

> **أكتر سيناريوهات بتحصل في الـ Production** — لازم نعرف كل واحدة عشان نعرف الحل.

## 7.1 الـ Currency Mismatch

### في Recharge Carrier/System
```
User: شحّن العربية (EGP) من حساب بنكي بـ KWD
    ↓
FlightCarrierRechargeService::executeRechargeTransaction [L95-101]
    ↓
throw new \RuntimeException("تضارب في العملة: الحساب المصدر بعملة (KWD) 
                              لا يتطابق مع عملة الناقل (EGP)...")
    ↓
Filament notification: "تعذر تنفيذ الشحن"
User: محتاج يحط KWD carrier بدل EGP، أو يحوّل لـ EGP أولاً
```

**الحل البديل:** استخدم `AccountRechargeService` (generic) لو محتاج cross-currency.

### في Refund to Treasury
```
Refund: EGP refund → Treasury بـ USD
    ↓
RefundService::processRefundRequest [L132-137]
    ↓
throw new \RuntimeException("تضارب في العملة: لا يمكن إيداع استرجاع 
                              بعملة (EGP) في خزينة تعمل بعملة (USD)...")
    ↓
User: محتاج يختار Treasury EGP
```

## 7.2 Insufficient Prepaid Balance

**ده الأكثر شيوعاً** — راجع الـ incident 2026-07-08.

```
User: حاول يعمل booking بـ 6,500 EGP
Carrier (Arabic) balance: +57,414 EGP  ← موجب!
But prepaid_flight_carrier.balance: -52,000 EGP  ← سالب
    ↓
FlightCarrier::debit() ينجح (carrier كافي)
    ↓
PrepaidLedgerService::consumeCogs() [L128-159] يفحص الـ prepaid
    ↓
throw new InsufficientBalanceException(
    "رصيد مسبق غير كافٍ على حساب 'رصيد مسبق — ناقلو الطيران'.
     المتاح: -52,000.00 EGP، المطلوب: 6500.00. 
     يرجى شحن رصيد الناقل/النظام من زر 'شحن رصيد' قبل إجراء الحجز."
)
    ↓
DB::transaction → ROLLBACK تلقائي
    ↓
الـ booking اتمنع بنجاح، الـ user شاف الـ error message
```

**الحل:** شحن رصيد الـ prepaid من Filament أولاً.

## 7.3 Insufficient Carrier/System Balance

```
User: حاول يعمل booking بـ 50,000 EGP
Carrier balance: +1,000 EGP  ← مش كافي
    ↓
FlightCarrier::debit(amount=50000) [L174]
    ↓
throws RuntimeException 'رصيد الناقل غير كافٍ' أو similar
    ↓
DB rollback
```

**الحل:** الـ user إما يشحن الرصيد أو يقسّم على carriers متعددة.

## 7.4 Race Condition / Deadlock (الـ Rare Case)

```
User A: يفتح recharge carrier "العربية" بـ 50,000
User B: نفس الوقت يفتح recharge carrier "العربية" بـ 30,000
    ↓
Both transactions try to lock same carrier row + bank account + prepaid
    ↓
If locking order is identical: one waits for the other (no deadlock)
If locking order differs: MySQL detects deadlock (1213)
    ↓
FlightCarrierRechargeService retry loop [L47-80]:
  attempt 1 → 1213 → usleep(50ms) → retry
  attempt 2 → 1213 → usleep(100ms) → retry  
  attempt 3 → 1213 → usleep(150ms) → retry
  attempt 4 → throws exception to user
    ↓
Filament notification: "تعذر تنفيذ الشحن بسبب deadlock"
```

> **🛡️ الحماية:** الـ ID-asc locks بتضمن إن مفيش deadlock بين carriers، لكن ممكن يحصل في عمليات أخرى.

## 7.5 Snapshot Conflict (1020)

```
REPEATABLE READ isolation
User A: read carrier.balance = 50000 في t1
User B: update carrier.balance = 80000 في t2 (commit)
User A: update based on stale 50000 → 1020 conflict
    ↓
Retry logic catches → usleep → retry → succeeds with new balance 80000
```

## 7.6 الـ Booking في حالة PENDING بدون PNR

```
User: فتح booking wizard، لكن ما عندوش PNR (Pre-NDC)
    ↓
FlightBooking::create({ status: 'pending' })
    ↓
الـ ticket numbers الـ generateTickets() — فيه handling خاص
الـ booking مفيش تذاكر فعلية
    ↓
لاحقاً: agent يدخل PNR → confirmBooking() → status: 'confirmed' → createFlightTickets()
```

## 7.7 الـ Refund بدون GL Posting (Airline Credit)

```
User: refund → airline_credit voucher
    ↓
RefundService: creates AirlineCredit row فقط
    ↓
NO GL posting
NO FlightCarrier balance change
NO treasury change
    ↓
⚠️ المحاسبة مش بتتأثر — ده voucher خارج الـ balance
```

## 7.8 Filament balance field disabled

```
Admin: فتح EditFlightCarrier
    ↓
balance field is disabled() + dehydrated(false)
    ↓
Even if user submits, balance won't update (Phase 1 defense layer)
```

## 7.9 الـ ChargeBack أو الـ Reversal

```
Booking تم، بعدها العميل يقول "بطاقتي مشتغلتش"
    ↓
Cancel booking (Scenario 3.4-ish)
    ↓
OR: refund logic same as Scenario 3
```

## 7.10 Customer Account مش موجود

```
New customer created in Customer model, but no Account row yet
    ↓
FlightBookingService::createBooking → ensureCustomerAccount() [L2066]
    ↓
ينشئ Account جديد للعميل تلقائياً
    ↓
Record sale on customer account → succeeds
```

## 7.11 الـ Company Account مش موجود (Bus-style)

مش مشكلة هنا — flight booking بيستخدم customer + carrier accounts بس.

## 7.12 الـ Multi-currency Booking

```
Booking بـ USD purchasing
    ↓
recordJournalTransfer [L539-558]:
  - source.currency = 'EGP' (prepaid)
  - to.currency = 'USD' (carrier)
  - conversion عبر CurrencyService
    ↓
converted_amount + exchange_rate في الـ transaction
```

## 7.13 الـ Writeoff scenario

```
Massive desync اكتشف في flight_carriers[id].balance
    ↓
Manual decision: Phase 3b v3 writeoff
    ↓
Transaction::create({ type: 'writeoff', ... })
AccountEntry::create × 2
    ↓
clears the desync from GL perspective
```

## 7.14 Phase 7 Test Recharge (الـ Cleanup)

```
A test recharge was applied to production by mistake
    ↓
Phase 7 script: phase7_cleanup_reverse_recharge.php
    ↓
Reverse the GL transfer + AirlineTransaction
    ↓
Stays clean — 1 transaction reversed
```

---

# 8 — Where to Find What — Index

## 8.1 الـ Vue Views (Frontend)

| الـ View | الملف | الـ Route (Vue) | الـ Purpose |
|---|---|---|---|
| FlightCreate | `resources/js/views/flights/FlightCreate.vue` | `/flights/create` | 7-step wizard |
| FlightEdit | `resources/js/views/flights/FlightEdit.vue` | `/flights/{id}/edit` | تعديل |
| FlightIndex | `resources/js/views/flights/FlightIndex.vue` | `/flights` | قائمة الحجوزات |
| FlightShow | `resources/js/views/flights/FlightShow.vue` | `/flights/{id}` | تفاصيل |
| FlightDashboard | `resources/js/views/flights/FlightDashboard.vue` | `/flights/dashboard` | لوحة تحكم |
| FlightAirlineAccountsIndex | `resources/js/views/flights/FlightAirlineAccountsIndex.vue` | `/flights/airline-accounts` | 🟡 Legacy (AirlineAccount) |
| FlightAirlineTransactions | `resources/js/views/flights/FlightAirlineTransactions.vue` | `/flights/airline-transactions` | 🟡 Legacy |
| FlightTreasuryOverview | `resources/js/views/flights/FlightTreasuryOverview.vue` | `/flights/treasury` | خزائن |
| FlightCustomersIndex | `resources/js/views/flights/FlightCustomersIndex.vue` | `/flights/customers` | عملاء |
| FlightDetailedReport | `resources/js/views/reports/FlightDetailedReport.vue` | `/reports/flights/detailed` | تقرير مفصل |

## 8.2 الـ Vue Components

| الـ Component | الـ Use |
|---|---|
| `FlightSegmentForm.vue` | إدخال القطاعات (GUC-CAI) |
| `PassengerForm.vue` | بيانات الركاب |
| `BookingSummary.vue` | ملخص في الـ sidebar |
| `PricingBox.vue` | عرض purchase/selling/profit |
| `TreasuryCard.vue` | بطاقة رصيد |
| `RefundWizard.vue` | wizard الاسترداد |
| `ModificationWizard.vue` | wizard التعديل |
| `CustomerSelect.vue` | اختيار العميل |
| `AirportSearchInput.vue` | بحث المطارات |
| `TimePicker.vue` | إدخال الوقت |
| `AirlineCreditBadge.vue` | بadge رصيد دائن |
| `CompactPassengerList.vue` | قائمة ركاب مضغوطة |

## 8.3 الـ Filament Resources

| الـ Resource | الـ URL |
|---|---|
| FlightCarrierResource | `/admin/flight-carriers` |
| FlightSystemResource | `/admin/flight-systems` |
| FlightBookingResource | `/admin/flight-bookings` |
| TicketModificationResource | `/admin/ticket-modifications` |
| FlightGroupResource | `/admin/flight-groups` |
| FlightTreasuryResource | `/admin/flight-treasuries` |
| FlightWalletResource | `/admin/flight-wallets` |

## 8.4 الـ Filament Pages

| الـ Page | الـ URL |
|---|---|
| FlightDashboard | `/admin/flight-dashboard` |
| FlightSystemsBalancesPage | `/admin/flight-system-balances` |
| CurrencyTreasuryExchangePage | `/admin/currency-treasury-exchange` |

## 8.5 الـ Filament Widgets

| الـ Widget | الـ Display |
|---|---|
| FlightStatsWidget | total balance / bookings / revenue sparklines |
| RecentFlightBookingsWidget | آخر 5 حجوزات |

## 8.6 الـ API Endpoints الأكثر استخداماً

| الـ Method + URL | الـ Purpose |
|---|---|
| GET `/v1/flight/bookings` | List |
| POST `/v1/flight/bookings` | Create |
| GET `/v1/flight/bookings/{id}` | Show |
| PATCH `/v1/flight/bookings/{id}` | Update |
| POST `/v1/flight/bookings/{id}/confirm` | Confirm |
| POST `/v1/flight/bookings/{id}/payments` | Add payment |
| POST `/v1/flight/bookings/{id}/cancel` | Cancel |
| DELETE `/v1/flight/bookings/{id}` | Delete |
| GET `/v1/flight/carriers` | List carriers |
| POST `/v1/flight/carriers/{id}/recharge` | Recharge carrier |
| GET `/v1/flight/systems` | List systems |
| POST `/v1/flight/treasury/systems/{id}/recharge` | Recharge system |
| POST `/v1/flight/refunds` | Create refund request |
| POST `/v1/flight/refunds/{id}/process` | Process refund |
| POST `/v1/flight/modifications/{id}/confirm` | Confirm modification |

## 8.7 الـ Services الأكثر استخداماً

| الـ Service | الـ Key Methods |
|---|---|
| `FlightBookingService::createBooking` | [L210-411] |
| `FlightBookingService::cancelBooking` | [L1690-1842] |
| `FlightBookingService::debitFlightCarrier` | [L801-857] |
| `FlightBookingService::debitFlightSystem` | [L859-908] |
| `FlightBookingService::creditTreasuryAccount` | [L910+] |
| `FlightBookingService::recordSaleToCustomer` | [L2106+] |
| `FlightBookingService::recordPurchaseFromGroup` | [L2144+] |
| `FlightBookingService::addPayment` | [L1546+] |
| `FlightBookingService::ensureCustomerAccount` | [L2066+] |
| `FlightBookingService::ensureFlightIncomeClearingAccount` | [L994+] |
| `FlightCarrierRechargeService::rechargeFromAccount` | [L38+] |
| `FlightSystemRechargeService::rechargeFromAccount` | [L32+] |
| `RefundService::createRefundRequest` | [L29-78] |
| `RefundService::processRefundRequest` | [L83-272] |
| `ModificationService::confirmModification` | [L89-140] |
| `TransactionService::recordJournalTransfer` | [L477-592] |
| `TransactionService::recordIncome` | [L132-210] |
| `TransactionService::recordExpense` | [L46-120] |
| `PrepaidLedgerService::recharge` | [L29-100] |
| `PrepaidLedgerService::consumeCogs` | [L109-180] |
| `PrepaidLedgerService::refundCogs` | [L185-224] |
| `LedgerClearingAccounts::prepaidAccountId` | [L63-76] |
| `LedgerClearingAccounts::incomeContraIdForModule` | [L21-32] |
| `LedgerClearingAccounts::expenseContraIdForModule` | [L47-61] |
| `LedgerBalanceMutationGuard::run` | [L17-25] |
| `LedgerBalanceMutationGuard::isAllowed` | [L27-30] |

## 8.8 الـ Models المرتبطة بالـ GL

| الـ Model | الـ Balance Field | الـ Protected? |
|---|---|---|
| `Account` | `balance` | 🛡️ **LedgerBalanceMutationGuard** |
| `FlightCarrier` | `balance` | 🛡️ **Phase 1 — mutateBalanceInternal** |
| `FlightSystem` | `balance` | 🛡️ **Phase 1 — mutateBalanceInternal** |
| `AirlineAccount` | `balance` | ⚠️ **GAP — NOT protected** |
| `Treasury` | `current_balance` | ⚠️ **Direct access (legacy in RefundService)** |

## 8.9 الـ Config Keys المتعلقة

```php
// config/accounting.php
'clearing' => [
    'prepaid' => [
        'flight_carrier' => 'رصيد مسبق — ناقلو الطيران',
        'flight_system'  => 'رصيد مسبق — أنظمة حجز الطيران',
        'fawry'          => 'رصيد مسبق — ماكينات فوري',
    ],
    'income' => [...],
    'expense' => [...],
],
'balance_guard' => [
    'block_unauthorized_updates' => true,
    'disable_in_testing' => false,
],
'strict_double_entry' => true,
'allow_legacy_single_leg_fallback' => false,
'strict_test_guards' => false,
```

---

# 📌 ملخص — الـ Quick Reference

## فين كل function؟

| الـ Function | الـ 1st choice | الـ Backup |
|---|---|---|
| **Create booking** | Vue `FlightCreate.vue` | Filament `FlightBookingResource` CreateFlightBooking page |
| **Process payment** | Vue step 7 | Filament EditFlightBooking |
| **Cancel booking** | Vue RefundWizard | API / Filament (soon) |
| **Modify ticket** | Vue ModificationWizard | Filament action → TicketModificationResource |
| **Recharge carrier** | ❌ Vue | ✅ Filament FlightCarrierResource action |
| **Recharge system** | ❌ Vue | ✅ Filament FlightSystemResource action + FlightSystemsBalancesPage |
| **View carrier balance** | ✅ Vue (read-only) | ✅ Filament table column |
| **View system balance** | ✅ Vue | ✅ Filament + FlightSystemsBalancesPage |
| **Refund approval** | ❌ Vue (sends request) | API direct (Filament الـ handler لسه مش dedicated) |
| **Dashboard** | ✅ Vue Dashboard.vue (عام) | ✅ Filament FlightDashboard |
| **Reports** | ✅ Vue FlightDetailedReport | ✅ API `/v1/reports/flights/*` |

## الـ Key Takeaways

1. **الـ frontend (Vue)** = الـ Users' primary booking flow (read + write على bookings)
2. **الـ Filament** = الـ admin operations: balance management, approvals, configurations
3. **الـ API** = الـ brain — كل العمليات الحسابية المعقدة بتشتغل هنا
4. **الـ Service Layer** = الـ business logic — الـ FlightBookingService هو الـ brain
5. **الـ TransactionService** = الـ canonical posting engine
6. **الـ LedgerBalanceMutationGuard** = الـ safety net على الـ balances
7. **الـ Phase 1** = الـ defense in depth على carriers + systems
8. **الـ GAP** = `AirlineAccount` (Legacy) + `ProcessTicketModificationAccounting` listener

---

**📅 آخر تحديث:** 2026-07-10
**✍️ المؤلف:** ZCode + Youssef Abd Elhaleim
**🎯 الـ Mission:** Operational scenarios for the Flight module (Tourism Division) — referenced from `docs/ARCHITECTURE.md`

> **🚀 يلا نبدأ نشتغل على الإصلاحات والتحسينات!**

