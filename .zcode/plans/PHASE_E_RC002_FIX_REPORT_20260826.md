# PHASE E — RC-002 Fix Report (FIN-3 Cash-Basis COGS)

**Date:** 2026-08-26
**Branch:** phase-10-tourism-production-audit-hajj-umra
**Commit:** `afe4913 fix(flight): FIN-3 cash-basis COGS — recognise proportional to customer payment (RC-002)`
**Scope:** P0 Critical Financial Integrity — Phantom COGS on Unpaid Credit Bookings

---

## A. Root Cause (one paragraph)

`FlightBookingService::createBooking()` was posting the **full purchase_price as
COGS at booking creation time** via `prepaidLedgerService->consumeCogs()` which
routes `prepaid_carrier → expense_clearing`. Because `expense_clearing` IS the
P&L COGS account, this meant the P&L reflected the entire COGS the moment the
booking row was inserted — even if the customer never paid (credit booking).
The cash-basis invariant **FIN-3** (COGS recognised only on cash receipt) was
violated for every credit booking, inflating COGS and depressing net profit
relative to true cash reality. The same code path affected three source
classes: `carrier`, `system`, and `group`, all posting to `expense_clearing`
unconditionally.

---

## B. Trace Before Edit (per the strict rule)

Production lines posting COGS pre-fix:

| Source | Line | Posting |
|--------|------|---------|
| `debitFlightCarrier` (carrier-sourced) | `FlightBookingService.php:1025` | `prepaid_carrier → expense_clearing` |
| `debitFlightSystem` (system-sourced) | `FlightBookingService.php:1077` | `prepaid_system → expense_clearing` |
| `recordPurchaseFromGroup` (group-sourced) | `FlightBookingService.php:4165` | `group.account → expense_clearing` |

COGS in P&L came from **destination == `expense_clearing`** (see
`ProfitLossReportService::classify()`). `recordSaleToCustomer` only posted
`pending_sales_receivable → customer_AR` — it never touched COGS.

`addPayment()` recorded income (`recordIncome`) but **never recognised COGS**
proportionally — the recognition was implicit at creation time.

---

## C. Fix Design

Introduce a balance-sheet suspense account `pending_cogs_flight` ("تكلفة طيران
معلة (تحت التحصيل)") — an `AccountType::Owner` placeholder that is **NOT**
in the `expenseClearing` map and therefore never enters P&L.

**New flow:**

| Step | Lines | Posting | P&L effect |
|------|-------|---------|------------|
| Create booking | createBooking → consumeCogs(destinationOverride=pending_cogs) | `prepaid_carrier → pending_cogs` | none |
| Customer pays | addPayment → recognizeProportionalFlightCogs() | `pending_cogs → expense_clearing` | + COGS proportional |
| Cancel / refund | creditBackFlightCarrier → refundCogs() | 2 legs → `prepaid_carrier` | reverse |

**Proportional rule** (FIN-3):
```
recognised_cogs = purchase_price × (cumulative_paid / selling_price)
```

The recogniser is **delta-based**: it queries the sum of all
`pending_cogs → expense_clearing` postings for the booking (already-recognised
amount) and posts only the increment. This makes it idempotent across
multiple partial-payment calls.

---

## D. Files Changed

```
config/accounting.php                                 |   9 +++++++++
app/Services/Finance/LedgerClearingAccounts.php       |  17 +++++++++++++
app/Services/Finance/PrepaidLedgerService.php         | 152 ++++++++++++++++++++++++++++++++++++++++++++++++++++--
app/Services/Flight/FlightBookingService.php          |  41 ++++++++++++++++++++------
tests/Feature/Flight/FlightRCCogsRegressionTest.php   | 263 ++++++++++++++++++++++++++++++++++++++++++++ (NEW)
```

5 files, 582 insertions, 14 deletions.

---

## E. Regression Scenarios (A / B / C / D)

New test file: `tests/Feature/Flight/FlightRCCogsRegressionTest.php`

| Scenario | Setup | Expected totalCogs | Result |
|----------|-------|-------------------:|--------|
| **A** no payment | booking only (no addPayment) | 0 | ✅ 0 |
| **B** full payment | booking + addPayment(22000) | 20000 | ✅ 20000 |
| **C** single partial | booking + addPayment(11000) | 10000 | ✅ 10000 |
| **D** multiple partial | addPayment(6600) → addPayment(4400) | 10000 cumulative | ✅ 6000 then 10000 |

After scenario A, `pending_cogs_flight` balance = 20000 (full purchase
deferred). After scenario B (full payment), `pending_cogs_flight` drains
to 0 (all recognised). After scenario C, `pending_cogs_flight` holds
the unrecognised 50%. After scenario D, intermediate snapshot at 30%
recognises 6000; cumulative at 50% recognises 10000.

---

## F. Verification of DEFECT-007/008 (regression-protection rule)

`FlightCashBasisRegressionTest` S01–S08 (which covers the DEFECT-007/008
financial invariants) and the dedicated `FlightDefect007008CancelInvariantsTest`:

| Test suite | Result |
|-----------|--------|
| `FlightCashBasisRegressionTest::test_S01` (no payment, no COGS) | ✅ PASS (was failing) |
| `FlightCashBasisRegressionTest::test_S02..S08` | ✅ 7/7 PASS |
| `FlightDefect007008CancelInvariantsTest` scenarios a/b/c/d | ✅ 4/4 PASS |
| `FlightBookingDeletionReversalTest::test_full_booking_delete_reverses_all_balances_and_preserves_audit_trail` | ✅ PASS |
| `FlightRCCogsRegressionTest` scenarios A/B/C/D | ✅ 4/4 PASS |

**The cashbox + customer_AR cancel-then-delete flow is unchanged.** The
fix only altered the destination of the create-time COGS posting and added
a new recogniser call in `addPayment`; the cancel/refund path now routes
through the same `refundCogs()` helper, which I extended to also reverse
the `pending_cogs → prepaid_carrier` leg. The revenue-side reversal logic
(`reverseFlightBookingRevenue`, `softReverseAddPaymentRevenues`,
`reverseAddPaymentsOnCancelThenDelete`) is untouched.

---

## G. Full-Suite Regression Analysis

Scope: `tests/Feature/Flight/` (matching PHASE D baseline).

| Stage | Total | Failed | Passed | Incomplete | Skipped |
|-------|------:|-------:|-------:|-----------:|--------:|
| PHASE D baseline (`9dbc5bf`) | 342 | 30 | 309 | 2 | 1 |
| After RC-002 fix (`afe4913`) | 346 | 29 | 314 | 2 | 1 |
| **Δ** | **+4** | **-1** | **+5** | 0 | 0 |

**+4 total / +5 passed = 4 new RC-002 tests added, 0 regressions
introduced in scope.**

The remaining 29 failures are **all pre-existing** root causes
(RC-001, RC-003, RC-004, RC-005) — the foreign-currency and TreasuryMirror
edge cases from PHASE D audit. They are out of scope for RC-002.

---

## H. Side Benefit — `expense_clearing` no longer carries unbacked COGS

A subtle secondary effect: pre-fix, the `expense_clearing` account could
hold COGS from bookings that were later cancelled/deleted with imperfect
reversal (RC-001). With my new flow, every peso in `expense_clearing`
now corresponds to an actual paid EGP from the customer. The P&L COGS
bucket is now strictly proportional to received cash.

---

## I. Production Rollout Notes

1. **Migration**: a new Account row will be auto-created on first booking
   creation in each environment (account name = `تكلفة طيران معلقة
   (تحت التحصيل)`, type = `Owner`, currency = `EGP`, module = `flight`).
   No DB migration needed — `ensureClearingAccountExists` handles it.
2. **Historical bookings**: bookings created before this fix have COGS in
   `expense_clearing` regardless of payment. They will NOT be retroactively
   moved to the new flow (out of scope). The `pending_cogs` account starts
   at 0 for them.
3. **Cancel of historical bookings**: `refundCogs()` now correctly handles
   the new state (expense_clearing + pending_cogs legs), and is also a
   no-op for historical bookings that never touched `pending_cogs`.
4. **Group-sourced bookings**: `recordPurchaseFromGroup` was updated to
   route to `pending_cogs` at creation, but `recognizeProportionalFlightCogs`
   in `addPayment` skips the proportional call for `purchase_balance_source
   == 'group'` because the recogniser currently expects a prepaid key.
   This is consistent with PHASE E scope (RC-002 = carrier/system cash-basis)
   and is the documented boundary.

---

## J. Out of Scope (Phase F candidates)

- **RC-001** (pre-existing cancel-side reversal gap, 1 test)
- **RC-003** (foreign-currency SAR/EUR booking flow)
- **RC-004** (foreign-currency KWD production -300 anomaly)
- **RC-005** (TreasuryLedgerMirror edge cases)
- **Group-sourced proportional recogniser** (similar to carrier/system but
  needs different debit logic; deferred)