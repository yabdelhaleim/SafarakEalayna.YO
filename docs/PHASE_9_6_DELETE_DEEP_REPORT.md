# Phase 9.6 — Delete/Reverse Deep Audit Report

**Status:** 🟢 **PASS** (15/15 tests pass, 47 assertions, no regression in 325-test Visa suite)
**Section:** 10 of 30 (Delete/Reverse Deep Audit)
**Date:** 2026-08-19

---

## Summary

Created `tests/Feature/Visa/VisaDeleteDeepAuditTest.php` with **15 tests** covering the full delete/reverse surface of the Visa module. All tests pass on the first iteration. **No application defects discovered** — the audit confirmed the delete service correctly implements the zero-ghost invariants.

---

## Critical Zero-Ghost Invariants — All Verified

The plan called out the gap:

> Asserts: zero ghost income, zero ghost expense, zero ghost payment, zero ghost ledger entry, zero ghost supplier debt.

All 5 invariants are now covered:

| Invariant | Test | Result |
|-----------|------|--------|
| Zero ghost income | `test_delete_leaves_zero_ghost_income_transactions` | ✅ |
| Zero ghost expense | `test_delete_leaves_zero_ghost_expense_transactions` | ✅ |
| Zero ghost payments | `test_delete_leaves_zero_ghost_payments` | ✅ (HARD-deleted) |
| Zero ghost ledger entries | `test_delete_leaves_zero_ghost_ledger_entries_globally` | ✅ |
| Zero ghost supplier debt | `test_delete_leaves_zero_ghost_supplier_debt` | ✅ (agent AP restored) |

---

## Test Coverage Matrix (15 tests)

| # | Test | What it asserts |
|---|------|-----------------|
| 1 | `test_delete_unpaid_booking_soft_deletes_with_reversal` | Fresh booking → DELETE → trashed, `deleted_at` set |
| 2 | `test_delete_partial_paid_booking_reverses_payments` | Pay 1000 + delete → vault NET back to baseline |
| 3 | `test_delete_full_paid_booking_reverses_all_payments` | Pay 1600 + delete → vault NET back to baseline |
| 4 | `test_delete_leaves_zero_ghost_income_transactions` | Original income tx preserved; reversal entries added; income-clearing NET=0 |
| 5 | `test_delete_leaves_zero_ghost_expense_transactions` | Original expense tx preserved; reversal entries added |
| 6 | `test_delete_leaves_zero_ghost_payments` | visa_payments rows HARD-deleted; reversal AccountEntries preserved |
| 7 | `test_delete_leaves_zero_ghost_supplier_debt` | Agent AP returns to baseline |
| 8 | `test_delete_leaves_zero_ghost_ledger_entries_globally` | SUM(credit) = SUM(debit) globally after delete |
| 9 | `test_double_delete_is_rejected` | Second delete on trashed → 404 or 422 |
| 10 | `test_delete_after_cancel_is_rejected` | Cancel first, then delete → service rejects |
| 11 | `test_delete_after_refund_is_rejected` | Refund first, then delete → service rejects |
| 12 | `test_delete_with_multi_method_payment_reverses_all_methods` | Cash + bank_transfer → both vault and bank NET back to baseline |
| 13 | `test_delete_with_usd_booking_restores_usd_vault` | USD booking + USD payment + delete → USD vault NET back |
| 14 | `test_delete_propagates_status_to_visa_detail` | visaDetail.status → Cancelled |
| 15 | `test_delete_preserves_audit_trail_via_additive_reversal` | AccountEntry count GROWS after delete (additive, not destructive) |

---

## Key Behavioral Findings

### 1. Delete = additive reversal + soft-delete (not destructive)

| Aspect | Cancel | Delete |
|--------|--------|--------|
| Reverses payments | ✅ Additive | ✅ Additive |
| Reverses income/expense | ✅ Additive | ✅ Additive |
| Status change | → Cancelled | (unchanged) |
| visaDetail.status | → Cancelled | → Cancelled |
| Booking visibility | Visible (status=Cancelled) | Hidden (`deleted_at` set) |
| Payment rows | Preserved | **HARD-deleted** |
| Actor required | No (controller uses auth) | **Yes** (Phase 8.6 B2) |
| `refund_audit_logs` row | No | No (cancel only) |

### 2. Hard-delete of visa_payments is intentional

`$booking->payments()->delete()` (not `forceDelete()` on soft-deletes) hard-removes the payment rows. This is fine because:
- The reversal `AccountEntry` rows preserve the full audit trail
- The original `Transaction` rows are also preserved (additive reversal)
- Re-querying the deleted booking's payments returns nothing (as expected)

### 3. Phase 8.6 B2 actor enforcement

`VisaRefundService::deleteWithReversal()` requires an authenticated `User` actor (line 305-312). Without one, it throws `RuntimeException`. This is the same pattern as `HajjUmraBookingService::deleteBookingWithReversal()` (Phase 8.6 B1) and `VisaRefundService::refund()`.

### 4. State machine protection against phantom reversals

| From | Delete attempt | Behavior |
|------|----------------|----------|
| Active (Submitted/Approved/etc.) | ✅ Allowed | normal flow |
| Cancelled | ❌ Rejected | would cause phantom reversal |
| Refunded | ❌ Rejected | would cause phantom reversal |
| Already trashed | ❌ Rejected (422/404) | idempotency |

The service guards against `Cancelled` and `Refunded` states because deleting from those would reverse already-reversed transactions (phantom reversals).

---

## Defects Discovered

**None.** All 15 tests pass without source code changes.

---

## Verifications

| Verification | Result |
|--------------|--------|
| All 15 Phase 9.6 tests pass | ✅ |
| Full Visa test suite (325 tests) passes — no regression | ✅ |
| `assertLedgerGloballyBalanced()` after delete | ✅ |
| Agent AP balance restored after delete (zero-ghost supplier debt) | ✅ |
| Customer AR cleared to 0 after full pay + delete | ✅ |
| Original income + expense tx preserved (additive pattern) | ✅ |
| visa_payments HARD-deleted (zero-ghost rows) | ✅ |
| AccountEntries GROWN after delete (additive audit trail) | ✅ |
| State machine: double-delete, delete-after-cancel, delete-after-refund rejected | ✅ |
| Multi-method payment (cash + bank_transfer) all reversed | ✅ |
| Multi-currency (USD) path works correctly | ✅ |
| visaDetail.status → Cancelled on delete | ✅ |

---

## Findings for Other Phases

### Phase 9.7 (Financial Reconciliation) — implications

Delete and cancel share the same accounting shape. Phase 9.7 can treat them uniformly:
- Both use additive reversal
- Both restore customer AR, vault, agent AP to baseline
- Both preserve Transaction history

### Phase 9.9 (TRUE HTTP Concurrency) — implications

The original plan called for `tests/scripts/stress_visa_delete.php` to test concurrent delete attempts. Now confirmed:
- The service uses `lockForUpdate()` on the booking (line 319 of VisaRefundService.php)
- TOCTOU guards re-check status inside the lock (line 187-193 of refund; delete has similar pattern)
- The Phase 9.9 stress script can verify race conditions are properly handled

### Phase 9.13 (State Machine Matrix) — implications

Confirmed illegal delete transitions:

| From state | Delete attempt | Expected |
|------------|----------------|----------|
| `Submitted` (unpaid) | delete | ✅ Allowed |
| `Submitted` (paid) | delete | ✅ Allowed |
| `Approved`/`Issued` (paid) | delete | ✅ Allowed (per current service) |
| `Cancelled` | delete | ❌ Rejected (test 10) |
| `Refunded` | delete | ❌ Rejected (test 11) |
| Already trashed | delete | ❌ Rejected (test 9) |

---

## Recommendations

1. **No code changes required** from this audit.
2. **Document the cancel vs delete distinction** in admin-facing UI:
   - Cancel = visible Cancelled status, payments preserved
   - Delete = hidden (`deleted_at`), payments hard-deleted, audit trail preserved
3. **Phase 9.9 stress script** can now be built on a verified-safe foundation.
4. **Proceed to Phase 9.7** (Financial Accounting + Ledger Reconciliation).

---

## Test Run Output

```
PHPUnit 12.5.23 by Sebastian Bergmann and contributors.

Time: 00:04.886, Memory: 92.00 MB

OK (15 tests, 47 assertions)
```

Full Visa suite:
```
OK (325 tests, 1006 assertions)
```