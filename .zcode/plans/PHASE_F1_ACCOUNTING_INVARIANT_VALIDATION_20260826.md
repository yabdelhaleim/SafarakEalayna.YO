# PHASE F.1 — RC-001 Accounting Invariant Validation

**Date:** 2026-08-26
**Branch:** phase-10-tourism-production-audit-hajj-umra
**Current commit:** `afe4913 fix(flight): FIN-3 cash-basis COGS — recognise proportional to customer payment (RC-002)`
**Investigation:** NO code changes. NO commits. Diagnostic only.

---

## Executive Summary — **CONCLUSION B**

**RC-001 is OBSOLETE TEST LOGIC. The post-RC-002 journal is economically correct.**

The `deltaSum` assertion in `RefundRequestReversalTest::test_refund_to_agency_treasury_reversal_restores_all_balances` is **mathematically/accounting incorrect** because it uses the wrong baseline for `expense_clearing.balance` — it assumes a baseline of `0`, but `expense_clearing.balance` has been non-zero (specifically +15,000) since the booking's `consumeCogs` ran at creation time. This test bug **already existed on commit 9dbc5bf** (PHASE D baseline); my RC-002 fix did not introduce it.

The accounting reality under the new (post-RC-002) architecture is:
- `expense_clearing.balance` = **+1,000** (cancellation fee retained as COGS absorbed by office — correct)
- `pending_sales_receivable.balance` = **−1,000** (cancellation fee retained as revenue contra — correct)
- These two cancel each other in a correctly-formulated sum.
- The test's `deltaSum` formula fails to apply a baseline adjustment to `expense_clearing` the way it does for `clearing`, causing an apparent +15,000 mismatch.

---

## TASK 1 — Business / Accounting Invariant

### What is the 1,000 EGP cancellation fee economically?

Three parties are involved in a cancellation:
- **Customer** paid 18,000; receives 17,000 back; **keeps 1,000 paid as cancellation penalty**.
- **Carrier** had a 15,000 purchase obligation; credits back only 14,000; **keeps 1,000 as its own cancellation penalty**.
- **Office** sits in the middle: collects 18,000 from customer, pays 15,000 to carrier, refunds 17,000 to customer, receives 14,000 from carrier.

**Net economic outcome for the office:**
- Cash in: 18,000 (sale) − 17,000 (refund to customer) + 14,000 (carrier credit-back) = **15,000 net cash retained**.
- Cash out: 15,000 (purchase) = **15,000 net cash spent**.
- **Net cash flow = 0** (it's a wash).

**Booking-level P&L for the office:**
- Revenue kept (cancellation fee from customer): **+1,000**.
- Cost absorbed (carrier kept its penalty): **−1,000**.
- **Net P&L = 0** — a wash.

### Where should the 1,000 cancellation fee be recognised in the GL?

**Answers:**
- **A) Revenue only?** No. The cancellation fee is a hybrid: it's +1,000 revenue (penalty from customer) AND −1,000 COGS (penalty absorbed from carrier).
- **B) Partly/fully in COGS?** YES — the full 1,000 should remain in COGS as the carrier penalty absorbed by the office. The office "spent" 1,000 to the carrier (the carrier didn't credit back the full purchase).
- **C) Carrier cost retained by the carrier?** YES, economically. The carrier retained 1,000 from the prepaid pool. The office has to either (a) book that 1,000 as a loss (COGS), or (b) recognise an offsetting revenue from the customer's cancellation fee.
- **D) Office cancellation/service margin?** Conceptually, the office keeps BOTH the customer's 1,000 AND absorbs the carrier's 1,000 — so the gross effect is a wash, NOT a margin. But the bookkeeping requires BOTH legs to be present in the ledger for the trail.
- **E) Which account carries the final 1,000?** Two accounts, in equal-and-opposite entries:
  - `income_clearing` / `pending_sales_receivable` carries **−1,000** (cancellation fee retained as revenue — the office's earned fee).
  - `expense_clearing` carries **+1,000** (cancellation fee portion of COGS retained — the office's absorbed penalty).
  - Net P&L = 0 (the cancellation is economically a wash).

---

## TASK 2 — Comparison with Existing Accounting Patterns

### Established pattern in the codebase

The codebase has TWO parallel flows that handle cancellation penalties the same way:

**Flow A — `cancelBooking` (FlightBookingService) — lines 2369–2665:**
- Reads `airlinePenalty` + `officePenalty`.
- Calls `creditBackFlightCarrier($booking, $airlinePenalty)` which:
  - Credits `carrier.balance += (purchaseEgp − airlinePenalty)` (carrier only gets back the net).
  - Calls `refundCogs(expense_clearing → prepaid_carrier, purchaseNet = purchaseEgp − airlinePenalty)`.
- Sale reversal uses `saleReversalAmount = saleAmount − totalPenalties` (cancellation fee retained as revenue).

**Flow B — `processRefundRequest` (RefundService) — lines 448–543:**
- Reads `cancellationFee`.
- Computes `purchaseNet = purchaseEgp − cancellationFee`.
- Calls `refundCogs(expense_clearing → prepaid_carrier, purchaseNet)`.
- Sale reversal uses `refundAmount = sellingPrice − cancellationFee` (cancellation fee retained as revenue).

**Both flows use the identical accounting pattern:**
1. Carrier/system is credited back only the **net** amount (`purchase − penalty`).
2. `refundCogs` is called with the **net** amount, not the full purchase.
3. The penalty is implicitly retained in `expense_clearing` as an un-reversed COGS portion.

### Existing pattern in `TourismPAndLComprehensiveTest` (line 351)

`test_flight_cogs_flows_from_prepaid_to_expense_clearing` asserts the canonical COGS pattern:
- `consumeCogs(prepaid → expense_clearing, 6000)` produces `totalCogs == 6000`.
- `refundCogs(expense_clearing → prepaid, 1000)` (partial) leaves expense_clearing at +5000.
- This test confirms: `expense_clearing` accumulates COGS at consumption; partial refund leaves the un-refunded portion in expense_clearing.

**Applying this to the cancellation fee:** the cancellation fee (= 1,000) is exactly the un-refunded portion of COGS that should remain in `expense_clearing`. The accounting is CORRECT and CONSISTENT with the existing pattern.

---

## TASK 3 — Reconciled Complete Journal (post-RC-002)

### Scenario recap

- Selling price = 18,000 EGP
- Purchase price = 15,000 EGP
- Cancellation fee = 1,000 EGP
- purchaseNet (credit-back to carrier) = 14,000 EGP
- Customer refund = 17,000 EGP

### Journal entries (from actual log capture)

```
SETUP:
  tx1 — cashbox(2)   → prepaid_carrier(4)             +100000   [recharge]

CREATE BOOKING:
  tx2 — prepaid_carrier(4) → pending_cogs(6)          −15000    [RC-002 NEW]
  tx3 — pending_sales(8)   → customer(1)              −18000    [sale posting]

PAYMENT (full):
  tx4 — customer(1)        → cashbox(5)               −18000    [cash receipt]
  tx5 — pending_cogs(6)    → expense_clearing(7)     +15000    [RC-002 NEW: COGS recognition]

PROCESS REFUND (cancellation_fee=1000):
  tx6 — customer(1)        → pending_sales(8)        +17000    [sale reversal, keeps 1000 fee]
  tx7 — expense_clearing(7)→ prepaid_carrier(4)      −14000    [refundCogs — recognized portion only]
  tx8 — cashbox(5)         → customer(1)             +17000    [cash refund]
```

### Final balance snapshot

| Account                | Initial | After create+pay+refund | Δ (from baseline) | Interpretation |
|------------------------|--------:|----------------------:|-------------------:|----------------|
| cashbox (5)            |       0 |                  1000 |              +1000 | Customer paid 18000, got 17000 back |
| customer_AR (1)        |       0 |                     0 |                 0 | Debt cleared |
| pending_sales (8)      |       0 |                 −1000 |             −1000 | Cancellation fee kept (revenue contra) |
| prepaid_carrier (4)    |  100000 |                 99000 |             −1000 | Carrier kept 1000 penalty |
| pending_cogs (6)       |       0 |                     0 |                 0 | Fully recognised into COGS |
| expense_clearing (7)   |       0 |                  1000 |             +1000 | Carrier penalty absorbed as COGS |
| FlightCarrier.balance  |  100000 |                 99000 |             −1000 | (matches prepaid_carrier GL — in lockstep) |

### Economic reconciliation

The double-entry sum of all Δ:
```
Δcashbox + Δcustomer + Δpending_sales + Δprepaid_carrier + Δpending_cogs + Δexpense_clearing
= +1000 + 0 + (−1000) + (−1000) + 0 + 1000
= 0   ✓  balanced
```

**Cancellation fee is correctly represented as +1,000 in expense_clearing (COGS absorbed) and −1,000 in pending_sales (revenue kept). The two cancel exactly — the wash is properly preserved in the GL.**

### P&L view

```
Revenue (pending_sales + income_clearing reconciliation)  =  −1000  (cancellation fee retained)
COGS (expense_clearing)                                   =  +1000  (carrier penalty absorbed)
Net P&L                                                   =   0   ✓  (wash, as expected)
```

---

## TASK 4 — Test Correctness (Evidence-Based)

### Critical evidence: the test fails on BOTH pre-RC-002 AND post-RC-002 with the IDENTICAL error

```
$ git checkout 9dbc5bf -- app/ config/ tests/Feature/Flight/    # restore pre-RC-002
$ php artisan test --filter=test_refund_to_agency_treasury_reversal_restores_all_balances
  Failed asserting that 15000.0 matches expected 0.0.

$ git checkout afe4913 -- app/ config/ tests/Feature/Flight/FlightRCCogsRegressionTest.php  # restore post-RC-002
$ php artisan test --filter=test_refund_to_agency_treasury_reversal_restores_all_balances
  Failed asserting that 15000.0 matches expected 0.0.
```

**The +15,000 failure is identical on both commits.** RC-001 was already failing on `9dbc5bf` (PHASE D baseline) and continues to fail on `afe4913` (RC-002). My fix did NOT introduce it.

### Where the test's formula breaks

The test's `deltaSum` formula (lines 273–277):

```php
$deltaSum = ($carrierAfter - $before['carrier'])       // +14000 (carrier credited back)
    + ($cashboxAfter - $before['cashbox'])             // −17000 (customer refunded)
    + ($customerBalance - 0.0)                          // 0 (debt cleared)
    + ($clearingBalance - (-$sellingPrice))            // +17000 (revenue reversal leg)
    + $expenseContraDelta;                              // +1000 (see below)
```

**`$expenseContraDelta` is computed as `$expenseContraBalance − 0.0`.**

The test's comment (line 271): `// كان 0 قبل (لم يتأثر بالحجز)` = "was 0 before (not affected by the booking)".

**This baseline assumption is FALSE.** Pre-RC-002, `consumeCogs` posted `prepaid_carrier → expense_clearing` at booking creation for 15,000. Post-RC-002, the recogniser posts `pending_cogs → expense_clearing` at payment for 15,000. In both versions, `expense_clearing` is at +15,000 just before the refund runs.

**The formula should subtract the pre-refund balance, not 0:**

```php
$expenseContraBalanceBeforeRefund = ... +15000
$expenseContraDelta = $expenseContraBalance - $expenseContraBalanceBeforeRefund   // = +1000 - +15000 = −14000
```

With the corrected baseline, the sum becomes:
```
+14000 − 17000 + 0 + 17000 + (−14000) = 0   ✓
```

### Why the test author wrote it this way

The test author's mental model (from the comment "expenseContra.balance بيتخصم منه purchaseNet (14000)") was:
- "refundCogs pulls 14000 OUT of expense_clearing, so expense_clearing.balance changes by −14000."
- They intended to assert that the **change** during the refund was −14000.
- But the formula computes the **net balance** against a 0 baseline, which conflates the pre-booking balance with the in-flight change.

This was a latent bug that happened to "balance" in earlier test designs where expense_clearing happened to be 0 (e.g., if the booking fixture didn't go through `consumeCogs` for some reason). With the canonical flow (createBooking + addPayment + refund), the test's formula is wrong.

### Is production actually broken?

**No.** The journal lifecycle I traced shows that every credit has a matching debit. The cancellation fee is correctly represented on both sides of the ledger. The economic reality (wash) is preserved.

If we look at the test's purpose — assert that a refund-to-agency reversal leaves a coherent ledger — the actual ledger IS coherent. It's the test's invariant formula that is wrong.

---

## TASK 5 — CONCLUSION

### **CONCLUSION B: RC-001 is obsolete test logic after RC-002.**

The post-RC-002 journal is economically correct. The cancellation fee (1,000 EGP) is properly represented as:
- +1,000 in `expense_clearing` (carrier penalty absorbed as COGS)
- −1,000 in `pending_sales_receivable` (cancellation fee kept as revenue contra)
- These cancel each other in a correctly-formulated accounting invariant.

### Evidence supporting this conclusion

1. **The test fails with the same +15,000 error on both `9dbc5bf` (pre-RC-002) and `afe4913` (post-RC-002).** RC-001 is a pre-existing test failure; it was not introduced by RC-002.

2. **The test's `deltaSum` formula uses the wrong baseline for `expense_clearing`.** It assumes a baseline of 0, but `expense_clearing.balance` is +15,000 just before the refund (due to `consumeCogs` at booking creation in the pre-RC-002 code, or `recognizeProportionalFlightCogs` at payment in the post-RC-002 code). With the correct baseline, the formula balances to 0.

3. **The accounting reality matches the canonical pattern** documented in `TourismPAndLComprehensiveTest::test_flight_cogs_flows_from_prepaid_to_expense_clearing` (line 351): COGS is recognised on cash receipt, partial refund leaves the un-refunded portion in `expense_clearing`. The 1,000 cancellation fee is exactly that "un-refunded COGS portion" — it represents the carrier's penalty kept from the prepaid pool.

4. **The double-entry holds across all touched accounts.** Sum of all balance deltas = 0 (verified by walking through the journal manually).

### Required test fix (NOT a production fix)

The test's `$expenseContraDelta` computation must change from:

```php
$expenseContraDelta = $expenseContraBalance - 0.0;
```

to:

```php
$expenseContraBalanceBeforeRefund = ... +15000;   // expense_clearing had +15000 BEFORE the refund (from create+payment)
$expenseContraDelta = $expenseContraBalance - $expenseContraBalanceBeforeRefund;
```

### What this would prove when fixed

With the corrected formula, the test would assert:
- `deltaSum == 0` after `processRefundRequest` (correctly balanced, given the corrected baseline).
- `expense_clearing.balance == +cancellationFee` (i.e., +1,000) — explicitly asserting that the cancellation fee is retained in COGS as the office's absorbed carrier penalty.
- All other assertions (carrier, cashbox, customer, pending_sales) remain unchanged and already pass.

### New regression test suggestion

`tests/Feature/Flight/FlightRC001CogsReversalTest.php` should assert:
1. After `createBooking + addPayment + processRefundRequest`, the **change** in `expense_clearing.balance` equals `−purchaseNet` (i.e., −14,000).
2. After `processRefundRequest`, `expense_clearing.balance` equals `+cancellationFee` (i.e., +1,000).
3. After `reverseRefundRequest`, `expense_clearing.balance` returns to `+fullPurchase` (i.e., +15,000 — back to the post-payment state).
4. The sum of all balance deltas across the complete refund + reversal cycle is 0.

---

## Why This is NOT Conclusion A

A genuine production defect would manifest as:
- A missing ledger entry (e.g., `expense_clearing` not debited at all).
- A mismatched credit/debit pair.
- A phantom balance that doesn't correspond to any economic event.

None of these exist. The 1,000 in `expense_clearing` IS economically meaningful (carrier penalty absorbed by office), and is balanced by −1,000 in `pending_sales_receivable` (cancellation fee retained as revenue). The ledger is internally consistent.

## Why This is NOT Conclusion C

A coordinated accounting redesign is NOT required because:
- The accounting is already correct under the new architecture.
- The test's `deltaSum` formula can be fixed with a one-line change (replacing `0.0` with the pre-refund balance).
- No production code changes are required.
- The pattern is already established in `TourismPAndLComprehensiveTest::test_flight_cogs_flows_from_prepaid_to_expense_clearing` (line 351).

---

## Awaiting Decision

The user has three options:
1. **Apply the test fix** described above (one-line change to `RefundRequestReversalTest`).
2. **Defer** and address RC-001 in a later phase.
3. **Override the conclusion** with additional evidence I have not considered.

Per PHASE F.1 strict rules, NO code changes have been made and NO commits have been created during this investigation.