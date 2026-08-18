# Tourism Booking Edit — Financial Incident Report
**Incident Date:** 2026-08-17
**Resolution Date:** 2026-08-18
**Severity:** CRITICAL (financial drift — phantom 20 JOD profit on Tourism booking edit)
**Status (Local):** ✅ CLOSED — No-Edit Contract enforced at all 3 layers (Route + Service + Controller)
**Status (Server/Staging):** ⏳ PENDING user approval before any Server modifications

---

## 1. Incident Summary

Admin Edit flow on a Tourism (Flight/Hajj-Umrah/Visa) booking allowed changing the selling
price from 50 → 30 JOD (a 20 JOD reduction) **without reversing the 20 JOD income
transaction**. This produced phantom profit +20 JOD that was never actually earned.

**Root cause:** Multiple layers of Edit paths existed with inconsistent guards:
- `Route::apiResource('bookings', FlightController::class)` was unrestricted → PUT/PATCH
  returned 200 OK and routed to `FlightController::update()` → `FlightBookingService::updateBooking()`.
- A dedicated `Route::post('bookings/{flightBooking}/prices', ...)` route also allowed
  price-reposts via `FlightController::updatePrices()` → `FlightBookingService::updatePrices()`.
- Explicit `Route::match(['put', 'patch'], 'bookings/{hajjUmra}', ...)` and `.../bookings/{visa}'`
  lines BYPASSED the apiResource restrictions.
- The price-repost path inside `update()` and `updatePrices()` was additive, but the
  edit-cancel-restore flow assumed the original transactions were still positive — leading
  to drift when the new selling_price < old selling_price.

**Defense missing:** No single layer ensured that financial fields (selling_price,
purchase_price, currency) were locked post-creation. No test enforced 405 at the route
layer + LogicException at the Service layer.

---

## 2. Executive Decision: **Option A — Permanent Edit Cancellation**

Per user decision: **"إلغاء Edit نهائيًا من Tourism فقط"** (cancel Edit permanently from Tourism only).

- **Tourism division** (Flight + Hajj/Umrah + Visa): Edit paths REMOVED at all layers.
- **Office division** (Bus): unchanged.
- **Non-Tourism modules** (Customer, Program, Online, Wallet, Fawry, etc.): unchanged.
- **Correction path for Tourism booking:** POST `/cancel` is the supported path.

---

## 3. No-Edit Contract — Three Layers Enforced

### Layer 1 — Routes (`routes/api.php`)
```php
// INCIDENT-2026-08-17: Tourism no-edit contract. PUT/PATCH returns 405 by design.
->except(['update']);        // Flight bookings + Aviation
->only(['index','show','store','destroy']);  // Aviation
// POST /bookings/{flightBooking}/prices — REMOVED
// Route::match(['put','patch'], 'bookings/{hajjUmra}', ...) — REMOVED
// Route::match(['put','patch'], 'bookings/{visa}', ...) — REMOVED
```

### Layer 2 — Controllers (5 files)
```php
// INCIDENT-2026-08-17: Tourism no-edit contract. PUT/PATCH removed.
//   Cancellation is the supported correction path.
```
Methods removed:
- `FlightController::update()` + `FlightController::updatePrices()`
- `HajjUmraController::update()` + `VisaBookingController::update()` + `AviationController::update()`

### Layer 3 — Services (3 files)
```php
/**
 * @deprecated INCIDENT-2026-08-17: Tourism No-Edit Contract. Always throws.
 *   Cancellation is the supported correction path.
 */
public function update(...): ...
{
    throw new \LogicException(
        '...::update is disabled by Tourism no-edit contract (2026-08-17). '
        .'Cancellation is the supported correction path.'
    );
}
```
Stubs created:
- `FlightBookingService::updateBooking()` + `FlightBookingService::updatePrices()`
- `HajjUmraBookingService::update()`
- `VisaBookingService::update()`

---

## 4. Files Modified / Deleted

### Backend — Routes
| File | Action |
|---|---|
| `routes/api.php` | Added `->except(['update'])` to Flight bookings; `->only([...])` to Aviation; removed `POST /prices` route; removed `Route::match PUT/PATCH` for Hajj/Visa |

### Backend — Controllers (4 files)
| File | Action |
|---|---|
| `app/Http/Controllers/Api/V1/Flight/FlightController.php` | Removed `update()` + `updatePrices()` |
| `app/Http/Controllers/Api/V1/Flight/AviationController.php` | Removed `update()` |
| `app/Http/Controllers/Api/V1/HajjUmraController.php` | Removed `update()` |
| `app/Http/Controllers/Api/V1/Visa/VisaBookingController.php` | Removed `update()` |

### Backend — Services (3 files)
| File | Action |
|---|---|
| `app/Services/Flight/FlightBookingService.php` | `updateBooking()` + `updatePrices()` → LogicException stub |
| `app/Services/HajjUmra/HajjUmraBookingService.php` | `update()` → LogicException stub |
| `app/Services/Visa/VisaBookingService.php` | `update()` → LogicException stub |

### Frontend — Vue Stores
| File | Action |
|---|---|
| `resources/js/stores/flightStore.js` | Removed `updateBooking()` action |

### Frontend — Vue Pages (3 files deleted)
| File | Action |
|---|---|
| `resources/js/views/flights/FlightEdit.vue` | DELETED |
| `resources/js/views/hajjUmra/HajjUmraEdit.vue` | DELETED |
| `resources/js/views/visa/VisaEdit.vue` | DELETED |

### Frontend — Router
| File | Action |
|---|---|
| `resources/js/router/index.js` | Removed `flights.edit`, `hajj.edit`, `visa.edit` routes |

### Frontend — Show Pages
| File | Action |
|---|---|
| `resources/js/views/hajjUmra/HajjUmraShow.vue` | Removed `updateStatus()` function |
| `resources/js/views/visa/VisaShow.vue` | Removed `updateStatus()` function |

### Frontend — Create Flow
| File | Action |
|---|---|
| `resources/js/views/flights/FlightCreate.vue` | Simplified — always calls `createBooking()` |

### Test Cleanup — Obsolete Edit Tests Deleted (15+ files touched)

**Test files with `test_update_*` / `test_*_after_edit` / `test_modification_*` removed or marked obsolete:**
- `tests/Feature/HajjUmra/HajjUmraApiTest.php` — `test_update_selling_price_reposts_income_transaction` DELETED
- `tests/Feature/HajjUmra/HajjUmraControllerTest.php` — `test_update_modifies_selling_price` DELETED
- `tests/Feature/HajjUmra/HajjUmraProductionE2ETest.php` — `test_8`, `test_9`, `test_22`, `test_25` DELETED; PATCH steps removed from `test_13` + `test_29`
- `tests/Feature/TourismDivision/HajjUmraProductionTest.php` — `test_update_selling_price_reposts_income_additively` DELETED
- `tests/Feature/Visa/VisaBookingControllerTest.php` — `test_update_modifies_selling_price` DELETED
- `tests/Feature/Visa/VisaProductionE2ETest.php` — `test_8`, `test_9`, `test_16`, `test_21` DELETED; PATCH step removed from `test_13`
- `tests/Feature/Visa/VisaBookingServiceDeadCodeTest.php` — `test_update_rejects_cancelled_booking`, `test_update_rejects_refunded_booking`, `test_update_rejects_soft_deleted_booking` DELETED (obsolete — Service now throws LogicException unconditionally)
- `tests/Feature/TourismAudit/HajjUmraFullAuditTest.php` — `test_update_blocks_locked_selling_price`, `test_update_blocks_locked_purchase_price` DELETED
- `tests/Feature/TourismAudit/VisaFullAuditTest.php` — `test_modification_reposts_income`, `test_modification_rejects_negative_price` DELETED
- `tests/Feature/Visa/VisaApiContractTest.php` — `test_filter_by_status_submitted` refactored (uses direct `$b2->update()` to avoid Service::update)
- `tests/Feature/Visa/VisaLedgerReconciliationTest.php` — `test_repost_preserves_original_amount` DELETED
- `tests/Feature/Visa/VisaPerformanceTest.php` — `test_filter_on_large_dataset` refactored (uses direct `$b->update()`)
- `tests/Feature/Flight/AviationServiceTest.php` — `test_update_booking_via_aviation_service` DELETED
- `tests/Feature/FlightBookingFlowTest.php` — `test_update_pending_booking_sets_pnr_and_notes` DELETED
- `tests/Feature/Flight/FlightModuleDeepE2ETest.php` — `test_scenario_15_sar_pay_from_bank_modify_price_delete` DELETED

### New Test Added (Regression Contract)
| File | Action |
|---|---|
| `tests/Feature/Tourism/TourismNoEditContractTest.php` | NEW — 16 tests, 37 assertions, all PASS ✓ |

---

## 5. Verification — `TourismNoEditContractTest` 16/16 PASS ✓

The contract test enforces 3 invariants and 1 financial-safety net:

**Route layer (4 tests):** PUT/PATCH on `/api/v1/flight/bookings/{id}` returns **405**, `/api/v1/flight/aviation/{id}` returns **405**, `/api/v1/hajj-umra/bookings/{id}` returns **405**, `/api/v1/visa/bookings/{id}` returns **405**, POST `/api/v1/flight/bookings/{id}/prices` returns **404**.

**Service layer (4 tests):** `FlightBookingService::updateBooking()` + `FlightBookingService::updatePrices()` + `HajjUmraBookingService::update()` + `VisaBookingService::update()` all throw `LogicException` with INCIDENT marker message.

**Financial safety (8 tests):** After attempting an Edit:
- `selling_price` UNCHANGED
- `profit` column UNCHANGED
- No new `Transaction` rows created
- No new `AccountEntry` rows created
- Customer balance UNCHANGED
- Carrier balance UNCHANGED
- Treasury balance UNCHANGED
- All ledger invariants hold

---

## 6. Tourism Regression Sweep Results (Local)

### Tourism + TourismAudit + TourismDivision (Hajj): 139 tests PASSED ✓
### HajjUmra folder: 269 PASSED, 2 pre-existing failures (HajjUmraProgramControllerTest — program_type validation + program.selling_price field, unrelated to no-edit)
### Visa folder: 227 PASSED, 2 pre-existing failures (zero-egp + zero-purchase-price validation, unrelated to no-edit)
### Flight folder: 126 PASSED, 26 pre-existing failures (selling price = 1,360,000 EGP mismatches in test fixtures + balance restoration tests, unrelated to no-edit)

**No new failures caused by the no-edit contract.** All previously-passing Tourism tests still pass.

---

## 7. Architectural Notes for Server/Staging (when approved)

Before applying on Server:
1. **Identify the actual incident booking** on Server (search `flight_bookings`, `hajj_umra_bookings`,
   `visa_bookings` for `updated_at` between incident detection and 2026-08-18 00:00 local).
2. **Ledger trace** — list all `AccountEntry` rows for those bookings, identify phantom income
   (income transaction that was reversed but didn't net to zero).
3. **Financial damage assessment** — sum the drift per booking.
4. **Corrective plan** — propose a one-time additive journal entry that restores the original
   ledger state without touching `accounts.balance` directly (use `recordJournalTransfer` /
   `runProfitMutation` patterns).
5. **Then** apply the same code changes as Local to Server.
6. **Migration order** — git pull → `php artisan route:clear` + `config:clear` → schedule
   correction ledger entries per booking → deploy code.

**User approval is required before any Server modification.** Per security directive preserved
from prior session: NO manual `accounts.balance` edits, NO manual Transaction/AccountEntry
manipulation, NO 'fix' of historical data before determining root cause.

---

**End of Local-Side Report.**
