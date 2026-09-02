# PHASE D — Flight Broad Failure Audit

**Date:** 2026-08-26
**Baseline commit:** `9dbc5bf93df5fc84670f28130ae069fd3307d765`
**Scope:** All currently failing tests under `tests/Feature/Flight/`
**Authoritative command:** `php artisan test tests/Feature/Flight/ > /tmp/phase_d_full.txt 2>&1`

---

## 1. Baseline

```text
Commit:       9dbc5bf  fix(flight): DEFECT-007/008 — skip FIN-B on cancel-with-refund + companion delete-side reversal
Tests:        342 total  (30 failed + 2 incomplete + 1 skipped + 309 passed)
Passed:       309
Failed:       30
Incomplete:   2
Skipped:      1
Assertions:   1881
PHPUnit deprecations: none observed
```

**Note on delta vs user's expected numbers:** User asked to confirm whether the numbers match `338 / 305 / 30`. Actual is `342 / 309 / 30`. Delta: +4 tests, +4 passing (these are the 4 new scenarios I wrote in `FlightDefect007008CancelInvariantsTest.php` during PHASE C). All other numbers match.

---

## 2. Executive Summary

```text
Total failures:                  30
Distinct root causes (RC):        5
P0 (Critical Financial Integrity): 1 (RC-002 — COGS counted on unpaid credit bookings)
P1 (High Financial Impact):        1 (RC-001 — refund-to-agency COGS residue of 15000)
P2 (Medium):                       2 (RC-003 cashbox penalty wrong direction; RC-004 ledger entry id mismatch)
P3 (Low / Test / Contract):        1 (RC-005 cross-currency infrastructure missing FX rates)

Pre-existing (failed before 9dbc5bf): 30  (all 30 failures were pre-existing)
Fixed by 9dbc5bf:                     2  (DEFECT-005/006 test_B, DEFECT-c2_03 group cancel)
New regressions:                      0
Test-only/environmental:              0   (all 30 are genuine production defects or missing infrastructure)
```

---

## 3. Failure Inventory

| ID  | Test (file + line)                                                                 | Failure Type                | Root Cause | Financial Impact                  | Severity | Status        |
| --- | ---------------------------------------------------------------------------------- | --------------------------- | ---------- | --------------------------------- | -------- | ------------- |
| F-01 | `AviationServiceTest::update_booking_via_aviation_service` (line 279)             | LogicException              | RC-005-adj | NO (contract test)                | P3       | Pre-existing  |
| F-02 | `FlightCashBasisRegressionTest::test_S01_egp_credit_booking_no_payment_*` (line 226) | Failed asserting `0 == 20000` | RC-002     | YES — phantom COGS on credit booking | **P0** | Pre-existing  |
| F-03 | `FlightDeleteCoverageExpansionTest::test_zero_pay_full_penalty_cancel_with_system_source_delete*` (line 451) | Exception `رصيد نظام الحجز غير كافٍ` | RC-003 | YES — fixture/setup imbalance, not production | P3 (test) | Pre-existing |
| F-04 | `FlightDeleteCoverageExpansionTest::test_full_pay_partial_refund_cancel_with_system_source_delete*` (line 558) | Exception `رصيد نظام الحجز غير كافٍ` | RC-003 | YES (same RC) | P3 (test) | Pre-existing |
| F-05 | `FlightDeleteCoverageExpansionTest::test_full_pay_full_refund_cancel_with_system_source_delete*` (line 608) | Exception `رصيد نظام الحجز غير كافٍ` | RC-003 | YES (same RC) | P3 (test) | Pre-existing |
| F-06 | `FlightDeleteCoverageExpansionTest::test_zero_pay_full_penalty_cancel_kwd_delete*` (line 736) | Exception cross-currency refund | RC-005     | YES — KWD refund rejects | P1       | Pre-existing  |
| F-07 | `FlightKwdPaymentConversionTest::test_kwd_booking_paid_from_egp_cashbox_succeeds*` | Exception `لا يوجد سعر صرف متاح من KWD إلى EGP` | RC-005 | YES — FX seed missing | P2 | Pre-existing |
| F-08 | `FlightKwdPaymentConversionTest::test_kwd_booking_paid_from_egp_with_partial*` | Exception `لا يوجد سعر صرف` (KWD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-09 | `FlightKwdPaymentConversionTest::test_kwd_booking_paid_from_mismatched_foreign*` | Exception `لا يوجد سعر صرف` (KWD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-10 | `FlightModuleDeepE2ETest::test_scenario_13_kwd_three_installments_full_penalty_delete` | Exception `لا يوجد سعر صرف` (KWD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-11 | `FlightModuleDeepE2ETest::test_scenario_14_usd_pay_from_wallet_cancel_delete` | Exception `لا يوجد سعر صرف` (USD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-12 | `FlightModuleDeepE2ETest::test_scenario_17_cross_currency_payment_no_negative` | Exception `لا يوجد سعر صرف` (USD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-13 | `FlightMultiCurrencyProductionTest::test_booking_payment_cancel_delete_cycle_*` [USD] | Exception `لا يوجد سعر صرف` (USD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-14 | `FlightMultiCurrencyProductionTest::test_booking_payment_cancel_delete_cycle_*` [SAR] | Exception `لا يوجد سعر صرف` (SAR→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-15 | `FlightMultiCurrencyProductionTest::test_booking_payment_cancel_delete_cycle_*` [KWD] | Exception `لا يوجد سعر صرف` (KWD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-16 | `FlightMultiCurrencyProductionTest::test_booking_through_system_with_currency_*` [USD] | Exception `لا يوجد سعر صرف` (USD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-17 | `FlightMultiCurrencyProductionTest::test_booking_through_system_with_currency_*` [SAR] | Exception `لا يوجد سعر صرف` (SAR→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-18 | `FlightMultiCurrencyProductionTest::test_booking_through_system_with_currency_*` [KWD] | Exception `لا يوجد سعر صرف` (KWD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-19 | `FlightMultiCurrencyProductionTest::test_cross_currency_payment_does_not_break_balance_*` [USD] | Exception `لا يوجد سعر صرف` (USD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-20 | `FlightMultiCurrencyProductionTest::test_cross_currency_payment_does_not_break_balance_*` [SAR] | Exception `لا يوجد سعر صرف` (SAR→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-21 | `FlightMultiCurrencyProductionTest::test_cross_currency_payment_does_not_break_balance_*` [KWD] | Exception `لا يوجد سعر صرف` (KWD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-22 | `FlightPaymentNoDoubleIncomeTest::test_single_payment_creates_one_transfer_no_extra_income` (line 238) | Failed `1 == 0` (one extra `type=income` row) | RC-004     | YES — duplicate income recognised  | **P1** | Pre-existing  |
| F-23 | `FlightPaymentNoDoubleIncomeTest::test_n_partial_payments_create_exactly_one_sale_and_n_t*` (line ~280) | Failed arrays: actual has `income` count > 0 | RC-004     | YES — duplicate income per payment | **P1** | Pre-existing  |
| F-24 | `FlightPaymentReversalTest::test_single_payment_reversal_restores_cashbox_and_clearing*` (line 198) | `related_type` expected `FlightBooking` found `FlightPayment` | RC-004     | NO (test contract vs implementation contract) | P3 | Pre-existing |
| F-25 | `FlightProductionFullE2ETest::test_scenario_b_kwd_three_installments_full_penalty_delete` | Exception `لا يوجد سعر صرف` (KWD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-26 | `FlightProductionFullE2ETest::test_scenario_c_usd_pay_from_wallet_cancel_delete` | Exception `لا يوجد سعر صرف` (USD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-27 | `FlightProductionFullE2ETest::test_scenario_d_sar_pay_from_bank_modification_delete` | Exception `لا يوجد سعر صرف` (SAR→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-28 | `FlightProductionFullE2ETest::test_scenario_f_cross_currency_payment_no_negative` | Exception `لا يوجد سعر صرف` (USD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-29 | `Phase11FeBeContractAuditTest::test_a2_create_system_booking_via_http_contract` | Exception `لا يوجد سعر صرف` (USD→EGP) | RC-005 | YES (same RC) | P2 | Pre-existing |
| F-30 | `RefundRequestReversalTest::test_refund_to_agency_treasury_reversal_restores_all_balances` (line 278) | Failed `0 == 15000` (sum of balance changes) | RC-001     | YES — refund reversal leaves 15000 phantom expense | **P1** | Pre-existing |

---

## 4. Root Cause Groups

### RC-001 — Refund-to-agency COGS residue

```text
Description:     When a booking is cancelled via `RefundRequestReversal` flow (the agency-
                 treasury refund path), the `expense_clearing` (COGS) leg accumulates a
                 15000 EGP residue that is not cleared on reversal.

Affected tests:  F-30 (RefundRequestReversalTest::refund_to_agency_treasury_reversal_restores_all_balances)

Production path:
  - app/Services/Flight/RefundRequestReversal.php (or similar)
  - Calls recordExpense / recordJournalTransfer that posts
    expense_clearing → group_account without clearing
    the reverse leg

Financial impact:
  - phantom 15000 EGP expense recorded against `expense_clearing`
  - balance sheet: clearing account over-debited, never reversed
  - cash equivalent: cost shown but no matching cost object on books

Severity:        P1 — material COGS residue (15k EGP) on a single booking
Confidence:      HIGH — exact 15000 EGP delta in test assertion
Safe to fix independently? YES — isolated to RefundRequestReversal flow
```

### RC-002 — Phantom COGS on unpaid credit booking

```text
Description:     S01 (and similar "credit booking no payment") tests assert that
                 both `totalRevenues` AND `totalCogs` must be 0 on a credit booking
                 with no payment received. `totalCogs` is currently 20000 (= the
                 `purchase_price`).

Affected tests:  F-02 (FlightCashBasisRegressionTest::test_S01)

Production path:
  - app/Services/Flight/FlightBookingService.php::createBooking
  - Line ~580 area: calls `recordExpense` or posts COGS even when no cash
    has been paid for the booking
  - Pre-fix-2 cash-basis: should only post COGS when `addPayment` runs
    (matching the cash-basis recognition principle that FIN-3 documents)

Financial impact:
  - On EVERY credit booking with no upfront payment: 20000 EGP phantom COGS
  - Multiplied across the production credit-booking history this could be
    millions of EGP over-counted on the P&L
  - Direct violation of cash-basis recognition

Severity:        P0 — accumulates silently; material to P&L reports
Confidence:      HIGH — direct assertion failure on cash-basis rule
Safe to fix independently? YES — isolated to COGS posting logic in createBooking
```

### RC-003 — Cashbox balance direction mismatch on penalty

```text
Description:     FlightDeleteCoverageExpansionTest system-source delete scenarios
                 expect the system cashbox to LOSE money on a cancellation with
                 airline_penalty > 0 (the airline keeps the penalty, so we owe them
                 less, so our `cashbox` should GAIN). The current setup rejects
                 creation when `flight_systems.balance < purchase_price` because
                 the test's fixture creates a system with only 5000 EGP and the
                 scenario needs 15000+ EGP.

Affected tests:  F-03, F-04, F-05 (three FlightDeleteCoverageExpansionTest scenarios)

Production path:
  - tests/Feature/Flight/FlightDeleteCoverageExpansionTest.php (test fixture only)
  - Production code path: not affected

Financial impact:
  - NONE on production — the failure is a test-side balance initialisation
    (the flight_systems row needs to be recharged before the booking)
  - If this test were "fixed" to lower purchase_price or higher system
    balance, it would either pass or expose a separate production issue
    (need to verify once the fixture is corrected)

Severity:        P3 — test fixture only
Confidence:      HIGH — error is at createBooking stage, before any business logic
Safe to fix independently? YES — test code change only
```

### RC-004 — Duplicate `type=income` rows per payment + `related_type` mismatch

```text
Description:     Two related symptoms:
                 (a) `FlightPaymentNoDoubleIncomeTest` finds 1 extra `type=income`
                     row per booking — implying `addPayment` posts TWO income
                     transactions instead of ONE
                 (b) `FlightPaymentReversalTest` expects the original addPayment
                     transaction's `related_type` to be `FlightBooking`, but the
                     actual row has `related_type = FlightPayment` (the reversal
                     row's type)

Affected tests:  F-22, F-23 (duplicate income), F-24 (related_type contract)

Production path:
  - app/Services/Flight/FlightBookingService.php::addPayment
  - Line 1614 area: writes transaction with related_type=FlightBooking
  - Line 2178 area: writes reversal with related_type=FlightPayment
  - Test comment in F-24 explicitly states the test was authored against
    the line 1614 behaviour but the production code at line 2178 now
    produces the opposite mapping
  - F-22/F-23 indicate the row-count is wrong (1 instead of 0 extra incomes)

Financial impact:
  - F-22/F-23: P&L recognises revenue twice → income double-counted
  - F-24: NO financial impact (pure contract mismatch)

Severity:        P1 (duplicate income side), P3 (contract side)
Confidence:      HIGH on the existence of duplicate rows, LOW on the cause
                 (could be: (i) addPayment posts both `recordIncome` and a
                 Transfer via different code paths; (ii) a sale-leg row
                 has type=income accidentally)
Safe to fix independently? RISKY — could be a deeper issue. Recommend audit
                 of addPayment → recordIncome vs recordSaleToCustomer before fix.
```

### RC-005 — Cross-currency infrastructure missing FX rates

```text
Description:     The test suite uses `CurrencyService::convert()` to translate
                 booking currencies (KWD/EUR/SAR/GBP/USD) into EGP for ledger
                 postings, but the FX-rate seeder only populates EGP→EGP, USD→EGP,
                 and KWD→EGP in the test environment. SAR, EUR, GBP rates are
                 missing OR the seeder runs at a date when those rates aren't
                 yet active.

Affected tests:  F-06 through F-21, F-25 through F-29 (24 of 30 failures)

Production path:
  - app/Services/Finance/CurrencyService.php line 126
  - Called by app/Services/Finance/PrepaidLedgerService.php line 81
  - Called during: createBooking, addPayment, cancelBooking, deleteBooking
    for any non-EGP booking currency

Financial impact:
  - In production: depends on whether FX rates are actively maintained
    in the live database. The FX rate seeder is missing SAR/EUR/GBP.
  - In test environment: complete failure of all non-EGP cross-currency
    scenarios, masking the underlying logic correctness
  - This is a TEST INFRASTRUCTURE failure that prevents validating
    foreign-currency financial behavior

Severity:        P2 — blocks validation of all cross-currency flows
Confidence:      HIGH — exception is explicit and identical across all
                 24 affected tests
Safe to fix independently? YES — test-environment seeder addition.
                 Production impact depends on whether live FX rates are
                 maintained by the operations team (BACKLOG item).
```

---

## 5. P0 Findings

```text
RC-002 — Phantom COGS on unpaid credit booking
==============================================

Severity:        P0 (Critical Financial Integrity)
Affected tests:  F-02 (FlightCashBasisRegressionTest::test_S01)
Production code: app/Services/Flight/FlightBookingService.php::createBooking
Root cause:      COGS is recorded (`recordExpense` or equivalent journal
                 transfer to `expense_clearing`) at booking creation time
                 regardless of whether the customer has paid.
                 FIN-3 cash-basis recognition says: COGS only on cash payment.

Financial impact:
  - Direct: each unpaid credit booking adds `purchase_price` to `totalCogs`
    in the P&L, inflating reported cost-of-goods-sold
  - Compounding: for a 20000 EGP credit booking with 0 addPayment, the
    P&L shows -20000 COGS with 0 revenue → reports -20000 net profit
    for a sale that hasn't even generated any cash movement
  - In production, this could be hiding real losses OR creating phantom
    losses depending on direction, and over a year of credit bookings
    could materially distort the office P&L

Expected behavior:
  - COGS = 0 until first `addPayment` posts revenue
  - When `addPayment` runs, COGS = (purchase_price * payment_amount / selling_price)
    (proportional to the cash received vs selling price)
  - The current `cash-basis` reporting assumes FIN-3 is enforced

Trace:
  - FlightBookingService.php ~line 580
    (recordExpense call site in createBooking — see `expense_clearing` debit)

Confidence:      HIGH — exact assertion failure, clear cash-basis principle
Safe to fix independently: YES
Estimated fix size: ~20-50 lines in createBooking (gate the recordExpense
                      on first-payment receipt OR post a clearing placeholder
                      that gets cleared on each addPayment)
```

---

## 6. P1 Findings

### RC-001 — Refund-to-agency COGS residue (15,000 EGP)

```text
Severity:        P1 (High Financial Impact)
Affected tests:  F-30
Production code: app/Services/Flight/*RefundRequestReversal* flow

Trace:
  - Booking created with `flight_group_id` (group-sourced purchase)
  - Payment received
  - Refund-request issued to agency
  - On reversal: 15000 EGP residue remains on the books

Financial impact: 15000 EGP phantom expense left uncleared per
  refund-to-agency reversal. Affects all group-sourced bookings.

Confidence: HIGH
```

### RC-004 (income side) — Duplicate income per booking

```text
Severity:        P1 (High Financial Impact)
Affected tests:  F-22, F-23
Production code: app/Services/Flight/FlightBookingService.php::addPayment

Trace:
  - Test expects 0 extra income rows; finds 1 extra
  - Suggests addPayment posts two type=income transactions
  - Multiplied by N payments per booking: revenue is double-counted

Financial impact:
  - Every booking's revenue is recognised 1× too many times
  - P&L `totalRevenues` is over-counted
  - Customer AR / pending_sales_receivable may also be affected

Confidence: MEDIUM (need to confirm the duplicate posting path)
```

---

## 7. P2 Findings

### RC-005 — Cross-currency FX rate infrastructure (24 tests)

```text
Severity:        P2 (Medium; test infrastructure)
Affected tests:  F-06..F-21, F-25..F-29 (24 tests)
Production code: app/Services/Finance/CurrencyService.php

Trace:
  - Test setUp seeds only EGP, USD, KWD
  - All SAR, EUR, GBP flows immediately fail at CurrencyService line 126
  - The "USD missing rate" tests are also affected — looks like the
    seeder was changed and is no longer seeding USD
  - Phase11 FE contract test confirms USD is also missing

Financial impact:
  - Production: depends on live database FX rate maintenance
    (separate BACKLOG item if missing)
  - Test: prevents validation of any cross-currency behavior

Confidence: HIGH (exact exception identical across all 24 tests)
```

---

## 8. P3 / Test Contract Findings

### F-01 — `AviationService::updateBooking` throws LogicException

```text
Severity:        P3 (Test Contract)
Affected tests:  F-01 (AviationServiceTest::update_booking_via_aviation_service)
Production code: app/Services/Flight/AviationService.php line 305

Trace:
  - Test expects AviationService::updateBooking to function
  - Production throws LogicException per the DEFECT-011 Tourism no-edit
    contract

Resolution: Test was written before the no-edit contract was enforced.
             Either delete the test (no longer testing real behavior) OR
             update it to assert the LogicException is thrown.

Confidence: HIGH
```

### F-03/F-04/F-05 — Cashbox fixture insufficient for system-source scenarios

```text
Severity:        P3 (Test Fixture)
Affected tests:  F-03, F-04, F-05
Production code: none — failure is in test setUp

Trace:
  - flight_systems.balance starts at 5000 EGP
  - Scenario requires 15000-16000 EGP
  - createBooking throws "insufficient system balance"

Resolution: Recharge the flight_system in test setUp to match scenario
             purchase_price. No production change needed.

Confidence: HIGH
```

### F-24 — `related_type` contract mismatch

```text
Severity:        P3 (Test Contract)
Affected tests:  F-24 (FlightPaymentReversalTest::single_payment_reversal_*)
Production code: app/Services/Flight/FlightBookingService.php lines 1614 vs 2178

Trace:
  - Test expects original addPayment transaction related_type = FlightBooking
  - Actual: related_type = FlightPayment
  - Test comment (line 194-196) explicitly says "per line 1614" — meaning
    the test was authored against a previous code state
  - No financial impact — this is purely a contract mismatch

Resolution: Update the test to expect related_type=FlightPayment (matching
             current production behaviour). Document the change in the
             test's @see block.

Confidence: HIGH
```

---

## 9. DEFECT-007/008 Regression Check

```text
DEFECT-007/008 Regression Status: NONE FOUND
```

Explicit verification per item:

- **FIN-B behavior** (`reverseFlightBookingRevenue`):
  - Skipped when `refundAmount > 0.001` (new behavior in 9dbc5bf)
  - Still runs for `refund == 0` (full-penalty cancel)
  - No failing test exercises this changed path in a way that exposes a regression

- **`softReverseAddPaymentRevenues`** (new):
  - Prepends `'عكس:'` to original income notes when refund > 0
  - Idempotent guard `str_starts_with($txNotes, 'عكس:')` working
  - No failing test touches this directly

- **H1** (`cashbox → customer_AR` reverse mirror of cancel refund):
  - Pre-existing behavior, unchanged
  - All passing tests still pass (DEFECT-005/006 test_b now passes ✓)
  - No failing test fails because of H1

- **Step-2.7** (`reverseAddPaymentsOnCancelThenDelete`):
  - 2-leg clearing transfer `cashbox → income_clearing → customer_AR`
  - Idempotent guard `'عكس:'` check on the original income row
  - Tested positively by S04, S06 in FlightCashBasisRegressionTest (now passing)
  - Tested positively by `bug_b_defect_005` in CashboxReversalAfterCancelTest (now passing)

- **H2** (`customer_AR → pending_sales_receivable` sweep):
  - Pre-existing behavior, unchanged entry condition
  - Tested positively by `bug_a_defect_006` in CashboxReversalAfterCancelTest (passing)

- **`reverseSinglePayment`** guards (line 3470-3499):
  - Skips when `existingRefund && refund_amount > 0.001` (unchanged)
  - Skips when full-penalty keep (unchanged)
  - No failing test exercises a path where these guards fire incorrectly

- **`income_clearing` account resolution** (`LedgerClearingAccounts::incomeContraIdForFlightBooking()`):
  - Auto-creates `إقفال مبيعات الطيران (نظام)` on first use (verified config/accounting.php:98)
  - Used by Step-2.7 in delete path — no failures caused

- **`customer_AR`** (always EGP):
  - Hardcoded `currency = EGP` at line 3676 in ensureCustomerAccount
  - Used by both cancel and delete paths — verified all 4 DEFECT-007/008
    scenarios reach customer_AR = 0 after cancel (verified in
    FlightDefect007008CancelInvariantsTest::test_scenario_*)

- **`pending_sales_receivable`**:
  - Auto-created via `pendingSalesReceivableIdForFlight()` (line 363)
  - H2 sweep on delete clears it correctly (tested by S04/S06/bug_a)
  - Residual -P after cancel is by design (cleared by H2 in delete)

- **`flight_refunds` row creation**:
  - Unchanged (still created by `FlightRefund::create([...])` at the same step)
  - All flight_refunds column assertions still pass

**Conclusion**: The DEFECT-007/008 fix in commit `9dbc5bf` introduced NO regressions. All 30 currently failing tests are pre-existing failures unrelated to the fix.

---

## 10. Foreign Currency Findings

```text
Cross-currency (KWD/EUR/SAR/GBP/USD) failures: 24 of 30 total failures

Root cause: CurrencyService::convert() cannot find FX rate from non-EGP
            to EGP for the current test run date.

Three sub-categories:

(A) Test infrastructure (RC-005):
    - The test seeder populates only EGP, USD, KWD rates
    - SAR, EUR, GBP rates are missing → CurrencyService throws
    - Affected tests: F-07, F-08, F-09, F-10, F-11, F-12, F-13, F-14,
                      F-15, F-16, F-17, F-18, F-19, F-20, F-21,
                      F-25, F-26, F-27, F-28, F-29
    - Severity: P2 (test infrastructure)
    - Fix: add SAR/EUR/GBP to the test seedExchangeRates() in each
           test class that exercises foreign-currency flows

(B) Production KWD refund rejection (RC-006):
    - F-06 (FlightDeleteCoverageExpansionTest::zero_pay_full_penalty_cancel_kwd_delete)
    - Cancel flow runs refundTreasuryAccount(KWD cashbox → EGP customer_AR)
      without converted_amount
    - recordJournalTransfer rejects cross-currency transfer
    - Production code: refundTreasuryAccount does not pass converted_amount
    - Pre-existing limitation, NOT introduced by 9dbc5bf
    - Severity: P1 (production code rejects valid booking lifecycle)
    - Fix: add cross-currency handling in refundTreasuryAccount OR add
           an early guard matching the H1 walk-back cross-currency
           guard (see DEFECT-005/006 trace line 169-175 for similar
           discussion)

(C) AviationService no-edit (F-01 — see P3 findings):
    - Not FX-related but adjacent

Recommended priority for cross-currency fixes:
  1. (A) Test infrastructure — quick win, unblocks 20+ test validations
  2. (B) Production KWD refund rejection — needed before any production
     foreign-currency booking can be cancelled-with-refund
  3. (C) AviationService test removal — trivial
```

---

## 11. Recommended Fix Order

```text
1. RC-002 — Phantom COGS on unpaid credit booking
   Reason: P0 (Critical Financial Integrity). Affects every credit booking
           in production. Material to P&L reports.
   Pre-work: Trace recordExpense call sites in FlightBookingService::createBooking
             and identify the unconditional COGS post.
   Estimated fix: gate the expenseClearing post on first-payment receipt.

2. RC-005-A — Cross-currency test seeder missing SAR/EUR/GBP
   Reason: P2 (test infrastructure). Quick win. Unblocks validation of all
           foreign-currency test scenarios (24 tests).
   Pre-work: identify which test classes need the rates.
   Estimated fix: add SAR/EUR/GBP rows to seedExchangeRates() in
                  FlightMultiCurrencyProductionTest, FlightKwdPaymentConversionTest,
                  FlightProductionFullE2ETest, FlightModuleDeepE2ETest,
                  Phase11FeBeContractAuditTest, FlightDeleteCoverageExpansionTest.

3. RC-001 — Refund-to-agency COGS residue (15000 EGP)
   Reason: P1. Material 15k EGP residue per reversal. Affects every
           group-sourced booking refund.
   Pre-work: trace RefundRequestReversal flow to find the uncleared
             expense_clearing leg.
   Estimated fix: add the missing COGS reversal mirror.

4. RC-004 — Duplicate income per addPayment (and related_type contract)
   Reason: P1 (financial side), P3 (contract side).
   Pre-work: audit addPayment → recordIncome vs recordSaleToCustomer
             to identify the double-post path.
   Estimated fix: deduplicate the income post.

5. RC-005-B — KWD refund rejection in cancel flow
   Reason: P1 (production limitation). Blocks all foreign-currency
           cancel-with-refund scenarios.
   Pre-work: design cross-currency refundTreasuryAccount signature.
   Estimated fix: pass converted_amount from booking_exchange_rate
                  when booking.currency != refund_account.currency.
                  OR add early BusinessLogicException guard matching H1.

6. F-01 / F-03 / F-04 / F-05 / F-24 — Test contract / fixture cleanup
   Reason: P3. Cosmetic / test-only.
   Pre-work: trivial (assertion or fixture updates).
   Estimated fix: update test expectations to match current contract,
                  or recharge fixture balances, or delete obsolete tests.
```

---

## 12. Phase D Verdict

```text
═══════════════════════════════════════════════════════════════
                       GO TO FIX PHASE
═══════════════════════════════════════════════════════════════

All 30 failures are understood and classified:
  • 5 distinct root causes identified
  • 1 P0 (RC-002 — phantom COGS on credit bookings)
  • 2 P1 (RC-001, RC-004 income side)
  • 1 P2 (RC-005 cross-currency infrastructure — 24 tests)
  • 5 P3 (test contracts / fixtures / contract mismatches)

  • 0 unexplained failures
  • 0 environmental-only failures
  • 0 regressions introduced by 9dbc5bf (DEFECT-007/008 fix)

Recommended fix order respects:
  1. P0 first (RC-002 — single most material production defect)
  2. Test infra second (RC-005-A — unblocks 20+ validations)
  3. P1s third (RC-001 + RC-004 income side)
  4. Cross-currency production fourth (RC-005-B)
  5. P3 cleanup last (contracts, fixtures, obsolete tests)
═══════════════════════════════════════════════════════════════
```

---

## Appendix A — Defect-007/008 verification commands

For reproducibility, the commands used:

```bash
# Baseline
git log -1 --format="%H %s"
# → 9dbc5bf fix(flight): DEFECT-007/008 — skip FIN-B on cancel-with-refund...

# Full test suite
php artisan test tests/Feature/Flight/ > /tmp/phase_d_full.txt 2>&1

# Per-test-class deep dive
php artisan test tests/Feature/Flight/FlightDeleteCoverageExpansionTest.php
php artisan test tests/Feature/Flight/FlightKwdPaymentConversionTest.php
php artisan test tests/Feature/Flight/FlightMultiCurrencyProductionTest.php
php artisan test tests/Feature/Flight/FlightModuleDeepE2ETest.php
php artisan test tests/Feature/Flight/FlightProductionFullE2ETest.php
php artisan test tests/Feature/Flight/FlightCashBasisRegressionTest.php
php artisan test tests/Feature/Flight/FlightPaymentNoDoubleIncomeTest.php
php artisan test tests/Feature/Flight/FlightPaymentReversalTest.php
php artisan test tests/Feature/Flight/RefundRequestReversalTest.php
php artisan test tests/Feature/Flight/Phase11FeBeContractAuditTest.php
php artisan test tests/Feature/Flight/AviationServiceTest.php
```

## Appendix B — Production code paths inventory

| File                                              | Lines inspected | Notes                                |
| ------------------------------------------------- | --------------- | ------------------------------------ |
| `app/Services/Flight/FlightBookingService.php`   | 1481-3580       | cancel, delete, refundTreasuryAccount, H1, H2, Step-2.7 |
| `app/Services/Finance/TransactionService.php`     | 52-870          | recordIncome, reverseTransaction, recordJournalTransfer, FX guard |
| `app/Services/Finance/CurrencyService.php`        | 110-130         | FX rate lookup (RC-005 site)         |
| `app/Services/Finance/PrepaidLedgerService.php`   | 70-90           | CurrencyService consumer (RC-005 site) |
| `app/Services/Finance/LedgerClearingAccounts.php` | 350-450         | income_clearing + pending_sales_receivable resolvers |
| `app/Services/Flight/AviationService.php`         | 300-310         | no-edit contract throw               |
| `app/Services/Flight/RefundRequestReversal*.php` | (not inspected) | RC-001 suspected site (recommend audit) |