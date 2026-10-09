# BUS MODULE — EGP-ONLY AUDIT REPORT

**Date:** 2026-08-26
**Scope:** Every Bus-namespaced file (Models, Services, Controllers, FormRequests, Filament, Vue, Migrations, Tests, Routes).
**Method:** Exhaustive source-code read of all files under Bus namespaces + Bus-prefixed routes.
**Goal:** Identify every currency / FX touchpoint and classify it.

---

## TL;DR

The Bus module **is wired for multi-currency at the data-model layer** (`bus_inventories.currency`, `bus_bookings.currency`, `bus_payments.currency`, `bus_refund_requests.original_currency` / `refund_currency` / `base_currency_refund`). The product requirement is **BUS = EGP ONLY**, so every multi-currency capability must be hardened to EGP-only and rejected if invoked.

---

## 1. INVENTORY (exhaustive)

### 1.1 Models — fields (declarations)

| Model | File:Line | Field | Default | Notes |
|---|---|---|---|---|
| `BusBooking` | `app/Models/Bus/BusBooking.php:40,63` | `currency` (string) | (mirror) | Mirrored from inventory at create |
| `BusBooking` | `app/Models/Bus/BusBooking.php:41,64` | `exchange_rate_to_egp` (decimal:6) | (mirror) | Mirrored from inventory at create |
| `BusInventory` | `app/Models/Bus/BusInventory.php:38,61` | `currency` (string) | `'EGP'` | First-class column |
| `BusInventory` | `app/Models/Bus/BusInventory.php:39,62` | `exchange_rate_to_egp` (decimal:6) | `1.0` | First-class column |
| `BusPayment` | `app/Models/Bus/BusPayment.php:22,41` | `currency` (string) | (unset) | **GAP**: writer never persists this |
| `BusPayment` | `app/Models/Bus/BusPayment.php:23,42` | `exchange_rate_to_egp` (decimal:6) | (unset) | **GAP**: writer never persists this |
| `BusRefundRequest` | `app/Models/Bus/BusRefundRequest.php:29` | `original_currency` (string) | (unset) | Service writes from booking |
| `BusRefundRequest` | `app/Models/Bus/BusRefundRequest.php:36` | `refund_currency` (string) | (unset) | Service writes from booking |
| `BusRefundRequest` | `app/Models/Bus/BusRefundRequest.php:37` | `refund_exchange_rate` (decimal:6) | (unset) | Service writes from booking |
| `BusRefundRequest` | `app/Models/Bus/BusRefundRequest.php:38` | `base_currency_refund` (decimal:2) | (unset) | Service writes from booking |

### 1.2 Services — runtime behavior

| Service | Lines | Behavior |
|---|---|---|
| `BusBookingService::createBooking` | multiple | Reads `inventory->currency` + `inventory->exchange_rate_to_egp`, mirrors to booking. Creates `customer_ar(bookingCurrency)` account via `ensureCustomerCurrencyAccount`. Posts `supplier_ap` in EGP-equivalent via `CurrencyService::convert`. |
| `BusBookingService::payBooking` | 611-638 | Cross-currency payment: when `booking.currency !== account.currency`, performs `CurrencyService::convert()` and posts with `converted_amount` + `exchange_rate`. |
| `BusBookingService::cancelBooking` | 786-829 | Refund writes `original_currency` + `refund_currency` from booking; computes `base_currency_refund` using booking's `exchange_rate_to_egp`. |
| `BusBookingService::deleteBookingWithReversal` | multiple | Refunds each payment; FX-aware reversals using each payment's currency/rate. |
| `BusCompanyService::createCompanyAccount` | 245 | Pins supplier AR to `'EGP'` — by design (operating currency pivot). |
| `BusInventoryService::createInventory` | full | Accepts whatever caller passes; persists `currency` + `exchange_rate_to_egp`. |
| `BusRefundService::processRefundRequest` | 207-210 | Rejects treasury mismatch (`refund_currency` vs `treasury->currency`) with Arabic "تضارب في العملة". |
| `BusRefundService` (general) | 79-85, 207-233 | Persists `original_currency`, `refund_currency`, `refund_exchange_rate`, `base_currency_refund`. |
| `BusDashboardController` | 13, 30-47 | Groups by currency; converts to EGP via `CurrencyService::convert`. |
| `BusTransactionTypeClassifier` | full | No currency references. |

### 1.3 Controllers — API surface

| Controller | Lines | Behavior |
|---|---|---|
| `BusBookingController` | full | No currency literals; delegates to service. |
| `BusRefundController` | validation, treasury filter | Accepts `refund_currency` query parameter; uses it for filtering. |
| `BusTreasuryController::overview` | full | Returns `liquidity-account.currency` in payload. |
| `BusCompanyController::payDebt` | 293 | Arabic error "يتجاوز الدين الفعلي (... ج.م)". |
| `BusInventoryController` | full | No currency literals; delegates to service. |
| `BusDashboardController` | multiple | FX-converts dashboard numbers to EGP. |

### 1.4 FormRequests — validation surface

| Request | Behavior |
|---|---|
| `PayBusBookingRequest` | Lines 61-73: rejects `booking.currency !== account.currency` with Arabic error. **GAP**: contradicts service-level FX-conversion path. |
| `CancelBusBookingRequest`, `StoreBusBookingRequest`, `StoreBusInventoryRequest`, `PayInventoryDebtRequest`, `StoreBusCompanyRequest`, `UpdateBusCompanyRequest`, `UpdateBusInventoryRequest`, `StoreBusTicketRequest`, `UpdateBusTicketRequest` | No currency fields accepted. |

### 1.5 Filament — admin UI

All Bus Filament forms/tables display monetary columns with `prefix('ج.م')` or `money('EGP')`. **No currency selector is exposed on any Bus Filament form.** Legacy `BusTicketResource` uses `money('jod')` — vestigial.

### 1.6 Vue — public/admin UI

- `BusCreate.vue`, `BusCustomerIndex.vue`, `BusShow.vue`: `Intl.NumberFormat('ar-EG', { style: 'currency', currency: 'EGP' })`.
- `BusRefundWizard.vue` L154,332,335: passes `acc.currency` to formatter — only dynamic-currency path.
- All other Vue files use `ج.م` literal suffix.
- No currency selector in any Bus Vue form.

### 1.7 Migrations

- `2026_07_18_120000/120001/120002` added `currency` + `exchange_rate_to_egp` to `bus_inventories`, `bus_bookings`, `bus_payments`.
- `2026_05_14_230032` added `original_currency`, `refund_currency`, `refund_exchange_rate`, `base_currency_refund` to `bus_refund_requests`.

### 1.8 Tests

- 38 feature tests in `tests/Feature/Bus/`.
- `BusTestCase.php` seeds `exchangeRates = ['USD_EGP' => 50.0, 'SAR_EGP' => 13.3333, 'KWD_EGP' => 162.5, 'EUR_EGP' => 54.5, ...]` and uses `'EGP'` / `'USD'` liquidity accounts.
- Several tests explicitly exercise USD/SAR/KWD paths via `BusInventory::create([..., 'currency' => 'USD', ...])` direct Eloquent writes.

---

## 2. REACHABILITY MATRIX

| Vector | Path | Reaches non-EGP today? | Reachable after Phase 3? |
|---|---|---|---|
| **Filament Inventory form** | `BusInventoryResource` create | ❌ No currency field | ❌ No (forced EGP) |
| **Filament Booking form** | `BusBookingResource` create | ❌ Currency derived from inventory | ❌ No (inventory forced EGP) |
| **Filament Booking edit** | `BusBookingResource` edit | ❌ No currency field | ❌ No |
| **API: `POST /bus/inventories`** | `StoreBusInventoryRequest` + service | ❌ FormRequest rejects | ❌ Rejected with 422 |
| **API: `POST /bus/bookings`** | `StoreBusBookingRequest` + service | ❌ FormRequest rejects | ❌ Rejected with 422 |
| **API: `POST /bus/bookings/{id}/pay`** | `PayBusBookingRequest` + service | ✅ Same-currency guard but service can FX-convert (legacy) | ❌ Rejected with 422 |
| **API: `POST /bus/refunds`** | `BusRefundController::store` | ✅ Refund service writes `refund_currency` | ❌ Rejected with 422 |
| **Vue `BusCreate.vue`** | form | ❌ No currency selector | ❌ No |
| **Vue `BusRefundWizard.vue`** | refund wizard | ✅ Formatter takes account currency dynamically | ❌ Forces EGP |
| **Direct service invocation** | `BusBookingService::createBooking(['inventory_id' => $nonEgpInventory])` | ✅ Reads `inventory.currency` | ❌ Rejects |
| **Direct DB write** | `DB::table('bus_inventories')->update(['currency' => 'USD'])` | ✅ Bypasses all | ⚠️ Out-of-band (acceptable; not a product path) |

---

## 3. KEY FINDINGS

### Finding 1: Multi-currency schema on Bus models
- `bus_inventories`, `bus_bookings`, `bus_payments`, `bus_refund_requests` all carry currency columns.
- Default is `'EGP'` / `1.0` everywhere; only changed by direct Eloquent write or via DB seeder.
- **BusInventoryResource has no currency field** — the inventory Filament form cannot create a non-EGP inventory.

### Finding 2: Service-level cross-currency conversion exists
- `BusBookingService::payBooking` and `BusRefundService` both call `CurrencyService::convert()`.
- This contradicts the product requirement (BUS = EGP ONLY).
- These code paths are reachable only via direct service invocation today, but still expose FX as a runtime possibility.

### Finding 3: FormRequest guards contradict service logic
- `PayBusBookingRequest` enforces same-currency payment at the validation layer.
- The service layer would still allow cross-currency conversion if the FormRequest guard is bypassed.

### Finding 4: BusPayment.currency never written
- The model has `currency` + `exchange_rate_to_egp` columns, but `BusBookingService::payBooking` does not persist these on the payment row.
- Future payment-row reconciliation has no currency snapshot to read.

### Finding 5: Dashboard FX conversion
- `BusDashboardController` aggregates `monthly_revenue` across currencies via `CurrencyService::convert`.
- Even though no Bus booking will be non-EGP after Phase 3, the controller still attempts to FX-convert. Code path is harmless (rate=1 for EGP), but should be simplified.

### Finding 6: Vue refund wizard passes account currency dynamically
- `BusRefundWizard.vue:154` calls `formatMoney(balance, acc.currency)`.
- After Phase 3, this should always render EGP regardless of the account's stored currency.

### Finding 7: BusTicketResource uses `money('jod')`
- `BusTicketResource` is a legacy resource but the `money('jod')` call is not part of the audited terms (JOD is Jordanian Dinar — neither EGP nor USD/SAR/KWD/EUR). It is a vestigial reference.
- The table was actually dropped via migration `2026_08_13_120000_drop_bus_tickets_table.php`, so the resource is unreachable.

### Finding 8: Tests assume multi-currency behaviour
- Several existing feature tests create USD/SAR/KWD inventories via direct Eloquent writes.
- After Phase 3, these tests will need to either:
  - (a) be removed (because they exercise unsupported behaviour), or
  - (b) be rewritten to assert that the EGP-only contract rejects the operation.

---

## 4. CLASSIFICATION OF EVERY CURRENCY TOUCHPOINT

| Touchpoint | Classification | Action in Phase 3 |
|---|---|---|
| `bus_inventories.currency` | field declaration | Force `'EGP'` always (override at writer) |
| `bus_inventories.exchange_rate_to_egp` | field declaration | Force `1.0` always |
| `bus_bookings.currency` | field declaration | Force `'EGP'` always (mirror only EGP) |
| `bus_bookings.exchange_rate_to_egp` | field declaration | Force `1.0` always |
| `bus_payments.currency` | field declaration (GAP) | Force `'EGP'` always; persist it explicitly |
| `bus_payments.exchange_rate_to_egp` | field declaration (GAP) | Force `1.0` always; persist it explicitly |
| `bus_refund_requests.original_currency` | field declaration | Force `'EGP'` always |
| `bus_refund_requests.refund_currency` | field declaration | Force `'EGP'` always |
| `bus_refund_requests.refund_exchange_rate` | field declaration | Force `1.0` always |
| `bus_refund_requests.base_currency_refund` | field declaration | Force equal to `refund_amount` (EGP) |
| `BusBookingService::createBooking` FX block | conversion (legacy) | Remove / replace with EGP-only mirror |
| `BusBookingService::payBooking` cross-currency block | conversion (legacy) | Reject non-EGP booking/cashbox mismatch with HTTP 422 |
| `BusBookingService::cancelBooking` FX block | conversion (legacy) | Force refund currency to EGP |
| `BusBookingService::deleteBookingWithReversal` FX block | conversion (legacy) | Force all reversals to EGP |
| `BusRefundService::processRefundRequest` mismatch check | validation (already) | Strengthen to reject if booking is non-EGP |
| `BusDashboardController` FX conversion | conversion (legacy) | Simplify; sum EGP only |
| `PayBusBookingRequest` currency match | validation (already) | Strengthen to reject non-EGP booking (force booking.currency='EGP') |
| `StoreBusBookingRequest` | validation gap | No currency fields accepted; no change needed |
| `StoreBusInventoryRequest` | validation gap | No currency fields accepted; no change needed |
| `BusRefundController` `refund_currency` query param | API surface | Reject with 422 if not 'EGP' or missing |
| `BusRefundWizard.vue` dynamic currency | UI display | Force EGP in formatter |
| Filament forms (all) | UI display (EGP only) | No change needed |
| Vue views (other) | UI display (EGP only) | No change needed |
| Legacy `BusTicketResource::money('jod')` | vestigial | No action (resource is dropped) |
| Direct DB write of non-EGP currency | out-of-band | Acceptable; not a product path |
| Existing tests that seed non-EGP inventories | test bug | Remove or rewrite in Phase 4 |

---

## 5. CONTRACT (Phase 2 — final)

The final EGP-only contract is:

1. Every `bus_inventories.currency` row is `'EGP'`.
2. Every `bus_inventories.exchange_rate_to_egp` row is `1.0`.
3. Every `bus_bookings.currency` row is `'EGP'`.
4. Every `bus_bookings.exchange_rate_to_egp` row is `1.0`.
5. Every `bus_payments.currency` row is `'EGP'`.
6. Every `bus_payments.exchange_rate_to_egp` row is `1.0`.
7. Every `bus_refund_requests.original_currency` row is `'EGP'`.
8. Every `bus_refund_requests.refund_currency` row is `'EGP'`.
9. Every `bus_refund_requests.refund_exchange_rate` row is `1.0`.
10. Every `bus_refund_requests.base_currency_refund` row equals `refund_amount` (no FX).
11. Every customer AR account created by Bus is in `'EGP'`.
12. Every supplier AR account created by Bus is in `'EGP'` (already enforced).
13. Every Bus treasury / cashbox movement is `'EGP'`.
14. No Bus code path may call `CurrencyService::convert()`.
15. No Bus FormRequest may accept a non-EGP `currency` / `refund_currency` / `original_currency` parameter.
16. Direct service invocation that produces a non-EGP Bus entity must throw.

The legacy `currency` + `exchange_rate_to_egp` columns are **retained** for backward compatibility (DB-level cost of dropping > benefit), but are **strictly forced to EGP / 1.0** at every writer.

---

## 6. ACTION ITEMS (Phase 3)

1. Update `BusInventoryService::createInventory` and `BusInventoryService::updateInventory` to force `currency='EGP'` and `exchange_rate_to_egp=1.0`.
2. Update `BusBookingService::createBooking` to force booking `currency='EGP'` and `exchange_rate_to_egp=1.0` (do not read from inventory).
3. Update `BusBookingService::payBooking` to force `payment.currency='EGP'` and `payment.exchange_rate_to_egp=1.0`. Reject with 422 if booking currency is not EGP.
4. Update `BusBookingService::cancelBooking` to force `refund_request.original_currency='EGP'`, `refund_currency='EGP'`, `refund_exchange_rate=1.0`, `base_currency_refund=refund_amount`.
5. Update `BusBookingService::deleteBookingWithReversal` to force all reversal rows to EGP.
6. Update `BusRefundService::processRefundRequest` to assert `booking.currency === 'EGP'`, `refund_currency === 'EGP'`. Reject if not.
7. Update `BusRefundController::store` validation: `refund_currency` must be `'EGP'` or omitted.
8. Update `BusDashboardController` to remove FX conversion (assume all bookings EGP).
9. Update `PayBusBookingRequest` to assert booking currency is EGP.
10. Update `BusRefundWizard.vue` to render EGP only.
11. Migrate any existing non-EGP Bus rows to EGP (data fix-up) — but only if such rows exist.
12. Update tests:
    - Remove tests that exercise non-EGP Bus inventories directly.
    - Add new tests (Phase 4) that assert rejection of non-EGP attempts at every layer.

---

## 7. FILE INDEX

- This report: `.zcode/plans/BUS_EGP_ONLY_AUDIT_REPORT_20260826.md`
- Inventory: `.zcode/plans/BUS_FINANCIAL_MOVEMENT_INVENTORY_20260826.md` (to be corrected in Phase 5)
- Currency scope validation: `.zcode/plans/BUS_CURRENCY_SCOPE_VALIDATION_20260826.md`
- Existing retest: `.zcode/plans/BUS_FM67_RETEST_REPORT_20260826.md`
- Existing full E2E report: `.zcode/plans/BUS_FULL_E2E_REPORT_20260826.md`

---

**No code was modified during this audit.**
