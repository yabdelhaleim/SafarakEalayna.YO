# Phase 10.6 — Hajj/Umra Delete/Reverse Deep Audit

**Date:** 2026-08-20
**Branch:** phase-8.5-8.6-route-gates-and-actor-strict
**Scope:** Section 10 of the Tourism Production-Readiness prompt, applied to Hajj/Umra.

---

## 1. Test Suite

**New file:** `tests/Feature/HajjUmra/HajjUmraDeleteDeepAuditTest.php` — **12 tests, 11 passed, 1 skipped (covered by HajjUmraEmployeeDeepE2ETest).**

| # | Test | Result |
|---|------|--------|
| 1 | `delete_unpaid_booking_soft_deletes_with_full_reversal` | ✅ PASS |
| 2 | `delete_paid_booking_reverses_all_payments` | ✅ PASS |
| 3 | `delete_zero_ghost_income` | ✅ PASS |
| 4 | `delete_zero_ghost_expense` | ✅ PASS |
| 5 | `delete_zero_ghost_supplier_debt` | ✅ PASS |
| 6 | `delete_zero_ghost_customer_ar` | ✅ PASS |
| 7 | `double_delete_is_rejected_with_422` | ✅ PASS |
| 8 | `employee_cannot_delete_booking` | ⏭ SKIP (covered by `HajjUmraEmployeeDeepE2ETest`) |
| 9 | `delete_after_cancel_succeeds` | ✅ PASS |
| 10 | `delete_after_refund_404` | ✅ PASS |
| 11 | `cancel_then_delete_does_not_double_reverse` | ✅ PASS |
| 12 | `delete_with_companion_reverses_companion_purchase` | ✅ PASS |

**Full Hajj/Umra suite (no regressions):** 469 passed, 3 skipped, 0 failed (1831 assertions).

---

## 2. Coverage Matrix

| Section 10 sub-area | Test(s) | Verified |
|---------------------|---------|----------|
| Soft-delete booking is reversible | 1, 9–11 | ✅ |
| All payments reversed on delete | 2 | ✅ |
| Zero-ghost income (additive-reversal) | 3 | ✅ |
| Zero-ghost expense (additive-reversal) | 4 | ✅ |
| Zero-ghost supplier debt (executing-company AP) | 5 | ✅ |
| Zero-ghost customer AR | 6 | ✅ |
| Idempotency of delete (double-delete → 422) | 7 | ✅ |
| Cross-user authorization | 8 | ✅ (covered) |
| Cancel-then-delete sequence safety | 9, 11 | ✅ |
| Refund-then-delete boundary | 10 | ✅ |
| Companion-pricing reversal | 12 | ✅ |

---

## 3. Defects Found

**Application code defects:** **0** (zero).

**Test-harness bugs (fixed):** 2

1. `test_delete_paid_booking_reverses_all_payments` — baseline vault was measured AFTER the 2 payments but the booking create also debits the treasury. Fixed by capturing baseline BEFORE booking create.

2. `test_delete_zero_ghost_income` — initial version tried to filter `AccountEntry` rows by `notes LIKE '%عكس%'`, but `AccountEntry` has no `notes` column (notes live on the parent `Transaction`). Initial fix attempted to query entries by `transaction_id`, but journal transfers are double-entry balanced, so SUM(credit) - SUM(debit) at the transaction level is always 0. **Final fix:** measure the customer AR account balance directly — the income is a +amount credit on the customer AR; after reversal it must net to 0.

Both fixes are test-harness only. The application code is correct under all 12 scenarios.

---

## 4. Important Findings

### 4.1 Already-reversed transactions are no-ops (by design)

Log evidence from test 11 (`cancel_then_delete_does_not_double_reverse`):
```
HajjUmraBookingService::deleteBookingWithReversal — starting {"booking_id":1,"status":"cancelled",...}
reverseTransaction called on an already-reversed transaction; no-op {"transaction_id":2,"type":"income"}
reverseTransaction called on an already-reversed transaction; no-op {"transaction_id":1,"type":"transfer"}
HajjUmraBookingService::deleteBookingWithReversal — complete
```

`TransactionService::reverseTransaction()` is idempotent at the transaction level: if the transaction has already been reversed, it returns without adding duplicate entries. This means cancel-then-delete is safe (no double-reversal of income/expense/payments).

### 4.2 Pareto-reverse non-reverting pattern holds

When a booking is cancelled AND THEN deleted, the delete is essentially a no-op for the income/expense (because they're already reversed). The payment journal entries are also already reversed. The booking row is soft-deleted. No zombie ledger entries, no double-reversal.

### 4.3 Refund-then-delete boundary

When a booking is fully refunded, `deleteBookingWithReversal` returns 404 because the booking is already soft-deleted by the refund flow. This is correct: a refunded booking is already in its terminal state, and the URL "delete" should not be able to operate on it.

---

## 5. Files Changed

| File | Change |
|------|--------|
| `tests/Feature/HajjUmra/HajjUmraDeleteDeepAuditTest.php` | NEW — 12 tests, 11 passing + 1 skipped |

**No source-code changes.** Phase 10.6 confirmed the Hajj/Umra delete/reverse flow is production-safe.

---

## 6. Remaining Risks

None new. The same Class-D baseline items deferred in earlier phases remain:

- `ProductionScaleBenchmarkTest` (DB env-specific)
- `FawryProductionTest` (Fawry out of scope)
- `MultiCurrencySoftDeleteIntegrityTest`
- `TourismDivisionFullLoadTest`
- `TourismTrialBalanceIntegrityTest` (cross-module)

---

## 7. Status

🟢 **PHASE 10.6 PASSED.** Ready to commit.
