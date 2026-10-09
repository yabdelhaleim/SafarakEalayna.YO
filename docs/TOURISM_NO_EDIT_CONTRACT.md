# Tourism Booking — "No Edit After Save" Contract

> **Effective from**: 2026-08-18 (INCIDENT-2026-08-17)
> **Applies to**: All Tourism division bookings — Flight + Hajj/Umra + Visa.
> **Decision**: Option A — إلغاء Edit نهائيًا (cancel Edit permanently).

---

## The Contract

> **After a Tourism booking is saved (created), it is immutable from a financial standpoint.**
> To change the selling price, purchase price, currency, or any other field that affects the
> ledger (customer AR balance, carrier/system prepaid balance, profit, expense, income),
> the operator must **cancel the booking (which creates reversal entries) and create a new one**.
> Editing financial fields is not allowed in any layer — Vue, API, Filament, or Service.

### Why

The Tourism modules previously allowed `PUT/PATCH /api/v1/{flight|hajj-umra|visa}/bookings/{id}`
to mutate `selling_price`, `purchase_price`, `currency`, and `service_fee` post-save. The
backend implementations had two stacked defects:

1. **Class-A silent ledger drift** — `FlightBookingService::updateBooking()` and
   `updatePrices()` did a direct column update with **no ledger reversal**. The `booking.selling_price`
   column was overwritten, but the customer AR (in `account_entries`), the carrier prepaid
   balance, and the profit column were NOT mutated. Result: silent financial drift —
   the booking column says "30 JOD" while the GL still says "customer owes 50 JOD".
   The user perceived this as "the system added the new price to the customer account and
   produced phantom profit".

2. **Class-C silent no-op** — `updateBooking()` wrapped financial mutations in
   `if ($booking->status === FlightBookingStatus::PENDING)`. When the booking was already
   `CONFIRMED` / `PAID` (post-payment), the PUT returned HTTP 200 but the column update
   was silently dropped. Result: API success but no change — extremely confusing for the
   operator.

3. **Class-B silent no-op in HajjUmra + Visa** — Both modules had a partial "repost"
   pattern (`repostExpenseTransaction` / `repostIncomeTransaction`) that
   - silently no-ops when financial fields are absent;
   - has no paid-amount guard against negative AR;
   - has no status guard — edits were allowed after payment.

See:
- `tests/reports/phase1_reproduction.log` — reproduces the Flight bug on isolated MySQL.
- `tests/reports/phase2_reproduction.log` — 6 scenarios covering reverse, no-change, multi-edit, payment-before/after, cancel-after-edit.
- `docs/TOURISM_EDIT_AUDIT_20260818.md` — full audit of every Edit surface.

---

## What is REMOVED

| Layer | Module | What was removed | Status |
|---|---|---|---|
| **Vue router** | Flight | `flights.edit` route (`resources/js/router/index.js:104-109`) | ✅ Removed |
| **Vue view** | Flight | `resources/js/views/flights/FlightEdit.vue` | ✅ Deleted |
| **Vue store** | Flight | `flightStore.updateBooking()` | ✅ Removed |
| **API route** | Flight | `Route::apiResource('bookings', FlightController::class)` was reduced to `except(['destroy', 'update'])` | ✅ Updated |
| **API route** | Flight | `POST /api/v1/flight/bookings/{id}/prices` | ✅ Removed |
| **API controller** | Flight | `FlightController::update()`, `FlightController::updatePrices()` | ✅ Removed |
| **FormRequest** | Flight | `UpdateFlightBookingRequest`, `UpdateFlightPricesRequest` | ✅ Deleted |
| **Service** | Flight | `FlightBookingService::updateBooking()` and `updatePrices()` replaced with throw-stubs | ✅ Stubbed |
| **Filament** | Flight | `EditFlightBooking` page | ✅ Deleted |
| **Vue router** | HajjUmra | `hajj.edit` route | ✅ Removed |
| **Vue view** | HajjUmra | `HajjUmraEdit.vue` | ✅ Deleted |
| **Vue router** | Visa | `visa.edit` route | ✅ Removed |
| **Vue view** | Visa | `VisaEdit.vue` | ✅ Deleted |
| **API route** | HajjUmra | `Route::match(['put', 'patch'], 'bookings/{hajjUmra}', ...)` | ✅ Removed |
| **API route** | Visa | `Route::match(['put', 'patch'], 'bookings/{visa}', ...)` | ✅ Removed |
| **API controller** | HajjUmra | `HajjUmraController::update()` | ✅ Removed |
| **API controller** | Visa | `VisaBookingController::update()` | ✅ Removed |
| **FormRequest** | HajjUmra | `UpdateHajjUmraBookingRequest` | ✅ Deleted |
| **FormRequest** | Visa | `UpdateVisaBookingRequest` | ✅ Deleted |
| **Service** | HajjUmra | `HajjUmraBookingService::update()` replaced with throw-stub | ✅ Stubbed |
| **Service** | Visa | `VisaBookingService::update()` replaced with throw-stub | ✅ Stubbed |
| **Filament** | HajjUmra | `EditHajjUmraBooking` page | ✅ Deleted |
| **Filament** | Visa | `EditVisaBooking` page | ✅ Deleted |
| **Vue UI** | All | Edit buttons in show/index views replaced with disabled placeholder or removed | ✅ Replaced |
| **Permission** | All | `flights.edit`, `hajj_umra.edit`, `visa.edit` removed from manager role grant list | ✅ Cleaned |

---

## What is ALLOWED

### Allowed (operations that are NOT covered by this contract)

| Operation | HTTP | Notes |
|---|---|---|
| **Create** | `POST /api/v1/{flight|hajj-umra|visa}/bookings` | Creates booking + ledger entries. |
| **Show** | `GET /api/v1/{flight|hajj-umra|visa}/bookings/{id}` | Read-only. |
| **List** | `GET /api/v1/{flight|hajj-umra|visa}/bookings` | Read-only. |
| **Cancel** | `POST /api/v1/{flight|hajj-umra|visa}/bookings/{id}/cancel` | Creates reversal entries. Status → CANCELLED. Booking row stays visible for audit. |
| **Soft-delete with reversal** | `DELETE /api/v1/{flight|hajj-umra|visa}/bookings/{id}` | `deleteBookingWithReversal` — full reversal + soft-delete. |
| **Payment** | `POST /api/v1/{flight|hajj-umra|visa}/bookings/{id}/payments` | Adds payment to existing booking. |
| **Refund** | `POST /api/v1/{flight|hajj-umra|visa}/bookings/{id}/refund` | After-cancel refund flow. |
| **Confirm** (Flight only) | `POST /api/v1/flight/bookings/{id}/confirm` | Status transition PENDING → CONFIRMED. Does NOT touch prices. |
| **Modifications** (Visa only) | `GET /api/v1/visa/bookings/{id}/modifications` | Read-only history. |

### Allowed at the Service layer (defense-in-depth throw-stubs)

These exist as **defense-in-depth** so any internal caller (Tinker, scheduled job, future
test) that still invokes them gets a loud `LogicException` instead of silent corruption:

```php
FlightBookingService::updateBooking($booking, $data);
// throws: LogicException("FlightBookingService::updateBooking() is removed by the Tourism no-edit contract (INCIDENT-2026-08-17). Cancel the booking and create a new one.")

FlightBookingService::updatePrices($booking, $purchase, $selling);
// throws: same message

HajjUmraBookingService::update($booking, $data);
// throws: same message

VisaBookingService::update($booking, $data);
// throws: same message
```

These stubs are intentionally NOT deleted. If the Service signature ever changes, the
throw-stub remains so the framework still rejects the call.

---

## Verification

### Regression tests

`tests/Feature/Tourism/TourismNoEditContractTest.php` covers:

1. `PUT /api/v1/flight/bookings/{id}` → **405 Method Not Allowed**
2. `PATCH /api/v1/flight/bookings/{id}` → **405 Method Not Allowed**
3. `POST /api/v1/flight/bookings/{id}/prices` → **404 Not Found**
4. `PUT /api/v1/hajj-umra/bookings/{id}` → **405**
5. `PUT /api/v1/visa/bookings/{id}` → **405**
6. `FlightBookingService::updateBooking()` → throws `LogicException`
7. `FlightBookingService::updatePrices()` → throws `LogicException`
8. `HajjUmraBookingService::update()` → throws `LogicException`
9. `VisaBookingService::update()` → throws `LogicException`

Run with:
```bash
DB_DATABASE=sfrk_edit_incident_20260818 php artisan test --filter=TourismNoEditContractTest
```

### Manual smoke test

```bash
# Login as admin and capture token
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@test.com","password":"password"}' | jq -r '.data.token')

# Create a booking
BOOKING_ID=$(curl -s -X POST http://127.0.0.1:8000/api/v1/flight/bookings \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"customer_id":1,"selling_price":50,"purchase_price":40,"currency":"EGP",...}' \
  | jq -r '.data.id')

# Attempt edit — must return 405
curl -i -X PUT http://127.0.0.1:8000/api/v1/flight/bookings/$BOOKING_ID \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"selling_price":30}'
# HTTP/1.1 405 Method Not Allowed

# Attempt price-mutation — must return 404
curl -i -X POST http://127.0.0.1:8000/api/v1/flight/bookings/$BOOKING_ID/prices \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"selling_price":30}'
# HTTP/1.1 404 Not Found
```

---

## Why not just FIX the bug instead of REMOVING the feature?

We considered two options:

- **Option A (chosen)**: Remove Edit entirely from Vue, API, Filament, Service. Operators must cancel + re-create.
- **Option B (rejected)**: Fix the bug by adding the missing reversal/repost logic in `FlightBookingService::updateBooking()`.

We chose A because:

1. **HajjUmra and Visa already had a partial fix** (`repostExpenseTransaction` /
   `repostIncomeTransaction`) and it was STILL buggy — silent no-ops, no paid-amount
   guard, no status guard. Option B would have left at least 3 different implementations
   across 3 modules, each with its own edge cases.

2. **Cancel + re-create is the correct user workflow.** The cancel flow already creates
   full reversal entries (additive — never destructive) and the new booking creates fresh
   ledger entries. The financial timeline is clean: original entries stay visible for
   audit, reversal entries marked with "عكس:", new booking gets new entries.

3. **Operator UX is clearer.** No "what happens if I edit this?" anxiety. The UI shows
   "تعديل (موقوف)" buttons with a tooltip explaining the correct workflow.

---

## Forward compatibility

If the project ever needs Edit-after-save in the future (e.g., for a non-financial metadata
update like PNR), the implementation MUST:

1. Use the additive pattern — create reversal entries, then create new entries. NEVER
   mutate `account_entries` rows in place.
2. Reject if `selling_price` / `purchase_price` / `currency` / `service_fee` would change
   after a payment has been recorded.
3. Reject if any reversal-on-cancel transaction would be negative.
4. Cover all three Tourism modules with the same code path (no module-specific repost).
5. Bump this contract doc with a new revision and require Code Review sign-off.

Until then: **cancel + re-create**.