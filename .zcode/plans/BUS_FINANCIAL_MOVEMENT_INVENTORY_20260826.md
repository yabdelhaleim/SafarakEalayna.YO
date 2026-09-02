# Bus Module — Financial Movement Inventory (EGP-ONLY)

**Date:** 2026-08-26 (rev. 2 — EGP-only reset)
**Scope:** Every discovered financial/accounting touchpoint in the Bus Module.
**Method:** Source-code trace from each entry point → service → TransactionService → AccountEntry.
**Product requirement:** **BUS = EGP ONLY**. Multi-currency booking, FX conversion, cross-currency payment/refund, and foreign-currency wallets are **out of scope**.

---

## Inventory Format

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible? | FX? |
|----|----------|-------------|--------------------|-------------------|-------------|-----|

`FX?` is **always NO** in this revised inventory (Bus does not perform FX). Every row writes EGP with `exchange_rate_to_egp = 1.0`.

---

## §B — Booking Creation (5 in-scope, 1 rejection)

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible | FX |
|----|----------|-------------|--------------------|-------------------|------------|-----|
| **FM-01** | Create booking (Mode A, EGP) | `POST /api/v1/bus/bookings` → `BusBookingService::createBooking` | Sale income to customer AR + supplier cost to expense clearing | `customer_ar(EGP)`, `income_clearing(EGP)`, `supplier_ap(EGP)`, `expense_clearing(EGP)` | YES | NO |
| ~~FM-02~~ | ~~Create booking (USD/SAR/KWD)~~ | n/a | n/a | n/a | n/a | n/a |
| **FM-03** | Auto-inventory Mode B (EGP, deferred) | `BusBookingService::findOrCreateAutoInventory` | Sale in EGP | `customer_ar(EGP)`, `income_clearing(EGP)`, `supplier_ap(EGP)` | YES | NO |
| **FM-04** | Auto-create customer (Mode B, EGP) | `Customer::firstOrCreate` → `ensureCustomerAccount` | New customer ledger account in EGP | `customer_ar(EGP, NEW)` | YES | NO |
| **FM-05** | Validation reject (qty=0, qty>avail, qty<0) | `POST /api/v1/bus/bookings` | NONE (transaction rejected) | NONE | N/A | N/A |
| **FM-06** | Inventory capacity decrement + restore on cancel | `BusBookingService::createBooking` / `cancelBooking` | NONE (column update only) | `inventory.available_tickets` | YES | NO |
| **FM-G02-RG** | Reject non-EGP booking at createBooking layer | `BusBookingService::createBooking` | NONE (InvalidArgumentException) | NONE | N/A | N/A |

## §C — Payment Flows (7 in-scope, 2 rejection)

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible | FX |
|----|----------|-------------|--------------------|-------------------|------------|-----|
| **FM-07** | Full EGP payment (cashbox) | `POST /api/v1/bus/bookings/{id}/pay` | Transfer `customer_ar(EGP) → cashbox(EGP)` | `customer_ar(EGP)`, `cashbox(EGP)` | YES (refund) | NO |
| ~~FM-08~~ | ~~USD payment (USD wallet)~~ | n/a | n/a | n/a | n/a | n/a |
| ~~FM-09~~ | ~~SAR payment (EGP cashbox, FX)~~ | n/a | n/a | n/a | n/a | n/a |
| **FM-10** | Partial → top-up aggregation | `POST /pay` × 2 | Two Transfer rows | `customer_ar(EGP)`, `cashbox(EGP)`, `bus_payments(EGP)` | YES | NO |
| **FM-11** | Multi-payment (3 partials) | `POST /pay` × 3 | Three Transfer rows | `customer_ar(EGP)`, `cashbox(EGP)`, `bus_payments(EGP)` | YES | NO |
| **FM-12** | Idempotent replay (same key) | `POST /pay` (header replay) | NONE (early return) | NONE | N/A | N/A |
| **FM-13** | Safety-net 5s tuple window | `POST /pay` (no header) | NONE (early throw) | NONE | N/A | N/A |
| **FM-14** | Overpayment rejected | `POST /pay` | NONE (Arabic error) | NONE | N/A | N/A |
| **FM-15** | Pay on cancelled booking rejected | `POST /pay` | NONE (Arabic error) | NONE | N/A | N/A |
| **FM-G08-RG** | Reject non-EGP payment account at payBooking layer | `BusBookingService::payBooking` | NONE (InvalidArgumentException) | NONE | N/A | N/A |
| **FM-G09-RG** | Reject HTTP non-EGP booking at PayBusBookingRequest | `PayBusBookingRequest::withValidator` | NONE (422) | NONE | N/A | N/A |

## §D — Cancellation (6 in-scope, 2 rejection)

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible | FX |
|----|----------|-------------|--------------------|-------------------|------------|-----|
| **FM-16** | Cancel unpaid, no penalty | `POST /api/v1/bus/bookings/{id}/cancel` | Reverse cost + reverse AR (unpaid portion); inventory restored | `customer_ar(EGP)`, `supplier_ap(EGP)`, `expense_clearing(EGP)`, `inventory` | NO | NO |
| **FM-17** | Cancel paid, no penalty | same | Reverse cost + reverse AR + **refund expense** (cashbox −paid) | + `cashbox(EGP)` | NO | NO |
| **FM-18** | Cancel paid, 100% penalty | same | Reverse cost + reverse AR; refund=0; status=PartiallyRefunded | + `cashbox(EGP)` (Δ=0) | NO | NO |
| **FM-19** | Cancel paid, partial penalty | same | Refund = paid − penalty | + `cashbox(EGP)` | NO | NO |
| ~~FM-20~~ | ~~Cancel USD paid from USD wallet~~ | n/a | n/a | n/a | n/a | n/a |
| ~~FM-21~~ | ~~Cancel USD paid from EGP cashbox (FX-at-refund)~~ | n/a | n/a | n/a | n/a | n/a |
| **FM-22** | Double-cancel rejected | same | NONE | NONE | N/A | N/A |
| **FM-23** | Cancel after pay-debt BLOCKED | same | NONE (conservation guard) | NONE | N/A | N/A |
| **FM-G20-RG** | Reject non-EGP refund account at cancelBooking | `BusBookingService::cancelBooking` | NONE (InvalidArgumentException) | NONE | N/A | N/A |
| **FM-G21-RG** | Reject non-EGP treasury at processRefundRequest | `BusRefundService::processRefundRequest` | NONE (InvalidArgumentException) | NONE | N/A | N/A |

## §E — Simple `deleteBooking` (3 in-scope)

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible | FX |
|----|----------|-------------|--------------------|-------------------|------------|-----|
| **FM-24** | Delete unpaid booking | `DELETE /api/v1/bus/bookings/{id}` | Reverse cost + reverse AR; inventory restored; soft-delete | `customer_ar(EGP)`, `supplier_ap(EGP)`, `expense_clearing(EGP)`, `inventory` | NO | NO |
| **FM-25** | Delete paid booking rejected | same | NONE (Arabic `لوجود مدفوعات`) | NONE | N/A | N/A |
| **FM-26** | Delete already-cancelled booking | same | Soft-delete only (no double reversal) | NONE (operational) | NO | NO |

## §F — `deleteBookingWithReversal` (5 in-scope)

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible | FX |
|----|----------|-------------|--------------------|-------------------|------------|-----|
| **FM-27** | Partial-paid delete | `BusBookingService::deleteBookingWithReversal` | Reverse each payment (additive) + reverse cost + reverse AR + inventory restored | `cashbox(EGP)`, `customer_ar(EGP)`, `supplier_ap(EGP)`, `expense_clearing(EGP)`, `inventory` | NO | NO |
| **FM-28** | Fully-paid delete | same | Reverse each payment + reverse cost + reverse AR | same | NO | NO |
| **FM-29** | Multi-payment delete | same | Reverse each of N payments + reverse cost + reverse AR | same | NO | NO |
| **FM-30** | Double delete rejected | same | NONE (RuntimeException Arabic) | NONE | N/A | N/A |
| **FM-31** | `BusRefundRequest.transaction_id` nulled | same | Post-delete UPDATE clears the FK | `bus_refund_requests` | NO | NO |

## §G — Inventory Debt Lifecycle (4 in-scope)

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible | FX |
|----|----------|-------------|--------------------|-------------------|------------|-----|
| **FM-32** | Deferred inventory partial→full debt pay | `POST /api/v1/bus/inventories/{id}/pay-debt` | Expense from cashbox to supplier; `remaining_debt` ↓ | `cashbox(EGP)`, `supplier_ap(EGP)` | NO | NO |
| **FM-33** | Cash inventory delete reverses expense | `DELETE /api/v1/bus/inventories/{id}` | Reverse cash expense (additive) | `cashbox(EGP)`, `expense_clearing(EGP)` | NO | NO |
| **FM-34** | Deferred inventory delete (no bookings) | same | Soft-delete only (no expense to reverse) | `inventory` (soft-delete) | NO | NO |
| **FM-35** | Inventory delete with bookings rejected | same | NONE | NONE | N/A | N/A |

## §H — Cross-Currency HTTP Layer (0 in-scope, 6 rejection)

| ID | Scenario | Entry Point | Status |
|----|----------|-------------|--------|
| ~~FM-36~~ | ~~USD booking → USD wallet pay (HTTP)~~ | `POST /pay` | **REJECTED** at PayBusBookingRequest (booking-currency ≠ EGP) + PayBusBookingRequest cross-currency check |
| ~~FM-37~~ | ~~USD booking → EGP cashbox pay (HTTP, FX)~~ | `POST /pay` | **REJECTED** at PayBusBookingRequest + payBooking service-layer assertBusCurrency |
| ~~FM-38~~ | ~~SAR booking → SAR wallet pay (HTTP)~~ | `POST /pay` | **REJECTED** at PayBusBookingRequest |
| ~~FM-39~~ | ~~SAR booking → EGP cashbox pay (HTTP, FX)~~ | `POST /pay` | **REJECTED** at PayBusBookingRequest |
| ~~FM-40~~ | ~~KWD booking via HTTP (high-rate precision)~~ | `POST /pay` | **REJECTED** at PayBusBookingRequest |
| ~~FM-41~~ | ~~Customer AR multi-currency stacking~~ | `POST /bookings` | **REJECTED** at createBooking (assertBusCurrency on inventory + customer_account) |
| **FM-G36-RG** | HTTP reject USD booking→USD wallet | `POST /pay` | NONE (422 from PayBusBookingRequest) |
| **FM-G37-RG** | HTTP reject USD booking→EGP cashbox FX | `POST /pay` | NONE (422) |
| **FM-G38-RG** | HTTP reject SAR booking→SAR wallet | `POST /pay` | NONE (422) |
| **FM-G39-RG** | HTTP reject SAR booking→EGP cashbox FX | `POST /pay` | NONE (422) |
| **FM-G40-RG** | HTTP reject KWD booking high-rate | `POST /pay` | NONE (422) |
| **FM-G41-RG** | Reject multi-currency AR stacking at createBooking | `BusBookingService::createBooking` | NONE (InvalidArgumentException) |

## §I — Idempotency / Duplicate Movement (5 in-scope)

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible | FX |
|----|----------|-------------|--------------------|-------------------|------------|-----|
| **FM-42** | Triple replay same Idempotency-Key | `POST /pay` × 3 | 1 payment row, 1 transfer | NONE on retries | N/A | N/A |
| **FM-43** | Replay after first call 422 | same | NONE on retry (no row) | NONE | N/A | N/A |
| **FM-44** | Replay with different `payment_method` | same | First succeeds; second rejected (different tuple) | partial | N/A | N/A |
| **FM-45** | Replay with different `amount` | same | First succeeds; second rejected | partial | N/A | N/A |
| **FM-46** | Same key on different bookings | same | Both succeed independently | per-booking | N/A | N/A |

## §J — Concurrency (4 in-scope)

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible | FX |
|----|----------|-------------|--------------------|-------------------|------------|-----|
| **FM-47** | 2 simultaneous same-key payments | `POST /pay` × 2 | 1 row (DB lock guarantees) | one-time only | N/A | N/A |
| **FM-48** | Pay vs cancel simultaneous | `POST /pay` + `POST /cancel` | Final state consistent (one wins) | depends | N/A | N/A |
| **FM-49** | 2 simultaneous `deleteBookingWithReversal` | service × 2 | Second throws | one-time only | N/A | N/A |
| **FM-50** | 2 simultaneous `cancelBooking` | service × 2 | Second throws | one-time only | N/A | N/A |

## §K — Mutation Lock (4 in-scope)

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible | FX |
|----|----------|-------------|--------------------|-------------------|------------|-----|
| **FM-51** | Direct `total_price` write after pay | `$booking->update(['total_price' => 0])` | NONE (column protected by resource edit; service path: no-op) | NONE | N/A | N/A |
| **FM-52** | Direct `currency` write after pay | `$booking->update(['currency' => 'EUR'])` | NONE (column free, but service-layer asserts EGP on every operation) | NONE | N/A | N/A |
| **FM-53** | Direct `$booking->restore()` after delete | post-delete restore | NONE for payments (soft-deleted); booking visible | operational | N/A | N/A |
| **FM-54** | Direct `status` write after cancel | `$booking->update(['status' => 'Pending'])` | NONE (status field guarded) | NONE | N/A | N/A |

## §L — Illegal / Invalid States (5 in-scope)

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible | FX |
|----|----------|-------------|--------------------|-------------------|------------|-----|
| **FM-55** | Refund unpaid booking | `POST /api/v1/bus/refunds` | NONE (throw `لا يمكن إنشاء استرداد لحجز غير مدفوع`) | NONE | N/A | N/A |
| **FM-56** | Refund > paid amount | same | NONE (throw `إجمالي الاستردادات يتجاوز المبلغ المدفوع`) | NONE | N/A | N/A |
| **FM-57** | Refund twice | same × 2 | First succeeds; second no-op (status=processed) | one-time only | N/A | N/A |
| **FM-58** | Pay amount=0 / negative | `POST /pay` | NONE (throw) | NONE | N/A | N/A |
| **FM-59** | Cancel after Refunded | `POST /cancel` | NONE (throw `الحجز ملغي أو مسترد بالفعل`) | NONE | N/A | N/A |

## §M — Database-Level Audit (5 in-scope)

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible | FX |
|----|----------|-------------|--------------------|-------------------|------------|-----|
| **FM-60** | Transaction row count after lifecycle | DB inspection | 1 sale + 1 cost + N payments + reversals | `transactions`, `account_entries` | N/A | N/A |
| **FM-61** | Soft-deleted rows hidden by default | DB inspection | NONE (operational) | `bus_bookings.deleted_at`, `bus_payments.deleted_at` | N/A | N/A |
| **FM-62** | No orphan AccountEntry rows | DB inspection | NONE (invariant) | `account_entries` | N/A | N/A |
| **FM-63** | No dangling `related_id` after delete | DB inspection | NONE (invariant) | `transactions.related_id` | N/A | N/A |
| **FM-64** | Income tx uniqueness | `recordIncome` × 2 | Second throws (duplicate-income guard) | `transactions` | N/A | N/A |

## §N — Reconciliation / Conservation (3 in-scope)

| ID | Scenario | Entry Point | Financial Movement | Accounts Affected | Reversible | FX |
|----|----------|-------------|--------------------|-------------------|------------|-----|
| **FM-65** | Cashbox Δ = Σ payments − Σ refunds | reconciliation | NONE ( invariant check) | `cashbox(EGP)` | N/A | N/A |
| **FM-66** | Booking financial state = Σ tx | reconciliation | NONE | `bus_bookings` | N/A | N/A |
| **FM-67** | Refund net = 0 on customer AR | reconciliation | NONE | `customer_ar(EGP)` | N/A | N/A |

---

## Totals (rev. 2 — EGP-only)

- **§B (Booking creation)**: 5 in-scope + 1 rejection guard = **6 tests**
- **§C (Payment flows)**: 7 in-scope + 2 rejection guards = **9 tests**
- **§D (Cancellation)**: 6 in-scope + 2 rejection guards = **8 tests**
- **§E (Simple delete)**: 3 in-scope = **3 tests**
- **§F (With-reversal delete)**: 5 in-scope = **5 tests**
- **§G (Inventory debt)**: 4 in-scope = **4 tests**
- **§H (Cross-currency HTTP)**: 0 in-scope + 6 rejection guards = **6 tests**
- **§I (Idempotency)**: 5 in-scope = **5 tests**
- **§J (Concurrency)**: 4 in-scope = **4 tests**
- **§K (Mutation lock)**: 4 in-scope = **4 tests**
- **§L (Illegal states)**: 5 in-scope = **5 tests**
- **§M (DB-level audit)**: 5 in-scope = **5 tests**
- **§N (Reconciliation)**: 3 in-scope = **3 tests**

**In-scope positive scenarios: 56**
**Negative rejection guards: 11**
**TOTAL = 67 tests** (56 EGP-only positive + 11 negative guards)

The 11 negative guards (FM-G02-RG, FM-G08-RG, FM-G09-RG, FM-G20-RG, FM-G21-RG, FM-G36..G41-RG) are the
**re-purposed** versions of the 11 removed cross-currency scenarios. They DO NOT count as in-scope
financial movements — they are explicit rejection tests proving that the Bus EGP-only contract holds.

---

## EGP-Only Enforcement Matrix (defense-in-depth)

| Layer | File | Enforcement | Behaviour on non-EGP attempt |
|-------|------|-------------|------------------------------|
| Filament Inventory form | `BusInventoryResource` | No currency field | Cannot create non-EGP inventory via UI |
| Filament Booking form | `BusBookingResource` | No currency field | Cannot create non-EGP booking via UI |
| Filament all `money()` calls | `BusBookingResource`, `BusInventoryResource` | `money('EGP')` | Display EGP only |
| HTTP validation | `StoreBusInventoryRequest`, `StoreBusBookingRequest`, `PayBusBookingRequest` | EGP-only checks | 422 with Arabic error |
| HTTP validation | `BusRefundController::store` | `refund_currency ∈ {EGP, egp}`, `refund_exchange_rate = 1.0` | 422 with Arabic error |
| Service writer | `BusInventoryService::createInventory` | Forces `'EGP', 1.0` | Cannot persist non-EGP |
| Service writer | `BusInventoryService::updateInventory` | Forces `'EGP', 1.0` + `assertBusCurrency` | Cannot persist non-EGP |
| Service writer | `BusBookingService::createBooking` | Forces `'EGP', 1.0` + asserts inventory EGP | Throws if inventory non-EGP |
| Service writer | `BusBookingService::payBooking` | Forces `'EGP', 1.0` + asserts customer_account + paid_account EGP | Throws if account non-EGP |
| Service writer | `BusBookingService::cancelBooking` | Forces EGP refund snapshot + asserts booking + refund_account EGP | Throws if non-EGP |
| Service writer | `BusBookingService::deleteBooking*` | Forces EGP + asserts booking EGP | Throws if booking non-EGP |
| Service writer | `BusRefundService::createRefundRequest` | Forces EGP refund snapshot + asserts booking + request currency | Throws if non-EGP |
| Service writer | `BusRefundService::processRefundRequest` | Forces EGP + asserts booking + request + treasury EGP | Throws if non-EGP |
| Dashboard | `BusDashboardController::index` | Filters `where('currency', 'EGP')` | Non-EGP rows excluded from sums |
| Vue refund wizard | `BusRefundWizard.vue` | `formatMoney(..., 'EGP')` always | Display EGP only |
| Trait | `App\Services\Bus\Concerns\BusEgpOnly` | `assertBusCurrency` / `assertBusExchangeRate` | Centralized guards |

---

## Out of Scope (explicitly NOT tested as positive scenarios)

- Foreign-currency booking creation (USD/SAR/KWD/EUR)
- FX conversion at any layer
- Cross-currency payment (booking in X, paid from account in Y ≠ X)
- Cross-currency refund (booking in X, refunded to account in Y ≠ X)
- Multi-currency customer AR stacking (one customer with USD + EGP accounts)
- Foreign-currency wallet settlement
- FX gain/loss journal entries
- `CurrencyService::convert` invocation from any Bus code path

These are NOT failures — they are product **out-of-scope**. They are tested ONLY as **rejection guards**
to prove the Bus module refuses them.

---

## File Index

- This report: `.zcode/plans/BUS_FINANCIAL_MOVEMENT_INVENTORY_20260826.md`
- Currency scope validation: `.zcode/plans/BUS_CURRENCY_SCOPE_VALIDATION_20260826.md`
- EGP-only audit: `.zcode/plans/BUS_EGP_ONLY_AUDIT_REPORT_20260826.md`
- Test runner: `phase10_bus_full_e2e_egp56.php` (replaces `phase10_bus_full_e2e_fm67.php`)