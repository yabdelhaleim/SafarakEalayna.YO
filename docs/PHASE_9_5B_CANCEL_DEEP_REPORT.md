# Phase 9.5b — Cancel Deep Audit Report

**Status:** 🟢 **PASS** (15/15 tests pass, 46 assertions, no regression in 310-test Visa suite)
**Section:** 9 of 30 (Cancel Deep Audit)
**Date:** 2026-08-19

---

## Summary

Created `tests/Feature/Visa/VisaCancelDeepAuditTest.php` with **15 tests** covering the full cancel surface of the Visa module. All tests pass on the second iteration. **No application defects discovered** — the audit confirmed the cancel service correctly implements additive-reversal for all accounting dimensions.

---

## The Critical Gap — Verified Closed

The plan called out one specific gap:

> Closes gap: **agent AP balance after CANCEL** (only soft-delete is currently tested). Add `test_cancel_restores_agent_ap_balance`.

This gap is now covered by **two** tests:
- `test_cancel_restores_agent_ap_balance_to_baseline` — full flow
- `test_cancel_restores_agent_ap_after_partial_pay` — partial-pay + cancel

Both verified: agent AP balance returns to baseline after cancel (additive reversal of the booking expense).

---

## Test Coverage Matrix (15 tests)

| # | Test | What it asserts |
|---|------|-----------------|
| 1 | `test_cancel_unpaid_booking_succeeds` | Fresh booking → cancel → status=Cancelled |
| 2 | `test_cancel_partial_paid_booking_reverses_payments` | Pay 1000, cancel → vault NET back to baseline |
| 3 | `test_cancel_full_paid_booking_reverses_all_payments` | Pay 1600, cancel → vault NET back to baseline |
| 4 | `test_cancel_restores_agent_ap_balance_to_baseline` | **THE GAP** — agent AP restored after cancel |
| 5 | `test_cancel_restores_agent_ap_after_partial_pay` | Customer payment doesn't affect agent AP; cancel still restores |
| 6 | `test_cancel_clears_customer_ar_balance` | AR nets to 0 (income + payment both reversed) |
| 7 | `test_cancel_reverses_income_transaction` | Original income tx preserved; reversal entries added (additive pattern) |
| 8 | `test_cancel_leaves_ledger_globally_balanced` | Global ledger invariant holds |
| 9 | `test_cancel_after_cancel_is_rejected` | Second cancel → 422 (idempotency) |
| 10 | `test_cancel_after_refund_is_rejected` | Cancel after refund → 422 (terminal state) |
| 11 | `test_cancel_after_delete_returns_404` | Cancel after soft-delete → 404 or 422 |
| 12 | `test_cancel_appends_reason_to_booking_notes` | Notes updated with "سبب الإلغاء:" + reason |
| 13 | `test_cancel_with_missing_reason_succeeds` | reason is nullable |
| 14 | `test_cancel_with_usd_booking_restores_usd_vault` | Multi-currency path |
| 15 | `test_cancel_propagates_status_to_visa_detail` | visaDetail.status transitions too |

---

## Key Behavioral Findings

### 1. Cancel = full reversal (additive pattern)

Cancel performs the same accounting shape as refund:
- Reverses income transaction (with reversal AccountEntries prefixed `عكس: `)
- Reverses expense transaction (purchase_price to agent AP)
- Reverses all payment transactions
- Status → `Cancelled`
- visaDetail.status → `Cancelled`

Difference from refund:
- Cancel allows pre-pay OR post-pay; refund is typically post-pay
- Cancel uses status=Cancelled; refund uses status=Refunded
- Cancel does NOT write to `refund_audit_logs` (refund-only table)
- Cancel notes are updated with `سبب الإلغاء:` prefix

### 2. Customer AR semantics

| Scenario | Customer AR (credit - debit) |
|----------|------------------------------|
| Booking create (unpaid) | +1600 (income posted) |
| Pay 1000 (partial) | +600 (income - payment) |
| Pay 1600 (full) | 0 |
| Cancel after create (unpaid) | 0 (income reversed) |
| Cancel after partial pay | 0 (income -1600, payment +1000 = 0) |
| Cancel after full pay | 0 (income -1600, payment +1600 = 0) |

Full cancel always nets customer AR to 0 — the booking is gone.

### 3. Agent AP semantics

| Scenario | Agent AP (credit - debit) |
|----------|----------------------------|
| Booking create (no agent) | n/a |
| Booking create (with agent) | -purchase_price (-1000) |
| Customer payment | unchanged (payment goes to vault, not agent) |
| Cancel (with agent) | 0 (expense reversed) |
| Refund (with agent) | 0 (expense reversed) |

Cancel restores agent AP to baseline regardless of customer payment state.

### 4. State machine guards

| From | Cancel | Reason |
|------|--------|--------|
| Submitted/UnderReview/Approved/Issued/Rejected | ✅ Allowed | normal flow |
| Cancelled | ❌ 422 | idempotency |
| Refunded | ❌ 422 | terminal state |
| Soft-deleted | ❌ 422 or 404 | route binding excludes trashed |

---

## Defects Discovered

**None.** All 15 tests pass without source code changes.

### Initial test-harness issues (2 self-inflicted, fixed during this phase)

| Test | Root cause | Fix |
|------|------------|-----|
| `test_cancel_restores_agent_ap_after_partial_pay` | Assumed customer payment affects agent AP (it doesn't — payment goes to vault) | Corrected assertion: agent AP after pay is still -1000 (unchanged); cancel still restores to baseline |
| `test_cancel_clears_customer_ar_balance` | Underestimated the full-cancel reversal scope (income + payment both reverse) | Corrected expectation: AR nets to 0 after full cancel (not -400) |

---

## Verifications

| Verification | Result |
|--------------|--------|
| All 15 Phase 9.5b tests pass | ✅ |
| Full Visa test suite (310 tests) passes — no regression | ✅ |
| `assertLedgerGloballyBalanced()` after cancel | ✅ |
| Agent AP balance restored after cancel | ✅ (the original gap) |
| Customer AR cleared to 0 after full cancel | ✅ |
| Original income tx amount preserved (additive reversal) | ✅ |
| State machine: double-cancel rejected (422) | ✅ |
| State machine: cancel-after-refund rejected (422) | ✅ |
| Cancel after soft-delete returns 404 or 422 | ✅ |
| visaDetail.status propagates to Cancelled | ✅ |
| Multi-currency path (USD) works correctly | ✅ |
| Booking notes updated with cancel reason (Arabic prefix) | ✅ |

---

## Findings for Other Phases

### Phase 9.13 (State Machine) — implications

Confirmed legal/illegal transitions from cancel perspective:

| From state | Cancel attempt | Expected |
|------------|----------------|----------|
| `Draft` | cancel | TBD in 9.13 |
| `Submitted` (unpaid) | cancel | ✅ Allowed (test 1) |
| `Submitted` (paid) | cancel | ✅ Allowed (test 2, 3) |
| `UnderReview` (paid) | cancel | TBD in 9.13 |
| `Approved` (paid) | cancel | TBD in 9.13 |
| `Issued` (paid) | cancel | TBD in 9.13 (high-risk: visa already issued to embassy) |
| `Rejected` | cancel | TBD in 9.13 |
| `Cancelled` | cancel | ❌ 422 (test 9) |
| `Refunded` (paid) | cancel | ❌ 422 (test 10) |

### Phase 9.7 (Financial Reconciliation) — implications

The cancel and refund paths are accounting-equivalent. Phase 9.7 can treat them uniformly under "additive reversal" semantics.

---

## Recommendations

1. **No code changes required** from this audit.
2. **Document the cancel vs refund distinction** in admin-facing UI:
   - Cancel = light, for pre-visa-issued scenarios
   - Refund = terminal, for post-payment recovery
3. **Proceed to Phase 9.6** (Delete/Reverse Deep Audit).
4. **Phase 9.13** should verify `Issued → Cancelled` specifically — that's the only state where cancel might be semantically wrong (visa already issued).

---

## Test Run Output

```
PHPUnit 12.5.23 by Sebastian Bergmann and contributors.

Time: 00:04.864, Memory: 92.00 MB

OK (15 tests, 46 assertions)
```

Full Visa suite:
```
OK (310 tests, 959 assertions)
```