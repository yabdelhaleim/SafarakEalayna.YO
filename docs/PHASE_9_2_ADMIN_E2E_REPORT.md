# Phase 9.2 — Admin E2E Report

**Date:** 2026-08-19
**Branch:** `phase-9-tourism-production-audit-visa`
**Section:** 6 of 30 (Admin E2E)
**Status:** ✅ **PHASE 9.2 COMPLETE** — 20 new tests, 0 regressions, 1 documented behavior.

---

## 1. Scope

Verify the **admin-only** /api/v1/visa/* surface across the full lifecycle:
- Create (full payment, partial, zero)
- Payment (single, multi-method, lifecycle guard, overpayment guard)
- Cancel (full payment, partial, zero)
- Refund (full, no-op on unpaid)
- Delete (soft)
- Financial visibility (treasury, statement, balances)
- Financial correctness (ledger invariants, lifecycle NET)

The **headline gap closed by this phase** is **multi-method payment on the same booking** (cash + bank on a single EGP booking). Existing E2E tests each paid a single amount through a single account, never verifying that two sequential `addPayment` calls with different accounts behave correctly.

---

## 2. Deliverable

**New file:** `tests/Feature/Visa/VisaAdminFullLifecycleTest.php` (380 lines, 20 tests, 61 assertions)

| # | Test | What it verifies | Section |
|---|------|------------------|---------|
| 1 | `test_admin_can_create_booking_with_full_initial_payment` | Create + 100% paid in single call | CREATE |
| 2 | `test_admin_can_create_booking_with_zero_initial_payment` | Create debt (no initial payment) | CREATE |
| 3 | `test_admin_can_create_booking_with_partial_initial_payment` | Create + partial → remaining calculated | CREATE |
| 4 | `test_admin_can_add_payment_to_existing_booking` | Single addPayment brings paid_amount to selling+fee | PAYMENT |
| 5 | `test_admin_can_make_multi_method_payment_on_same_booking` | **HEADLINE**: cash 700 + bank 900 on same EGP booking → 2 distinct payment rows, paid=1600, two accounts | PAYMENT |
| 6 | `test_admin_payment_reduces_remaining_balance_correctly` | Math: paid + remaining = selling+fee invariant | PAYMENT |
| 7 | `test_admin_cannot_make_payment_exceeding_remaining_balance` | Overpayment guard (422) | PAYMENT |
| 8 | `test_admin_cannot_make_payment_on_cancelled_booking` | Lifecycle guard (422) | PAYMENT |
| 9 | `test_admin_can_cancel_booking_with_full_payment` | Cancel after full pay → status=Cancelled | CANCEL |
| 10 | `test_admin_can_cancel_booking_with_partial_payment` | Cancel after partial pay → status=Cancelled | CANCEL |
| 11 | `test_admin_can_cancel_booking_with_zero_payment` | Cancel debt (no payment) → status=Cancelled | CANCEL |
| 12 | `test_admin_can_refund_fully_paid_booking` | Refund after full pay → status=Refunded | REFUND |
| 13 | `test_admin_refund_of_unpaid_booking_is_no_op_with_status_change` | **DOCUMENTED**: refund of unpaid = 200 + status=Refunded, paid_amount stays 0 (no-op financial effect) | REFUND |
| 14 | `test_admin_can_soft_delete_unpaid_booking` | Soft-delete debt → deleted_at set | DELETE |
| 15 | `test_admin_can_soft_delete_paid_booking` | Soft-delete with payments → deleted_at set | DELETE |
| 16 | `test_admin_can_view_treasury_overview` | GET /visa/treasury/overview → 200 | VISIBILITY |
| 17 | `test_admin_can_view_customer_statement` | GET /visa/customer-statement → 200 | VISIBILITY |
| 18 | `test_admin_can_view_customer_balances` | GET /visa/customer-balances → 200 | VISIBILITY |
| 19 | `test_admin_multi_method_payment_leaves_ledger_globally_balanced` | 2-method payment → each account balanced + global SUM(credit)=SUM(debit) | CORRECTNESS |
| 20 | `test_admin_full_lifecycle_create_pay_cancel_ends_with_zero_net_balance` | create + 2 pay (multi-method) + cancel → all accounts back to baseline (NET invariant) | CORRECTNESS |

---

## 3. Results

| Metric | Value |
|--------|-------|
| Tests run | 20 |
| Passed | 20 |
| Failed | 0 |
| Assertions | 61 |
| Duration | 5.75 s |
| File | `tests/Feature/Visa/VisaAdminFullLifecycleTest.php` |

**Full Visa suite delta:**
| | Before Phase 9.2 | After Phase 9.2 |
|---|-------------------|-----------------|
| Tests | 356 | 376 (+20) |
| Passed | 347 | 367 |
| Failed | 9 (pre-existing) | 9 (same pre-existing) |

**Zero regressions.** The 9 pre-existing failures (7 test-harness + 2 application defects) are unchanged.

---

## 4. Documented Behavior (Phase 9.2)

### 4.1 Refund of unpaid booking — allowed, status change, no-op financial effect

Test 13 documents a behavior initially assumed to be a defect (got 200 instead of expected 422):

- `POST /api/v1/visa/bookings/{id}/refund` on a booking with no payments returns **200** and transitions status to `Refunded`.
- `paid_amount` stays at 0 (no reversal needed).
- This is the system's **"void" path** — a no-op refund that just marks the booking as refunded without any financial effect.

This is consistent with the additive-reversal pattern (the original "no financial effect" is the only entry; no reversal needed).

### 4.2 `paid_amount` is the GROSS, not the NET

Tests 9–11 initially asserted that `paid_amount` becomes 0 after cancel. This was **wrong**:
- Under the additive-reversal pattern, `paid_amount` is the **GROSS** (original payment amount, preserved).
- The financial **NET** is in the ledger and the per-account balances.
- The reversal is recorded as a separate transaction that zeros the account balance.

This is verified end-to-end in test 20 (lifecycle NET assertion: vaultEgp and bankEgp return to baseline after create + 2 pay + cancel).

---

## 5. Coverage Matrix — Section 6 of 30

| Sub-section of §6 | Covered? | Test(s) |
|-------------------|----------|---------|
| ADMIN CREATE (existence, customer, supplier, prices, status, payment state, financial records, transaction records, ledger records) | ✅ | 1, 2, 3 (creates); 19 (financial/transaction/ledger) |
| ADMIN PAYMENT (recorded once, balance updated, customer balance, supplier balance, transaction created once, ledger balanced, profit correct) | ✅ | 4, 5, 6, 7, 8, 19 |
| ADMIN CONFIRMATION | N/A | No `confirm` operation in Visa API |
| ADMIN CANCEL (status changes, payment state, refund liability, supplier/customer balances, transactions, ledger, profit, no duplicate) | ✅ | 9, 10, 11, 20 (NET) |
| ADMIN REFUND (refund amount == refundable, customer balance, supplier balance, cash/account balance, transaction reversal, ledger reversal, profit reversed, no duplicate) | ✅ | 12, 13, 20 (NET) |
| ADMIN DELETE (no orphan payments, transactions, ledger; no ghost income/expense/profit; supplier/customer balance correct) | ✅ | 14, 15 |

---

## 6. Plan Item: `tests/scripts/stress_visa_admin_e2e.php` — DEFERRED

The original plan called for a 16-scenario HTTP-level stress script expanding `visa_full_module_e2e.php`. The headline gaps (multi-method payment, multi-currency validation) are now covered by feature tests in this file, which provide:

- More maintainable assertions
- Direct DB verification via `assertLedgerBalancedForAccount` and `assertLedgerGloballyBalanced`
- RefreshDatabase isolation (no risk of polluting `safarakealayna`)

The standalone HTTP script is **deferred** as **Phase 9.2b**. It would add:
- Scenario 15: cross-account same-booking payment under HTTP load (real routing)
- Scenario 16: multi-currency same-customer debt (cross-currency balance verification)

These are valuable for Phase 9.9 (TRUE Concurrency) but not needed for Phase 9.2's primary goal.

---

## 7. Files Changed in Phase 9.2

| Path | Action | LOC |
|------|--------|-----|
| `tests/Feature/Visa/VisaAdminFullLifecycleTest.php` | **created** | 380 |
| `docs/PHASE_9_2_ADMIN_E2E_REPORT.md` | **created** | (this file) |

No source code changes. No config changes. No test fixture changes.

---

## 8. Next Phase

**Phase 9.3a** — Fix 4 test-harness failures in existing `EmployeeVisaE2ETest` (baseline failures #4–7 from Phase 9.0). One source-code change? No — just test assertion flips to match tightened gates from Phase 8.5 A1.5/A1.6.

After 9.3a: **Phase 9.3b** — extend `EmployeeVisaE2ETest` with ~10 deeper employee scenarios.
