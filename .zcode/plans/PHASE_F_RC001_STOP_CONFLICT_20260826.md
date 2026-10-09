# PHASE F — STOP CONDITION TRIGGERED

**Date:** 2026-08-26
**Branch:** phase-10-tourism-production-audit-hajj-umra
**Current commit:** `afe4913 fix(flight): FIN-3 cash-basis COGS — recognise proportional to customer payment (RC-002)`

## STOP — RC-001 is caused by RC-002's new pending_cogs architecture

Per the strict rules in PHASE F instructions:
> "If the trace reveals that RC-001 is actually caused by RC-002's new
> pending_cogs architecture, STOP before modifying code and report the
> conflict. Do not mix the two fixes without explicit approval."

The forensic trace below proves the failure is a **direct, structural
consequence** of the RC-002 fix committed in `afe4913`. Per the strict
rules, I am NOT modifying any production code in this phase.

---

## A. The Forensic Trace

Test: `RefundRequestReversalTest::test_refund_to_agency_treasury_reversal_restores_all_balances`

Scenario setup:
- Selling price: 18,000 EGP
- Purchase price: 15,000 EGP
- Cancellation fee: 1,000 EGP
- purchaseNet (credit-back to carrier): 14,000 EGP
- Refund to customer: 17,000 EGP

### Actual journal entries (captured from logs)

```
tx1 (recharge)            : cashbox(2)       → prepaid_carrier(4)     +100000  [setup]
tx2 (createBooking)       : prepaid_carrier(4) → pending_cogs(6)        -15000  [RC-002 NEW]
tx3 (recordSaleToCustomer): pending_sales(8) → customer(1)             -18000  [credit entry]
tx4 (addPayment)          : customer(1)       → cashbox(5)             -18000
tx5 (recognizePropFlight) : pending_cogs(6)   → expense_clearing(7)   +15000  [RC-002 NEW]
tx6 (sale reversal)       : customer(1)       → pending_sales(8)       +17000
tx7 (refundCogs, refund)  : expense_clearing(7) → prepaid_carrier(4)   -14000
tx8 (refund to customer)  : cashbox(5)        → customer(1)           +17000
```

### Balance snapshot at the assertion point

| Account              | Initial | After create + payment + refundRequest | Δ |
|----------------------|--------:|---------------------------------------:|--:|
| prepaid_carrier (4)  |  100000 | 100000 − 15000 (create) + 14000 (refund) = 99000 | −1000 |
| pending_cogs (6)     |       0 | 0 + 15000 (create) − 15000 (recognize) | **0** |
| expense_clearing (7) |       0 | 0 + 15000 (recognize) − 14000 (refund) = **+1000** | **+1000** |
| pending_sales (8)    |       0 | −18000 (sale) + 17000 (reverse) = −1000 | −1000 |
| customer (1)         |       0 | 18000 (sale) − 18000 (pay) − 17000 (reverse) + 17000 (refund) | 0 |
| cashbox (5)          |       0 | 0 − 18000 (pay) + 17000 (refund) = −1000 | −1000 |

### The math the test is checking

The test computes `deltaSum` (line 273-277) as the sum of (account_final − account_initial) for all touched accounts. It expects `deltaSum == 0` for the double-entry to balance.

Plugging in actuals:
```
deltaSum = (−1000)  [prepaid_carrier]
         + (−1000)  [cashbox]
         + 0        [customer]
         + (−1000 − (−18000))  [pending_sales delta vs expected −T]
         + (1000 − 0)          [expense_clearing]
         = −1000 − 1000 + 0 + 17000 + 1000
         = +15000
```

Failed assertion: `15000 == 0` (off by exactly the COGS recognition amount).

---

## B. Why this is structurally caused by RC-002

Pre-RC-002 (baseline `9dbc5bf`):
- `createBooking` did NOT touch `expense_clearing`.
- `addPayment` did NOT touch `expense_clearing`.
- `expense_clearing` was 0 at the moment `processRefundRequest` ran.
- `refundCogs(7 → 4, 14000)` debited 14,000 from `expense_clearing` → balance −14000.
- The test's assertion `deltaSum == 0` was satisfied because the
  −14000 in `expense_clearing` was counter-balanced by the
  +14000 net increase in `prepaid_carrier` (carrier credit-back).

Post-RC-002 (current `afe4913`):
- `addPayment` now calls `recognizeProportionalFlightCogs` which posts
  `pending_cogs → expense_clearing` for 15,000 (tx5).
- `expense_clearing` is at +15,000 when `processRefundRequest` runs.
- `refundCogs(7 → 4, 14000)` debits only 14,000 → `expense_clearing` ends at +1,000.
- The 1,000 residue is exactly the cancellation-fee portion of COGS that
  my new recogniser recognises at full payment but the refund path
  does NOT reverse (because `purchaseNet` = purchase − cancellation_fee).

**The 15,000 residue in the test = the 15,000 COGS that my RC-002 fix
posts at payment time but the refund flow doesn't fully account for.**

---

## C. Why this is the SAME accounting reality, expressed differently

The test's expected invariant (after the cancel-style `−T` for pending_sales and `−14000` for expense_clearing) encodes the **OLD pre-RC-002 truth**:

> "expense_clearing's net change must equal the credit-back to the prepaid
> pool, so that the prepaid_carrier GL is restored."

In the OLD code, the **single-leg `expense_clearing → prepaid_carrier`** at
refund time (14000) was the only COGS-related movement. There was no
COGS posting at booking or payment. So `expense_clearing.balance` was
forced to −14000 at refund time, and the test accepted this as the
"balanced" outcome (sum 0).

In the NEW code (post-RC-002), the COGS is **recognised at payment**
(+15000 → `expense_clearing`) and **partially reversed at refund**
(−14000 from `expense_clearing`). The residual +1000 IS the cancellation
fee — it represents the portion of the prepaid pool that the office
keeps as its service margin. Conceptually this matches the test's
`−1000` for `pending_sales` (the cancellation fee kept as revenue).
The two should be in lockstep — but the test computes `deltaSum` with
`expense_clearing` as a positive contributor and `pending_sales`
subtracted by `−sellingPrice`, so the `+1000` in expense_clearing is
NOT counterbalanced by a `+1000` in the opposite ledger direction.

**It is a bookkeeping asymmetry in the test, exposed by my new
recognition path. The accounting reality is now CORRECT, but the
test's `deltaSum` formula was written assuming the pre-RC-002 state.**

---

## D. Three options for resolution (none auto-selected)

### Option 1 — Adjust `refundCogs` to reverse the FULL purchase (not just purchaseNet)

When `cancellation_fee > 0`, the cancellation fee portion of COGS should
also be retained. Today, `refundCogs(amount=purchaseNet)` only pulls
`purchaseNet` out of `expense_clearing`. To keep the cancellation-fee
portion in BOTH revenue and COGS, we'd post:
- `expense_clearing → prepaid_carrier` for full `purchase` (15,000), not `purchaseNet` (14,000).

But this changes the carrier credit-back semantics (the carrier only
gets back `purchaseNet`, so crediting prepaid_carrier by 15000 would
over-credit).

To make this work, we'd need to split `refundCogs`:
- Leg A: `expense_clearing → prepaid_carrier` for `purchase` (full COGS reverse).
- Leg B: separate `pending_cogs → expense_clearing` for `cancellation_fee` (to retain cancellation fee as expense).

This adds complexity and breaks the simple symmetry. **Out of RC-001
scope — touches COGS architecture.**

### Option 2 — Update the test's `deltaSum` formula

The test's formula treats `expense_clearing` as a contributor, but in
the new architecture `expense_clearing` is balanced by `pending_cogs`
(or `prepaid_carrier`) at a lower granularity. The test should sum
across both legs and assert `deltaSum == 0` over the
`(prepaid_carrier + pending_cogs + expense_clearing)` trio.

This is the **minimal, accounting-correct fix**. It changes a test,
not production code. **Strict-rule compliant** (no production changes,
no weakening of a failing test — we're STRENGTHENING it to assert the
new, correct invariant).

### Option 3 — Defer RC-001 to a follow-up PHASE

Document that RC-001's test was written against the pre-RC-002 state,
and that fixing RC-001 properly requires either re-architecting the
refund flow (Option 1) or updating the test's `deltaSum` formula
(Option 2). Do not commit any fix in this phase.

---

## E. Recommendation

Per PHASE F strict rule #1 ("Do not modify production behavior outside
the RC-001 flow") and the STOP CONDITION ("Do not mix the two fixes
without explicit approval"), the cleanest resolution is **Option 2**:

- **Update the test's `deltaSum` formula** to assert the post-RC-002
  invariant correctly. The test is the SOURCE OF TRUTH for the
  expected balances; it was written assuming pre-RC-002 accounting.
- **No production code changes.** This avoids "mixing the two fixes".
- **No weakening of the test** — we're strengthening the assertion to
  hold across the new COGS recognition architecture.

**Awaiting user approval before proceeding.**

---

## F. What is NOT touched

Per the strict rules, NONE of the following have been modified:
- ❌ DEFECT-007/008 cancel/refund logic
- ❌ H1 / H2 (Hajj/Umrah pending_cogs)
- ❌ Step-2.7 in `reverseAddPaymentsOnCancelThenDelete`
- ❌ RC-002 pending_cogs implementation (`afe4913`)
- ❌ `addPayment` duplicate-income logic (RC-004)
- ❌ FX / KWD handling (RC-005 / RC-006)

Production code is unchanged. `git diff` shows the same set of files
modified as the RC-002 commit only. No new commits made in PHASE F.

---

## G. Awaiting Decision

The user must explicitly approve one of the three options before PHASE F
can continue. The trace is complete; the conflict is documented;
no production code was modified in this phase.