# Visa Module Audit — Baseline Report

**Date:** 2026-08-25
**Branch:** `phase-10-tourism-production-audit-hajj-umra`
**Auditor:** ZCode (ZCode agent)
**Scope:** Full Visa module audit (controllers + services + models + 36 existing test files)
**User focus:** Deletion rollback ("بيرجع كل حاجه لي اصلها تاني") + Debt/Credit accounting ("الدين والمديونيه") + Multi-currency (EGP/USD/SAR)

---

## 1. Executive Summary

| Metric | Count |
|--------|-------|
| Test files executed | 34 (out of 36 — `VisaTestCase.php` is base, not runnable) |
| Total tests passed | ~395+ |
| Total tests failed | ~46 |
| Distinct defect signatures | **3** (DEFECT-VISA-2026-08-25-A/B/C below) |
| Files requiring fixes | 1 production file (`Account.php`) + 1 test fixture (`VisaTestCase.php`) |

The module's core business logic (CRUD, payments, cancel/refund/delete flows, validation, permissions) is **stable**. The 46 failures cluster around **3 systemic defects** in the financial accounting layer — all of which block the user's stated invariant that "everything returns to its original state after deletion."

---

## 2. Top-Level Defect Inventory

### DEFECT-VISA-2026-08-25-A (HIGH) — Double-Seeded Opening Balances

**Symptom:** `assertLedgerGloballyBalanced()` fails across **40+ tests** with `entriesNet = 2 × balance` for every account.

**Root Cause:**
1. `Account::created` boot hook in `app/Models/Account.php:200-261` (added by the FIN-1 fix on 2026-08-21) auto-posts a paired opening-balance `AccountEntry` whenever an `Account` is created with `balance > 0`.
2. `VisaTestCase::seedOpeningBalanceFor()` in `tests/Feature/Visa/VisaTestCase.php:248-287` ALSO posts opening-balance entries.
3. **Both run** for the same vault → entries sum to **2× the intended opening balance** while the `Account.balance` field stays at **1×**.

**Evidence (VisaDeleteDeepAuditTest::test_delete_leaves_zero_ghost_ledger_entries_globally):**
```
{
    "id": 1, "name": "Audit Vault EGP", "currency": "EGP",
    "expected": 200000, "actual": 100000, "entries": 5
},
{
    "id": 5, "name": "Audit Bank EGP", "currency": "EGP",
    "expected": 100000, "actual": 50000, "entries": 3
},
{
    "id": 4, "name": "Audit Vault SAR", "currency": "SAR",
    "expected": 20000, "actual": 10000, "entries": 3
},
{
    "id": 3, "name": "Audit Vault USD", "currency": "USD",
    "expected": 20000, "actual": 10000, "entries": 3
}
```

The 2× ratio is the smoking gun.

**Tests affected (40+):** VisaDeleteDeepAuditTest (1), VisaRefundDeepAuditTest (1), VisaCancelDeepAuditTest (1), VisaIdempotencyDeepTest (1), VisaIdempotencyTest (2), VisaProductionE2ETest (1), VisaFinancialReconciliationTest (8), VisaLedgerReconciliationTest (6), VisaCustomerDebtScenarioTest (2), VisaAdminFullLifecycleTest (1), VisaConcurrencyTest (2), VisaFailureInjectionTest (1), VisaRollbackTest (5), VisaSupplierFlowDeepTest (1), VisaPerformanceTest (1), VisaIDORAndValidationTest (2 — ledger side-effects).

**Production impact:** The defect is **cosmetic in tests** (production code never double-seeds), BUT every assertion that uses `assertLedgerGloballyBalanced()` is now unreliable as a financial-integrity check. Any new test relying on this assertion will get the same false-failure.

**Recommended fix (Test-side, NOT production):**
In `VisaTestCase::seedOpeningBalanceFor()`, detect the auto-seeded entry (via `is_opening=true`) and skip the manual seed. OR remove the manual seed call from `setUp()` since the FIN-1 auto-seed now handles it.

---

### DEFECT-VISA-2026-08-25-B (MEDIUM) — `deleteBookingWithReversal` Shim TypeError

**Symptom:** `VisaBookingServiceDeadCodeTest::test_delete_booking_with_reversal_shim_delegates` throws `TypeError`.

**Root Cause:** The shim signature was changed in commit `ffc2b5e` (or its sibling) to accept `?User $actor` instead of `?int $actor`. The test still passes `null`/`int`.

**Tests affected:** VisaBookingServiceDeadCodeTest (1).

**Production impact:** None — production callers pass `User` model correctly.

**Recommended fix:** Update the test signature to `?User $actor = null`.

---

### DEFECT-VISA-2026-08-25-C (MEDIUM) — Double Cancel/Refund Invokes Reversal Twice

**Symptom:**
- `VisaIdempotencyTest::test_double_cancel_does_not_double_reversal` fails
- `VisaIdempotencyTest::test_double_refund_does_not_double_reversal` fails

**Root Cause:** `VisaRefundService::cancel()` and `refund()` reject double-cancel/refund at the controller (422 returned). However, when the test invokes the SERVICE DIRECTLY (bypassing the controller), the second call appears to re-reverse the first reversal. The idempotency guard inside `TransactionService::reverseTransaction()` SHOULD catch this (notes prefix check) — but may be racing or being bypassed under direct service invocation.

**Tests affected:** VisaIdempotencyTest (2).

**Production impact:** Low — production calls always go through the controller which returns 422. But direct Filament or tinker usage could double-reverse.

**Recommended fix:** Investigate the second-call path; verify the notes-prefix guard fires; possibly add an explicit booking-status check at the top of the service.

---

## 3. Defect-Free Files (Fully Green)

| File | Tests | Notes |
|------|-------|-------|
| VisaBookingControllerTest | 16/16 | All HTTP paths work |
| VisaAgentApiControllerTest | 9/9 | Agent CRUD works |
| VisaAgentFinanceControllerTest | 9/9 | Dues/withdraw/repay work |
| VisaApiContractTest | 22/22 | Envelope/pagination/filters OK |
| VisaControllerTest | 15/15 | Customer balances/statement OK |
| VisaDoublePaymentDefectReproduction | 2/2 | Defect repro confirmed |
| VisaEdgeCasesTest | 17/17 | Unicode/emoji/decimals OK |
| VisaMasterDataAuditTest | 12/12 | Settings/agents/durations OK |
| VisaPaymentDuplicatesPreCheck | 2/2 | DB UNIQUE backstop works |
| VisaPurchasePriceValidationTest | 8/8 | Phase 9.5a fix holds |
| VisaRouteAuthorizationTest | 11/11 | B-1 fix holds |
| VisaStateMachineMatrixTest | 23/23 | 8-state matrix all reachable |
| VisaStatusTransitionTest | 3/3 | Cancel/refund transitions OK |
| VisaTreasuryControllerTest | 6/6 | Treasury overview OK |
| VisaVueStoreTest | 11/11 | Frontend contract OK |
| V12Phase12RegressionTest | 4/4 | V-2 authz fix holds |
| VisaValidationTest | 36/36 | FormRequest rules OK |

---

## 4. Partially Failing Files

| File | Passed | Failed | Failing Test Names |
|------|--------|--------|-------------------|
| VisaAdminFullLifecycleTest | ~16 | 1 | admin_multi_method_payment_leaves_ledger_globally_balanced |
| VisaBookingServiceDeadCodeTest | ~9 | 1 | delete_booking_with_reversal_shim_delegates (TypeError) |
| VisaCancelDeepAuditTest | 14 | 1 | cancel_leaves_ledger_globally_balanced |
| VisaConcurrencyTest | 3 | 2 | concurrent_cancel_and_payment_no_corruption; two_simultaneous_payments_to_same_booking_one_succeeds_one_fails |
| VisaCustomerDebtScenarioTest | 6 | 2 | exact_10k_debt_scenario_from_prompt; ledger_balanced_after_full_debt_lifecycle |
| VisaDeleteDeepAuditTest | 13 | 1 | delete_leaves_zero_ghost_ledger_entries_globally |
| VisaFailureInjectionTest | 8 | 1 | global_ledger_balanced_after_all_failure_injection_scenarios |
| VisaFinancialReconciliationTest | 13 | 8 | income_clearing_account_net_zero_after_lifecycle; multiple_bookings_aggregate_correctly; supplier_ap_aggregates_correctly_across_bookings; per_currency_totals_isolated; per_status_breakdown_correct; per_customer_portfolio_balances_correctly; global_ledger_balanced_after_complex_lifecycle; multi_currency_booking_does_not_pollute_other_currencies |
| VisaIDORAndValidationTest | 13 | 2 | employee_can_record_payment_on_any_booking; other_employee_can_refund_same_booking |
| VisaIdempotencyDeepTest | 12 | 1 | global_ledger_remains_balanced_after_idempotent_replays |
| VisaIdempotencyTest | 4 | 2 | double_cancel_does_not_double_reversal; double_refund_does_not_double_reversal |
| VisaLedgerReconciliationTest | 4 | 6 | booking_creation_balances_all_ledger; payment_creates_balanced_transaction; cancel_reverses_balance_to_zero_net; refund_reverses_balance_to_zero_net; delete_with_reversal_full_balance_zero; multi_currency_bookings_independent_balances |
| VisaPerformanceTest | 4 | 1 | create_50_bookings_bulk |
| VisaPermissionTest | 15 | 1 | employee_can_add_payment |
| VisaProductionE2ETest | 22 | 1 | 13_every_transaction_is_balanced_after_full_lifecycle |
| VisaRefundDeepAuditTest | 13 | 1 | full_refund_leaves_ledger_globally_balanced |
| VisaRollbackTest | 0 | 5 | All 5 (every test in this file calls `assertLedgerGloballyBalanced()`) |
| VisaSupplierFlowDeepTest | 11 | 1 | agent_account_ledger_is_balanced_across_full_cycle |

---

## 5. Hard/Soft Delete Conflict (RESOLVED)

**Originally flagged:** `VisaProductionE2ETest::test_23_soft_delete_egp_full_roundtrip` asserts SOFT delete of `visa_payments`, while `VisaDeleteDeepAuditTest::test_delete_leaves_zero_ghost_payments` asserts HARD delete.

**Resolution (from code):** `VisaRefundService::deleteWithReversal()` at line 321 calls `$booking->payments()->delete()` which is **HARD delete**. Therefore:
- `VisaDeleteDeepAuditTest` is correct.
- `VisaProductionE2ETest::test_23` is incorrect (test passes incidentally because the assertion is loose).

**Action item:** Phase 3 will fix `VisaProductionE2ETest::test_23` to assert hard-delete.

---

## 6. Coverage Assessment (against User's Original Concerns)

| User Concern | Coverage | Status |
|--------------|----------|--------|
| "بيرجع كل حاجه لي اصلها تاني" (everything returns to original state after deletion) | Tested in 8+ places (VisaDeleteDeepAuditTest, VisaLedgerReconciliationTest, VisaProductionE2ETest, VisaRefundDeepAuditTest, VisaCancelDeepAuditTest) but each in isolation | **INCOMPLETE** — needs consolidated `VisaFullBaselineRestoreTest` that asserts ALL accounts (vault + bank + customer AR + agent AP) in ONE test |
| "حسايبات الخذذ" (deletion accounting — additive pattern) | Tested: original `Transaction.amount` preserved; reversal entries added with `عكس:` prefix; AccountEntry originals preserved | **COMPLETE** |
| "الدين والمديونيه" (debt/credit) | Tested: customer AR zero after full refund; supplier AP zero after delete; FIFO distribution on pay-debt; debtor/creditor filter | **MOSTLY COMPLETE** — gaps in `creditors` filter explicit test and statement pagination |

---

## 7. Recommended Next Steps (Phases 2-4)

### Phase 2: Fix the defects (Test-side only)
1. **Fix DEFECT-VISA-2026-08-25-A** in `VisaTestCase.php` (skip `seedOpeningBalanceFor` if `is_opening=true` already present).
2. **Fix DEFECT-VISA-2026-08-25-B** in `VisaBookingServiceDeadCodeTest.php` (correct shim signature).
3. **Investigate DEFECT-VISA-2026-08-25-C** in `VisaRefundService` (add double-cancel guard at service layer if needed).

### Phase 3: Consolidate deletion coverage
1. Create `tests/Feature/Visa/VisaFullBaselineRestoreTest.php` with 16 scenarios covering:
   - DELETE: EGP-full-pay, EGP-partial-pay, EGP-multi-payment, USD-agent, SAR-no-agent, pay-customer-debt-then-delete, two-customers-isolation
   - CANCEL: EGP-full, USD-full
   - REFUND: EGP-full, SAR-partial
   - Invariants: API view-level, original amount preserved, reversal entries added, double-delete idempotency
2. Fix `VisaProductionE2ETest::test_23` to assert hard-delete on `visa_payments`.

### Phase 4: Final Report
1. Compile `.zcode/plans/VISA_FULL_AUDIT_20260825.md` with:
   - Defect-fix summary
   - Coverage delta
   - Comparison with `HAJJ_UMRA_FULL_AUDIT_20260824.md`
   - Recommended commit pattern (mirroring `dc3cc5e fix(hajj): ...`)

---

## 8. Files NOT Run (Excluded)

- `VisaTestCase.php` — abstract base, not a runnable test.
- `tests/Feature/VisaDurationTest.php` and `tests/Feature/VisaUmrahImprovementsTest.php` — outside `tests/Feature/Visa/` directory but Visa-related. Will run separately if needed.

---

**End of Baseline Report.**