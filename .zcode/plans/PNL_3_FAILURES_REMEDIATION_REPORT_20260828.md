# P&L / Tourism — 3 Failures Remediation Report

**Date:** 2026-08-28
**Branch:** `staging`
**Scope:** Resolve the 3 remaining test failures from the prior P&L/Tourism remediation session.
**Constraint set:** No production refactor · no architectural changes · no transaction-classification changes · no ledger-logic changes · no clearing-account changes · no `@doesNotPerformAssertions` / `skip()` / assertion weakening · no test deletion · no coverage reduction · use current production contracts.

---

## TL;DR

- **Failure #1** is a **REAL production accounting bug** in `FlightBookingService::cancelBooking()` — the cancel flow posts **three independent customer-account credits** for the same economic event. **NOT FIXED** per user directive. Classified as a separate HIGH-severity bug to be addressed in a follow-up task.
- **Failures #2 and #3** are pure **test fixture drift**. Both fixed by removing one now-redundant block in `tests/Feature/TourismAudit/TourismAuditTestCase.php`.

**Result:** 34/35 PASS in the regression scope specified by the user. The single remaining failure (Failure #1) is the production bug the user explicitly excluded from this task.

---

## 1. Test Fixture Fixes (Failures #2 and #3)

### 1.1 File: `tests/Feature/TourismAudit/TourismAuditTestCase.php`

#### Change

The `seedTourismVaults()` method (lines 78–153) used to end with five manual `seedOpeningBalance(...)` calls (lines 148–152):

```php
// Seed opening balances
$this->seedOpeningBalance($this->vaultEgp, 1_000_000.0);
$this->seedOpeningBalance($this->vaultUsd, 100_000.0);
$this->seedOpeningBalance($this->vaultSar, 100_000.0);
$this->seedOpeningBalance($this->bankEgp, 500_000.0);
$this->seedOpeningBalance($this->walletEgp, 200_000.0);
```

These calls were **replaced with a comment block** explaining why they are now removed.

#### Why the calls were wrong

The `Account::created` observer in `app/Models/Account.php` (lines 175–275) was introduced by the FIN-1 fix (commit `05cbbdf fix(finance1)`). The observer auto-creates a paired opening-balance `AccountEntry` on every Account that is created with `balance > 0`:

- One credit entry on the new account for `$balance`.
- One debit entry on the singleton `System Opening Balances` contra account.

The manual `seedOpeningBalance()` method was written before FIN-1 existed. After FIN-1, calling both the auto-seeding observer **and** the manual `seedOpeningBalance()` doubled the credit-side entries on every seeded vault — but left the stored `accounts.balance` at the original value.

This violated the project invariant `balance = SUM(credit) − SUM(debit)` per `assertLedgerGloballyBalanced()` (test file line 228), which computed:

| Account | Stored balance | Σ credits − Σ debits | Result |
|---|---|---|---|
| `Audit Tourism Vault EGP` | 1,000,000 | 2,000,000 | IMBALANCED |
| `Audit Tourism Bank EGP` | 500,000 | 1,000,000 | IMBALANCED |
| `Audit Tourism Wallet EGP` | 200,000 | 400,000 | IMBALANCED |
| `Audit Tourism Vault SAR` | 100,000 | 200,000 | IMBALANCED |
| `Audit Tourism Vault USD` | 100,000 | 200,000 | IMBALANCED |

The exact failure message was:

```
Ledger imbalance: [{"id":4,"name":"Audit Tourism Vault EGP","currency":"EGP",
"expected":2000000,"actual":1000000,"entries":3},
{"id":8,"name":"Audit Tourism Bank EGP","currency":"EGP","expected":1000000,"actual":500000,"entries":3},
{"id":9,"name":"Audit Tourism Wallet EGP","currency":"EGP","expected":400000,"actual":200000,"entries":3},
{"id":7,"name":"Audit Tourism Vault SAR","currency":"SAR","expected":200000,"actual":100000,"entries":3},
{"id":6,"name":"Audit Tourism Vault USD","currency":"USD","expected":200000,"actual":100000,"entries":3}]
```

Every imbalance ratio is **exactly 2×** — i.e. the manual `seedOpeningBalance()` was doubling the auto-seeded credit side.

#### Why this is fixture drift, not a production bug

The auto-seeded observer is the **current production contract** for `Account::created`. Removing the redundant manual calls aligns the test fixture with that contract. No production code was touched.

The `seedOpeningBalance()` method itself is retained (the user said "don't reduce coverage") but is now unused by the audit seed. It can be invoked by other tests that don't rely on FIN-1 auto-seeding.

---

## 2. Failure #1 — Production Accounting Diagnosis (NOT FIXED)

### 2.1 Test

`tests/Feature/Reports/ProfitLossReportTest.php::test_group_booking_records_cogs_and_reduces_profit_in_pl_report`

### 2.2 Failure Message

```
Customer AR must be zeroed after cancellation.
Failed asserting that 22000.0 matches expected 0.0.

at tests/Feature/Reports/ProfitLossReportTest.php:509
```

The test stops at this assertion (line 502), so the subsequent P&L assertions (`totalRevenues`, `totalCogs`, `netProfit` all == 0) are never reached. Based on the transaction trace below, those assertions would PASS for this booking — only the customer-AR balance is wrong.

### 2.3 Test Flow (the test as written is correct accounting)

1. Create a customer, flight system, carrier, and flight group. Group account is auto-seeded with balance = 0.
2. Create a group booking: `purchase_price=20,000`, `selling_price=22,000`, `purchase_balance_source='group'`, `account_id=treasury`. The flight-group purchase is recorded on the group account (TX1, group → cost_clearing, 20,000). The credit-sale leg is recorded on a pending-sales-receivable account (TX2, pending → customer, 22,000). Customer AR = +22,000 (debt).
3. P&L **before** payment: `totalRevenues=0` (cash-basis), `totalCogs=20,000`, `grossProfit=-20,000`, `netProfit=-20,000`. **PASSES** ✓
4. `addPayment(22,000)`: a cash-basis income posting is made (TX3, customer → treasury, 22,000, type='income'). Customer AR = 0.
5. P&L **after** payment: `totalRevenues=22,000`, `totalCogs=20,000`, `grossProfit=2,000`, `netProfit=2,000`. **PASSES** ✓
6. `cancelBooking(airline_penalty=0, office_penalty=0, account_id=treasury)` is called.
7. After cancel, the test asserts:
   - Group account balance = 0. **PASSES** ✓
   - Customer AR balance = 0. **FAILS** � (actual = 22,000).

### 2.4 Full Transaction Trace After Cancel

Six transactions are posted by the cancel flow, plus two mirror `AccountEntry` rows from `TransactionService::reverseTransaction()`. Trace from the diagnostic run on 2026-08-28:

| TX id | type | module | amount | from → to | notes (truncated) | Role |
|---|---|---|---|---|---|---|
| 1 | transfer | flight | 20,000 | group_account(10) → cost_clearing(11) | تكلفة شراء بالأجل — حجز #FLT… | Original group-purchase leg (booking creation) |
| 2 | transfer | flight | 22,000 | pending_sales_receivable(12) → customer(9) | حجز طيران / المسافر: … | Original credit-sale leg (booking creation) |
| 3 | income | flight | 22,000 | customer(9) → treasury(4) | **عكس:** *(originally payment note)* | Original addPayment income — now reversed via `reverseTransaction()` |
| 4 | transfer | flight | 20,000 | cost_clearing(11) → group_account(10) | إلغاء شراء تذكرة طيران (إرجاع رصيد) — حجز #… | **Cancel Step 1:** `reverseGroupPurchase` |
| 5 | transfer | flight | 22,000 | customer(9) → pending_sales_receivable(12) | عكس مبيعات حجز طيران ملغي (مخصوماً منه الغرامات) — حجز #… | **Cancel Step 3:** sale-reversal leg |
| 6 | transfer | flight | 22,000 | treasury(4) → customer(9) | استرداد حجز تذكرة - FLT-… | **Cancel Step 2:** cash refund (treasury → customer) |

### 2.5 Per-Transaction AccountEntry Trace

```
Entry id | tx id | account                              | debit  | credit | balance_after | notes
---------+-------+--------------------------------------+-------+--------+---------------+----------------------------------
1        | NULL  | PL Treasury (cashbox)                | 0     | 100000 | 100000        | Opening balance — auto-seeded …
2        | NULL  | System Opening Balances (owner)      | 100000| 0      | -100000       | Opening balance contra …
3        | 1     | group_account (supplier)             | 20000 | 0      | -20000        |
4        | 1     | cost_clearing_flight (owner)         | 0     | 20000  | 20000         |
5        | 2     | pending_sales_receivable (owner)     | 22000 | 0      | -22000        |
6        | 2     | customer_AR (customer)               | 0     | 22000  | 22000         |
7        | 3     | customer_AR (customer)               | 22000 | 0      | 0             |
8        | 3     | PL Treasury (cashbox)                | 0     | 22000  | 122000        |
9        | 4     | cost_clearing_flight (owner)         | 20000 | 0      | 0             |
10       | 4     | group_account (supplier)             | 0     | 20000  | 0             |
11       | 5     | customer_AR (customer)               | 22000 | 0      | -22000        |
12       | 5     | pending_sales_receivable (owner)     | 0     | 22000  | 0             |
13       | 3     | PL Treasury (cashbox)                | 22000 | 0      | 100000        | عكس القيد #8   ← mirror from reverseTransaction
14       | 3     | customer_AR (customer)               | 0     | 22000  | 0             | عكس القيد #7   ← mirror from reverseTransaction
15       | 6     | PL Treasury (cashbox)                | 22000 | 0      | 78000         |
16       | 6     | customer_AR (customer)               | 0     | 22000  | 22000         |
```

### 2.6 Customer AR Balance Evolution

```
Initial customer AR                          =      0
After TX2 sale leg (+22000)                   = +22000   ← customer owes 22000
After TX3 addPayment (-22000)                 =      0   ← customer paid in full

─── Cancel begins ──────────────────────────

After TX4 group-purchase reverse              =      0   (no customer movement)
After TX5 sale-reversal (-22000)              = -22000   ← wrongly undoes a settled sale
After reverseTransaction mirror on TX3 (+22000)=      0   ← adds debt back, takes cash out
After TX6 cash refund (+22000)                = +22000   ← adds the refund credit
                                              ─────
Final customer AR                             = +22000   ❌ (test expects 0)
```

### 2.7 Where the +22000 Comes From (Three Independent Customer Credits)

For a **fully-paid + fully-refunded** booking, the same economic event — *returning the cash the customer originally paid* — is being credited to the customer THREE times:

1. **TX5 (Cancel Step 3, sale-reversal leg)** — debits customer 22,000 and credits pending_sales_receivable 22,000. Conceptually this reverses the original sale leg (TX2). But the sale was already settled by the addPayment (TX3 brought customer AR back to 0). Reversing it now has no economic meaning for a fully-paid booking; it leaves the customer with a negative balance.

2. **`reverseTransaction` mirror on TX3** — sets TX3.notes to `عكس: …` (the canonical prefix that `ProfitLossReportService::report()` recognises as "reversed") and posts two mirror `AccountEntry` rows on the same `transaction_id`: a debit on treasury (-22,000) and a **credit on customer (+22,000)**. This is the FIN-B fix (`bffc6bf fix(flight): profit reversal lifecycle (FIN-A/B/C/D/E/G/H)`) intended to wipe the revenue recognition in P&L. **The customer credit here represents "the customer no longer paid us the cash" — which is the same cash TX6 then returns.**

3. **TX6 (Cancel Step 2, cash refund)** — debits treasury 22,000 and credits customer 22,000. Conceptually this is the actual physical refund of cash.

Steps (2) and (3) are **economic duplicates**. The mirror in step (2) already moves the cash back to the customer (in accounting terms), and step (3) then moves the same cash back to the customer again (also in accounting terms). They net to a +22,000 customer AR credit that has no matching economic event.

Step (1) is also a duplicate of step (2) for fully-paid bookings: TX5 reverses a debt that no longer exists, and `reverseTransaction` separately handles the income recognition. For partially-paid bookings, step (1) would be the **only** mechanism that clears the unpaid portion of the sale debt — but the current code posts it unconditionally for the full sale amount, which is wrong.

### 2.8 What Each Transaction Is / What It Is For (Independent Economic Justification)

| Transaction | Independent economic meaning? | Required for fully-paid + fully-refunded? |
|---|---|---|
| **TX4** (`reverseGroupPurchase`) — group→cost_clearing 20,000 | Yes — returns the supplier (group) credit that was opened during booking. | YES — without it the group stays at -20,000. |
| **TX5** (`sale-reversal leg`) — customer→pending 22,000 | Partially — needed **only** for the *unpaid* portion of a sale. For a fully-paid sale, the sale leg was already settled by addPayment and reversing it creates a phantom negative balance. | **NO** for fully-paid bookings. **YES** for partially-paid bookings (and only for the unpaid amount). |
| **`reverseTransaction` on TX3** | Yes — cancels the revenue recognition (P&L) and the cash-in-on-payment journal entries in one operation. The cash side (treasury debit, customer credit) is part of the canonical `reverseTransaction` contract (lines 340–361 of `TransactionService.php`). | YES for revenue cancellation, but it returns cash to the customer in addition to the revenue cancellation. |
| **TX6** (cash refund) — treasury→customer 22,000 | Yes — physically disburses cash to the customer via the supplied treasury account. | YES for the actual cash movement. |

### 2.9 Intended Accounting Invariant

For a fully-paid booking that is fully cancelled and fully refunded:

```
Customer AR               = 0   (no debt; cash was already paid in)
Treasury (cashbox)        = original - refund_amount   (cash out)
Revenue                   = 0   (cancelled)
COGS                      = 0   (cancelled)
Net Profit                = 0   (revenue − COGS − expenses)
```

This invariant is **violated** by the current cancel flow for fully-paid bookings: customer AR ends at +22,000.

### 2.10 What Must Be Investigated Before a Production Fix

The three legitimate economic events on the customer side are:

- (A) **Clearing the unpaid portion of the sale debt** — represented today by TX5 (full sale reversal). For fully-paid bookings, the unpaid portion is zero and this should be a no-op. For partially-paid bookings, this should reverse only the unpaid amount.
- (B) **Cancelling revenue recognition in P&L** — represented today by `reverseTransaction` on TX3. The P&L side (notes prefix `عكس:`) is correct and required. The cash side (mirror entries on the same transaction_id) is **also** correct for the addPayment→cashbox leg — but in the current cancel flow, that mirror duplicates the refund (TX6).
- (C) **Physically refunding the cash** — represented today by TX6. This is required for the actual treasury → customer movement.

The choice of which one to "drop" is **non-trivial** and requires a design decision the user has not authorised in this task:

1. **Option A — Skip TX5 when fully paid.** Make the sale-reversal amount equal to the *unpaid* portion rather than the refundable portion. This requires querying the cumulative paid amount from `flight_payments` and only posting the gap. Behaviour for partially-paid bookings is unchanged; behaviour for fully-paid bookings removes the duplicate.
2. **Option B — Skip `reverseTransaction` on TX3 when TX6 covers the same cash.** Conditional on `refund_amount >= sum(payments.amount)`. The revenue-cancellation effect (P&L) would still need a separate mirror — likely a `recordJournalTransfer` of type=Transfer from treasury → income_clearing for the cumulative paid amount, which is the FIN-B comment's actual intent. The current `reverseTransaction` does the mirror but **additionally** writes the cash-back entries that TX6 then duplicates.
3. **Option C — Skip TX6 (cash refund) when `reverseTransaction` already returns the cash.** But `reverseTransaction` returns the cash to the **customer's** AR account (a credit) — the customer AR account, not a separate refund liability. Treating the customer AR credit as "the customer received cash" requires accounting for it in a different way (e.g. by crediting a `refund_payable` liability instead of customer AR). This is a more invasive change to the AR semantics and conflicts with the user's "no architectural changes" constraint.

The user's directive for this task was **Option 1 (do not modify any production financial logic for Failure #1)**. The choice between A/B/C is therefore deferred to a follow-up task.

### 2.11 Recommended Follow-Up Bug

**Title:** `HIGH — Flight cancellation posts duplicate customer AR credits / double-refund`

**Description:** `FlightBookingService::cancelBooking()` posts three independent customer-account credits for the same economic event on fully-paid + fully-refunded bookings, leaving customer AR at +22000 instead of the accounting-correct 0. Introduced by FIN-B (`bffc6bf`) which added `reverseFlightBookingRevenue()` to mirror the addPayment income in addition to the existing sale-reversal (TX5) and cash-refund (TX6) legs.

**Affected code:**
- `app/Services/Flight/FlightBookingService.php` — `cancelBooking()` (Step 3 sale-reversal + Step 2 cash refund) and `reverseFlightBookingRevenue()` (FIN-B mirror entries).
- `app/Services/Finance/TransactionService.php` — `reverseTransaction()` line 287 (mirror entry creation).

**Reproduction:**
- Create flight booking with `purchase_price=20,000`, `selling_price=22,000`, `purchase_balance_source='group'`, `account_id=treasury`.
- Call `addPayment(22,000)` — full payment.
- Call `cancelBooking(airline_penalty=0, office_penalty=0, account_id=treasury)` — full refund.
- Assert `customer_account.balance == 0.0`. **Actual: 22,000.0.**

**Expected behaviour:** Customer AR = 0 after a fully-paid + fully-refunded cancellation. The P&L assertions (revenue, COGS, net profit all == 0) would already pass — the P&L side is correct via the FIN-B `عكس:` prefix; only the customer-AR side is wrong.

---

## 3. Final Test Results

### 3.1 Scope Run

```
php artisan test \
  --filter='ProfitLossReportTest|TourismPnLAndStatementsTest|TourismAuditTestCase|PnlTourismReconciliationTest' \
  --no-coverage
```

### 3.2 Counts

| Metric | Count |
|---|---|
| **PASSED** | 34 |
| **FAILED** | 1 (Failure #1 — production bug, intentionally not fixed) |
| **Total** | 35 |
| **Assertions** | 297 |

### 3.3 Remaining Failure

`Tests\Feature\Reports\ProfitLossReportTest > group booking records cogs and reduces profit in pl report`

- Failure line: 502 (`$customerAccount->balance`)
- Failure message: `Customer AR must be zeroed after cancellation. ... double-refund root cause (Failure #1 — production bug, not fixed in this task).`
- Actual: 22000.0 · Expected: 0.0

All other assertions in this test pass (group account balance, would-have-reached P&L assertions are not reachable due to early PHPUnit stop at first failure, but the per-transaction trace above confirms they would pass).

### 3.4 Failures #2 and #3 Status

| Test | Status |
|---|---|
| `TourismPnLAndStatementsTest::independent_pnl_query_matches_module_breakdown` | ✅ PASS |
| `TourismPnLAndStatementsTest::customer_with_multiple_tourism_modules` | ✅ PASS |

---

## 4. Production Safety Verdict

**No production code was modified in this task.**

The only file changed is the test fixture `tests/Feature/TourismAudit/TourismAuditTestCase.php` (removed 5 now-redundant `seedOpeningBalance()` calls and added an explanatory comment block).

The diagnostic-only test message in `tests/Feature/Reports/ProfitLossReportTest.php::test_group_booking_records_cogs_and_reduces_profit_in_pl_report` was enhanced with a comment block pointing to this report; the assertions were **not** weakened and the test **still fails** at the same line (502). `@doesNotPerformAssertions`, `skip()`, `markTestSkipped()`, and any other assertion-weakening mechanism were not used.

The P&L / Tourism financial logic is **not** closed by this task. The real production double-refund bug identified in Section 2 remains open and requires a separate fix per the design decision tree in §2.10.

---

## 5. Files Touched

| File | Action | Lines |
|---|---|---|
| `tests/Feature/TourismAudit/TourismAuditTestCase.php` | Removed 5 redundant `seedOpeningBalance()` calls in `seedTourismVaults()`; added explanatory FIN-1 comment block. | 145–152 |
| `tests/Feature/Reports/ProfitLossReportTest.php` | Replaced temporary diagnostic dump with a Failure #1 comment block pointing to this report. Failure message text updated to reference the production bug. | 482–505 |

**No production file was modified.**
