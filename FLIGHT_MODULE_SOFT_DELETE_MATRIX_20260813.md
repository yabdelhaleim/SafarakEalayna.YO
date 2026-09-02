# ✈️ Flight Module — Soft Delete Matrix (Pre-Execution)

> **تاريخ:** 2026-08-13
> **الغرض:** Pre-execution matrix للـ soft-delete behavior على Flight entities.
> **الـ env:** Staging (primary) + SQLite (isolated destructive tests only).
> **الحالة:** 🔴 **PRE-EXECUTION** — لا اختبارات تم تنفيذها بعد.

---

## 1. الـ Scope

Soft-delete (`deleted_at`) موجود على **9 من 18** Flight models. الـ 9 الباقون إما ledger-side (صحيح ألا يكون soft-deletable) أو legacy.

### 1.1 Soft-Deletable Models (9)

| # | Model | File | Migration | SoftDeletes Trait | Guarded |
|---|---|---|---|---|---|
| 1 | `Flight` (legacy) | `app/Models/Flight.php` | `2026_05_17_000001_create_flights_table_custom.php` | ✅ | – |
| 2 | `FlightBooking` | `app/Models/Flight/FlightBooking.php` | `2026_04_26_211424_create_flight_bookings_table.php` | ✅ | `ModelDeletionGuard` + `ModelProfitMutationGuard` |
| 3 | `FlightCarrier` | `app/Models/Flight/FlightCarrier.php` | `2026_05_03_143632_create_flight_carriers_table.php` | ✅ | balance mutation guarded |
| 4 | `FlightGroup` | `app/Models/Flight/FlightGroup.php` | `2026_05_03_143632_create_flight_groups_table.php` | ✅ | – |
| 5 | `FlightSystem` | `app/Models/Flight/FlightSystem.php` | `2026_05_03_143626_create_flight_systems_table.php` | ✅ | balance mutation guarded |
| 6 | `AirlineCredit` | `app/Models/Flight/AirlineCredit.php` | `2026_05_12_000003_create_airline_credits_table.php` | ✅ | – |
| 7 | `FlightPayment` | `app/Models/Flight/FlightPayment.php` | `2026_04_27_123013_create_flight_payments_table.php` | ✅ | – |
| 8 | `RefundRequest` | `app/Models/Flight/RefundRequest.php` | `2026_05_12_000002_create_refund_requests_table.php` | ✅ | – |
| 9 | `TicketModification` | `app/Models/Flight/TicketModification.php` | `2026_05_12_000006_create_ticket_modifications_table.php` | ✅ | – |

### 1.2 Non-Soft-Deletable Models (9)

| # | Model | Why |
|---|---|---|
| 10 | `FlightPricing` (legacy) | legacy EGP-only |
| 11 | `AirlineAccount` | comment: "legacy schema — hard delete only" |
| 12 | `AirlineTransaction` | ledger — must NEVER have soft-delete (per migration 2026_07_10) |
| 13 | `FlightPassenger` | child of FlightBooking, no soft-delete |
| 14 | `FlightRefund` | ledger-side |
| 15 | `FlightSegment` | child of FlightBooking |
| 16 | `FlightSystemTransaction` | ledger — must NEVER have soft-delete |
| 17 | `FlightGroupTransaction` | ledger |
| 18 | `FlightTicket` | minimal — no soft-delete |

---

## 2. الـ User-Facing Surface (Filament + API + Vue)

### 2.1 Per-Entity Surface Map

| Entity | Filament Delete | API Delete | Vue Delete | Restore UI | Force-Delete UI |
|---|---|---|---|---|---|
| `FlightBooking` | ✅ Admin (L317 `deleteWithReversal`) | ✅ `DELETE /api/v1/flight/bookings/{id}` (calls `deleteBookingWithReversal`) | ✅ `FlightShow.vue` (DELETE button) | ❌ NOT in Admin resource | ❌ NOT in Admin resource |
| `FlightCarrier` | ✅ Admin (DeleteAction) | ✅ `DELETE /api/v1/flight/carriers/{id}` (refuses if balance ≠ 0 OR has bookings) | – | ✅ `RestoreAction` imported but only `RestoreBulkAction` used; `RestoreAction` is in actions array | ✅ `ForceDeleteAction` imported but NOT wired |
| `FlightGroup` | ✅ Admin (DeleteAction) | ✅ `DELETE /api/v1/flight/groups/{id}` | – | ✅ `RestoreAction` imported; `RestoreBulkAction` used | ✅ `ForceDeleteAction` imported but NOT wired |
| `FlightSystem` | ✅ Admin (DeleteAction) | ✅ `DELETE /api/v1/flight/systems/{id}` (refuses if balance ≠ 0 OR has carriers) | – | ✅ `RestoreAction` imported; `RestoreBulkAction` used | ✅ `ForceDeleteAction` imported but NOT wired |
| `AirlineCredit` | ✅ Root (DeleteAction) | ❌ NO route | – | ❌ | ❌ |
| `FlightPayment` | ❌ NO Resource | ❌ NO route | ❌ NO UI | ❌ | ❌ |
| `RefundRequest` | ✅ Root (DeleteAction + `process` action) | ✅ `DELETE /api/v1/flight/refunds/{id}` (via `RefundService::reverseRefundRequest`) | – | ❌ | ❌ |
| `TicketModification` | ✅ Admin (BulkAction `deleteWithReversal`) | ✅ `DELETE /api/v1/flight/modifications/{id}` (via `ModificationService::reverseConfirmation`) | – | ❌ | ❌ |
| `Flight` (legacy) | ❌ NO Resource | ❌ NO route | ❌ NO UI | ❌ | ❌ |

### 2.2 TrashedFilter Exposure

| Resource | TrashedFilter |
|---|---|
| `FlightBookingResource` (Admin) | ✅ L273 |
| `FlightCarrierResource` (Admin) | ✅ L242 |
| `FlightGroupResource` (Admin) | ✅ L278 |
| `FlightSystemResource` (Admin) | ✅ L251 |
| `AirlineCreditResource` (Root) | ❌ |
| `RefundRequestResource` (Root) | ❌ |
| `TicketModificationResource` (Root + Admin) | ❌ |
| `Flight` (legacy) | ❌ NO Resource |

### 2.3 `getRecordRouteBindingEloquentQuery` Override

| Resource | Override |
|---|---|
| `FlightBookingResource` (Admin) | ✅ removes `SoftDeletingScope` (L408-414) → allows editing soft-deleted bookings |
| `FlightCarrierResource` (Admin) | ✅ removes `SoftDeletingScope` (L323-329) |
| `FlightGroupResource` (Admin) | ✅ (assumed similar pattern) |
| `FlightSystemResource` (Admin) | ✅ removes `SoftDeletingScope` (L339-345) |

---

## 3. SD Scenario Catalog (17 Scenarios)

نفس الـ Bus SD1-SD17 pattern، لكن airlines-specific:

| SD# | Scenario |
|---|---|
| SD1 | Create via Filament UI |
| SD2 | Delete via Filament UI |
| SD3 | `deleted_at` populated |
| SD4 | Row still present in DB |
| SD5 | Excluded from Vue listing |
| SD6 | Excluded from Filament listing |
| SD7 | Excluded from API listing |
| SD8 | Direct lookup behavior (with Trashed vs without) |
| SD9 | Relations after delete (children still linked?) |
| SD10 | Search/filters/counts consistency |
| SD11 | Dashboard excludes deleted |
| SD12 | Treasury excludes deleted |
| SD13 | Reports exclude deleted |
| SD14 | Re-delete (idempotency) |
| SD15 | Restore (UI + service) |
| SD16 | Force-Delete (UI + service) |
| SD17 | Unauthorized delete attempt |

---

## 4. Per-Entity SD Matrix

### 4.1 `FlightBooking` (Primary Entity)

| SD# | Status | Notes |
|---|---|---|
| SD1 | ✅ testable | Admin Resource + `StoreFlightBookingRequest` |
| SD2 | ✅ testable | `deleteBookingWithReversal` (L2559) reverses GL |
| SD3 | ✅ testable | Eloquent `delete()` populates `deleted_at` |
| SD4 | ✅ testable | Row remains in DB |
| SD5 | ✅ testable | `FlightIndex.vue` filters via `getAllBookings` |
| SD6 | ✅ testable | Admin Resource uses `SoftDeletingScope` by default |
| SD7 | ✅ testable | API `index` excludes by default |
| SD8 | ✅ testable | `FlightBooking::find()` returns null; `withTrashed()->find()` returns row |
| SD9 | ✅ testable | Passengers/segments/payments/tickets still linked (no FK cascade) |
| SD10 | ✅ testable | Search/filters respect global scope |
| SD11 | ✅ testable | `FlightDashboardController@index` exclusion |
| SD12 | ✅ testable | `FlightTreasuryController` excludes |
| SD13 | ✅ testable | `FinancialReportController@detailedFlightReport` exclusion |
| SD14 | ✅ testable | `delete()` on already-deleted is no-op (Eloquent) |
| SD15 | ⚠️ **TESTABLE via DB only** | `RestoreAction` و `RestoreBulkAction` معرّفة لكن غير مرتبطة بـ button واضح؛ `ForceDeleteAction` مستورد لكن غير مرتبط |
| SD16 | ⚠️ **TESTABLE via DB only** | نفس SD15 |
| SD17 | ⚠️ **TESTABLE via API** | لا توجد `admin` middleware — كل authenticated user يقدر يحذف |

### 4.2 `FlightCarrier`

| SD# | Status | Notes |
|---|---|---|
| SD1 | ✅ testable | Admin Resource |
| SD2 | ✅ testable | `DeleteAction` (refuses if balance ≠ 0 OR has bookings — soft constraint) |
| SD3 | ✅ testable | Eloquent |
| SD4 | ✅ testable | Row remains |
| SD5 | ⚠️ N/A | لا Vue listing مباشر للـ carriers |
| SD6 | ✅ testable | Admin Resource filter |
| SD7 | ✅ testable | `FlightCarrierController@index` |
| SD8 | ✅ testable | Direct lookup |
| SD9 | ✅ testable | Groups/bookings still linked via `flight_carrier_id` |
| SD10 | ✅ testable | Filter scope |
| SD11 | ⚠️ partial | FlightStatsWidget may reference |
| SD12 | ✅ testable | `FlightTreasuryController@carrierTransactions` |
| SD13 | ✅ testable | Reports |
| SD14 | ✅ testable | Idempotent |
| SD15 | ✅ testable | `RestoreBulkAction` wired; `RestoreAction` imported |
| SD16 | ⚠️ **NOT WIRED** | `ForceDeleteAction` imported but NOT in actions array |
| SD17 | ⚠️ **GAP** | API has no admin middleware |

### 4.3 `FlightGroup`

| SD# | Status | Notes |
|---|---|---|
| SD1 | ✅ testable | Admin Resource |
| SD2 | ✅ testable | `DeleteAction` |
| SD3 | ✅ testable | – |
| SD4 | ✅ testable | – |
| SD5 | ⚠️ partial | `FlightGroupsIndex.vue` (manage_finance perm) |
| SD6 | ✅ testable | Admin filter |
| SD7 | ✅ testable | API |
| SD8 | ✅ testable | – |
| SD9 | ✅ testable | Bookings still linked |
| SD10 | ✅ testable | – |
| SD11 | ✅ testable | `FlightGroupThresholdService` should not show deleted |
| SD12 | ✅ testable | – |
| SD13 | ✅ testable | – |
| SD14 | ✅ testable | – |
| SD15 | ✅ testable | `RestoreBulkAction` wired |
| SD16 | ⚠️ **NOT WIRED** | `ForceDeleteAction` imported but NOT in actions |
| SD17 | ⚠️ **GAP** | – |

### 4.4 `FlightSystem`

| SD# | Status | Notes |
|---|---|---|
| SD1 | ✅ testable | Admin Resource (has View page) |
| SD2 | ✅ testable | `DeleteAction` (refuses if balance ≠ 0 OR has carriers) |
| SD3 | ✅ testable | – |
| SD4 | ✅ testable | – |
| SD5 | ⚠️ partial | TreasuryOverview uses carriers+systems |
| SD6 | ✅ testable | – |
| SD7 | ✅ testable | – |
| SD8 | ✅ testable | – |
| SD9 | ✅ testable | Carriers/bookings still linked |
| SD10 | ✅ testable | – |
| SD11 | ✅ testable | Dashboard |
| SD12 | ✅ testable | `FlightTreasuryController@systemTransactions` |
| SD13 | ✅ testable | – |
| SD14 | ✅ testable | – |
| SD15 | ✅ testable | `RestoreBulkAction` wired |
| SD16 | ⚠️ **NOT WIRED** | `ForceDeleteAction` imported but NOT in actions |
| SD17 | ⚠️ **GAP** | – |

### 4.5 `AirlineCredit`

| SD# | Status | Notes |
|---|---|---|
| SD1 | ✅ testable | Root Resource |
| SD2 | ✅ testable | `DeleteAction` |
| SD3 | ✅ testable | – |
| SD4 | ✅ testable | – |
| SD5 | ⚠️ N/A | No Vue listing |
| SD6 | ✅ testable | – |
| SD7 | ❌ **NO API route** | – |
| SD8 | ✅ testable | – |
| SD9 | ✅ testable | RefundRequest still linked |
| SD10 | ✅ testable | – |
| SD11 | ⚠️ partial | Treasury may reference |
| SD12 | ✅ testable | – |
| SD13 | ⚠️ partial | – |
| SD14 | ✅ testable | – |
| SD15 | ❌ **NOT SUPPORTED** | No restore UI |
| SD16 | ❌ **NOT SUPPORTED** | No force-delete UI |
| SD17 | ⚠️ **GAP** | – |

### 4.6 `FlightPayment`

| SD# | Status | Notes |
|---|---|---|
| SD1 | ⚠️ **indirect** | Created via `FlightBookingService::addPayment` |
| SD2 | ⚠️ **indirect** | No UI; only via DB or mass operation |
| SD3 | ✅ testable | – |
| SD4 | ✅ testable | – |
| SD5 | ⚠️ **partial** | `FlightShow.vue` payments listing |
| SD6 | ❌ **NO Resource** | – |
| SD7 | ❌ **NO route** | – |
| SD8 | ✅ testable | – |
| SD9 | ✅ testable | Booking still linked |
| SD10 | ✅ testable | – |
| SD11 | ⚠️ partial | Dashboard paid totals |
| SD12 | ✅ testable | – |
| SD13 | ✅ testable | – |
| SD14 | ✅ testable | – |
| SD15 | ❌ **NOT SUPPORTED** | – |
| SD16 | ❌ **NOT SUPPORTED** | – |
| SD17 | ❌ **NOT TESTABLE** | No UI |

⚠️ **Critical:** `FlightPayment::deleted_at` يؤثر على `FlightBooking::getPaidAmountAttribute()` (computed via `with payments`).

### 4.7 `RefundRequest`

| SD# | Status | Notes |
|---|---|---|
| SD1 | ✅ testable | Root Resource |
| SD2 | ✅ testable | `DeleteAction` + `process` (status=processed only) |
| SD3 | ✅ testable | – |
| SD4 | ✅ testable | – |
| SD5 | ⚠️ partial | `FlightShow.vue` may show refund info |
| SD6 | ✅ testable | – |
| SD7 | ✅ testable | `RefundController@destroy` (via `RefundService::reverseRefundRequest`) |
| SD8 | ✅ testable | – |
| SD9 | ✅ testable | Booking still linked |
| SD10 | ✅ testable | – |
| SD11 | ⚠️ partial | – |
| SD12 | ✅ testable | – |
| SD13 | ✅ testable | – |
| SD14 | ✅ testable | – |
| SD15 | ❌ **NOT SUPPORTED** | – |
| SD16 | ❌ **NOT SUPPORTED** | – |
| SD17 | ⚠️ **GAP** | – |

### 4.8 `TicketModification`

| SD# | Status | Notes |
|---|---|---|
| SD1 | ✅ testable | Admin Resource |
| SD2 | ✅ testable | `reverseConfirmation` bulk action |
| SD3 | ✅ testable | – |
| SD4 | ✅ testable | – |
| SD5 | ⚠️ partial | `FlightShow.vue` modifications |
| SD6 | ✅ testable | – |
| SD7 | ✅ testable | `ModificationController@destroy` |
| SD8 | ✅ testable | – |
| SD9 | ✅ testable | Booking still linked |
| SD10 | ✅ testable | – |
| SD11 | ⚠️ partial | – |
| SD12 | ✅ testable | – |
| SD13 | ✅ testable | – |
| SD14 | ✅ testable | – |
| SD15 | ❌ **NOT SUPPORTED** | – |
| SD16 | ❌ **NOT SUPPORTED** | – |
| SD17 | ⚠️ **GAP** | – |

### 4.9 `Flight` (Legacy)

| SD# | Status | Notes |
|---|---|---|
| SD1 | ❌ **NO Resource** | – |
| SD2 | ❌ **NO UI/API** | – |
| SD3-SD17 | ❌ **NOT_TESTABLE** | No user-facing surface. Legacy table. Decision: keep as data-only. |

---

## 5. Cross-Entity SD Matrix (XSD)

| XSD# | Scenario | Booking | Carrier | Group | System | Credit | Payment | Refund | Modification |
|---|---|---|---|---|---|---|---|---|---|
| XSD1 | Cascade payment on booking delete | impacted | – | – | – | – | linked | – | – |
| XSD2 | RefundRequest deletion → AirlineCredit status | – | – | – | – | reversed | – | soft-deleted | – |
| XSD3 | AirlineTransaction preservation on parent delete | – | – | – | – | – | – | – | – |
| XSD4 | Balance restoration on carrier delete | – | refunded | – | – | – | – | – | – |
| XSD5 | Dropdown filtering (active only) | – | ✅ | ✅ | ✅ | – | – | – | – |
| XSD6 | Soft-deleted parent relation (e.g., booking→customer) | parent | – | – | – | – | – | – | – |
| XSD7 | TrashedBookingView shows trashed parent | ✅ | – | – | – | – | – | – | – |
| XSD8 | DB-wide soft-deleted count | – | – | – | – | – | – | – | – |

---

## 6. Pre-Discovered Gaps

### 6.1 Critical Gaps

**Gap #1 — `ForceDeleteAction` Imported but NOT Wired**
- `FlightCarrierResource`, `FlightGroupResource`, `FlightSystemResource` (Admin) يستوردون `ForceDeleteAction` لكن لا يربطونه بـ actions array.
- لا يستطيع المستخدم force-delete عبر Filament.
- لتفعيل: إضافة `Tables\Actions\ForceDeleteAction::make()` إلى `getActions()`.

**Gap #2 — `RestoreAction` على Flights جزئياً**
- `FlightCarrierResource` يستورد `RestoreAction` لكن فقط `RestoreBulkAction` يُستخدم. Restore لـ single record(s) ليس بديهياً.
- Decision: تأكيد إذا يعرض أو يستخدم في single-record restore.

**Gap #3 — لا `Restore` لـ `FlightBooking`**
- `FlightBookingResource` (Admin) لا يستخدم `RestoreAction` أو `RestoreBulkAction` على الإطلاق.
- إذا حُذف booking بالخطأ، الـ staff لا يمكنه استعادته إلا عبر DB.
- Decision: ما هو الـ contract المقصود؟ هل الـ `deleteBookingWithReversal` نهائي؟

**Gap #4 — لا `Restore` لـ `FlightPayment`, `RefundRequest`, `TicketModification`, `AirlineCredit`**
- كلها `SoftDeletes` لكن لا restore UI.
- Decision: ما الـ contract؟ إذا الـ UI لا يدعمه، الـ soft-delete عديم الفائدة.

### 6.2 Architectural Gaps

**Gap #5 — `FlightPricing` (singular) لا Soft-Delete**
- حجزًا EGP legacy. لا restore لو حُذف.

**Gap #6 — `AirlineAccount` (legacy) لا Soft-Delete**
- القرار الصريح: "hard delete only". لكن الـ soft-delete الجديد للـ `FlightCarrier` يترك نظامين متضاربين.

**Gap #7 — `FlightPassenger`, `FlightSegment`, `FlightTicket` لا Soft-Delete**
- لو booking له passenger واحد وحُذف passenger، العلاقة مكسورة.
- Decision: لا يحذف passengers من DB إلا مع booking.

### 6.3 Financial Gaps

**Gap #8 — `FlightBooking::getPaidAmountAttribute()` يستثني Soft-Deleted Payments**
- لو soft-delete لـ payment بالخطأ → `paid_amount` يقل → `remaining_amount` يزيد → over-collection ممكن.
- يجب اختبار: soft-delete payment → `paid_amount` يصبح صفر → refund يعمل على 0 → ينتج record غريب.

**Gap #9 — Booking Cancellation vs FlightPayment Soft-Delete**
- `FlightBookingService::cancelBooking` يُنشئ `FlightRefund` و `FlightPayment` يُبقى. لكن لا يُمسح الـ payments.
- Decision: هل المدفوعات الأصلية soft-deleted بعد cancel؟

**Gap #10 — `deleteBookingWithReversal` Reverses GL لكن ما يفعل بالـ Payments**
- لا يوضح إذا soft-delete للـ payments أو يحتفظ بها.
- Decision: ما الـ contract؟

### 6.4 Authorization Gaps

**Gap #11 — No `admin` middleware على Flight routes**
- كل authenticated user يقدر يحذف FlightBooking عبر API.
- في الـ Admin Filament UI، الـ Resource لا يستخدم `Policy`، فأي user يمكنه الـ mass delete.
- Impact: لا يوجد منع لـ unauthorized delete.

**Gap #12 — `PassengerController::index` يعمل withTrashed على eager-load**
- يعرض passengers من bookings محذوفة. هذا intention أو oversight؟

---

## 7. الـ Pre-Execution Coverage Estimate

| Entity | SD Cells Testable | SD Cells Partial | SD Cells Not Supported | SD Cells Not Testable |
|---|---|---|---|---|
| `FlightBooking` | 14 | 2 | 0 | 0 (17) |
| `FlightCarrier` | 13 | 2 | 1 (ForceDelete UI) | 0 (17) |
| `FlightGroup` | 13 | 2 | 1 | 0 (17) |
| `FlightSystem` | 13 | 2 | 1 | 0 (17) |
| `AirlineCredit` | 9 | 4 | 2 | 0 (17) |
| `FlightPayment` | 8 | 4 | 2 | 1 (17) |
| `RefundRequest` | 10 | 4 | 2 | 0 (17) |
| `TicketModification` | 10 | 4 | 2 | 0 (17) |
| `Flight` (legacy) | 0 | 0 | 0 | 17 (17) |
| **TOTAL** | **90** | **24** | **11** | **18** (**143**) |

**% Testable (incl. partial):** ~80%
**% Not Testable:** ~13%

---

## 8. الـ Financial-Integrity Test Plan

**Rule:** لا نفترض أن soft-delete يزيل financial effect. الـ audit يحدد ما يحدث فعلياً.

### 8.1 Soft-Delete Impact Tests

| Test | Expected | Risk if Wrong |
|---|---|---|
| Soft-delete `FlightPayment` → `FlightBooking::paid_amount` يقل | YES (by code) | over-collection |
| Soft-delete `FlightBooking` → customers/payments لا يُمسحون | YES (no cascade) | orphan rows |
| Soft-delete `FlightCarrier` (with balance=0) → يحذف؟ | should soft-delete | broken UI |
| Soft-delete `FlightCarrier` (with balance≠0) → controller يرفض | YES (per code) | hard delete |
| Soft-delete `FlightGroup` → bookings still linked | YES | orphan bookings |
| Soft-delete `FlightSystem` → carriers still linked | YES | orphan carriers |
| Soft-delete `RefundRequest` → AirlineCredit stays | YES | but credit state needs check |
| Soft-delete `TicketModification` → balance debit still in DB | YES | unbalanced ledger |
| Soft-delete `AirlineCredit` → associated refund unaffected | YES | but refund status unchanged |

### 8.2 Force-Delete Impact Tests

| Test | Expected | Risk if Wrong |
|---|---|---|
| Force-delete `FlightPayment` → `paid_amount` يقل دائماً | YES | dup detection broken |
| Force-delete `FlightBooking` → orphans | YES | data integrity broken |
| Force-delete `FlightCarrier` → group.bookings orphaned | YES | cascade not enforced |

---

## 9. Execution Plan (Phase SD.0 → SD.5)

### SD.0 — Setup
- Create `audit_user` with `modifications.confirm` permission
- Verify `FlightBooking` exists with payments
- Verify `FlightCarrier` exists with balance

### SD.1 — Direct Delete (Hardcoded SQL)
- `UPDATE flight_bookings SET deleted_at = NOW() WHERE id = ?`
- Verify row still exists
- Verify `paid_amount` accessor behavior
- Verify `find()` returns null

### SD.2 — Filament Delete via UI
- Browse to Admin resource
- Click Delete
- Verify `deleted_at` populated
- Verify TrashedFilter shows the row
- Verify restore button appears

### SD.3 — API Delete via HTTP
- `DELETE /api/v1/flight/bookings/{id}` as admin user
- Verify reverse-GL
- Verify soft-delete row
- Verify unauthorized user gets 403

### SD.4 — Cross-Entity Tests
- XSD1: Soft-delete booking → payments still linked
- XSD2: Soft-delete RefundRequest → AirlineCredit status
- XSD3: Force-delete AirlineTransaction → ledger broken

### SD.5 — Coverage Report
- Count tested scenarios per entity
- List untested/not-supported scenarios
- Final report

---

## 10. Acceptance Criteria

**Verdict Legend:**
- ✅ **PASS** — Soft-delete behavior matches expected contract
- ⚠️ **WARN** — Behavior works but with caveats
- ❌ **FAIL** — Soft-delete corrupts data
- 🚫 **NOT_SUPPORTED** — Feature not implemented
- ❓ **NOT_TESTABLE** — Cannot test due to missing UI

**Verdict Rule:**
- أي soft-delete corrupts financial data → **NO-GO**
- 3+ HIGH findings → **NO-GO**
- 1-2 HIGH findings → **GO WITH FINDINGS**
- 0 HIGH, no critical → **GO**

---

## 11. Pre-Execution Gaps Summary

| # | Gap | Severity |
|---|---|---|
| 1 | `ForceDeleteAction` not wired | LOW |
| 2 | `RestoreAction` partial | LOW |
| 3 | No `Restore` for `FlightBooking` | MEDIUM |
| 4 | No `Restore` for `FlightPayment`, `RefundRequest`, `TicketModification`, `AirlineCredit` | MEDIUM |
| 5 | `FlightPricing` no soft-delete | LOW |
| 6 | `AirlineAccount` no soft-delete (legacy) | MEDIUM |
| 7 | `FlightPassenger`, `FlightSegment`, `FlightTicket` no soft-delete | LOW |
| 8 | `paid_amount` accessor + soft-deleted payments | **HIGH** |
| 9 | Booking cancellation vs payment soft-delete | **HIGH** |
| 10 | `deleteBookingWithReversal` + payment handling | **HIGH** |
| 11 | No admin middleware on Flight routes | **CRITICAL** |
| 12 | `PassengerController::index` withTrashed | LOW |

**Expected verdict based on pre-execution discovery:** ⚠️ **GO WITH FINDINGS** — assuming soft-delete follows Eloquent default behavior, but #8, #9, #10 need verification.

---

## 12. الـ Next Steps

1. ✅ Inventory (done)
2. ✅ Soft-Delete Matrix (this file)
3. ⬜ Verify Staging DB connection
4. ⬜ Run baseline `flight_module_full_e2e.php` on Staging
5. ⬜ Build & run Phase A (Auth) — covers Gap #11
6. ⬜ Build & run Phase S (Soft-Delete) — covers all gaps
7. ⬜ Build & run Phase F (Filament)
8. ⬜ Build & run Phase H (Multi-Currency)
9. ⬜ Build & run Phase I (Transaction Type)
10. ⬜ Build & run Phase J (Treasury)
11. ⬜ Build & run Phase L (Validation)
12. ⬜ Build & run Phase M (Reports)
13. ⬜ Build & run Phase N (DB Integrity)
14. ⬜ Build & run Phase O (Real-Life Scenarios)
15. ⬜ Build & run Phase P (Regression)
16. ⬜ Build & run Phase Q (Coverage)
17. ⬜ Build & run Phase T (Idempotency)
18. ⬜ Build & run Phase V (Vue Frontend)
19. ⬜ Final Report (`FLIGHT_MODULE_FULL_E2E_AUDIT_20260813.md`)

---

**END OF SOFT-DELETE MATRIX**
