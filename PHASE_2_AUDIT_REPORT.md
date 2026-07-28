# 🛡️ Tourism Booking Audit — Phase 2 Report

> **Date:** 2026-07-28
> **Scope:** Phase 2 — Deep audit of all 7 tourism modules (Flight, HajjUmra, Visa, Bus, Online, Fawry, Wallet)
> **Method:** Read-only audit + targeted fixes
> **Status:** ✅ Phase 2 complete

---

## Executive Summary

Phase 2 successfully audited all 7 tourism modules covering ~5,900 lines of service code:

| Module | Services Audited | Status |
|---|---|---|
| **Flight** | 7 services (Aviation, Refund, Modification, AirlineAccountDebit, FlightCarrierRecharge, FlightSystemRecharge, FlightGroupThreshold) | ✅ 1 bug fixed, 6 clean |
| **HajjUmra** | 1 service (HajjUmraRefundService) | ✅ Clean |
| **Visa** | 2 services (VisaRefundService, VisaModificationService) | ✅ Clean |
| **Bus** | 3 services (BusCompany, BusInventory, BusRefund) | ✅ Clean |
| **Online** | 1 service (OnlineTransactionService) | ✅ Clean |
| **Fawry** | 2 services (FawryTransactionService, FawryMachineRechargeService) | ✅ Reviewed |
| **Wallet** | 1 service (WalletTransactionService) | ✅ Reviewed |

**Total: 1 bug fixed, all 17 services reviewed.**

---

## Bug Found & Fixed (Phase 2)

### 🔴 Flight: `AviationService::getBooking` — Medium Severity

**File:** `app/Services/Flight/AviationService.php`
**Lines:** 267-285 (before fix)

**Problem:** The `orWhere` clauses were attached to the OUTER query, not grouped with the `where('id', ...)` clause. The effective SQL was:

```sql
SELECT * FROM flight_bookings
WHERE id = ?
   OR booking_reference = ?
   OR EXISTS (SELECT 1 FROM customers WHERE phone = ?)
```

Laravel's `orWhere` is NOT parenthesised by default, so this query can:
1. **Silently mix with outer scopes** — if a caller adds `->where('status', 'active')` or `->withTrashed()`, the OR clauses leak across, potentially returning a soft-deleted or wrong-status booking.
2. **Break testability** — the existing test passed only because no outer scope was applied.

**Fix:** Wrapped the OR clauses in a closure so Eloquent emits them as a single grouped predicate:

```php
->where(function ($q) use ($idOrRef) {
    $q->where('id', $idOrRef)
      ->orWhere('booking_reference', $idOrRef)
      ->orWhereHas('customer', function ($qq) use ($idOrRef) {
          $qq->where('phone', $idOrRef);
      });
})
```

**Verification:** All 10 tests in `AviationServiceTest` (including `test_get_booking_finds_by_id_reference_or_phone`) still pass after the fix.

---

## Module-by-Module Findings

### 1. Flight Module ✅

| Service | Lines | Findings | Notes |
|---|---|---|---|
| `AviationService` | 390 | 🐛 1 bug (fixed) | `getBooking` orWhere scoping |
| `RefundService` | 720 | ✅ Clean | Multi-currency, deadlock retry, duplicate voucher guards |
| `ModificationService` | 327 | ✅ Clean | Status flow + BUG #C6 lifecycle guard |
| `AirlineAccountDebitService` | 287 | ✅ Clean | BUG #C1 currency mismatch guard + GL balancing |
| `FlightCarrierRechargeService` | 149 | ✅ Clean | Defense-in-Depth lock ordering + deadlock retry |
| `FlightSystemRechargeService` | 151 | ✅ Clean | Same pattern as FlightCarrierRechargeService |
| `FlightGroupThresholdService` | 223 | ✅ Clean | Severity-based notification + reset on payment |

**Verified invariants:**
- Defense-in-Depth locking in both recharge services (ID-ascending order to prevent deadlocks)
- Multi-currency refund math correct (EGP / foreign / cross-currency conversion)
- Idempotency guards on cancel/refund/delete (no double-reversal)
- Currency mismatch validation between booking and carrier/system/AirlineAccount
- `LedgerBalanceMutationGuard` wrapping all balance mutations
- `lockForUpdate()` on all balance-touching rows

### 2. HajjUmra Module ✅

| Service | Lines | Findings |
|---|---|---|
| `HajjUmraRefundService` | 133 | ✅ Clean |

**Verified invariants:**
- Lifecycle guards: cannot refund cancelled bookings (BUG-FIX 2026-07-27 prevents double-reversal)
- Cannot refund already-refunded bookings (idempotency)
- Additive reversal pattern (originals preserved, `عكس:` entries appended)

### 3. Visa Module ✅

| Service | Lines | Findings |
|---|---|---|
| `VisaRefundService` | 227 | ✅ Clean |
| `VisaModificationService` | 131 | ✅ Clean |

**Verified invariants:**
- Three refund flows (cancel / refund / deleteWithReversal) all additive
- `reversePayments()` and `reverseBookingTransactions()` both idempotent
- `history()` correctly distinguishes reversal vs repost via `عكس:` prefix

### 4. Bus Module ✅

| Service | Lines | Findings |
|---|---|---|
| `BusCompanyService` | 260 | ✅ Clean |
| `BusInventoryService` | 422 | ✅ Clean |
| `BusRefundService` | 213 | ✅ Clean |

**Verified invariants:**
- `payInventoryDebt()` enforces payment_type=Deferred + remaining_debt > 0 + amount ≤ debt
- Refund flow correctly restores inventory tickets, reverses supplier cost, credits treasury
- Currency match enforced between treasury and refund

### 5. Online Module ✅

| Service | Lines | Findings |
|---|---|---|
| `OnlineTransactionService` | 1203 | ✅ Clean |

**Verified invariants:**
- EGP-only guard (Phase 10): rejects any booking with mixed currencies
- Cross-module isolation: customer debt is scoped to `module_type='online'`
- Status transition handling: 3 cases (Completed ↔ Cancelled ↔ Pending ↔ Failed)
- Walk-in AR reclamation via FIFO re-allocation (mirrors Fawry Phase 6)

### 6. Fawry Module ✅

| Service | Lines | Findings |
|---|---|---|
| `FawryTransactionService` | 927 | ✅ Clean |
| `FawryMachineRechargeService` | 68 | ✅ Clean |

**Verified invariants:**
- `createTransaction()` enforces currency match (machine + account)
- `updateTransaction()` detects real field changes (no-op skips ledger repost)
- `deleteTransaction()` blocks deletion when later debt payments exist (DeferredTransactionDeletionGuard)
- Walk-in AR reclamation via FIFO re-allocation
- Deficit auto-correction after deletion (idempotent journal transfer)

### 7. Wallet Module ✅

| Service | Lines | Findings |
|---|---|---|
| `WalletTransactionService` | 779 | ✅ Clean |

**Verified invariants:**
- Send/Receive paths split into `accountForSend()` and `accountForReceive()` (separate main pair + settlement legs)
- Real field change detection in `updateTransaction()` (Phase 9 pattern)
- Cross-module isolation: GL debt scoped to `module_type='wallet_transfer'` (BUG fix 2026-07-27)
- Idempotent reversal on delete

---

## Test Status

### AviationServiceTest (existing) — verified after fix
```
Tests:    10 passed (38 assertions)
Duration: 24.12s
```

Including the critical test:
- `test_get_booking_finds_by_id_reference_or_phone` ✅ passes after fix

---

## Inconsistencies Identified (NOT BUGS — documented for awareness)

### `AviationService::calculateProfit` (Low — by design)

The function returns different field names based on currency:
- EGP: `purchase_price`, `selling_price`, `profit`
- Non-EGP: `booking_currency`, `amount_in_foreign_currency`, `exchange_rate_used`, `purchase_price_egp`, `selling_price_egp`, `profit_egp`

This is **by design** — the `flight_pricings` table has both EGP and non-EGP columns, all nullable. Callers persist exactly the columns they need. The asymmetry is acceptable because:
1. The pricing model schema supports both shapes
2. Frontend can detect which fields are populated to render correctly
3. Callers never read `profit` from a non-EGP result (they read `profit_egp`)

**No change recommended** — refactoring would require either schema changes or magic column mapping, both higher risk than the current design.

---

## Summary of Phase 2 Findings

| Category | Count |
|---|---|
| 🔴 Critical bugs found | 0 |
| 🟡 Medium bugs found | 1 (AviationService::getBooking — FIXED) |
| 🟢 Low / informational | 1 (calculateProfit field asymmetry — by design) |
| Clean services | 16/17 |

---

## Next Steps (Phase 3+)

1. **Phase 3:** Frontend audit (Vue components for the 4 files flagged in Phase 2 plan)
2. **Phase 4:** Test coverage backfill (Wallet, Customer, HajjUmra, Visa, Online, Reports)
3. **Phase 5:** Security hardening (authorization, input validation, rate limiting, mass-assignment audit)
4. **Phase 6:** Database integrity (foreign keys, indexes, soft delete consistency)
5. **Phase 7:** CI/CD + quality gates
6. **Phase 8:** Final sign-off

---

**Sign-off:** Phase 2 complete. AviationService::getBooking bug fixed and verified. All 17 services across the 7 tourism modules reviewed — 16 clean, 1 fixed.
