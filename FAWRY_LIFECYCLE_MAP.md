# FAWRY LIFECYCLE MAP — PHASE 1 DELIVERABLE

**Date:** 2026-08-20
**Auditor:** ZCode (autonomous Fawry lifecycle audit mission)
**Scope:** Real Fawry module lifecycles as implemented — NOT as the audit prompt assumed
**Method:** Read every Fawry entry point, controller, service, model, observer, route, and migration. Trace every code path. Document the actual state space.

---

## ⚠️ CRITICAL DISCOVERY — MODULE ≠ PAYMENT GATEWAY

The audit prompt's PHASE 2 master matrix assumes a Fawry-style payment gateway with states like `pending`, `paid`, `settled`, `cancelled`, `refunded`, `failed`, callbacks/webhooks, retries, expirations, and refund flows.

**The actual Fawry module in this codebase has NONE of those:**

| Audit Prompt Assumption | Actual Implementation |
|---|---|
| `status` field with `pending/paid/settled/cancelled` | ❌ No `status` column exists on `fawry_transactions` |
| External Fawry payment gateway (callbacks/webhooks) | ❌ No external Fawry gateway is integrated |
| Payment pending → succeeded / failed | ❌ Payments are recorded at create-time; payment = cash in at counter |
| Cancel before/after payment | ❌ DELETE = soft-delete (single path, no state guard) |
| Refund flow (full, partial, etc.) | ❌ No refund endpoint — DELETE is the only "reversal" |
| Settlement retry / duplicate settlement | ❌ Settlement is part of create-time, no retry |
| Callback idempotency | ❌ No callbacks exist |
| Refund greater than paid | ❌ Not applicable; no refund mechanism |

**What the Fawry module ACTUALLY is:**
- A closed-loop internal accounting system that tracks customer cash transactions on prepaid vendor machines (Aman/Fawry/Masary/Momtaz).
- Cashiers enter transactions manually via UI/API/Filament.
- The "Fawry machine" is an internal prepaid balance (vendor-side), not a payment gateway.
- All GL entries are created at the moment of `POST /api/v1/fawry/transactions` — there is no async / deferred / pending state.

**This document maps the ACTUAL lifecycles**, not the assumed ones. The PHASE 2 test matrix (next deliverable) will be built from this reality.

---

## 1. ACTUAL STATE SPACE

### 1.1 Implicit "States" (derived from columns, not from a `status` field)

| Implicit State | Identified By | Existence |
|---|---|---|
| **Active** | `fawry_transactions.deleted_at IS NULL` | ✅ |
| **Soft-deleted (cancelled)** | `fawry_transactions.deleted_at IS NOT NULL` | ✅ |
| **Has unpaid debt** | `selling_price > amount` (for any non-deleted active row) | ✅ |
| **Fully settled** | `selling_price == amount` | ✅ |
| **Overpaid** | `selling_price < amount` (must be impossible by validation but possible by walk-in pay-debt edge cases) | 🟡 |
| **Machine-attached** | `fawry_machine_id IS NOT NULL` | ✅ |
| **Walk-in** | `client_id IS NULL` | ✅ |
| **Registered customer** | `client_id IS NOT NULL` | ✅ |

### 1.2 Account / Machine "States" (related)

| Resource | State | Identified By |
|---|---|---|
| FawryMachine | Active | `is_active = TRUE` |
| FawryMachine | Soft-deleted | `deleted_at IS NOT NULL` |
| FawryOperationType | Active | `is_active = TRUE`, `deleted_at IS NULL` |
| FawryPaymentMethod | Active | `is_active = TRUE`, `deleted_at IS NULL` |
| FawryCurrency | Active | `is_active = TRUE` |
| Cashbox/Wallet/Bank | Active | `is_active = TRUE` |
| Customer | Has Fawry account | `customers.account_id IS NOT NULL` and `accounts.module_type = 'fawry'` |

### 1.3 Transition Graph (real)

```
                         ┌──────────────────────────────────────────────┐
                         │                                              │
                         │  (1) CREATE POST /api/v1/fawry/transactions  │
                         │       Validates account + machine active     │
                         │       Posts Expense + Income + Settlement   │
                         │       Debits machine balance                 │
                         │                                              │
                         ▼                                              │
              ┌────────────────────────┐                                 │
              │                        │                                 │
              │      ACTIVE            │                                 │
              │  deleted_at = NULL     │                                 │
              │  debt = selling_price  │                                 │
              │        − amount        │                                 │
              │  GL entries posted     │                                 │
              │  machine debited       │                                 │
              │                        │                                 │
              └──┬──────────┬──────────┘                                 │
                 │          │                                            │
   (2) Update    │          │  (4) Walk-in pay-debt                     │
   PUT/PATCH     │          │  POST /api/v1/fawry/walk-in/pay-debt     │
                 │          │  (walk-in only; FIFO allocation)          │
                 │          │  increments amount column                 │
                 ▼          ▼                                            │
        ┌─────────────────────────┐                                      │
        │                         │                                      │
        │  ACTIVE - mutated       │                                      │
        │  (updated columns)      │                                      │
        │  GL may be reposted     │                                      │
        │  if selling_price,      │                                      │
        │  fawry_price, amount,   │                                      │
        │  or account_id changed  │                                      │
        │                         │                                      │
        └──┬──────────────────────┘                                      │
           │                                                             │
   (3) DELETE │                                                          │
   (soft-del) │                                                          │
              ▼                                                           │
   ┌────────────────────────┐                                             │
   │                        │                                             │
   │  SOFT-DELETED          │ (5) Retry DELETE                           │
   │  deleted_at = NOW()    │ → idempotent no-op                        │
   │  GL entries reversed   │                                             │
   │  machine credited back │                                             │
   │  deficit auto-corrected│                                             │
   │                        │                                             │
   └────────────────────────┘                                             │
                                                                            │
                         ┌──────────────────────────────────────────────┘
                         │ MACHINE RECHARGE (orthogonal):
                         │ (6) POST /api/v1/fawry/machines/{id}/recharge
                         │     GL: source → prepaid(fawry)
                         │     Machine.balance += amount
                         │     (No link to any fawry_transaction)
                         └──────────────────────────────────────────────┘
```

---

## 2. ENTRY POINTS (the only ways to interact with Fawry)

### 2.1 API Endpoints (20 total — all under `/api/v1/fawry`)

| # | Method | Path | Mutates State? | Auth |
|---|--------|------|:---:|---|
| 1 | GET | `/api/v1/fawry/dashboard` | ❌ | sanctum |
| 2 | POST | `/api/v1/fawry/transactions` | ✅ Create | sanctum + `fawry.create` |
| 3 | GET | `/api/v1/fawry/transactions` | ❌ | sanctum |
| 4 | GET | `/api/v1/fawry/transactions/{id}` | ❌ | sanctum |
| 5 | PUT | `/api/v1/fawry/transactions/{id}` | ✅ Update | sanctum + admin |
| 6 | DELETE | `/api/v1/fawry/transactions/{id}` | ✅ Soft-delete | sanctum + admin |
| 7 | GET | `/api/v1/fawry/transactions/daily-summary` | ❌ | sanctum |
| 8 | GET | `/api/v1/fawry/customer-balances` | ❌ | sanctum |
| 9 | GET | `/api/v1/fawry/customer-statement` | ❌ | sanctum |
| 10 | POST | `/api/v1/fawry/walk-in/pay-debt` | ✅ Walk-in pay-debt | sanctum + `fawry.create` |
| 11 | GET | `/api/v1/fawry/machines` | ❌ | sanctum |
| 12 | GET | `/api/v1/fawry/machines/{id}/transactions` | ❌ | sanctum |
| 13 | POST | `/api/v1/fawry/machines/{id}/recharge` | ✅ Recharge | sanctum + admin |
| 14 | GET | `/api/v1/fawry/accounts` | ❌ | sanctum |
| 15 | GET | `/api/v1/fawry/treasury/overview` | ❌ | sanctum |
| 16 | GET | `/api/v1/fawry/treasury/accounts/{account}/transactions` | ❌ | sanctum |
| 17 | GET | `/api/v1/fawry/settings/operation-types` | ❌ | sanctum |
| 18 | GET | `/api/v1/fawry/settings/payment-methods` | ❌ | sanctum |
| 19 | GET | `/api/v1/fawry/settings/currencies` | ❌ | sanctum |
| 20 | GET | `/api/v1/fawry/settings/all` | ❌ | sanctum |

### 2.2 Filament Admin Routes (8 resources)

| # | Resource | Mutations |
|---|----------|-----------|
| 1 | `/admin/fawry-operation-types` | CRUD |
| 2 | `/admin/fawry-payment-methods` | CRUD |
| 3 | `/admin/fawry-currencies` | CRUD |
| 4 | `/admin/fawry-machines` | CRUD (balance locked at update) |
| 5 | `/admin/fawry-transactions` | Inline edit + Delete (via service) |
| 6 | `/admin/fawry-banks` | CRUD (Account-backed) |
| 7 | `/admin/fawry-wallets` | CRUD (Account-backed) |
| 8 | `/admin/fawry-cashboxes` | CRUD (Account-backed) |

### 2.3 External Triggers

**NONE.** Zero webhooks, callbacks, scheduled jobs, queue jobs, events, notifications, console commands, or external HTTP calls related to Fawry.

---

## 3. LIFECYCLE 1 — CREATE (POST /api/v1/fawry/transactions)

### 3.1 Entry Point

```
POST /api/v1/fawry/transactions
Auth: sanctum + permission:fawry.create
Body: { client_id?, client_name, operation_type, client_amount, fawry_price,
        selling_price, amount, employee_id, account_id, payment_method,
        fawry_machine_id?, reference_number?, notes?, currency_id?, payment_details? }
```

### 3.2 Code Path

```
HTTP                     Service                         DB / GL
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Request
  ↓
StoreFawryTransactionRequest
  ↓ (validation rules)
FawryTransactionController::store
  ↓
FawryTransactionService::createTransaction
  ↓
  ┌──────────────────────────────────────────────────────────────┐
  │ Step 1: Validate account_id exists and is_active             │
  │ Step 2: BEGIN DB::transaction                              │
  │ Step 3: Account::lockForUpdate + FawryMachine::lockForUpdate │
  │ Step 4: If machine: check is_active + balance >= fawry_price│
  │ Step 5: Compute profit = selling_price - fawry_price        │
  │ Step 6: FawryTransaction::create (wrapped in                │
  │         runProfitMutation to bypass observer guard)         │
  │   → static::creating observer auto-sets profit if empty     │
  │ Step 7: If machine: machine->debit(fawry_price)             │
  │   → Writes fawry_machine_transactions row                    │
  │   → fawry_machines.balance -= fawry_price (no GL)            │
  │ Step 8: postLedgerEntries()                                 │
  │   8a. Expense: recordExpense(fawry_price, from, module=fawry)│
  │   8b. If client_id:                                          │
  │       recordIncome(selling_price, to=customerAR, module=fawry)│
  │       if amount > 0:                                         │
  │         recordJournalTransfer(amount, customerAR → cashbox)   │
  │   8c. Else (walk-in):                                        │
  │       recordJournalTransfer(selling_price,                   │
  │         incomeContra → walkInAR)                             │
  │       if amount > 0:                                         │
  │         recordJournalTransfer(amount, walkInAR → cashbox)    │
  │ Step 9: Income/expense TX IDs persisted to fawry_transactions│
  │ Step 10: COMMIT                                             │
  └──────────────────────────────────────────────────────────────┘
  ↓
Return FawryTransactionResource (JSON envelope)
```

### 3.3 Side Effects

| Target | Mutation |
|---|---|
| `fawry_transactions` | INSERT row (selling_price, fawry_price, amount, profit computed, FKs set) |
| `fawry_machines.balance` | DECREMENT by fawry_price (if machine attached) |
| `fawry_machines.balance` observer | Bypassed via `mutateBalanceInternal` flag |
| `fawry_machine_transactions` | INSERT row (type=debit, balance_before, balance_after) |
| `transactions` (GL) | INSERT 1 expense + 1 income/transfer (registered) or 0 expense + 1 transfer (walk-in if no payment) or 1 expense + 1 transfer (walk-in if paid) or 1 expense + 1 income + 1 transfer (registered) |
| `account_entries` | INSERT 2 per GL tx (debit + credit) |
| `customers.account_id` | LAZY CREATE if missing (via `ensureCustomerAccount`) |
| `accounts.balance` (all GL targets) | Auto-recomputed via `account_entries` aggregates |
| Cache `fawry_transactions` | Auto-cleared via `ClearsCache` trait |

### 3.4 Final State

- `fawry_transactions.deleted_at = NULL` (active)
- `fawry_transactions.amount` = on-the-spot cash received
- `fawry_transactions.profit` = `selling_price - fawry_price`
- `fawry_machines.balance` (if attached) = pre - fawry_price
- All GL accounts balanced (Σcredits = Σdebits per transaction)

---

## 4. LIFECYCLE 2 — UPDATE (PUT/PATCH /api/v1/fawry/transactions/{id})

### 4.1 Entry Point

```
PUT /api/v1/fawry/transactions/{id}
Auth: sanctum + admin
Body: { client_name?, operation_type?, fawry_price?, selling_price?, amount?,
        account_id?, payment_method?, reference_number?, notes? }
```

### 4.2 Code Path

```
FawryTransactionController::update
  ↓
FawryTransactionService::updateTransaction
  ↓
  ┌──────────────────────────────────────────────────────────────────┐
  │ Step 1: compute diff flags (sellingChanged, fawryPriceChanged,   │
  │         amountChanged, accountChanged)                           │
  │ Step 2: capture oldFawryPrice, oldMachineId BEFORE mutation     │
  │ Step 3: if price changed → recompute profit                     │
  │ Step 4: runProfitMutation { $transaction->update($data) }       │
  │ Step 5: if any GL-affecting field changed:                      │
  │   5a. Query all linked Transactions (related_type, related_id)   │
  │   5b. For each (reverse-chronological): reverseTransaction()    │
  │       → adds mirrored AccountEntry rows with notes='عكس القيد #'│
  │   5c. Re-run postLedgerEntries() with updated values            │
  │   5d. Update income_transaction_id, expense_transaction_id      │
  │ Step 6: if fawryPriceChanged AND machine unchanged:             │
  │   diff = new - old                                              │
  │   if diff > 0: machine->debit(diff)                             │
  │   if diff < 0: machine->credit(-diff)                           │
  │ Step 7: COMMIT                                                  │
  └──────────────────────────────────────────────────────────────────┘
```

### 4.3 Side Effects

| Target | Mutation |
|---|---|
| `fawry_transactions` | UPDATE row (only the supplied fields) |
| `transactions` (GL) | If GL-affecting: REVERSE old rows + REPOST new rows |
| `account_entries` | If GL-affecting: 2 reversal rows + 2 new rows per old/new tx |
| `fawry_machines.balance` | If `fawry_price` changed on same machine: +/- diff |
| `fawry_machine_transactions` | If price changed: INSERT credit/debit row |

### 4.4 Final State

- `fawry_transactions.deleted_at` unchanged
- `fawry_transactions.profit` recomputed if price changed
- GL accounts balanced (reversals + new posts net to zero, then new values apply)
- Machine balance reconciled (vendor-side and GL stay in sync)

---

## 5. LIFECYCLE 3 — SOFT-DELETE (DELETE /api/v1/fawry/transactions/{id})

### 5.1 Entry Point

```
DELETE /api/v1/fawry/transactions/{id}
Auth: sanctum + admin
Body: (none)
```

### 5.2 Code Path

```
FawryTransactionController::destroy
  ↓
FawryTransactionService::deleteTransaction
  ↓
Idempotency check: DB::table('fawry_transactions')->whereNotNull('deleted_at')
  if already deleted → return true (no-op)
  ↓
  ┌──────────────────────────────────────────────────────────────────┐
  │ Step 1: $transaction = $transaction->fresh()                  │
  │ Step 2: DeferredTransactionDeletionGuard::ensureNoLaterPayment │
  │         (throws if any later payment was recorded)               │
  │ Step 3: Capture $settlementBalanceBefore                       │
  │ Step 4: Compute $originalSettlementAmount (sum credits from GL)│
  │         + $excessToReclaim = max(0, paidAmount - original)      │
  │ Step 5: If machine attached: machine->credit(fawry_price)      │
  │ Step 6: Reverse ALL linked transactions (reverse-chronological) │
  │         via TransactionService::reverseTransaction()           │
  │ Step 7: If walk-in && excessToReclaim > 0.005:                │
  │   7a. FIFO re-allocate excess to other walk-in txs for same client_name
  │   7b. If remaining > 0.005: recordJournalTransfer(remaining,
  │       settlementAccountId → walkInAR) (cash back to AR)        │
  │   7c. set amount = 0 on deleted tx                            │
  │ Step 8: $transaction->delete() (soft-delete: deleted_at = NOW()) │
  │ Step 9: correctDeficitIfAny() — auto-correct cashbox drift     │
  │ Step 10: COMMIT                                                │
  │ Step 11: Cache flush (accounts, dashboard, fawry_transactions)  │
  └──────────────────────────────────────────────────────────────────┘
```

### 5.3 Side Effects

| Target | Mutation |
|---|---|
| `fawry_transactions` | UPDATE deleted_at = NOW() (soft-delete) |
| `fawry_transactions.amount` (walk-in only) | UPDATE amount = 0 (if excess was reclaimed) |
| `fawry_machines.balance` | INCREMENT by fawry_price (vendor-side restoration) |
| `fawry_machine_transactions` | INSERT credit row |
| `transactions` | REVERSE all linked (additive, never destructive) |
| `account_entries` | INSERT reversal rows (mirrored debit/credit) |
| `fawry_transactions.amount` (FIFO targets) | UPDATE amount += allocation (walk-in only) |
| Cashbox (settlement account) | May have auto-corrective journal transfer if drift > 0.01 |

### 5.4 Final State

- `fawry_transactions.deleted_at` = current timestamp
- Machine balance restored (if applicable)
- GL accounts balanced (reversals cancel originals)
- No status field change (the soft-delete IS the cancellation marker)

---

## 6. LIFECYCLE 4 — WALK-IN PAY-DEBT (POST /api/v1/fawry/walk-in/pay-debt)

### 6.1 Entry Point

```
POST /api/v1/fawry/walk-in/pay-debt
Auth: sanctum + permission:fawry.create
Body: { client_name, amount, account_id, notes? }
```

### 6.2 Code Path

```
FawryWalkInPaymentController::payDebt
  ↓
  ┌──────────────────────────────────────────────────────────────────┐
  │ Step 1: validate { client_name, amount > 0, account_id exists, │
  │         EGP only }                                              │
  │ Step 2: BEGIN DB::transaction                                 │
  │ Step 3: lockForUpdate { walkInAR, paying_account }             │
  │ Step 4: compute current debt = SUM(selling_price - amount)      │
  │         over walk-in rows for client_name                       │
  │ Step 5: if debt <= 0.005 → throw "no debt to settle"           │
  │ Step 6: if amount > debt + 0.005 → throw "overpayment"        │
  │ Step 7: FIFO allocation                                       │
  │   for each (tx, oldest first, where selling_price > amount):   │
  │     allocate = min(remaining, tx_debt)                          │
  │     update fawry_transactions.amount += allocate               │
  │     remaining -= allocate                                       │
  │ Step 8: ONE aggregate recordJournalTransfer(amount,             │
  │         walkInAR → paying_account, related_id=NULL)            │
  │ Step 9: COMMIT                                                  │
  └──────────────────────────────────────────────────────────────────┘
```

### 6.3 Side Effects

| Target | Mutation |
|---|---|
| `fawry_transactions` (FIFO targets) | UPDATE amount += allocated |
| `transactions` (GL) | INSERT 1 row (type=transfer, walkInAR → cashbox) |
| `account_entries` | INSERT 2 rows (debit walkInAR, credit cashbox) |
| `walkInAR` (account balance) | DECREMENT by amount (debt reduced) |
| `cashbox` (account balance) | INCREMENT by amount (cash received) |

### 6.4 Final State

- `fawry_transactions` rows for client_name: total `amount` increased by total paid
- `fawry_transactions.deleted_at` unchanged (still active)
- Walk-in AR balance reduced by payment amount
- Cashbox balance increased by payment amount
- GL balanced (debit AR = credit cashbox, both = `amount`)

---

## 7. LIFECYCLE 5 — MACHINE RECHARGE (POST /api/v1/fawry/machines/{id}/recharge)

### 7.1 Entry Point

```
POST /api/v1/fawry/machines/{id}/recharge
Auth: sanctum + admin
Body: { from_account_id, amount, notes? }
```

### 7.2 Code Path

```
FawryMachineApiController::recharge
  ↓
FawryMachineRechargeService::rechargeFromAccount
  ↓
  ┌──────────────────────────────────────────────────────────────────┐
  │ Step 1: validate { from_account_id exists, amount > 0 }         │
  │ Step 2: BEGIN DB::transaction                                 │
  │ Step 3: check machine.is_active = true                         │
  │ Step 4: lockForUpdate { machine, source_account }              │
  │ Step 5: PrepaidLedgerService::recharge('fawry', ...)          │
  │   → recordJournalTransfer(amount, source → prepaidAccountId,  │
  │     type=transfer, related=FawryMachine, related_id=machine.id)│
  │ Step 6: machine->credit(machineCreditAmount, ...)             │
  │   (Cross-currency: uses posted EGP credit value, not source)    │
  │ Step 7: COMMIT                                                  │
  └──────────────────────────────────────────────────────────────────┘
```

### 7.3 Side Effects

| Target | Mutation |
|---|---|
| `fawry_machines.balance` | INCREMENT by amount (vendor-side) |
| `fawry_machine_transactions` | INSERT credit row |
| `transactions` (GL) | INSERT 1 row (type=transfer, source → prepaid(fawry)) |
| `account_entries` | INSERT 2 rows (debit source, credit prepaid) |
| `source_account.balance` | DECREMENT by source amount |
| `prepaid_fawry.balance` | INCREMENT by EGP-equivalent amount |

### 7.4 Final State

- Machine balance top-up completed
- Prepaid Fawry account credited (encumbered for future machine sales)
- Source account debited (cash moved out)
- GL balanced

---

## 8. LIFECYCLE 6 — INTERNAL MACHINE DEBIT/CREDIT (triggers only)

### 8.1 FawryMachine::debit(amount, ...)

- **Triggered by**: `createTransaction` (sale), `updateTransaction` (price diff up)
- **Guard**: `balance >= amount` (else throws InsufficientBalanceException)
- **Side effects**: `fawry_machines.balance -= amount`, INSERT `fawry_machine_transactions` (debit row)
- **No GL impact** (vendor-side only)

### 8.2 FawryMachine::credit(amount, ...)

- **Triggered by**: `rechargeFromAccount` (top-up), `updateTransaction` (price diff down), `deleteTransaction` (refund)
- **Guard**: none (model observer blocks direct writes)
- **Side effects**: `fawry_machines.balance += amount`, INSERT `fawry_machine_transactions` (credit row)
- **No GL impact** (vendor-side only)

---

## 9. STATE TRANSITIONS (MACHINE)

### 9.1 Valid Transitions

| From | To | Trigger | Allowed? |
|------|-----|---------|:--------:|
| (no row) | Active | `FawryTransaction::create` | ✅ |
| Active | Active | `FawryTransaction::update` | ✅ |
| Active | Active (cashflow) | `walk-in/pay-debt` (FIFO allocation) | ✅ |
| Active | Soft-deleted | `FawryTransaction::delete` | ✅ |
| Soft-deleted | Soft-deleted | Re-DELETE | ✅ (idempotent no-op) |
| Machine, balance=0 | Machine, balance=P | `recharge` | ✅ |
| Machine, balance=P | Machine, balance=P−X | `debit` (sale) | ✅ |
| Machine, balance=P−X | Machine, balance=P | `credit` (refund via delete) | ✅ |

### 9.2 Invalid Transitions (attempted but rejected)

| Attempted | Outcome |
|---|---|
| DELETE on already-soft-deleted | Idempotent no-op (returns 200) |
| DELETE on tx with later payment | `DeferredTransactionDeletionGuard` throws `RuntimeException` |
| CREATE with machine.balance < fawry_price | `InsufficientBalanceException` |
| CREATE with account inactive | `InvalidArgumentException` |
| CREATE with machine inactive | `InvalidArgumentException` |
| Walk-in pay-debt with amount > debt + 0.005 | `InvalidArgumentException` (overpayment) |
| Walk-in pay-debt with amount = 0 or no debt | `InvalidArgumentException` |
| Walk-in pay-debt with non-EGP account | `InvalidArgumentException` |
| Direct profit mutation (Filament, etc.) | `RuntimeException` (ModelProfitMutationGuard) |
| Direct machine balance mutation | `RuntimeException` (model observer guard) |

### 9.3 No-Op Transitions (designed validations)

| Action | Outcome |
|---|---|
| UPDATE with same values | `anyLedgerAffectingChange = false` → no GL repost, no machine rebalance |
| UPDATE without GL-affecting fields | No reverse, no repost, just column update |
| Walk-in pay-debt with amount covering multiple txs | Single aggregate journal entry; multiple FIFO updates |

---

## 10. COVERAGE GAPS — Where the Audit Prompt Assumes Things That Don't Exist

### 10.1 Lifecycle Gaps (NOT supported by the module)

| Audit Prompt Requirement | Module Reality | Audit Decision |
|---|---|---|
| Cancel before payment | No "pending" state; payment happens at create | Mark N/A |
| Cancel after payment | N/A — same reason | Mark N/A |
| Cancel after settlement | Same | Mark N/A |
| Duplicate cancellation | Re-DELETE is idempotent no-op | Test idempotency |
| Cancellation retry | Same | Test idempotency |
| Unauthorized cancellation | Auth-gated to admin | Test auth |
| Full refund | No refund endpoint; only DELETE | Test DELETE = full reversal |
| Partial refund | Not supported | Mark N/A — verify no partial path exists |
| Refund after settlement | N/A — same | Mark N/A |
| Refund twice | Same | Mark N/A |
| Refund greater than paid | N/A — same | Mark N/A |
| Reversal retry | Same as DELETE retry | Test idempotency |
| Duplicate reversal | Same | Test idempotency |
| Webhook callback | No callbacks exist | Skip |
| Callback idempotency | N/A | Skip |
| Callback after timeout | N/A | Skip |
| Callback after refund | N/A | Skip |
| Callback with mismatched amount | N/A | Skip |
| Successful payment | No payment state | Mark N/A — recorded at create |
| Pending payment | N/A | Skip |
| Failed payment | N/A | Skip |
| Expired payment | N/A | Skip |
| Retry payment | N/A | Skip |
| Repeated payment | Walk-in pay-debt is the closest | Test pay-debt idempotency |
| Duplicate settlement | Same | Test idempotency |
| Settlement after failure | N/A | Skip |
| Settlement after cancellation | Walk-in paying after DELETE? | Test (debt should be 0) |
| Successful completion | N/A | N/A |
| Failed completion | N/A | N/A |
| Repeated completion | N/A | N/A |
| Completion after invalid state | N/A | N/A |

### 10.2 Unsupported Features (documented design decisions)

These are explicit design decisions, NOT defects:

1. **No refund endpoint** — Cancellation = DELETE (soft-delete + additive ledger reverse).
2. **Duplicate `reference_number` allowed** — No UNIQUE constraint.
3. **No restore-from-soft-delete** — Additive reversal pattern means originals are never destroyed.
4. **Walk-in pay-debt uses FIFO allocation with NO per-`related_id` link** — Single aggregate journal entry.
5. **Idempotent DELETE returns HTTP 200** — Service-level guard returns true.

### 10.3 Real Lifecycles That MUST Be Tested

✅ **MUST test** (every transition must be verified):

1. CREATE — registered customer (full + partial + deferred payment)
2. CREATE — walk-in (full + partial + deferred payment)
3. CREATE — with machine (all 6 sub-cases)
4. CREATE — without machine (all 6 sub-cases)
5. CREATE — validation failures (inactive account, inactive machine, insufficient balance)
6. UPDATE — change selling_price (GL + profit recompute)
7. UPDATE — change fawry_price (GL + machine rebalance)
8. UPDATE — change amount (GL repost)
9. UPDATE — change account_id (GL repost)
10. UPDATE — change non-GL fields (no GL repost)
11. UPDATE — same values (no-op)
12. UPDATE — admin-only (auth)
13. DELETE — registered customer (full reversal + machine restoration)
14. DELETE — walk-in with full payment (FIFO reclamation if excess)
15. DELETE — walk-in with deferred payment (no FIFO)
16. DELETE — idempotent (re-DELETE is no-op)
17. DELETE — guard against later-payment (DeferredTransactionDeletionGuard)
18. DELETE — admin-only (auth)
19. WALK-IN PAY-DEBT — same client multiple txs (FIFO)
20. WALK-IN PAY-DEBT — overpayment rejection
21. WALK-IN PAY-DEBT — underpayment (partial)
22. WALK-IN PAY-DEBT — non-EGP rejection
23. WALK-IN PAY-DEBT — no debt rejection
24. WALK-IN PAY-DEBT — soft-deleted txs excluded from FIFO
25. MACHINE RECHARGE — same currency
26. MACHINE RECHARGE — cross-currency
27. MACHINE RECHARGE — admin-only (auth)
28. MACHINE RECHARGE — inactive machine rejection
29. CUSTOMER ISOLATION — Customer A cannot pay Customer B's debt
30. REVENUE RECONCILIATION — every GL movement tracked and matched

---

## 11. FIRST EXECUTABLE LIFECYCLE TEST

The first test to execute is the **core CREATE → UPDATE → DELETE happy path** for a registered customer. This forms the baseline for all subsequent tests.

### First Test: `FawryLifecycle_HappyPath_RegisteredCustomer`

**Purpose:** Verify the complete lifecycle of a registered-customer Fawry transaction: create with full payment, update selling price, then soft-delete.

**Steps:**

1. **Setup:**
   - Create an active EGP cashbox with balance 10,000
   - Create an active Fawry machine with balance 5,000
   - Create a customer with no existing account
   - Authenticate as admin

2. **CREATE — POST /api/v1/fawry/transactions:**
   - client_id = customer.id, client_name = customer.full_name
   - operation_type = "bill_payment"
   - fawry_price = 800, selling_price = 1000, amount = 1000 (full payment)
   - account_id = cashbox.id, fawry_machine_id = machine.id, payment_method = "cash"
   - **Verify:** HTTP 201, response contains id
   - **Verify:** customer.account_id is now set, module_type = 'fawry'
   - **Verify:** machine.balance = 4200 (5000 - 800)
   - **Verify:** cashbox.balance = 11000 (10000 + 1000)
   - **Verify:** 3 transactions in GL: 1 expense (800), 1 income (1000), 1 transfer (1000)
   - **Verify:** customer.account.balance = 0 (1000 in via income, 1000 out via transfer)
   - **Verify:** fawry_transactions.deleted_at = NULL

3. **UPDATE — PUT /api/v1/fawry/transactions/{id}:**
   - selling_price = 1200 (was 1000)
   - **Verify:** HTTP 200
   - **Verify:** new profit = 1200 - 800 = 400 (was 200)
   - **Verify:** 6 transactions in GL: 3 reversal rows + 3 new rows
   - **Verify:** cashbox.balance = 11000 (unchanged — settlement was 1000, deleted and reposted at 1000)
   - **Verify:** customer.account.balance = 0 (still 0)
   - **Verify:** machine.balance unchanged (only selling_price changed)

4. **DELETE — DELETE /api/v1/fawry/transactions/{id}:**
   - **Verify:** HTTP 200
   - **Verify:** fawry_transactions.deleted_at IS NOT NULL
   - **Verify:** machine.balance = 5000 (restored)
   - **Verify:** cashbox.balance = 10000 (reversed)
   - **Verify:** GL: 3 reversal rows + 3 new reversal rows added (3 original + 3 reverse + 3 reverse-of-update = 9 total)
   - **Verify:** No "impossible" combination: cashbox cannot be negative, machine cannot be negative

5. **Re-DELETE — DELETE /api/v1/fawry/transactions/{id} again:**
   - **Verify:** HTTP 200 (idempotent)
   - **Verify:** No new GL rows added
   - **Verify:** machine.balance unchanged

**Expected outcome:** 100% pass.

**Files to create:**
- `tests/scripts/fa_fawry_lifecycle_happy_path.php` (script-based — runs against in-memory SQLite)
- `tests/Feature/Fawry/FawryLifecycleHappyPathTest.php` (PHPUnit feature test)

---

## 12. DELIVERABLES SCHEDULE

| Phase | Deliverable | Status |
|-------|-------------|:------:|
| PHASE 1 | This document (`FAWRY_LIFECYCLE_MAP.md`) | ✅ DONE |
| PHASE 2 | `FAWRY_LIFECYCLE_TEST_MATRIX.md` | ⏳ NEXT |
| PHASE 3 | `FAWRY_LIFECYCLE_RESULTS.md` (executes matrix) | ⏳ PENDING |
| PHASE 4 | State machine deep test | ⏳ PENDING |
| PHASE 5 | `FAWRY_LIFECYCLE_SECURITY.md` (frontend + auth) | ⏳ PENDING |
| PHASE 6-12 | Backend lifecycle, customer isolation, financial proof, idempotency, concurrency, failure, forensics | ⏳ PENDING |
| PHASE 13 | `FAWRY_LIFECYCLE_RECONCILIATION.md` | ⏳ PENDING |
| PHASE 14 | 240-regression re-run | ⏳ PENDING |
| Final | `FAWRY_FINAL_REPORT.md` updated with lifecycle verdict | ⏳ PENDING |

---

## 13. KEY APPROACH DEVIATIONS FROM THE AUDIT PROMPT

The audit prompt assumed a payment-gateway-style lifecycle. The actual module is an internal accounting system. I will:

1. **Test the REAL lifecycles**: create, update, soft-delete, walk-in pay-debt, machine recharge.
2. **Map all "N/A" prompt requirements to the closest real equivalent** (e.g., "successful payment" → CREATE success; "callback after refund" → DELETE followed by walk-in pay-debt).
3. **Document the gap** between the prompt's assumptions and the implementation so the reviewer knows which features do not exist.
4. **Run the full financial proof** for every real lifecycle, with independent expected-value calculations.
5. **Test all 4 dimensions** (frontend, HTTP, backend, DB) where the lifecycle touches them.

**The verdict will be based on the real lifecycle coverage, not on the prompt's hypothetical scenarios.**

---

**END OF PHASE 1 — FAWRY_LIFECYCLE_MAP.md**
