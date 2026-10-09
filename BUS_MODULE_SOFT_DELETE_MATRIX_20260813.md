# Bus Module — Soft Delete / Restore / Force Delete Pre-Execution Matrix

> **تاريخ:** 2026-08-13  
> **الغرض:** matrix شامل لكل الـ soft-deletable entities في الـ Bus module + السيناريوهات الـ testable قبل التنفيذ  
> **البيئة:** isolated SQLite (`storage/app/local_bus_audit.sqlite`)  
> **حالة:** 🔴 **PRE-EXECUTION** (لم تُشغّل أي test بعد)

---

## 1. النطاق (Scope)

كل الـ Bus-related models اللي بتستخدم `Illuminate\Database\Eloquent\SoftDeletes` trait. كمان الـ models اللي مرتبطة بيهم واللي بتتأثر بـ soft-delete cascade (Customer, Account, Transaction, Inventory, Supplier).

---

## 2. الـ Soft-Deletable Models Inventory

| # | Model | File | Migration | SoftDeletes | ModelDeletionGuard | حذف إضافي في Observer |
|---|---|---|---|---|---|---|
| 1 | `App\Models\Bus\BusBooking` | `app/Models/Bus/BusBooking.php` | `2026_04_27_230404_create_bus_bookings_table.php` | ✅ | ✅ | — |
| 2 | `App\Models\Bus\BusInventory` | `app/Models/Bus/BusInventory.php` | `2026_04_27_230403_create_bus_inventories_table.php` | ✅ | ✅ | يرفض لو فيه bookings موجودة |
| 3 | `App\Models\Bus\BusCompany` | `app/Models/Bus/BusCompany.php` | `2026_04_27_230344_create_bus_companies_table.php` | ✅ | ✅ | — |
| 4 | `App\Models\Bus\BusPayment` | `app/Models/Bus/BusPayment.php` | `2026_05_02_030000_create_bus_payments_table.php` + `2026_07_11_140000_add_soft_deletes_to_bus_payment_tables.php` | ✅ | ❌ | — |
| 5 | `App\Models\Bus\BusRefundRequest` | `app/Models/Bus/BusRefundRequest.php` | `2026_05_14_230032_create_bus_refund_requests_table.php` + `2026_07_11_140000_*` | ✅ | ❌ | — |
| 6 | `App\Models\Bus\BusCompanyPayment` | `app/Models/Bus/BusCompanyPayment.php` | `2026_04_27_230404_create_bus_company_payments_table.php` + `2026_07_11_140000_*` | ✅ | ❌ | — |
| 7 | `App\Models\Bus\BusTicket` | `app/Models/Bus/BusTicket.php` | `2026_04_27_160500_create_bus_tickets_table.php` | ✅ (per migration) | ❌ | — (orphan module) |

---

## 3. الـ User-Facing Surface Area

| Entity | Filament Delete | Vue Delete | API Delete | **Restore UI** | **Force-Delete UI** |
|---|---|---|---|---|---|
| **BusBooking** | ❌ مفيش action | ✅ `BusShow.vue:765` (`store.deleteBooking`) | ✅ `DELETE /api/v1/bus/bookings/{id}` | ❌ | ❌ |
| **BusInventory** | ❌ مفيش action | ✅ `BusInventoryIndex.vue:605` (`store.deleteInventory`) | ✅ `DELETE /api/v1/bus/inventories/{id}` | ❌ | ❌ |
| **BusCompany** | ✅ Custom `Action::make('deleteCompany')` في `BusCompanyResource.php:203` (بيستدعي `BusCompanyService::deleteCompany()`) | ✅ `busStore.deleteCompany` | ✅ `DELETE /api/v1/bus/companies/{id}` | ❌ | ❌ |
| **BusPayment** | ❌ | ❌ | ❌ | ❌ | ❌ |
| **BusRefundRequest** | ❌ | ❌ | ❌ | ❌ | ❌ |
| **BusCompanyPayment** | ✅ Custom resource (Filament only) | ❌ | ❌ | ❌ | ❌ |
| **BusTicket** | ✅ Custom resource (Filament only — orphan) | ❌ | ❌ | ❌ | ❌ |

---

## 4. TrashedFilter Exposure في Filament

| Resource | TrashedFilter | Restore/ForceDelete actions |
|---|---|---|
| `BusBookingResource` | ✅ في `tables.filters` (line 279) | ❌ مفيش restore/force-delete action |
| `BusInventoryResource` | ✅ في `tables.filters` (line 235) | ❌ مفيش restore/force-delete action |
| `BusCompanyResource` | ✅ في `tables.filters` (line 176) | ❌ مفيش restore/force-delete action |

> **⚠️ finding:** TrashedFilter موجود لكن مفيش restore/force-delete actions → users ممكن يكتشفوا records محذوفة لكن مش هيقدروا يستعيدوها عبر الـ UI.

---

## 5. الـ 17 Soft-Delete Scenario Matrix (لكل entity)

| Scenario | BusBooking | BusInventory | BusCompany | BusPayment | BusRefundRequest | BusCompanyPayment | BusTicket |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| **SD1** Create via UI | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ (Filament) | ✅ (Filament) |
| **SD2** Delete via UI | ✅ (Vue) | ✅ (Vue) | ✅ (Filament+Vue) | ❌ | ❌ | ✅ (Filament) | ✅ (Filament) |
| **SD3** deleted_at populated | ✅ testable | ✅ testable | ✅ testable | ❌ no endpoint | ❌ no endpoint | ⚠️ Filament only | ⚠️ Filament only |
| **SD4** row still present | ✅ testable | ✅ testable | ✅ testable | ✅ testable (DB only) | ✅ testable (DB only) | ✅ testable (DB only) | ✅ testable (DB only) |
| **SD5** excluded from Vue listing | ✅ testable | ✅ testable | ✅ testable (filter) | n/a | n/a | n/a | n/a |
| **SD6** excluded from Filament listing | ✅ testable | ✅ testable | ✅ testable | ✅ testable (no resource) | ✅ testable (no resource) | ✅ testable | ✅ testable |
| **SD7** excluded from API listing | ✅ testable | ✅ testable | ✅ testable | ✅ testable (no endpoint) | ✅ testable (no endpoint) | ✅ testable (no endpoint) | ✅ testable (no endpoint) |
| **SD8** direct lookup behavior | ✅ testable (controller use withTrashed) | ✅ testable | ✅ testable | ✅ testable (DB only) | ✅ testable (DB only) | ✅ testable (DB only) | ✅ testable (DB only) |
| **SD9** relations after delete | ✅ testable (`inventoryWithTrashed`) | ✅ testable | ✅ testable | ⚠️ booking always nullable | n/a | ⚠️ company nullable | n/a |
| **SD10** search/filters/counts | ✅ testable | ✅ testable | ✅ testable | ✅ testable (no UI) | ✅ testable (no UI) | ✅ testable (Filament) | ✅ testable (Filament) |
| **SD11** Dashboard excludes deleted | ✅ testable | ✅ testable | ✅ testable | ✅ testable (no impact) | ✅ testable (no impact) | ✅ testable (no impact) | ✅ testable (no impact) |
| **SD12** Treasury excludes deleted | ✅ testable | ✅ testable | ✅ testable (via accounts) | ✅ testable | ✅ testable | ✅ testable | ✅ testable (no impact) |
| **SD13** Reports exclude deleted | ✅ testable | ✅ testable | ✅ testable | ✅ testable | ✅ testable | ✅ testable | ✅ testable |
| **SD14** Re-delete | ✅ testable (idempotent `deleteBookingWithReversal`) | ✅ testable | ✅ testable | ✅ testable (DB level) | ✅ testable (DB level) | ✅ testable | ✅ testable |
| **SD15** Restore | ❌ NOT SUPPORTED | ❌ NOT SUPPORTED | ❌ NOT SUPPORTED | ❌ NOT SUPPORTED | ❌ NOT SUPPORTED | ❌ NOT SUPPORTED | ❌ NOT SUPPORTED |
| **SD16** Force-Delete | ❌ NOT SUPPORTED | ❌ NOT SUPPORTED | ❌ NOT SUPPORTED | ❌ NOT SUPPORTED | ❌ NOT SUPPORTED | ❌ NOT SUPPORTED | ❌ NOT SUPPORTED |
| **SD17** Unauthorized delete | ✅ testable | ✅ testable | ✅ testable | ✅ testable (no endpoint) | ✅ testable (no endpoint) | ✅ testable (Filament only) | ✅ testable (Filament only) |

---

## 6. الـ 8 Cross-Entity Matrix

| Scenario | Description | Status |
|---|---|---|
| **XSD1** Booking soft-delete → payments soft-deleted | ✅ testable (per `deleteBookingWithReversal` line 1098) |
| **XSD2** Booking soft-delete → refund_requests.transaction_id NULL | ✅ testable (Fix #12, line 1120-1122) |
| **XSD3** Booking soft-delete → Transaction rows preserved | ✅ testable (additive reversals) |
| **XSD4** Booking soft-delete → Account balances restored | ✅ testable |
| **XSD5** Inventory soft-delete → not in Vue create dropdown | ✅ testable |
| **XSD6** Booking → soft-deleted inventory → inventory()=null | ✅ testable (gotcha: default relation fails) |
| **XSD7** Company soft-delete → inventories/bookings still list | ✅ testable (parent hidden, children surface) |
| **XSD8** All soft-deletable + count soft-deleted | ✅ testable (DB-wide) |

---

## 7. الـ Pre-Discovered Gaps (قبل التشغيل)

### 🔴 Critical
1. **Restore is NOT implemented in ANY user-facing layer** — no endpoint, no Filament action, no Vue button. SD15 = **FAIL** for all 7 entities.
2. **Force-Delete is NOT implemented in ANY user-facing layer** — only test cleanup scripts. SD16 = **FAIL** for all 7 entities.
3. **TrashedFilter exists but no RestoreAction** — users can SEE trashed records but cannot restore them.
4. **Vue busStore has no restore/forceDelete actions** — none of the bus pages support recovery.

### ⚠️ Architectural
5. **`BusBooking::deleting` observer blocks direct `$booking->delete()` outside `BusBooking::run()`** — guard is real and works.
6. **`BusInventory::deleting` observer ALSO refuses if `bookings()->exists()`** — extra safety layer (correct behavior).
7. **`BusCompany::deleting` observer blocks direct delete outside `BusCompany::run()`** — same guard.
8. **`BusPayment`, `BusRefundRequest`, `BusCompanyPayment`** do not have `ModelDeletionGuard` — but they have no delete endpoint either, so deletion via UI is impossible (defense by omission).
9. **`BusTicket`** is orphan: table + Filament resource + migration + form requests exist but no Service, no API, no Vue page.

### ⚠️ Financial / Contract
10. **`deleteBookingWithReversal`** is additive — never destructive. Transactions survive, account balances restored by reversal journals, payments soft-deleted but never destroyed.
11. **Idempotency guard** in `deleteBookingWithReversal` line 1075: re-delete attempt throws "هذا الحجز محذوف بالفعل" — perfect, no double-reversal.
12. **`BusRefundRequest.transaction_id` is nulled out** on booking reversal (Fix #12) — prevents stale refund→transaction links after reversal.

### 🔴 Authorization
13. **`DELETE /v1/bus/*` endpoints are NOT gated by `admin` middleware** — only `auth:sanctum`. The legacy `CheckPermission` middleware maps `buses.delete` to admin/manager only, but the controller-level middleware does NOT enforce it. **SD17 unauthorized attempts will LIKELY succeed at HTTP level** — depends on whether `CheckPermission` is invoked inside the controller. Need to verify.
14. **`Filament DeleteBulkAction`/`DeleteAction` imports exist** in BusBookingResource but are NOT registered in `recordActions` — dead imports.

---

## 8. التغطية الإجمالية (Pre-Execution Estimate)

| Status | Count | Examples |
|---|---|---|
| ✅ **TESTABLE** | ~95/119 cells | Create/Delete/Vue/API checks for the 3 main entities |
| ⚠️ **PARTIAL_TESTABLE** | ~10/119 cells | Filament-only entities (BusPayment, BusRefundRequest, BusCompanyPayment, BusTicket) |
| ❌ **NOT_SUPPORTED** | ~14/119 cells | SD15 Restore (7) + SD16 Force-Delete (7) |
| ❌ **NOT_TESTABLE** | few | Logic that requires a restore path we know doesn't exist |

**Coverage estimate:** ~80% of discovered soft-delete scenarios can be exercised end-to-end.

---

## 9. الـ Financial-Integrity Test Plan (مهم جداً per user's rule)

> **القاعدة:** "do not assume that soft-deleting a booking/payment/account/transaction should remove its financial effect. Determine the actual business contract from the codebase and test that contract explicitly."

**الـ contract المكتشف من الكود:**
1. `BusBooking` soft-delete = additive reversal:
   - `recordJournalTransfer` يُنشئ **جديد** entries عكسيّة
   - الـ `Transaction` الأصلية تبقى موجودة (`related_type=BusBooking`، `related_id` يبقى)
   - الـ `Account.balance` يتحدّث حقيقياً
2. `BusPayment` soft-delete (cascade): الـ `transaction_id` يبقى في الـ Payment؛ الـ Transaction row يبقى
3. `BusRefundRequest`: الـ `transaction_id` يتفعّل null-out لما الـ booking يتـ soft-delete (Fix #12)
4. **لا يوجد hard-delete** في الـ user-facing flows (cleanup scripts فقط)
5. **لا يوجد accidental destruction** للحسابات أو الـ treasury

**الـ tests هتتحقق:**
- ✅ `Transaction::count()` قبل و بعد = نفسه (لا جديد، لا محذوف) لأي booking عادي
- ⚠️ للـ `deleteBookingWithReversal`: لازم يكون `count() + 1` per payment reversed (الـ additive reversal entry)
- ✅ `Account.balance` بعد الـ delete = الرصيد قبل الـ booking (complete restoration)
- ⚠️ الـ `Customer.accounts[booking.currency].balance` بعد delete = قبل الـ booking

---

## 10. Execution Plan

```
Phase SD.0: Run scripts/bus_audit_setup.php (already done — verified working)
Phase SD.1: Create test data via BusBookingService.createBooking() (programmatic seed)
Phase SD.2: Run scripts/bus_audit_soft_delete_run.php
   → 17 SD × 7 entities = 119 scenario cells
   → 8 XSD cross-entity
   → Total = 127 assertions
Phase SD.3: Run scripts/bus_audit_soft_delete_financial.php
   → Per-account reconciliation
   → Transaction preservation check
Phase SD.4: Run scripts/bus_audit_soft_delete_authz.php
   → 3 roles × 7 entities × 3 ops (delete/restore/force-delete) = 63 auth cells
Phase SD.5: Generate phase-SD report section (will be appended to BUS_MODULE_FULL_E2E_AUDIT_20260813.md)
```

---

## 11. Acceptance Criteria (Pass / Fail)

| اختصار | المعنى | Where the cell lives |
|---|---|---|
| ✅ **PASS** | الاختبار نجح + الـ behavior يطابق الـ contract | SD-X = ✓ |
| ❌ **FAIL** | الاختبار فشل أو الـ behavior غلط | SD-X = ✗ |
| ❌ **NOT_SUPPORTED** | الـ feature مش موجودة في الـ codebase (gap) | SD15, SD16 |
| ⚠️ **NOT_TESTABLE** | الـ feature موجودة بس الـ test setup مش ممكن (deps, infra) | rare cases |

**Verdict rule (per user's instruction):**
> "Any corruption, accidental hard delete, broken restore, unauthorized delete/restore, broken relationship, or financial inconsistency must contribute to a NO-GO verdict."

**Expected verdict based on pre-execution discovery:**
- SD15 Restore = NOT_SUPPORTED × 7 entities → **NO-GO contributor**
- SD16 Force-Delete = NOT_SUPPORTED × 7 entities → **NO-GO contributor**
- Authorization gaps → likely **NO-GO contributor** if non-admin can delete
