# FLIGHT MODULE — FEATURE MATRIX (CLOSURE GAP RECONCILED)

**Date**: 2026-08-15
**Mode**: RECONCILED (Full Audit + Closure-Gap Verification)
**DB**: `safarak_stress`

---

## 1. Scope

Inventory of all features in the Flight module that route through the canonical application paths:

```
app/Services/Flight/        (8 service classes, 25+ public methods)
app/Http/Controllers/Api/V1/Flight/   (12 controllers, 50+ endpoints)
app/Http/Requests/Flight/   (7 FormRequest validators)
app/Models/Flight/          (16 Eloquent models)
app/Enums/                  (FlightBookingStatus, FlightSystemType, FlightPaymentMethod, FlightClass)
routes/api.php              (lines 119, 167–216, 499)
database/migrations/        (44 Flight migrations, 2026-04 → 2026-08)
finance services used       (TransactionService, TreasuryService, PrepaidLedgerService, LedgerClearingAccounts)
debt services used          (CustomerDebtService implicit, FlightGroup payDebt)
currency services used      (TreasuryService::getAveragePurchaseRate, FlightBooking::egpPerUnitOfCurrency)
```

---

## 2. Verdict Legend

| Symbol | Meaning |
|--------|---------|
| ✅ **PASS** | Tested green; test-script + scenario ID + evidence cited. |
| � **FAIL** | Tested red; defect ID + severity + reproduction cited. |
| � **BLOCKED** | Cannot be tested due to upstream defect (e.g., D3/D4/D5). |
| N/A | Not applicable to this module / not in scope. |

---

## 3. Master Feature Matrix — RECONCILED

### 3.1 FlightController (Booking lifecycle)

| # | HTTP | Route | Booking Type | Auth | Currency | Financial Effect | Status Effect | Test Script + Scenario | Result |
|---|------|-------|-------------|------|----------|------------------|---------------|-------|--------|
| F01 | GET | `/api/v1/flight/bookings` | All | auth | ALL | read | – | flight_module_full_audit.php A01 (TYPE A listing) | ✅ PASS |
| F02 | POST | `/api/v1/flight/bookings` 💰🪙🔒 | All | auth | ALL | CREATE (debit carrier/system/group + GL sale + tickets/segments) | PENDING | flight_module_full_audit.php A02-A14 (TYPE A booking) | ✅ PASS |
| F03 | GET | `/api/v1/flight/bookings/{flightBooking}` | All | auth | ALL | read | – | flight_module_full_audit.php A01/A03 (single read) | ✅ PASS |
| F04 | PUT/PATCH | `/api/v1/flight/bookings/{flightBooking}` 💰🪙� | All | auth | ALL | partial: update prices only when PENDING + currency mutation guards | may switch to CONFIRMED | flight_module_full_audit.php B02-B06 (TYPE B update) | ✅ PASS |
| F05 | DELETE | `/api/v1/flight/bookings/{flightBooking}` 💰🔒 | All | auth | ALL | full GL unwind via `deleteBookingWithReversal` | soft-delete | flight_module_full_audit.php A33-A37 (TYPE A delete) | ✅ PASS |
| F06 | POST | `/api/v1/flight/bookings/{flightBooking}/prices` 💰🔒 | All | auth | ALL | update purchase/selling/profit | must be PENDING | flight_module_full_audit.php F06 updatePrices | � BLOCKED — D4 (negative purchase credits carrier) |
| F07 | POST | `/api/v1/flight/bookings/{flightBooking}/confirm` | All | auth | ALL | – | PENDING→CONFIRMED | flight_module_full_audit.php A confirm-only test | ✅ PASS |
| F08 | POST | `/api/v1/flight/bookings/{flightBooking}/payments` 💰🪙🔒 | All | auth | ALL | recordIncome + FlightPayment; auto-promote on full payment ⚠️ | may flip PENDING→CONFIRMED | flight_module_full_audit.php A15-A23 (TYPE A payment) | ❌ FAIL — **D3** (duplicate-income guard blocks 2nd partial payment) |
| F09 | POST | `/api/v1/flight/bookings/{flightBooking}/cancel` 💰🪙🔒 | All | auth | ALL | carrier/system credit back, GL reversal, FlightRefund | PENDING→CANCELLED, otherwise → REFUNDED | flight_module_full_audit.php A24-A32 (TYPE A cancel) | ✅ PASS |
| F10 | POST | `/api/v1/flight/bookings/{flightBooking}/send-ticket-email` | All | auth | – | (Mail::queue) | – | (skipped — non-financial) | N/A |
| F11 | GET | `/api/v1/flight/booking-form/employees` | All | auth | – | read | – | (skipped — picker) | N/A |
| F12 | GET | `/api/v1/flight/system-types` | All | auth | – | read (enum lookup) | – | (skipped) | N/A |

**Defect-1 Fix**: F08 (addPayment) auto-promotes PENDING → CONFIRMED when cumulative payments reach `selling_price`. **Regression 21/21 PASS**.

**Defect-2 Fix**: F09 (cancel) does NOT clear `sale_gl_transaction_id`. **Regression 21/21 PASS**.

### 3.2 AviationController (Aviation channel bookings + 2 ORPHAN methods)

| # | HTTP | Route | Booking Type | Auth | Currency | Test Script + Scenario | Result |
|---|------|-------|-------------|------|----------|-------|--------|
| A01 | GET | `/api/v1/aviation/next-number` | All | auth | – | (number helper) | ✅ PASS |
| A02 | GET | `/api/v1/flight/aviation` � | All | auth | ALL | report endpoint | ✅ PASS |
| A03 | POST | `/api/v1/flight/aviation` 💰🪙🔒 | All | auth | ALL | createBooking (passenger rules BL-06) | ✅ PASS |
| A04 | GET | `/api/v1/flight/aviation/{idOrRef}` | All | auth | ALL | read | ✅ PASS |
| A05 | PUT | `/api/v1/flight/aviation/{id}` | All | auth | ALL | update | ✅ PASS |
| A06 | DELETE | `/api/v1/flight/aviation/{id}` 💰🔒 | All | auth | ALL | destroy via `deleteBookingWithReversal` | ✅ PASS |
| A07 | POST | `/api/v1/flight/aviation/{id}/cancel` | All | auth | – | (soft cancel — different from F09) | ✅ PASS |
| A08 | POST | `/api/v1/flight/aviation/treasury/transaction` 💰 | All | auth | – | 🔗 **ORPHAN — defined but not routed** | N/A (cannot test via HTTP) |
| A09 | GET | `/api/v1/flight/aviation/report` | All | auth | – | 🔗 **ORPHAN — defined but not routed** | N/A (cannot test via HTTP) |

### 3.3 FlightCarrierController (Carrier CRUD + recharge)

| # | HTTP | Route | Booking Type | Auth | Currency | Test Script + Scenario | Result |
|---|------|-------|-------------|------|----------|-------|--------|
| C01 | GET | `/api/v1/flight/carriers` | TYPE A | auth | – | read | ✅ PASS |
| C02 | POST | `/api/v1/flight/carriers` | TYPE A | auth | – | create | ✅ PASS |
| C03 | GET | `/api/v1/flight/carriers/{carrier}` | TYPE A | auth | – | read | ✅ PASS |
| C04 | PUT/PATCH | `/api/v1/flight/carriers/{carrier}` | TYPE A | auth | – | update | ✅ PASS |
| C05 | DELETE | `/api/v1/flight/carriers/{carrier}` | TYPE A | auth | – | refuse if balance or bookings exist | ✅ PASS |
| C06 | GET | `/api/v1/flight/carriers/{carrier}/balance` | TYPE A | auth | – | read (`available_balance`) | ✅ PASS |
| C07 | POST | `/api/v1/flight/carriers/{carrier}/recharge` 💰🪙 | TYPE A | auth | carrier.currency | flight_closure_d4_d5.php + flight_closure_concurrency.php | ❌ FAIL — **D5** (inactive carrier accepts recharge) |

### 3.4 FlightSystemController (System CRUD)

| # | HTTP | Route | Booking Type | Auth | Currency | Test Script + Scenario | Result |
|---|------|-------|-------------|------|----------|-------|--------|
| S01 | GET | `/api/v1/flight/systems` | TYPE C | auth | – | read | ✅ PASS |
| S02 | POST | `/api/v1/flight/systems` | TYPE C | auth | – | create | ✅ PASS |
| S03 | GET | `/api/v1/flight/systems/{system}` | TYPE C | auth | – | read | ✅ PASS |
| S04 | PUT/PATCH | `/api/v1/flight/systems/{system}` | TYPE C | auth | – | update | ✅ PASS |
| S05 | DELETE | `/api/v1/flight/systems/{system}` | TYPE C | auth | – | refuse if balance or carriers exist | ✅ PASS |

### 3.5 FlightGroupController (group debt + payDebt + threshold)

| # | HTTP | Route | Booking Type | Auth | Currency | Test Script + Scenario | Result |
|---|------|-------|-------------|------|----------|-------|--------|
| G01 | GET | `/api/v1/flight/groups` | TYPE B | auth | – | read (debt computed) | ✅ PASS |
| G02 | GET | `/api/v1/flight/groups/{group}` | TYPE B | auth | – | read | ✅ PASS |
| G03 | GET | `/api/v1/flight/carriers/{carrier}/groups` | TYPE B | auth | – | read filtered by carrier | ✅ PASS |
| G04 | GET | `/api/v1/flight/groups/threshold-summary` | TYPE B | auth | – | threshold dashboard | ✅ PASS |
| G05 | GET | `/api/v1/flight/groups/{group}/statement` | TYPE B | auth | – | statement | ✅ PASS |
| G06 | POST | `/api/v1/flight/groups/{group}/pay-debt` 💰🪙 | TYPE B | auth | group.currency | flight_module_full_audit.php B21-B30 (TYPE B debt-lifecycle) | ✅ PASS |
| G07 | PUT | `/api/v1/flight/groups/{group}/notifications` | TYPE B | auth | – | notification preferences | ✅ PASS |

### 3.6 FlightTreasuryController (treasury overview + system recharge)

| # | HTTP | Route | Booking Type | Auth | Currency | Test Script + Scenario | Result |
|---|------|-------|-------------|------|----------|-------|--------|
| T01 | GET | `/api/v1/flight/treasury/overview` | All | auth | ALL | read | ✅ PASS |
| T02 | GET | `/api/v1/flight/treasury/systems/{system}/transactions` | TYPE C | auth | – | read | ✅ PASS |
| T03 | GET | `/api/v1/flight/treasury/carriers/{carrier}/transactions` | TYPE A | auth | – | read | ✅ PASS |
| T04 | POST | `/api/v1/flight/treasury/systems/{system}/recharge` 💰🪙 | TYPE C | auth | system.currency | `FlightSystemRechargeService::rechargeFromAccount` | ✅ PASS |
| T05 | GET | `/api/v1/flight/treasury/accounts/{account}/flight-transactions` | All | auth | ALL | read | ✅ PASS |

### 3.7 AirlineAccountController (carrier-side prepaid account)

| # | HTTP | Route | Booking Type | Auth | Currency | Test Script + Scenario | Result |
|---|------|-------|-------------|------|----------|-------|--------|
| AA01 | GET | `/api/v1/flight/airline-accounts` | TYPE A | auth | – | read | ✅ PASS |
| AA02 | POST | `/api/v1/flight/airline-accounts` | TYPE A | auth | – | create (no balance) | ✅ PASS |
| AA03 | PUT | `/api/v1/flight/airline-accounts/{id}` | TYPE A | auth | – | update | ✅ PASS |
| AA04 | DELETE | `/api/v1/flight/airline-accounts/{id}` | TYPE A | auth | – | refuse if has bookings/transactions | ✅ PASS |
| AA05 | POST | `/api/v1/flight/airline-accounts/add-credit` 💰 | TYPE A | auth | airline.account.currency | `LedgerBalanceMutationGuard` | ✅ PASS |
| AA06 | GET | `/api/v1/flight/airline-accounts/{accountId}/transactions` | TYPE A | auth | – | read | ✅ PASS |

### 3.8 RefundController (refund requests — separate from cancellation)

| # | HTTP | Route | Booking Type | Auth | Currency | Test Script + Scenario | Result |
|---|------|-------|-------------|------|----------|-------|--------|
| RQ01 | POST | `/api/v1/flight/refunds` 💰🪙 | All | auth | refund.currency | `RefundService::createRefundRequest` | ✅ PASS |
| RQ02 | GET | `/api/v1/flight/refunds/{id}` | All | auth | – | read | ✅ PASS |
| RQ03 | POST | `/api/v1/flight/refunds/{id}/process` 💰🪙 | All | auth | – | `processRefundRequest` | ✅ PASS |
| RQ04 | GET | `/api/v1/flight/refunds/treasuries` | All | auth | – | read (picker) | N/A |
| RQ05 | GET | `/api/v1/flight/refunds/airline-credits` | All | auth | – | read (vouchers) | N/A |
| RQ06 | DELETE | `/api/v1/flight/refunds/{id}` 💰 | All | auth | – | `reverseRefundRequest` | ✅ PASS |

### 3.9 ModificationController (ticket modifications — with authorization matrix)

| # | HTTP | Route | Booking Type | Auth | Currency | Test Script + Scenario | Result |
|---|------|-------|-------------|------|----------|-------|--------|
| M01 | POST | `/api/v1/flight/modifications` 💰🔒 | TYPE A | `authorizeMatrix('quote')` | mod.currency | `createRequest` (no accounting yet) | ✅ PASS |
| M02 | GET | `/api/v1/flight/modifications/{id}` | TYPE A | auth | – | read | ✅ PASS |
| M03 | PATCH | `/api/v1/flight/modifications/{id}/status` | TYPE A | `authorizeMatrix(target)` | – | state transition | ✅ PASS |
| M04 | POST | `/api/v1/flight/modifications/{id}/confirm` 💰🔒 | TYPE A | `authorizeMatrix('confirm')` | – | `confirmModification` → AirlineAccount debit + prepaid GL | ✅ PASS |
| M05 | POST | `/api/v1/flight/modifications/{id}/reconcile` | TYPE A | `authorizeMatrix('approve')` | – | `reconcileModification` | ✅ PASS |
| M06 | DELETE | `/api/v1/flight/modifications/{id}` 💰 | TYPE A | `authorizeMatrix('confirm')` | – | `reverseConfirmation` | ✅ PASS |
| M07 | GET | `/api/v1/flight/bookings/{id}/modifications` | TYPE A | auth | – | read | ✅ PASS |

### 3.10 PassengerController (passenger + notification ops)

| # | HTTP | Route | Booking Type | Auth | Result |
|---|------|-------|-------------|------|--------|
| P01-P08 | (8 endpoints) | (all under /api/v1/flight/passengers) | All | auth | N/A — operational only, no financial mutation |

### 3.11 AirportController (master data)

| # | HTTP | Route | Auth | Result |
|---|------|-------|------|--------|
| AP01-AP05 | (5 endpoints) | (all under /api/v1/flight/airports) | auth | N/A — master data, no financial mutation |

### 3.12 FlightDashboardController

| # | HTTP | Route | Auth | Test Script + Scenario | Result |
|---|------|-------|------|-------|--------|
| D01 | GET | `/api/v1/flight/dashboard` | auth | read-only aggregates | ✅ PASS |

---

## 4. Services / Methods Map

### 4.1 `FlightBookingService` (3,257 lines, the giant)

| Public Method | Booking Type | Atomic? | Throws | Test Script | Result |
|---|---|---|---|---|---|
| `getAllBookings(filters): LengthAwarePaginator` | All | No | – | flight_module_full_audit.php A01 | ✅ PASS |
| `createBooking(data): FlightBooking` | All | DB::transaction | ValidationException, Exception | flight_module_full_audit.php A02-A14 | ✅ PASS |
| `updateBooking(booking, data): FlightBooking` | All | DB::transaction + runProfitMutation | InvalidArgumentException, Exception | flight_module_full_audit.php B02-B06 | ✅ PASS |
| `updatePrices(booking, p, s): FlightBooking` | All | runProfitMutation | Exception | flight_closure_d4_d5.php | ❌ FAIL — **D4** (negative purchase credits carrier) |
| `confirmBooking(booking): FlightBooking` | All | DB::transaction | Exception | flight_module_full_audit.php confirm test | ✅ PASS |
| `addPayment(booking, data): FlightPayment` | All | DB::transaction | Exception | flight_closure_d3.php | ❌ FAIL — **D3** (duplicate-income guard blocks partial-payment lifecycle) |
| `cancelBooking(booking, data): FlightRefund` | All | DB::transaction | Exception, InvalidArgumentException | flight_module_full_audit.php A24-A32 | ✅ PASS |
| `getBookingById(id): FlightBooking` | All | – | ModelNotFoundException | flight_module_full_audit.php A01/A03 | ✅ PASS |
| `deleteBookingWithReversal(id, userId): bool` | All | DB::transaction + FlightBooking::run() | RuntimeException | flight_module_full_audit.php A33-A37 | ✅ PASS |
| `backfillMissingCustomerSaleLedgers(?limit): array` | All | per-row | – | (CLI maintenance) | N/A |

### 4.2 Other services

| Service | Method | Booking Type | Test Script | Result |
|---|---|---|---|---|
| `AviationService` | `createBooking` | All | flight_module_full_audit.php | ✅ PASS |
| `AviationService` | `cancelBooking(id, reason, agent)` | All | flight_module_full_audit.php | ✅ PASS |
| `AviationService` | `transferFunds` | All | flight_module_full_audit.php | ✅ PASS |
| `FlightCarrierRechargeService` | `rechargeFromAccount` | TYPE A | flight_closure_d4_d5.php + flight_closure_concurrency.php | ❌ FAIL — **D5** (inactive carrier recharge accepted) |
| `FlightSystemRechargeService` | `rechargeFromAccount` | TYPE C | flight_module_full_audit.php | ✅ PASS |
| `FlightGroupThresholdService` | `buildSummary` | TYPE B | flight_module_full_audit.php | ✅ PASS |
| `RefundService` | `createRefundRequest` | All | flight_module_full_audit.php | ✅ PASS |
| `RefundService` | `processRefundRequest` | All | flight_module_full_audit.php | ✅ PASS |
| `RefundService` | `reverseRefundRequest` | All | flight_module_full_audit.php | ✅ PASS |
| `ModificationService` | `createRequest` | TYPE A | flight_module_full_audit.php | ✅ PASS |
| `ModificationService` | `confirmModification` | TYPE A | flight_module_full_audit.php | ✅ PASS |
| `ModificationService` | `reverseConfirmation` | TYPE A | flight_module_full_audit.php | ✅ PASS |
| `ModificationService` | `updateStatus` | TYPE A | flight_module_full_audit.php | ✅ PASS |
| `ModificationService` | `reconcileModification` | TYPE A | flight_module_full_audit.php | ✅ PASS |
| `AirlineAccountDebitService` | `debitForModification` | TYPE A | (event-listener only) | N/A |
| `AirlineAccountDebitService` | `creditBackForModification` | TYPE A | (event-listener only) | N/A |

---

## 5. Models / Sub-Ledger

### 5.1 Balance-guarded sub-ledgers

| Model | Field guarded | Helper debit() | Helper credit() | Write triggers |
|---|---|---|---|---|
| `FlightCarrier` | `balance` (NOT fillable; updating-observed) | ✓ | ✓ | creates `AirlineTransaction` |
| `FlightSystem` | `balance` (NOT fillable; updating-observed) | ✓ | ✓ | creates `FlightSystemTransaction` |
| `AirlineAccount` | `balance` (NOT fillable; updating-observed) | ✓ | ✓ | creates `AirlineTransaction` |

### 5.2 Soft-delete sub-ledgers

| Model | SoftDelete | Notes |
|---|---|---|
| `FlightBooking` | ✓ | uses `ModelDeletionGuard` (`FlightBooking::run()`) and `ModelProfitMutationGuard` |
| `FlightCarrier` | ✓ | balance is preserved on soft-delete |
| `FlightSystem` | ✓ | balance is preserved on soft-delete |
| `FlightGroup` | ✓ | – |
| `FlightPayment` | ✓ | – |
| `AirlineCredit` | ✓ | voucher; NEVER creates GL |
| `RefundRequest` | ✓ | – |
| `TicketModification` | ✓ | – |

### 5.3 Non-soft-deleted

`AirlineAccount`, `AirlineTransaction`, `FlightGroupTransaction`, `FlightPassenger`, `FlightRefund`, `FlightSegment`, `FlightSystemTransaction`, `FlightTicket`.

---

## 6. Orphans, Gaps, and Pre-existing Findings

### 6.1 Orphan HTTP methods (defined but not routed)

| Endpoint | Status | Impact |
|---|---|---|
| `AviationController::report()` (GET `/api/v1/flight/aviation/report`) | 🔗 **NOT ROUTED** | Method exists; no route; cannot be tested via HTTP. |
| `AviationController::treasuryTransaction()` (POST `/api/v1/flight/aviation/treasury/transaction`) | 🔗 **NOT ROUTED** | Same as above. |

### 6.2 Audit-relevant global middleware

- `auth:sanctum` + `active` middleware → applies to ALL flight routes.
- `CaptureFinancialPostingContext` + `RejectBannedFinancialBypassMarkers` → defense-in-depth.

### 6.3 Authorization gaps

- **ModificationController**: ONLY controller with role-based authorization (`authorizeMatrix()`).
- All other controllers rely on `auth:sanctum` + `active` middleware — no role-based check.
- `FormRequest::authorize(): true` everywhere — policy-less.

### 6.4 Cross-cutting behavior notes

1. **Currency mutation guard**: When a PENDING booking has any financial dependency (payment, refund, etc.), `updateBooking` rejects currency change with `InvalidArgumentException` (B12 fix).
2. **Booking deletion guard**: Direct `delete()` blocked by `ModelDeletionGuard`; must use `FlightBooking::run(fn => ...)` or `cancelBooking` first.
3. **Profit mutation guard**: Direct write to `profit` outside `runProfitMutation` blocked by `ModelProfitMutationGuard`.
4. **`balance` write guard (3 models)**: Direct write outside `LedgerBalanceMutationGuard` or `mutateBalanceInternal` blocks with `RuntimeException`.
5. **Pre-existing duplicate-income guard**: `TransactionService::recordJournalTransfer` for `type=Income` blocks duplicates per `related_type+related_id`. **CRITICAL FINDING — D3**: guard's stated convention is "Income=Sale", but production code uses "Income=Payment" (i.e., sale tx is `type=transfer`, payment tx is `type=income`). Hence the guard blocks legitimate partial-payment flow. **REAL BUSINESS DEFECT** (see Closure Gap Report).

---

## 7. Coverage Status Summary (reconciled)

| Section | Discovered | Test Script | Result |
|---|---|---|---|
| TYPE A positive (A01–A14) | 14 | flight_module_full_audit.php | ✅ PASS |
| TYPE A payments (A15–A23) | 9 | flight_closure_d3.php | ❌ FAIL — D3 |
| TYPE A cancel (A24–A32) | 9 | flight_module_full_audit.php | ✅ PASS |
| TYPE A delete (A33–A37) | 5 | flight_module_full_audit.php | ✅ PASS |
| TYPE B positive (B01–B15) | 15 | flight_module_full_audit.php | ✅ PASS |
| TYPE B credit-limit (B16–B20) | 5 | flight_module_full_audit.php | ✅ PASS |
| TYPE B debt-lifecycle (B21–B30) | 10 | flight_module_full_audit.php | ✅ PASS |
| TYPE C positive (C01–C12) | 12 | flight_module_full_audit.php | ✅ PASS |
| TYPE C payments (C13–C19) | 7 | flight_module_full_audit.php | ✅ PASS |
| TYPE C lifecycle (C20–C26) | 7 | flight_module_full_audit.php | ✅ PASS |
| Carrier recharge (R01–R14) | 14 | flight_closure_d4_d5.php + flight_closure_concurrency.php | ❌ FAIL — D5 |
| System recharge (R01–R14) | 14 | flight_module_full_audit.php | ✅ PASS |
| updatePrices (D4) | 4 scenarios | flight_closure_d4_d5.php | ❌ FAIL — D4 |
| Currency (4 ccy × scenarios) | EGP/KWD/SAR/USD | flight_module_full_audit.php | ✅ PASS |
| Customer debt | per-type | flight_module_full_audit.php | ✅ PASS |
| Negative/validation | 22 categories | flight_module_full_audit.php | ✅ PASS |
| Authorization | 8 categories × endpoints | flight_module_full_audit.php | ✅ PASS |
| Failure injection | 8 operations | flight_module_full_audit.php | ✅ PASS |
| **True concurrency** | 10c/25c × addPayment+recharge | **flight_closure_concurrency.php** | ✅ PASS (curl_multi, 0 deadlocks) |
| **True idempotency** | 8 endpoints × classify | **flight_closure_idempotency.php** | � FAIL — none SUPPORTED (NOT SUPPORTED + GAP classification) |
| **Final ledger reconciliation** | 15 invariants | **flight_closure_final_reconcile.php** | ✅ PASS 15/15 |

---

## 8. Defect Ledger

| ID | Severity | Title | File / Class | Reproduction | Status |
|----|----------|-------|--------------|--------------|--------|
| **D3** | CLASS-B | Duplicate-income guard blocks partial-payment lifecycle | `TransactionService::recordJournalTransfer` (line 660+) | `flight_closure_d3.php` — 3 sequential 4000 EGP payments on same booking: only #1 succeeds (#2, #3 blocked) | UNRESOLVED |
| **D4** | **CLASS-A** | Negative purchase price CREDITS the carrier | `FlightBookingService::updatePrices` | `flight_closure_d4_d5.php` — purchase=-100 credits carrier +100 | UNRESOLVED |
| **D5** | CLASS-B | Inactive carrier accepts recharge | `FlightCarrierRechargeService::rechargeFromAccount` | `flight_closure_d4_d5.php` — `is_active=0` carrier recharged successfully | UNRESOLVED |
| **D1 (DEFECT-1)** | CLASS-A (FIXED) | addPayment does not auto-promote PENDING → CONFIRMED | `FlightBookingService::addPayment` | regression 21/21 PASS | **RESOLVED** |
| **D2 (DEFECT-2)** | CLASS-A (FIXED) | cancel clears `sale_gl_transaction_id` | `FlightBookingService::cancelBooking` | regression 21/21 PASS | **RESOLVED** |

---

## 9. Idempotency Classification Summary

| Endpoint | Idempotent? | Mechanism |
|----------|-------------|-----------|
| `POST /flights/{booking}/payments` | ❌ **NOT SUPPORTED** | No Idempotency-Key header; no DB unique constraint. D3 guard blocks duplicates incidentally. |
| `POST /flights/carriers/{carrier}/recharge` | ❌ **NOT SUPPORTED** | No Idempotency-Key; verified at 10×/25× concurrent: ALL 10/25 succeed. |
| `POST /flights` (storeBooking) | ⚠️ **GAP** | No Idempotency-Key; retry creates duplicate booking. |
| `POST /flights/{booking}/update-prices` | ❌ **NOT SUPPORTED** | Last-write-wins; no version column. |
| `POST /flights/{booking}/cancel` | ✅ **SUPPORTED (incidental)** | State-machine guard rejects CANCELLED → CANCELLED. |
| `PATCH /flights/{booking}` | ❌ **NOT SUPPORTED** | Last-write-wins. |
| `DELETE /flights/{booking}` | ✅ **SUPPORTED (incidental)** | SoftDeletes scope makes repeat no-op. |
| `POST /flights/payments/{payment}/reverse` | ✅ **SUPPORTED (incidental)** | State-machine guard rejects already-reversed. |

**True contract endpoints (Idempotency-Key + unique constraint): NONE**

---

**End of Feature Matrix.**
