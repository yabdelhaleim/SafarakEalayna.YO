# BUS CURRENCY SCOPE VALIDATION — 2026-08-26

**Purpose:** determine whether the multi-currency FM scenarios in the FM-67 inventory
correspond to **Bus-specific** supported functionality, or whether they were inferred
from generic/shared infrastructure (`CurrencyService`, `TransactionService`,
`Account`, `ExchangeRate`, Visa/Hajj modules).

**Method:** exhaustively searched every Bus-namespaced file:

- `app/Http/Controllers/Api/V1/Bus/*.php`
- `app/Http/Requests/Bus/*.php`
- `app/Models/Bus/*.php`
- `app/Services/Bus/*.php`
- `app/Filament/Admin/Resources/Bus*/*.php`
- `app/Filament/Admin/Pages/Bus*.php`
- `resources/js/views/bus/*.vue`
- `resources/js/components/bus/*.vue`
- `routes/api.php` (Bus entries)
- `database/migrations/*bus*`

**Sources of evidence (verbatim, with file:line):**

1. **Models** — currency is a first-class field:
   - `app/Models/Bus/BusBooking.php:39,62` — `'currency' => 'string'`
   - `app/Models/Bus/BusPayment.php:21,40` — `'currency' => 'string'`
   - `app/Models/Bus/BusInventory.php:37,60` — `'currency' => 'string'`
   - `app/Models/Bus/BusRefundRequest.php:28,35,37,58` —
     `original_currency`, `refund_currency`, `base_currency_refund`
2. **Migrations** — `2026_07_18_120000/120001` explicitly added multi-currency
   columns to `bus_inventories` and `bus_bookings` (header:
   *"Add multi-currency support to `bus_inventories`"* / *"to `bus_bookings`"*).
3. **Service contract** —
   `app/Services/Bus/BusBookingService.php:212-215` declares the
   *"Multi-currency contract (Phase 6 — multi-currency wiring)"*:
   - Inventory's currency is the BOOKING's currency (mirrored).
   - Customer AR account is created in booking currency (with FX tag).
   - Company debt is always posted in EGP.
4. **Inventory-side FX** — `BusBookingService::createBooking` reads
   `$inventory->currency` and `$inventory->exchange_rate_to_egp` (lines 244-305).
5. **Payment-side FX** — `BusBookingService::payBooking` at lines 611-638 +
   `BusRefundService.php:166-233` convert between booking currency and
   paying account currency.
6. **Refund-side FX** — `BusRefundService` records `original_currency`,
   `refund_currency`, `base_currency_refund` (lines 79-85, 207-233).
7. **FormRequest validation** —
   `app/Http/Requests/Bus/PayBusBookingRequest.php:61-73` enforces that
   booking currency and payment account currency **must match** —
   cross-currency payment is **rejected** with a 422.
8. **Inventory Filament form** — `BusInventoryResource.php:59-167` has
   **NO currency selector**; all prices use `prefix('ج.م')` (EGP). A non-EGP
   inventory can only exist if `currency` and `exchange_rate_to_egp` are
   written directly to the DB or via API bypass.
9. **InventoryService** — `BusInventoryService::createInventory` never sets a
   non-EGP currency; defaults are `'EGP'`, `1.0`. The auto-create path inside
   `BusBookingService::findOrCreateAutoInventory` also does not inject currency.
10. **Booking Filament form** — `BusBookingResource.php:59-298` exposes
    `Select` for `inventory_id` (which carries currency indirectly),
    but no standalone currency choice; payment column shows `->money('EGP')`.
11. **Vue UI** — `BusCreate.vue`, `BusShow.vue`, `BusCustomerIndex.vue`,
    `BusTreasury.vue` use `Intl.NumberFormat('ar-EG', { currency: 'EGP' })`
    only. `BusRefundWizard.vue:334` reads `acc.currency` for display, but the
    selector for which currency to refund into comes from server-enforced rules.
12. **API** — `BusRefundController.php:28,91-94` accepts an optional
    `refund_currency` query parameter but uses it only for filtering /
    display, not for currency transformation. The actual refund currency is
    decided inside `BusRefundService` from booking + treasury currencies.

---

# BUS CURRENCY SCOPE VALIDATION — FM-MATRIX

The 12 multi-currency scenarios named in the question are validated below
against actual Bus code. **No evidence is taken from generic Finance code,
CurrencyService, other modules, or shared infrastructure.**

| FM | Currency Scenario | Bus-specific implementation found? | User/API can actually invoke it? | Evidence (file:line, Bus-only) | Classification |
|----|-------------------|------------------------------------|----------------------------------|--------------------------------|----------------|
| **FM-02** | Create booking in non-EGP currency (e.g., USD/SAR) | **YES (canonical backend)** — service reads `inventory.currency` and mirrors it on booking | **Partially** — only via direct DB write to `bus_inventories.currency` + `bus_inventories.exchange_rate_to_egp`, or via `BusBookingService::createBooking` after seeding an inventory row with a non-EGP currency. Filament inventory form (`BusInventoryResource.php`) **has NO currency selector**. Booking form (`BusBookingResource.php`) reads currency FROM inventory. | `BusBookingService.php:244-247`; `BusBookingService.php:291-307` (booking create reads `inventory->currency` + `inventory->exchange_rate_to_egp`); `database/migrations/2026_07_18_120000_add_currency_columns_to_bus_inventories_table.php` (header declares explicit multi-currency support); `BusInventoryResource.php:59-167` (NO currency field on form). | **A. Backend supports it, but Admin UI cannot create a non-EGP inventory.** Genuine Bus capability in code, but unreachable through standard UI paths (only via DB write or tinker/seed). |
| **FM-03** | Create booking with explicit FX rate | **YES** — `exchange_rate_to_egp` is a first-class column | **Partially** — same as FM-02; cannot be set via Filament inventory form. | `database/migrations/2026_07_18_120000_add_currency_columns_to_bus_inventories_table.php:29` — `exchange_rate_to_egp DECIMAL(12,6)`. `BusBookingService.php:246` reads it. `BusBookingService.php:247-252` throws if booking-currency ≠ EGP but rate ≤ 0. | **A. Same as FM-02.** |
| **FM-08** | Pay foreign-currency booking with same-currency wallet | **YES (wire-level code, NOT reachable through UI)** — `BusBookingService::payBooking` lines 611-638 validate booking-currency vs. paid-account-currency; if they match, payment posts in that currency. | **Partially reachable** — depends on a foreign-currency inventory already existing (which the admin UI cannot create). | `BusBookingService.php:611-638` (booking-currency vs. paid-account-currency enforcement); `BusPayment.php:21` (`currency` stored on payment). | **A. Wire-supported; UI-unreachable.** Genuine Bus service-layer capability; cannot be invoked end-to-end via Filament or Vue. |
| **FM-09** | Pay SAR booking using EGP cashbox (FX-at-payment) | **NO** — `PayBusBookingRequest.php:61-73` explicitly **rejects** booking-currency ≠ account-currency | **NO** — FormRequest returns 422 with Arabic message `"الحجز بعملة {$bookingCurrency} لكن الحساب المختار بعملة {$accountCurrency}"` | `app/Http/Requests/Bus/PayBusBookingRequest.php:61-73` | **D. Unsupported scenario.** Phase 6.B fix explicitly enforces same-currency payment. Service-layer FX-converting cross-currency payment does NOT exist. |
| **FM-20** | Cancel USD booking — USD wallet refund | **YES (wire-level)** — `BusRefundService` lines 57-85 + 207-233 records `refund_currency` and `base_currency_refund`; treasury currency check forces same-currency payout (line 207) | **Partially reachable** — only if a USD booking already exists (admin UI cannot create one). | `BusRefundService.php:57,70,79,83,85,207`; `BusBookingService.php:786-829`; `BusRefundRequest.php:28,35,37`. | **A. Wire-supported; UI-unreachable.** Genuine Bus code path exists (line 207 even ENFORCES same-currency treasury deposit). |
| **FM-21** | Cancel USD booking — refund to EGP cashbox (FX-at-refund) | **NO** — `BusRefundService.php:207-210` throws `"تضارب في العملة"` if `treasury.currency !== refund_currency` | **NO** — explicit same-currency refund enforced | `BusRefundService.php:207-210` | **D. Unsupported scenario.** Phase 7 fix explicitly enforces same-currency treasury destination. |
| **FM-36** | USD booking → EGP cashbox HTTP (FX) | **NO** — same as FM-09: `PayBusBookingRequest.php:61-73` blocks cross-currency payment. | **NO** | `PayBusBookingRequest.php:61-73`; `BusBookingService.php:634-638` (re-validates booking-currency vs. paid-account-currency inside the service) | **D. Unsupported scenario.** |
| **FM-37** | SAR booking → SAR wallet | **YES (wire)** — same-currency path in `BusBookingService.php:611-638` | **Partially reachable** — only if a SAR inventory exists (admin UI cannot create one) | `BusBookingService.php:611-638`; `PayBusBookingRequest.php:68` (`if ($bookingCurrency !== $accountCurrency) reject`) | **A. Wire-supported; UI-unreachable.** |
| **FM-38** | SAR booking → SAR wallet | **YES (wire)** — overlap with FM-37 (same-currency) | **Partially reachable** | `BusBookingService.php:611-638` | **A. Wire-supported; UI-unreachable.** |
| **FM-39** | SAR booking → EGP cashbox via FX HTTP | **NO** — same as FM-09; same rejection at both FormRequest and service layer | **NO** | `PayBusBookingRequest.php:61-73`; `BusBookingService.php:634-638` | **D. Unsupported scenario.** |
| **FM-40** | KWD high-precision FX payment | **NO** — same cross-currency blocker; even the same-currency wire path exists, but no UI to set up the KWD inventory in the first place. The high-precision FX claim is spurious — same-currency payment does **not** perform FX. | **NO** | `PayBusBookingRequest.php:61-73` (cross-currency blocked); `BusBookingService.php:611-638` (same-currency does no FX). | **D. Unsupported scenario** (the "FX" claim has no wiring). |
| **FM-41** | KWD ledger reconciliation after booking+payout | **NO direct Bus code path** — same-currency booking/payment/refund posts do **not** move FX. There is no Bus-specific reconciliation routine for FX gain/loss. | **NO** | (only generic `CurrencyService::convert` + dashboard grouping exists — neither Bus-specific). `BusDashboardController.php:13,30-47` aggregates by currency but does not produce FX-G/L entries. | **D. Unsupported scenario.** No Bus wiring exists for "FX-G/L on multi-currency booking reconciliation". |

---

## SUPPORTED BUS CURRENCIES

Based on Bus-specific evidence:

| Currency | Service-layer support | Admin-UI support | Public/Internal API | Notes |
|----------|----------------------|------------------|----------------------|-------|
| **EGP** | ✅ All flows | ✅ Filament form uses `prefix('ج.م')` | ✅ Default currency | Operating currency. Company debt, treasury, customer AR all post in EGP by default. |
| **USD / SAR / KWD / EUR** | ✅ Service-layer mirror + FX snapshot | ❌ **NO admin UI selector** | ⚠️ HTTP reachable only if a non-EGP `bus_inventories` row already exists in DB | Service supports multi-currency by virtue of `bus_inventories.currency` + `bus_bookings.currency` columns and the `PayBusBookingRequest` same-currency enforcement. **The currency must be written into a `bus_inventories` row first**, which can currently only be done via direct DB write / seed script / tinker — the Filament admin form has no currency field. |

**Operational currencies genuinely supported end-to-end via UI: only EGP.**
**Service-layer booking/payment/refund code covers: EGP, USD, SAR, KWD, EUR** (per migration header and service contracts).

---

## UNSUPPORTED SCENARIOS

The following FM scenarios must be **REMOVED** from the FM-67 inventory because
the Bus module does not implement them, even though they could be partially
inferred from generic/shared infrastructure:

| FM | Why it's unsupported in Bus | Implication |
|----|------------------------------|-------------|
| **FM-09** | `PayBusBookingRequest.php:61-73` rejects booking-currency ≠ account-currency. No Bus service performs FX-converting payment. | Was relying on generic `CurrencyService::convert` reachable via `BusBookingService::convertAmount`, but that helper is **only** invoked for the company debt posting and is not exposed in the payment path. |
| **FM-21** | `BusRefundService.php:207-210` rejects cross-currency treasury deposit. No Bus service performs FX-converting refund. | Same as FM-09. |
| **FM-36** | Identical blocker to FM-09 (cross-currency HTTP payment). | |
| **FM-39** | Identical blocker to FM-09. | |
| **FM-40** | "High-precision FX payment" assumes cross-currency FX is wired into `payBooking` — it is not. Same-currency payments do **not** invoke FX. | Misreads the service contract. |
| **FM-41** | "FX ledger reconciliation after KWD booking+payout" has **zero Bus-specific wiring**. The dashboard groups by currency but does not generate FX-G/L entries. | Inferred from `CurrencyService` + generic `Account`/`Transaction` infrastructure, not from Bus code. |

**Additionally, the following FMs are partially-valid (service wiring exists) but currently UNREACHABLE through the admin UI:**

- **FM-02, FM-03, FM-08, FM-20, FM-37, FM-38**: backend code path exists, but
  non-EGP inventory cannot be created via Filament (`BusInventoryResource`
  has no currency field — `BusInventoryResource.php:59-167`).

These should be **retained as CONDITIONAL** in the inventory and **flagged as
UI-unreachable**; they become genuine Bus scenarios only if/when a currency
selector is added to the inventory form, OR they are exercised by seeders /
tinker scripts that bypass the UI.

---

## INVENTORY CORRECTION

The original FM-67 inventory declared 67 scenarios. The 6 unsupported ones
above plus the 6 UI-unreachable ones need to be **re-classified**:

| Status | FMs | Count | Notes |
|--------|-----|------:|-------|
| **Genuinely supported end-to-end (UI + service)** | FM-01, FM-04..FM-07, FM-10..FM-19, FM-22..FM-23, FM-24..FM-31, FM-32..FM-35, FM-42, FM-44 (if same-method) | ~32 | Booking/payment/refund/delete in **EGP** only. Idempotency in EGP path. |
| **Wire-supported but UI-unreachable (CONDITIONAL)** | FM-02, FM-03, FM-08, FM-20, FM-37, FM-38 | 6 | Backend code path exists; requires non-EGP inventory row seeded via DB/tinker. Test must seed inventory directly. |
| **Unsupported (must be removed)** | FM-09, FM-21, FM-36, FM-39, FM-40, FM-41 | 6 | Cross-currency HTTP payment/refund + FX-at-payment + FX-at-refund do NOT exist in Bus. PayBusBookingRequest and BusRefundService explicitly REJECT them. |

**Corrected total: 67 − 6 = 61 scenarios.**

### Corrected FM Inventory (61 scenarios)

The corrected Bus-only FM inventory (65 baseline + 0 added by me, minus 6 removed):

| # | FM | Section | Status |
|---|----|---------|--------|
| 1 | FM-01 | §B Booking Creation | SUPPORTED (EGP) |
| 2 | FM-02 | §B Booking Creation | CONDITIONAL (USD/SAR wire-OK, UI-blocked) |
| 3 | FM-03 | §B Booking Creation | CONDITIONAL (FX snapshot wire-OK, UI-blocked) |
| 4 | FM-04 | §B Booking Creation | SUPPORTED (EGP, multi-quantity) |
| 5 | FM-05 | §B Booking Creation | SUPPORTED (EGP, deferred inventory) |
| 6 | FM-06 | §B Booking Creation | SUPPORTED (EGP) |
| 7 | FM-07 | §C Payment Flow | SUPPORTED (EGP, partial pay) |
| 8 | FM-08 | §C Payment Flow | CONDITIONAL (same-currency foreign wire-OK, UI-blocked) |
| ~~9~~ | ~~FM-09~~ | ~~§C Payment Flow~~ | **REMOVED — unsupported** |
| 10 | FM-10 | §C Payment Flow | SUPPORTED (full pay) |
| 11 | FM-11 | §C Payment Flow | SUPPORTED (overpay blocked) |
| 12 | FM-12 | §C Payment Flow | SUPPORTED (payment_method validation) |
| 13 | FM-13 | §C Payment Flow | SUPPORTED (5s safety net) |
| 14 | FM-14 | §C Payment Flow | SUPPORTED |
| 15 | FM-15 | §C Payment Flow | SUPPORTED |
| 16 | FM-16 | §D Cancellation | SUPPORTED (zero penalty, full refund) |
| 17 | FM-17 | §D Cancellation | SUPPORTED (with penalty) |
| 18 | FM-18 | §D Cancellation | SUPPORTED (full refund to EGP) |
| 19 | FM-19 | §D Cancellation | SUPPORTED |
| 20 | FM-20 | §D Cancellation | CONDITIONAL (foreign-currency refund wire-OK, UI-blocked) |
| ~~21~~ | ~~FM-21~~ | ~~§D Cancellation~~ | **REMOVED — unsupported (FX-at-refund does not exist)** |
| 22 | FM-22 | §D Cancellation | SUPPORTED |
| 23 | FM-23 | §D Cancellation | SUPPORTED |
| 24-26 | FM-24..26 | §E Simple Delete | SUPPORTED (3 EGP scenarios) |
| 27-31 | FM-27..31 | §F With-Reversal Delete | SUPPORTED (5 EGP scenarios) |
| 32-35 | FM-32..35 | §G Inventory Debt | SUPPORTED (4 scenarios) |
| ~~36~~ | ~~FM-36~~ | ~~§H Cross-Currency~~ | **REMOVED — unsupported** |
| 37 | FM-37 | §H Same-currency foreign | CONDITIONAL (UI-blocked) |
| 38 | FM-38 | §H Same-currency foreign | CONDITIONAL (UI-blocked) |
| ~~39~~ | ~~FM-39~~ | ~~§H Cross-Currency~~ | **REMOVED — unsupported** |
| ~~40~~ | ~~FM-40~~ | ~~§H Cross-Currency~~ | **REMOVED — unsupported (FX-at-payment does not exist)** |
| ~~41~~ | ~~FM-41~~ | ~~§H Cross-Currency~~ | **REMOVED — no Bus FX-G/L wiring** |
| 42 | FM-42 | §I Idempotency | SUPPORTED (exact replay) |
| 43-46 | FM-43..46 | §I Idempotency | SUPPORTED (variation rejects) |
| 47-50 | FM-47..50 | §J Concurrency | SUPPORTED (TRUE parallel) |
| 51-54 | FM-51..54 | §K Mutation Lock | SUPPORTED |
| 55-59 | FM-55..59 | §L Illegal States | SUPPORTED |
| 60-64 | FM-60..64 | §M DB Audit | SUPPORTED |
| 65-67 | FM-65..67 | §N Reconciliation | SUPPORTED (in EGP) |

**Final corrected count: 61 scenarios (was 67).**

Of the 6 removed scenarios (FM-09, FM-21, FM-36, FM-39, FM-40, FM-41), the
following are the technical roots of removal:

1. **Cross-currency HTTP payment** (FM-09, FM-36, FM-39) — block at
   `PayBusBookingRequest.php:61-73` re-enforced at `BusBookingService.php:634-638`.
2. **Cross-currency treasury refund** (FM-21) — block at
   `BusRefundService.php:207-210` (`"تضارب في العملة"`).
3. **FX-at-payment precision** (FM-40) — claim is spurious because no FX
   occurs on the payment path (same-currency guard).
4. **FX-G/L reconciliation** (FM-41) — no Bus-specific routine generates
   FX-G/L journal entries for finished multi-currency bookings.

### Implication for `phase10_bus_full_e2e_fm67.php`

The current `phase10_bus_full_e2e_fm67.php` script attempts the 6 removed
scenarios by:

- Creating SAR/USD/KWD `bus_inventories` rows **directly via Eloquent** (lines
  similar to `BusInventory::create([..., 'currency' => 'USD', ...])`).
- Expecting `WalletProvider::*` and `Account::OWNER_TYPE_CUSTOMER` enums which do
  not exist (already proven during the earlier run by `instapay`/string substitutions).
- Asserting "FX-converted balance" that the production code never performs.

**All of those tests are NOT exercising Bus behavior — they are exercising
generic `CurrencyService` + generic `Account::create()` after the Bus
guard rails have been bypassed.**

Consequently the 3 PASS / 0 PASS / 22 FAIL outcomes for those 6 scenarios in
the earlier retest are **not meaningful**. They need to be removed from the
pass/fail tally and the FM-67 inventory needs to be amended to 61.

---

## ACTION REQUIRED (no code changes in this phase)

1. **Update `.zcode/plans/BUS_FINANCIAL_MOVEMENT_INVENTORY_20260826.md`** to
   remove the 6 unsupported scenarios and re-classify the 6 conditional ones.
2. **Update `phase10_bus_full_e2e_fm67.php`** to:
   - Either (a) drop FM-09/21/36/39/40/41 entirely;
   - Or (b) re-purpose the 6 CONDITIONAL FMs (FM-02/03/08/20/37/38) with
     **explicit direct-inventory seeding** so they exercise the real Bus wire path.
3. **Decide whether to add a currency selector to `BusInventoryResource`** —
   that is the ONLY way to make FM-02/03/08/20/37/38 reachable through the
   admin UI. Currently they are wire-supported but Filament-admin-unreachable.

---

## FILE INDEX

- This report: `.zcode/plans/BUS_CURRENCY_SCOPE_VALIDATION_20260826.md`
- Original inventory: `.zcode/plans/BUS_FINANCIAL_MOVEMENT_INVENTORY_20260826.md` (needs correction)
- Existing retest script: `phase10_bus_full_e2e_fm67.php` (exercises the over-broad inventory)
- Prior retest report: `.zcode/plans/BUS_FM67_RETEST_REPORT_20260826.md`
  (PASS/FAIL counts include 6 scenarios that turned out to be unsupported)
