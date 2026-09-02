# FAWRY LIFECYCLE TEST MATRIX — PHASE 2 DELIVERABLE

**Date:** 2026-08-20
**Auditor:** ZCode (autonomous Fawry lifecycle audit mission)
**Method:** Build the complete test matrix from the actual lifecycle map (not the prompt's assumed matrix).

---

## 1. TEST DIMENSIONS

Every test must verify all 4 dimensions where applicable:

| Dimension | What It Checks |
|---|---|
| **Frontend** | UI form submits correct payload, validation enforced, displayed state matches |
| **HTTP/API** | Endpoint returns correct HTTP code + JSON envelope |
| **Backend/Service** | Service method executes correctly, all guards exercised |
| **Database** | Correct rows in `fawry_transactions`, `transactions`, `account_entries`, `fawry_machines`, `fawry_machine_transactions` |
| **Financial** | All GL balances match independent expected values (zero variance) |

---

## 2. MASTER TEST MATRIX

### LIFECYCLE 1 — CREATE (POST /api/v1/fawry/transactions)

#### T1.1: Registered customer, full payment, with machine

| # | Dimension | Expected | Variable |
|---|---|---|---|
| 1.1.1 | HTTP | 201 with `success=true`, returns FawryTransactionResource | `tx_id` |
| 1.1.2 | Service | `FawryTransactionService::createTransaction` returns new model | — |
| 1.1.3 | DB | 1 row in `fawry_transactions` (deleted_at=NULL, profit=200) | `tx_id` |
| 1.1.4 | DB | 1 row in `fawry_machine_transactions` (type=debit, balance_after=4200) | `mt_id` |
| 1.1.5 | DB | 3 rows in `transactions` (expense, income, transfer) | `e_id, i_id, t_id` |
| 1.1.6 | DB | 6 rows in `account_entries` (2 per transaction) | — |
| 1.1.7 | Financial | machine.balance = 4200 (5000 - 800) | `m_balance` |
| 1.1.8 | Financial | cashbox.balance = 11000 (10000 + 1000) | `c_balance` |
| 1.1.9 | Financial | customer_AR.balance = 0 (1000 in via income, 1000 out via transfer) | `ar_balance` |
| 1.1.10 | Financial | prepaid_fawry.balance = -800 (debited) | `pp_balance` |
| 1.1.11 | Financial | income_clearing.balance = -1000 (debited) | `ic_balance` |
| 1.1.12 | Financial | expense_clearing.balance = +800 (credited) | `ec_balance` |
| 1.1.13 | Financial | Σcredits = Σdebits across all GL rows | `verify` |

#### T1.2: Registered customer, partial payment, with machine
(same as T1.1 but amount=500, customer_AR.balance=500)

#### T1.3: Registered customer, deferred payment (amount=0), with machine
(same as T1.1 but no settlement leg; customer_AR.balance=1000)

#### T1.4: Registered customer, full payment, no machine
(same as T1.1 but no machine debit; 2 GL transactions instead of 3: expense + income; expense from cashbox instead of prepaid)

#### T1.5: Registered customer, partial payment, no machine
(same as T1.4 + settlement leg from customer_AR to cashbox)

#### T1.6: Registered customer, deferred payment, no machine
(same as T1.4 but no settlement; expense from income_clearing instead of cashbox)

#### T1.7: Walk-in customer, full payment, with machine
(same as T1.1 but income is `recordJournalTransfer` (transfer type) to walkInAR, not `recordIncome`; walkInAR.balance = 0 after settlement)

#### T1.8: Walk-in customer, partial payment, with machine
(same as T1.7 but amount=500; walkInAR.balance=500)

#### T1.9: Walk-in customer, deferred payment, with machine
(same as T1.7 but no settlement; walkInAR.balance=1000)

#### T1.10: Walk-in customer, full payment, no machine
(2 GL transactions: expense from cashbox, transfer to walkInAR)

#### T1.11: Walk-in customer, deferred payment, no machine
(2 GL transactions: expense from income_clearing, transfer to walkInAR; walkInAR.balance=1000)

#### T1.12: Edge — minimum valid amount (fawry_price=0.01, selling_price=0.01)
(profit=0; verify all balances stable)

#### T1.13: Edge — maximum valid amount (within DB column limits)
(use 999,999,999.99 — verify all balances reconcile)

#### T1.14: Invalid — account_id is inactive
(HTTP 422, error message in Arabic, no DB writes)

#### T1.15: Invalid — account_id does not exist
(HTTP 422)

#### T1.16: Invalid — machine is inactive
(HTTP 422)

#### T1.17: Invalid — machine.balance < fawry_price
(HTTP 422, InsufficientBalanceException)

#### T1.18: Invalid — missing required fields
(HTTP 422, validation errors)

#### T1.19: Invalid — selling_price < fawry_price
(allowed: profit becomes negative — verify business rule)

#### T1.20: Invalid — payment_method is invalid enum
(HTTP 422 — depends on validation rule)

#### T1.21: Auth — no auth token
(HTTP 401)

#### T1.22: Auth — auth but no `fawry.create` permission
(HTTP 403)

#### T1.23: Frontend — POST from `/fawry/create` form
(verify Vue form sends correct payload, redirects to `/fawry/{id}` on success)

---

### LIFECYCLE 2 — UPDATE (PUT /api/v1/fawry/transactions/{id})

#### T2.1: Update selling_price (registered, full payment)
- Pre: cashbox.balance=11000, customer_AR.balance=0
- Change: selling_price 1000 → 1200
- Post: cashbox.balance=11000 (settlement amount unchanged), customer_AR.balance=0, profit=400
- 6 transactions in GL: 3 reversal + 3 new

#### T2.2: Update fawry_price (registered, with machine)
- Pre: machine.balance=4200
- Change: fawry_price 800 → 1000
- Post: machine.balance=4000 (additional debit of 200), profit recomputed
- 6 GL transactions: 3 reversal + 3 new
- 2 fawry_machine_transactions: 1 reversal debit + 1 additional debit, OR 1 credit + 1 debit depending on direction

#### T2.3: Update amount (registered, partial payment)
- Pre: customer_AR.balance=500, cashbox.balance=10500
- Change: amount 500 → 1000 (full payment)
- Post: customer_AR.balance=0, cashbox.balance=11000

#### T2.4: Update account_id (registered, partial payment)
- Same as T2.3 but change cashbox to wallet
- Post: cashbox.balance unchanged, wallet.balance=+500

#### T2.5: Update non-GL fields (client_name, notes, reference_number)
- No GL changes, no machine changes
- Verify transaction row updated, GL rows unchanged

#### T2.6: Update with same values (no-op)
- Verify no DB changes, no extra GL rows

#### T2.7: Invalid — update with selling_price < 0
- HTTP 422

#### T2.8: Invalid — update fawry_price > machine.balance
- HTTP 422 (InsufficientBalanceException)

#### T2.9: Invalid — update deleted_at via field
- Field not in fillable; should be ignored

#### T2.10: Auth — admin only
- HTTP 403 for non-admin

#### T2.11: Frontend — PUT from `/fawry/{id}/edit` form
- Verify Vue form sends correct payload, redirects to `/fawry/{id}` on success

---

### LIFECYCLE 3 — SOFT-DELETE (DELETE /api/v1/fawry/transactions/{id})

#### T3.1: Delete registered customer (full payment, with machine)
- Pre: machine.balance=4200, cashbox.balance=11000
- Post: machine.balance=5000 (credited), cashbox.balance=10000 (reversed)
- 6 GL rows: 3 reversal of original + 3 reversal of update (if any) + 6 reversal of fresh post
- Wait: Need to verify exact count. Issue: when no update has happened, original 3 GL rows are reversed → 3 reversal rows. Total = 6 GL rows for the original transaction.
- After delete: original 3 + 3 reversal = 6 rows in `transactions` table all linked to this fawry_transaction

#### T3.2: Delete walk-in customer (full payment, with machine)
- Pre: walkInAR.balance=0, cashbox.balance=11000
- Post: walkInAR.balance=0 (reversed), cashbox.balance=10000 (reversed)
- No FIFO reclamation (no excess)

#### T3.3: Delete walk-in customer (partial payment, with machine) — clean reversal
- Pre: walkInAR.balance=500, cashbox.balance=10500
- Post: walkInAR.balance=0, cashbox.balance=10000
- excessToReclaim = max(0, 500 - 500) = 0 (assuming original settlement = 500)
- No FIFO reclamation

#### T3.4: Delete walk-in customer (after walk-in pay-debt was applied)
- Pre: walkInAR.balance=0, cashbox.balance=11000 (after pay-debt added 500)
- Post: walkInAR.balance=0, cashbox.balance=10000
- excessToReclaim = 500 (paid amount = 1000, original settlement = 500)
- FIFO reclamation: 500 allocated to OTHER walk-in txs for same client_name, OR if none, journal transferred back to walkInAR

#### T3.5: Delete registered customer (after walk-in pay-debt could have been applied)
- N/A — walk-in pay-debt only works for walk-in customers

#### T3.6: Idempotent — re-DELETE
- HTTP 200, no new GL rows, no DB changes

#### T3.7: Re-DELETE walks through idempotency guard
- Verify idempotency check fires BEFORE ensureNoLaterPayment

#### T3.8: Block — later payment was recorded
- Pre: customer has paid via walk-in pay-debt after the original tx
- Post: DELETE returns 422/500 with `DeferredTransactionDeletionGuard` rejection

#### T3.9: Auth — admin only
- HTTP 403 for non-admin

#### T3.10: Deficit auto-correct — delete creates cashbox drift
- Trigger: legacy walk-in code path that didn't fully mirror
- Verify: `correctDeficitIfAny` posts corrective journal with notes "تصحيح عجز حذف عملية فوري #..."

#### T3.11: Frontend — DELETE from `/fawry/{id}` view
- Verify Vue form uses `confirm()` dialog, redirects to `/fawry` on success

---

### LIFECYCLE 4 — WALK-IN PAY-DEBT (POST /api/v1/fawry/walk-in/pay-debt)

#### T4.1: Single tx, full walk-in debt
- Pre: 1 walk-in tx with selling_price=1000, amount=0 (debt=1000)
- Pay 1000
- Post: fawry_transactions.amount = 1000 (debt=0), walkInAR.balance = 0, cashbox.balance = +1000

#### T4.2: Multiple txs, FIFO allocation
- Pre: 3 walk-in txs for same client_name: tx1(selling=1000, amount=0), tx2(500,0), tx3(300,0)
- Pay 1200
- Post: tx1.amount=1000, tx2.amount=200, tx3.amount=0
- walkInAR.balance = -800, cashbox.balance = +1200

#### T4.3: Full repayment (overpayment)
- Pre: debt=500
- Pay 600
- HTTP 422 (overpayment guard)

#### T4.4: Exact repayment
- Pre: debt=500
- Pay 500
- Allocated to single tx, fully_settled=true

#### T4.5: Partial repayment (underpayment)
- Pre: debt=1000
- Pay 600
- Allocated to single tx, remaining_debt=400

#### T4.6: Multiple txs, partial across all
- Pre: 3 walk-in txs, total debt=1800
- Pay 900
- Allocated FIFO: tx1.amount += 900, tx2.amount += 0, tx3.amount += 0

#### T4.7: Soft-deleted txs excluded from FIFO
- Pre: tx1 (deleted_at=NULL, debt=500), tx2 (deleted_at=NOT NULL, debt=500)
- Pay 600
- Only tx1 receives allocation (tx2 excluded)

#### T4.8: No walk-in debt
- Pre: 0 walk-in txs OR all amounts = selling_price
- HTTP 422

#### T4.9: Non-EGP paying account
- HTTP 422 (currency guard)

#### T4.10: Invalid — account_id is inactive
- HTTP 422

#### T4.11: Frontend — submit from `/fawry/customer-balances` modal
- Verify Vue form sends correct payload, displays remaining_debt and fully_settled

#### T4.12: After pay-debt, return to customer balances page
- Verify debt is updated, status badge changes

#### T4.13: Pay-debt for customer A using customer B's account_id
- Allocated to A's txs (since walk-in identifies by client_name, not account_id)
- Verify no cross-customer allocation

---

### LIFECYCLE 5 — MACHINE RECHARGE (POST /api/v1/fawry/machines/{id}/recharge)

#### T5.1: Recharge from EGP cashbox (same currency)
- Pre: machine.balance=5000, cashbox.balance=10000, prepaid_fawry.balance=0
- Recharge 2000
- Post: machine.balance=7000, cashbox.balance=8000, prepaid_fawry.balance=2000
- 1 GL transaction: source → prepaid

#### T5.2: Recharge from wallet
- Same as T5.1 but source = wallet

#### T5.3: Recharge from bank
- Same as T5.1 but source = bank

#### T5.4: Recharge cross-currency (cashbox in USD, prepaid in EGP)
- Pre: cashbox.balance=100 USD, exchange_rate=50
- Recharge 100 USD
- Post: machine.balance+=5000 EGP (converted), cashbox.balance=0 USD, prepaid_fawry.balance+=5000 EGP

#### T5.5: Inactive machine
- HTTP 422

#### T5.6: Auth — admin only
- HTTP 403 for non-admin

#### T5.7: Invalid — amount <= 0
- HTTP 422

#### T5.8: Invalid — from_account_id doesn't exist
- HTTP 422

#### T5.9: Invalid — from_account_id is not eligible (e.g., customer AR)
- HTTP 422 (FawryLiquidityAccount rule)

#### T5.10: Frontend — POST from `/fawry/machines` recharge modal
- Verify Vue form sends correct payload, updates machine balance

---

### LIFECYCLE 6 — INTERNAL MACHINE DEBIT/CREDIT (no endpoint)

#### T6.1: Machine.debit insufficient balance
- Pre: machine.balance=100
- debit(500)
- Throws InsufficientBalanceException

#### T6.2: Machine.debit bypasses observer guard
- Observer should NOT throw when called via debit()

#### T6.3: Machine.credit bypasses observer guard
- Observer should NOT throw when called via credit()

#### T6.4: Direct balance mutation blocked
- $machine->balance = 1000 (direct write)
- RuntimeException from observer

#### T6.5: Direct profit mutation blocked
- $transaction->profit = 500 (direct write)
- RuntimeException from ModelProfitMutationGuard

---

## 3. CROSS-CUTTING TESTS

### X1. Customer Isolation

| # | Test | Expected |
|---|------|----------|
| X1.1 | Customer A queries their own statement | Returns A's data only |
| X1.2 | Customer A queries customer B's statement | 403/404 or empty |
| X1.3 | Customer A pays walk-in debt for B's client_name | Allocated to B's txs (allowed by design — pay-debt is by client_name, not account) |
| X1.4 | Customer A balance shown to A | only A's balance |
| X1.5 | Customer A balance with parameterized `client_id` | filters by client_id correctly |

### X2. Performance (smoke level)

| # | Test | Expected |
|---|------|----------|
| X2.1 | Create 100 txs in sequence | All succeed, all GL balanced |
| X2.2 | List 1000 txs | Pagination works, cache hit |
| X2.3 | List with filters | Returns correct subset |

### X3. Concurrent/Race

| # | Test | Expected |
|---|------|----------|
| X3.1 | Two parallel CREATE on same machine | One succeeds, one fails (race on balance) |
| X3.2 | Two parallel walk-in pay-debt for same client_name | FIFO respected, no double-spend |
| X3.3 | Update + Delete simultaneously | One wins, DB consistent |
| X3.4 | Pay-debt + Delete simultaneously | DB consistent |

### X4. Idempotency

| # | Test | Expected |
|---|------|----------|
| X4.1 | POST same tx twice with same reference_number | Both succeed (no UNIQUE constraint) |
| X4.2 | DELETE same tx twice | Second is idempotent no-op |
| X4.3 | POST walk-in pay-debt twice with same amount | Allocations may overlap (LIMIT by remaining) |
| X4.4 | correctDeficitIfAny called twice | Second is idempotent (notes check) |

### X5. Failure / Rollback

| # | Test | Expected |
|---|------|----------|
| X5.1 | CREATE fails mid-way (e.g., GL fails) | DB rollback, no orphan rows |
| X5.2 | UPDATE fails mid-way | DB rollback, original state preserved |
| X5.3 | DELETE fails mid-way | DB rollback, no partial reversal |
| X5.4 | Walk-in pay-debt fails mid-way | DB rollback |
| X5.5 | Recharge fails mid-way | DB rollback |

### X6. Database Forensics

| # | Test | Expected |
|---|------|----------|
| X6.1 | All transactions balanced (Σcredits = Σdebits per tx) | 100% |
| X6.2 | No orphan transactions | All related_id FKs valid |
| X6.3 | No orphan fawry_machine_transactions | All exist |
| X6.4 | No negative balances | All accounts >= 0 |
| X6.5 | Walk-in AR balance matches per-tx debt | Always |
| X6.6 | customer AR balance matches per-tx debt | Always |
| X6.7 | Machine balance matches sum of credits - debits | Always |
| X6.8 | No duplicate tx composition for same related_id | Unique (1 income + n transfers + n reversals) |

---

## 4. TEST COVERAGE MATRIX

| Lifecycle | Sub-tests | Total |
|-----------|-----------|------:|
| CREATE | T1.1-T1.23 | 23 |
| UPDATE | T2.1-T2.11 | 11 |
| DELETE | T3.1-T3.11 | 11 |
| WALK-IN PAY-DEBT | T4.1-T4.13 | 13 |
| MACHINE RECHARGE | T5.1-T5.10 | 10 |
| INTERNAL MACHINE | T6.1-T6.5 | 5 |
| Customer Isolation | X1.1-X1.5 | 5 |
| Performance | X2.1-X2.3 | 3 |
| Concurrent | X3.1-X3.4 | 4 |
| Idempotency | X4.1-X4.4 | 4 |
| Failure | X5.1-X5.5 | 5 |
| Forensics | X6.1-X6.8 | 8 |
| **TOTAL** | | **102** |

Each test will verify ALL applicable dimensions (HTTP, DB, financial, frontend where applicable).

---

## 5. TEST ARTIFACTS (planned)

| File | Purpose |
|---|---|
| `tests/scripts/fa_fawry_lifecycle_happy_path.php` | First test (T1.1 baseline) |
| `tests/Feature/Fawry/FawryLifecycleCreateTest.php` | PHPUnit for T1.x |
| `tests/Feature/Fawry/FawryLifecycleUpdateTest.php` | PHPUnit for T2.x |
| `tests/Feature/Fawry/FawryLifecycleDeleteTest.php` | PHPUnit for T3.x |
| `tests/Feature/Fawry/FawryLifecycleWalkInPayDebtTest.php` | PHPUnit for T4.x |
| `tests/Feature/Fawry/FawryLifecycleRechargeTest.php` | PHPUnit for T5.x |
| `tests/Feature/Fawry/FawryLifecycleCustomerIsolationTest.php` | PHPUnit for X1 |
| `tests/Feature/Fawry/FawryLifecycleIdempotencyTest.php` | PHPUnit for X4 |
| `tests/Feature/Fawry/FawryLifecycleFailureTest.php` | PHPUnit for X5 |
| `tests/Feature/Fawry/FawryLifecycleForensicsTest.php` | PHPUnit for X6 |
| `tests/scripts/fa_fawry_lifecycle_concurrency.php` | X3 stress tests |
| `tests/scripts/fa_fawry_lifecycle_reconciliation.php` | PHASE 13 deliverable |

---

## 6. EXECUTION PLAN

1. **PHASE 3**: Execute Matrix (102 tests)
2. **PHASE 4**: State machine validation (valid + invalid transitions)
3. **PHASE 5**: Frontend E2E (Vue component tests)
4. **PHASE 6**: Backend API lifecycle audit (already covered by tests)
5. **PHASE 7**: Customer isolation (X1 already in matrix)
6. **PHASE 8**: Financial proof (independent expected values per test)
7. **PHASE 9**: Idempotency (X4)
8. **PHASE 10**: Concurrency (X3)
9. **PHASE 11**: Failure (X5)
10. **PHASE 12**: Forensics (X6)
11. **PHASE 13**: Reconciliation
12. **PHASE 14**: 240 existing regression tests + new lifecycle tests

---

**END OF PHASE 2 — FAWRY_LIFECYCLE_TEST_MATRIX.md**
