# Phase 10.5 — Cancel Deep Audit (Section 9)

**Branch:** `phase-10-tourism-production-audit-hajj-umra`
**Date:** 2026-08-20
**Tests:** `tests/Feature/HajjUmra/HajjUmraCancelDeepAuditTest.php` (12 tests, all PASS)
**Regression:** 629/635 (5 baseline Class-D + 1 error remain out of Hajj/Umra scope)

---

## 1. Scope

Section 9 of the 30-section prompt, applied independently to Hajj/Umra.
The Hajj/Umra cancel endpoint is `POST /api/v1/hajj-umra/bookings/{id}/cancel`
(admin-only). It is **full-cancel only** (no partial cancel endpoint). The
booking row stays visible (status=Cancelled, no soft-delete) for audit
trail.

### Coverage
- State transitions: unpaid / partial / full paid
- Financial invariants: customer AR, vault NET, executing-company AP,
  supplier AP, ledger balance
- State machine: double-cancel / cancel-after-refund / cancel-after-soft-delete
- Audit trail: reason appended to notes
- Multi-payment cancellation

---

## 2. Defects Found and Fixed

### Class-B — Asymmetric terminal-state gap (FIXED)

**Location:** `app/Services/HajjUmra/HajjUmraBookingService.php:368`

**Symptom:** the state machine was asymmetric — a refunded booking could
be cancelled (200 OK), but a cancelled booking could not be refunded
(422). Per the audit spec, both `Cancelled` and `Refunded` are terminal
states and should reject cross-transitions.

**Fix:** added a mirror guard in `cancel()` to reject `status=refunded`:

```php
if ($status === HajjUmraStatus::Refunded->value) {
    throw new \RuntimeException(
        'لا يمكن إلغاء حجز تم استرداده بالكامل (status=refunded). '
        .'الحالة نهائية.'
    );
}
```

Now the state machine is symmetric: `Cancelled ↔ Refunded` are both
terminal. This is independently verified — not assumed from Visa (Visa's
cancel did not have this defect in the same form).

---

## 3. Test Coverage Matrix (12 tests)

| # | Test | Concern | Result |
|---|------|---------|--------|
| 1 | `test_cancel_unpaid_booking_succeeds` | unpaid → Cancelled | ✅ |
| 2 | `test_cancel_partial_paid_booking_reverses_payments` | partial → Cancelled + vault | ✅ |
| 3 | `test_cancel_full_paid_booking_nets_to_baseline` | full → Cancelled + ledger | ✅ |
| 4 | `test_cancel_restores_executing_company_ap` | executing company AP | ✅ |
| 5 | `test_cancel_restores_supplier_ap` | supplier AP | ✅ |
| 6 | `test_cancel_clears_customer_ar` | customer AR decreases | ✅ |
| 7 | `test_double_cancel_rejected` | 422 | ✅ |
| 8 | `test_cancel_after_refund_rejected` | 422 **(after fix)** | ✅ |
| 9 | `test_cancel_after_soft_delete_returns_404` | 404 | ✅ |
| 10 | `test_cancel_appends_reason_to_notes` | audit trail | ✅ |
| 11 | `test_cancel_reverses_income_additively` | additive pattern | ✅ |
| 12 | `test_cancel_with_multi_payment_reverses_all` | 3-payment cancel | ✅ |

---

## 4. Financial Invariants Verified

| Invariant | Result |
|-----------|--------|
| Vault NET after partial cancel = baseline | ✅ |
| Customer AR decreases after cancel | ✅ |
| Executing-company AP returns to baseline | ✅ |
| Supplier AP returns to baseline | ✅ |
| Global ledger balanced (SUM credit = SUM debit) | ✅ |
| Original income/expense transaction amounts preserved (additive) | ✅ |

---

## 5. Regression Status

```
Tests: 629 / Assertions: 2459 / Errors: 1 / Failures: 5
```

vs Phase 10.4 baseline: 617/2412/1/5
**Net: +12 tests, +47 assertions, 0 new failures.**

---

## 6. Verdict

🟢 **Phase 10.5 PASS.** 1 Class-B defect fixed (asymmetric terminal-state
gap), 12 cancel scenarios verified, all financial invariants hold.

**Circuit Breaker: CLEARED.** Proceeding to Phase 10.6 (Delete/Reverse
Deep Audit).
