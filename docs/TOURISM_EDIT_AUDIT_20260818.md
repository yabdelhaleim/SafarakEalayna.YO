# Tourism Edit Surface Audit — 2026-08-18

> **Scope**: Audit every code path that allows editing a Tourism booking AFTER it has been saved.
> **Tourism division** = Flight + Hajj/Umra + Visa. Bus is Office division (out of scope).
> **Decision** (per user): إلغاء Edit نهائيًا — remove Edit entirely from Vue, API, Filament, Service.

---

## FLIGHT — Defect: Class-A silent ledger drift

| Layer | File:line | What exists | Verdict |
|---|---|---|---|
| Vue router | `resources/js/router/index.js:104-109` | `path: ':id/edit', name: 'flights.edit'` | ❌ REMOVE |
| Vue view | `resources/js/views/flights/FlightEdit.vue` | Embeds `FlightCreate` with `:is-edit="true"` | ❌ REMOVE |
| Vue store | `resources/js/stores/flightStore.js:477` | `updateBooking(id, payload)` → `PUT /api/v1/flight/bookings/{id}` | ❌ REMOVE |
| API route | `routes/api.php:167-175` | `Route::apiResource('bookings', FlightController::class)` (includes PUT) | ❌ REPLACE: drop PUT/PATCH |
| API route | `routes/api.php:224` | `Route::post('bookings/{flightBooking}/prices', [FlightController::class, 'updatePrices'])` | ❌ REMOVE |
| Controller | `app/Http/Controllers/Api/V1/Flight/FlightController.php:213-225` | `update()` → `FlightBookingService::updateBooking()` | ❌ REMOVE |
| Controller | `app/Http/Controllers/Api/V1/Flight/FlightController.php:230-246` | `updatePrices()` | ❌ REMOVE |
| FormRequest | `app/Http/Requests/Flight/UpdateFlightBookingRequest.php` | Allows `selling_price`, `purchase_price`, `currency` | ❌ REMOVE |
| FormRequest | `app/Http/Requests/Flight/UpdateFlightPricesRequest.php` | Allows price-only mutation | ❌ REMOVE |
| Service | `app/Services/Flight/FlightBookingService.php:1470-1671` `updateBooking()` | Direct `$booking->update(...)` with NO ledger reversal | ❌ REMOVE — **Class-A bug** |
| Service | `app/Services/Flight/FlightBookingService.php:1680-1744` `updatePrices()` | Same direct-update pattern | ❌ REMOVE — **Class-A bug** |
| Filament | `app/Filament/Admin/Resources/FlightBookings/Pages/EditFlightBooking.php` | Filament edit page delegates to controller | ❌ REMOVE |

### Secondary Flight defect (Class-C silent no-op)
`FlightBookingService::updateBooking()` at line 1477:
```php
$pending = $booking->status === FlightBookingStatus::PENDING;
```
ALL financial field mutations are wrapped in `if ($pending)` (lines 1506-1632). When booking is **not PENDING** (e.g., after full payment), the PUT returns HTTP 200 but the column update is silently dropped. Reproduced in **PHASE 2 — Scenario E**.

---

## HAJJ/UMRA — Defect: Class-A silent ledger drift + Class-B silent no-op

| Layer | File:line | What exists | Verdict |
|---|---|---|---|
| Vue router | `resources/js/router/index.js:215-217` | `path: ':id/edit', name: 'hajj.edit'` | ❌ REMOVE |
| Vue view | `resources/js/views/hajjUmra/HajjUmraEdit.vue` | Edit view for HajjUmra bookings | ❌ REMOVE |
| API route | `routes/api.php:613` | `Route::match(['put', 'patch'], 'bookings/{hajjUmra}', [HajjUmraController::class, 'update'])` | ❌ REPLACE: drop PUT/PATCH |
| Controller | `app/Http/Controllers/Api/V1/HajjUmraController.php:79-94` | `update()` → `HajjUmraBookingService::update()` | ❌ REMOVE |
| FormRequest | `app/Http/Requests/HajjUmra/UpdateHajjUmraBookingRequest.php` | Allows `selling_price`, `purchase_price`, `companion_selling_price`, `companion_purchase_price` | ❌ REMOVE |
| Service | `app/Services/HajjUmra/HajjUmraBookingService.php:352-505` `update()` | **Has** repost pattern via `repostExpenseTransaction` / `repostIncomeTransaction` (lines 491, 497) | ❌ REMOVE — still has bugs (see below) |
| Filament | `app/Filament/Admin/Resources/HajjUmraBookings/Pages/EditHajjUmraBooking.php` | Filament edit page delegates to Service | ❌ REMOVE |

### Why remove HajjUmra Edit even though it has a "repost" pattern?

The repost pattern is present (lines 491, 497) but the method still has bugs that put money integrity at risk:

1. **Silent no-op when key fields are absent** — the method only reposts if `selling_price`/`purchase_price`/companion fields are sent. If user edits only `notes`, the existing ledger stays while columns stay — same drift as Flight.
2. **No paid-amount guard** — if the new selling_price < already paid amount, the booking's AR would go negative silently. FormRequest allows `min:0` but doesn't check `>= paid_amount`.
3. **No status guard** — unlike Flight's `if ($pending)` which at least blocks edit-after-pay, HajjUmra lets the user edit after payment is recorded.
4. **`expenseTransaction` / `incomeTransaction` relation may be null** — when no transactions are linked (e.g., partial create, soft-deleted tx), the repost silently does nothing.

These are the same defect *family* as Flight. Even if "less severe", they share the same root cause: **edit-after-save is unsafe**. User policy: cancel + re-create.

---

## VISA — Defect: Class-A silent ledger drift + Class-B silent no-op

| Layer | File:line | What exists | Verdict |
|---|---|---|---|
| Vue router | `resources/js/router/index.js:266-268` | `path: ':id/edit', name: 'visa.edit'` | ❌ REMOVE |
| Vue view | `resources/js/views/visa/VisaEdit.vue` | Edit view for Visa bookings | ❌ REMOVE |
| API route | `routes/api.php:650` | `Route::match(['put', 'patch'], 'bookings/{visa}', [VisaBookingController::class, 'update'])` | ❌ REPLACE: drop PUT/PATCH |
| Controller | `app/Http/Controllers/Api/V1/Visa/VisaBookingController.php:103-118` | `update()` → `VisaBookingService::update()` | ❌ REMOVE |
| FormRequest | `app/Http/Requests/Visa/UpdateVisaBookingRequest.php` | Allows `purchase_price`, `selling_price`, `service_fee` | ❌ REMOVE |
| Service | `app/Services/Visa/VisaBookingService.php:276-417` `update()` | **Has** repost pattern (lines 381, 387) via `repostExpenseTransaction` / `repostIncomeTransaction` (now in `VisaModificationService`) | ❌ REMOVE — same bugs as HajjUmra |
| Filament | `app/Filament/Admin/Resources/VisaBookings/Pages/EditVisaBooking.php` | Filament edit page | ❌ REMOVE |

### Why remove Visa Edit (same reasoning as HajjUmra)

The `repostExpenseTransaction`/`repostIncomeTransaction` pattern (now refactored into `VisaModificationService`) tries to fix the issue but the `update()` method still suffers from:
1. Silent no-op when financial fields are absent.
2. No paid-amount guard against negative AR.
3. No status guard — edits allowed after payment.
4. Side-effect: also writes to `visaDetail` relation (line 102 of EditVisaBooking.php), which is fine for non-financial visa fields but coexists with the broken financial flow.

User policy: cancel + re-create.

---

## Implementation plan (Option A — final)

### 1. Vue (SPA)
- **Remove** `:id/edit` routes for `flights`, `hajj`, `visa` in `resources/js/router/index.js`.
- **Delete** view files: `FlightEdit.vue`, `HajjUmraEdit.vue`, `VisaEdit.vue`.
- **Remove** `updateBooking` from `flightStore.js`, `updateBooking` from any HajjUmra/Visa store.
- **Remove** all "Edit" buttons / `router-link` to `:id/edit` in the list/show views.

### 2. API routes (`routes/api.php`)
- **Flight**: change `Route::apiResource('bookings', FlightController::class)` → drop PUT/PATCH. Either keep the `only([...])` whitelist or wrap with `Route::any(...) { abort(405); }`.
- **Flight**: drop `POST bookings/{flightBooking}/prices` line 224.
- **HajjUmra** (line 613): drop `Route::match(['put', 'patch'], ...)`.
- **Visa** (line 650): drop `Route::match(['put', 'patch'], ...)`.

### 3. Controllers
- Remove `update()` and `updatePrices()` methods from FlightController, HajjUmraController, VisaBookingController (or keep them but throw `LogicException("removed per no-edit contract")`).

### 4. FormRequests
- Delete `UpdateFlightBookingRequest.php`, `UpdateFlightPricesRequest.php`, `UpdateHajjUmraBookingRequest.php`, `UpdateVisaBookingRequest.php`.

### 5. Services
- Remove `FlightBookingService::updateBooking()`, `updatePrices()`.
- Remove `HajjUmraBookingService::update()`.
- Remove `VisaBookingService::update()`.

### 6. Filament
- Delete `app/Filament/Admin/Resources/FlightBookings/Pages/EditFlightBooking.php`.
- Delete `app/Filament/Admin/Resources/HajjUmraBookings/Pages/EditHajjUmraBooking.php`.
- Delete `app/Filament/Admin/Resources/VisaBookings/Pages/EditVisaBooking.php`.
- Remove `EditAction` from tables in respective resources.

### 7. Tests (regression)
- Add `tests/Feature/Tourism/TourismNoEditContractTest.php` covering:
  - `PUT /api/v1/flight/bookings/{id}` → 405 Method Not Allowed.
  - `POST /api/v1/flight/bookings/{id}/prices` → 404 Not Found.
  - `PUT /api/v1/hajj-umra/bookings/{id}` → 405.
  - `PUT /api/v1/visa/bookings/{id}` → 405.
  - For each module, asserting the corresponding Service method either doesn't exist OR throws.

### 8. Documentation
- Add contract note to `docs/ARCHITECTURE.md` (or create new doc):
  > **Tourism booking contract**: After a booking is saved (created), it is **immutable** from a financial standpoint. To change selling price, purchase price, currency, or any field that affects the ledger, the operator must **cancel the booking (creates reversal entries) and create a new one**. Editing financial fields is not allowed in any layer — Vue, API, Filament, or Service.

---

## Defects recorded as SEPARATE per-module

Per user policy ("if same pattern exists in HajjUmra/Visa, record as separate defect per module"):

| Module | Defect | Severity |
|---|---|---|
| **Flight** | Direct column update of `selling_price`/`purchase_price`/`currency` without ledger reversal. Plus silent no-op when `status != PENDING`. | **Class-A** (financial integrity) |
| **HajjUmra** | `update()` method has partial repost pattern but is incomplete — silent no-op when financial fields are absent, no paid-amount guard, no status guard. | **Class-B** (silent drift possible) |
| **Visa** | `update()` method has partial repost pattern via `VisaModificationService` — same defects as HajjUmra. | **Class-B** (silent drift possible) |

---

## Evidence

| Phase | Evidence file |
|---|---|
| PHASE 0 (root cause) | This audit document + PHASE 1 reproduction |
| PHASE 1 | `tests/reports/phase1_reproduction.log` |
| PHASE 2 | `tests/reports/phase2_reproduction.log` |
| PHASE 3 (this audit) | `docs/TOURISM_EDIT_AUDIT_20260818.md` |
| PHASE 5 (final incident report) | `tests/reports/TOURISM_BOOKING_EDIT_FINANCIAL_INCIDENT_20260817.md` |

---

**Decision tree**:
- After this audit, apply all code changes listed in §Implementation plan.
- After code changes, run PHASE 3 regression tests on isolated DB to confirm PUT→405 and POST→404.
- PHASE 4 (Server correction plan) runs separately AFTER Local fix is verified.