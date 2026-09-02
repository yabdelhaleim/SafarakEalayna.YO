# WALLET_TRANSFER_FINDINGS.md

**Audit:** Wallet & Transfers — Full E2E / Security / Financial Integrity
**Date:** 2026-08-20
**Branch:** `phase-10-tourism-production-audit-hajj-umra`
**Scope:** `routes/api.php` prefix `wallet` + `app/Services/Wallet/*` + `app/Models/Wallet/*` + `app/Models/Account.php` + `app/Models/AccountEntry.php` + `app/Services/Finance/TransactionService.php` (wallet paths)
**Out of scope:** Hajj/Umra, Visa, Flight, Bus, Fawry, Online, HR — explicitly excluded per user override.

---

## Severity legend

| Symbol | Severity | Definition |
|---|---|---|
| 🔴 | CRITICAL | Production data-loss / financial-loss / direct vulnerability |
| 🟠 | HIGH | Functional bug with monetary impact / broken security invariant |
| 🟡 | MED | Design gap / weak defense / silent corruption under specific conditions |
| 🟢 | LOW | Cosmetic / documentation drift / strict-mode advisory |

---

## Summary table

| ID | Title | Severity | Phase | Class |
|---|---|---|---|---|
| **FIN-1** | Balance invariant unsatisfiable for accounts with non-zero opening balance | 🟠 HIGH | 6, 7, 15 | Design / Invariant |
| **FIN-2** | `accountForSend` posts duplicate active income when `amount_paid > 0` + registered customer | 🟠 HIGH | 7, 11 | Service / Business-logic |
| **FIN-3** | `recordExpense` writes `type='transfer'` (not `type='expense'`) when a clearing account is resolved — semantic loss | 🟠 HIGH | 7, 12 | Service / Persistence |
| **FIN-4** | `getDailySummary` sums `amount` (not `total_amount`); fees excluded from cash-flow report | 🟡 MED | 7 | Reporting |
| **FIN-5** | `AuditLog` uses `model_type`/`model_id` instead of `auditable_type`/`auditable_id` morph pair | 🟡 MED | 7 | Convention / Interop |
| **FIN-6** | `wallet_account_id == cash_account_id` on a Send creates an asymmetric journal — fee is silently lost | 🟠 HIGH | 8 | Service / Validation |
| **FIN-7** | Inactive `wallet_account_id` is accepted without validation | 🟠 HIGH | 8 | Validation |
| **R1-A** | `GET /api/v1/wallet/transactions` index route is missing (405) | 🟠 HIGH | 7 | Routing |
| **R1-B** | `routes/finance.php` is dead code (NOT loaded by `bootstrap/app.php`) — DORMANT SECURITY RISK | 🟡 MED | 0 (pre-discovery) | Routing / Dead code |
| **SEC-1** | Default-employee role grants `manage_treasury` automatically; no per-user permission can deny this | 🔴 CRITICAL | 9 | Authorization |
| **SEC-2** | Show / statement endpoints do NOT filter by creator — any authenticated user can read any transaction | 🟡 MED | 9 | IDOR |
| **SEC-3** | Cross-branch (cross-tenant) account usage is allowed at the API layer | 🟡 MED | 16 | Multi-tenancy |
| **IDM-1** | No idempotency mechanism: `Idempotency-Key`, `X-Request-Id`, payload fingerprint — NONE honored | 🔴 CRITICAL | 11 | API design |
| **CONC-1** | `WalletTransaction::create()` runs BEFORE the `lockForUpdate()` block — outer race window | 🟠 HIGH | 12 | Concurrency |
| **CONC-2** | `ensureCustomerAccount()` is not protected by a row-level lock — concurrent first-time sends may create duplicate customer accounts | 🟡 MED | 12 | Concurrency |
| **REC-1** | Reconciliation between `accounts.balance` and `SUM(credit-debit)` will always report the opening balance as a phantom delta | 🟡 MED | 15 | Reconciliation |
| **UX-1** | All exceptions converted to HTTP 422 — business-logic errors (insufficient balance, duplicate income) are indistinguishable from validation errors | 🟡 MED | 8 | UX |
| **UX-2** | Error messages are in Arabic; not internalizable for downstream callers | 🟢 LOW | 8 | i18n |
| **SEC-4** | Soft-deleted WT may still be reachable via `GET /transactions/{id}` (status-dependent) | 🟢 LOW | 16 | Soft-delete leak |
| **VAL-1** | No currency-mismatch validation between `wallet_account` and `cash_account` | 🟠 HIGH | 8, 10 | Validation |
| **VAL-2** | No minimum amount validation beyond `0.01` (allows dust attacks) | 🟢 LOW | 8 | Validation |
| **VAL-3** | No maximum amount validation — 999,999.99 accepted, only fails on insufficient balance | 🟢 LOW | 8 | Validation |
| **VAL-4** | Notes field is not sanitized — stored as-is (XSS risk if rendered in admin UI) | 🟡 MED | 16 | XSS |

---

## Detailed findings

### FIN-1 — Balance invariant unsatisfiable for accounts with non-zero opening balance 🟠

**Severity:** HIGH
**Phase first documented:** PHASE 6 (test_balance_invariant_for_initial_fixtures)
**Also exercised in:** PHASE 7 (test_balance_invariant_does_NOT_hold_for_non_zero_opening_balance_FIN_1), PHASE 15 (test_reconciliation_detects_FIN_1_opening_balance_gap)

**Description**

The project's `Account` model docblock declares:

```
1) `Account.balance = SUM(credit) - SUM(debit)` on `account_entries`
   tied to this account.  This is the **PROJECT'S convention** — the
   opposite of standard double-entry accounting.
```

This invariant is **mathematically unsatisfiable** for any account that is created with a non-zero `balance` field. The system does NOT auto-create an opening-balance `AccountEntry` row when an account is created with `balance > 0`. The `account_entries` table remains empty until the first real transaction.

**Reproduction**

```php
$wallet = Account::create([
    'name' => 'Vodafone Cash - Agency',
    'type' => AccountType::Wallet,
    'balance' => 10000.00,
    'currency' => 'EGP',
    'module_type' => 'office',
    'is_active' => true,
    'owner_type' => Account::OWNER_TYPE_OWNER,
    'created_by' => $admin->id,
]);

// Same DB row, no entries yet.
$stored  = AccountState::balance($wallet->id);          // '10000.00'
$derived = AccountState::entriesDerivedBalance($wallet->id); // '0.00'
// Diff = 10000.00 = opening balance.
```

**Impact**

- Reconciliation reports a phantom delta equal to the sum of all opening balances forever.
- The `accounting:ledger:reconcile` daily command (scheduled in `bootstrap/app.php:44`) will report a constant false-positive gap.
- Any external audit or BI query that compares `accounts.balance` against `SUM(credit-debit)` will produce a divergent number.

**Recommendation**

- Option A: Auto-create an opening-balance `AccountEntry` row when an account is created with non-zero balance. Mark it with `is_opening=true` (column missing in migration `2026_05_06_000002_create_wallet_transactions_table.php` — needs new migration).
- Option B: Update the documented invariant to `balance = opening_balance + SUM(credit - debit)` and document the rule in production code.

**Files**

- `app/Models/Account.php` (lines 27-61 — documentation)
- `database/migrations/2026_04_27_170118_create_account_entries_table.php` (no `is_opening` column)
- `tests/Feature/Wallet/Phases/Phase00SmokeTest.php` (test_balance_invariant_for_initial_fixtures)
- `tests/Feature/Wallet/Phases/Phase07PositiveTest.php` (test_balance_invariant_does_NOT_hold_for_non_zero_opening_balance_FIN_1)

---

### FIN-2 — `accountForSend` posts duplicate active income when `amount_paid > 0` + registered customer 🟠

**Severity:** HIGH
**Phase first documented:** PHASE 7 (the existing `WalletTransactionCrudTest`)
**Also exercised in:** PHASE 11 (test_creating_transaction_with_amount_too_high_after_first_wallet_drain)

**Description**

`WalletTransactionService::accountForSend()` (line 491-500) calls `postMainSendPair()` AND `postSettlementSend()` unconditionally. The settlement flow uses `recordIncome()` with the SAME `related_type + related_id` as the main pair:

```php
// postMainSendPair — first recordIncome
$income = $this->transactionService->recordIncome([
    'amount' => $totalAmount,
    'to_account_id' => $customerAccount->id,
    'related_type' => WalletTransaction::class,
    'related_id' => $record->id,
    ...
]);

// postSettlementSend — second recordIncome (DUPLICATE)
if ($amountPaid >= 0.001) {
    $this->transactionService->recordIncome([
        'amount' => $amountPaid,
        'to_account_id' => $record->cash_account_id,
        'contra_account_id' => $customerAccount->id,
        'related_type' => WalletTransaction::class,
        'related_id' => $record->id,    // SAME
        ...
    ]);
}
```

The duplicate guard in `TransactionService::recordIncome()` (line 650-674) REJECTS the second call with:

```
"Duplicate income transaction blocked for App\Models\Wallet\WalletTransaction#1. Each booking can have only ONE ACTIVE income transaction (the sale). Reversed (عكس:) incomes do not occupy this slot — repostIncomeTransaction() can re-issue a new sale once the prior sale is reversed. Subsequent COLLECTIONS on a booking must use Transfer (type=transfer)."
```

**Reproduction**

```php
$payload = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
// amount_paid NOT set → defaults to totalAmount = 510
$response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
// Returns 422 with the duplicate-income error message.
```

**Mitigation in this audit**

`tests/Feature/Wallet/Phases/Phase07PositiveTest.php` and all subsequent phases use `amount_paid=0` to bypass the settlement. The bug is documented but not exercised in production paths during this audit.

**Impact**

- Production cashier posting a Send for a registered customer with `amount_paid > 0` (the default!) gets a 422 rejection.
- The Vue UI must be relaying `amount_paid` explicitly to avoid this. Confirmed via the existing CRUD test (also red).
- The existing `tests/Feature/Wallet/WalletTransactionCrudTest.php` (test_can_create_send_transaction) is failing for exactly this reason.

**Recommendation**

- Either remove the postSettlementSend call (settlement should be a separate `Transfer` action per the duplicate guard's own message), OR
- Tag the settlement income with a different `related_type` so it doesn't conflict with the main pair's unique slot.

**Files**

- `app/Services/Wallet/WalletTransactionService.php` (lines 491-500, 573-600)
- `app/Services/Finance/TransactionService.php` (lines 650-674 — the duplicate guard)

---

### FIN-3 — `recordExpense` writes `type='transfer'` (not `type='expense'`) when a clearing account is resolved 🟠

**Severity:** HIGH
**Phase first documented:** PHASE 7 (test_send_creates_two_journal_transactions_with_expected_types)

**Description**

`TransactionService::recordExpense()` (line 56-137) routes through `recordJournalTransfer()` when a clearing contra account is resolved:

```php
if ($resolvedContra !== null && $resolvedContra !== $fromId) {
    return $this->recordJournalTransfer([
        'amount' => $amount,
        'from_account_id' => $fromId,
        'to_account_id' => $resolvedContra,
        ...
    ]);
}
```

`recordJournalTransfer()` always writes `type='transfer'` (its semantic is "money moved between accounts"). The expense intent is lost in the persisted `transactions.type` column.

**Reproduction**

```php
$payload = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
$payload['amount_paid'] = 0;
$response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
// 2 ledger transactions are created. Inspect:

DB::table('transactions')
    ->where('related_type', WalletTransaction::class)
    ->where('related_id', $response->json('data.id'))
    ->get(['id', 'type', 'amount']);
// [
//   {id: 1, type: 'income',   amount: 510},  // ← main pair (correct)
//   {id: 2, type: 'transfer', amount: 500},  // ← should be 'expense' (BUG)
// ]
```

**Impact**

- Filtering expenses by `transactions.type='expense'` misses all clearing-account-based expenses.
- Reports based on `transactions.type` (e.g. P&L, treasury dashboard) are skewed.
- Audit queries must inspect `module + notes` to recover the expense semantic.

**Recommendation**

- Add a `category` or `subtype` column to `transactions` that preserves the expense/income/transfer semantic independently of the journal pattern.
- OR change `recordJournalTransfer` to accept a `type` parameter that overrides the default 'transfer'.

**Files**

- `app/Services/Finance/TransactionService.php` (lines 56-137 — recordExpense)
- `app/Services/Finance/TransactionService.php` (recordJournalTransfer)
- `tests/Feature/Wallet/Phases/Phase07PositiveTest.php` (test_send_creates_two_journal_transactions_with_expected_types)

---

### FIN-4 — `getDailySummary` sums `amount` (not `total_amount`) 🟡

**Severity:** MED
**Phase first documented:** PHASE 7 (test_daily_summary_uses_amount_not_total_amount_FIN_4)

**Description**

`WalletTransactionService::getDailySummary()` (line 868-889) sums `amount` (the principal) and reports fees separately in `total_fees`. The `total_amount` field (amount + fee) is the cash actually moved, but the summary under-reports by exactly the fee total.

**Reproduction**

```php
// 1 send of 500 + 10 fee = 510 total_amount
$payload = $this->sendPayloadRegistered($customer, amount: 500.00, fee: 10.00);
$payload['amount_paid'] = 0;
$this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

$response = $this->asAdmin()->getJson('/api/v1/wallet/transactions/daily-summary?date=' . today()->toDateString());
$response->json();
// {
//   total_transactions: 1,
//   send_count: 1,
//   total_sent: 500.00,         // ← sum of amount, NOT total_amount
//   total_received: 0,
//   total_fees: 10.00,
// }
```

**Impact**

- The `total_sent + total_received` figure understates the cash moved by the day's fees.
- Operators comparing the day's `total_sent` against the cashbox's actual receipts will see a discrepancy.

**Recommendation**

- Add a `total_sent_with_fees` field, or include `total_amount` in the existing sum and document the chosen convention.

**Files**

- `app/Services/Wallet/WalletTransactionService.php` (line 875-877)
- `tests/Feature/Wallet/Phases/Phase07PositiveTest.php` (test_daily_summary_uses_amount_not_total_amount_FIN_4)

---

### FIN-5 — AuditLog uses `model_type`/`model_id` instead of `auditable_type`/`auditable_id` morph pair 🟡

**Severity:** MED
**Phase first documented:** PHASE 7 (test_wallet_transaction_writes_audit_log)

**Description**

The audit log migration uses `model_type`/`model_id` for the audited entity. The standard Laravel pattern is `auditable_type`/`auditable_id`. Off-the-shelf audit packages (e.g. `owen-it/laravel-auditing`, `spatie/laravel-activitylog`) expect the morph pair.

**Reproduction**

```bash
php artisan tinker
>>> DB::table('audit_logs')->first();
// → has model_type, model_id columns (not auditable_*)
```

**Impact**

- Off-the-shelf audit consumers cannot be plugged in.
- The API is convention-inconsistent with the rest of the Laravel ecosystem.

**Recommendation**

- Document the chosen convention, or rename the columns to `auditable_type`/`auditable_id` via a migration.

**Files**

- `database/migrations/2025_*_create_audit_logs_table.php` (column names)
- `app/Services/Wallet/WalletTransactionService.php` (writeAuditLog line 952-970)
- `tests/Feature/Wallet/Phases/Phase07PositiveTest.php` (test_wallet_transaction_writes_audit_log)

---

### FIN-6 — `wallet_account_id == cash_account_id` on a Send creates an asymmetric journal 🟠

**Severity:** HIGH
**Phase first documented:** PHASE 8 (test_wallet_account_equals_cash_account_FIN_6_creates_asymmetric_journal)

**Description**

When the same account is used for both `wallet_account_id` and `cash_account_id` on a Send:

```
Before: shared = 5000.00, customer = 0.00, income_clearing = 0.00, expense_clearing = 0.00
POST: shared = 4900.00, customer = +105.00, income_clearing = -105.00, expense_clearing = +100.00
```

The shared account loses `amount` (100), not `total_amount` (105). The fee (5) is lost in the income clearing account.

**Impact**

- The user pays 105 EGP but the cash side only recorded 100 EGP of expense.
- The income clearing carries a phantom liability of 5.
- Reconciliation will report a 5 EGP discrepancy.

**Recommendation**

- Add validation: `wallet_account_id != cash_account_id`.
- Or detect the self-loop and skip the second leg.

**Files**

- `app/Http/Requests/Wallet/StoreWalletTransactionRequest.php` (no such validation)
- `app/Services/Wallet/WalletTransactionService.php` (no such check)
- `tests/Feature/Wallet/Phases/Phase08NegativeTest.php` (test_wallet_account_equals_cash_account_FIN_6_creates_asymmetric_journal)

---

### FIN-7 — Inactive `wallet_account_id` is accepted without validation 🟠

**Severity:** HIGH
**Phase first documented:** PHASE 8 (test_inactive_wallet_account_is_still_accepted_FIN_7)

**Description**

The system does NOT validate that the `wallet_account` is `is_active=true`. A deactivated (soft-closed) wallet can still be used as a source/sink for a transaction.

**Reproduction**

```php
$closedWallet = $this->makeAccount(
    type: AccountType::Wallet,
    name: 'Closed Wallet',
    balance: 5000.00,
    isActive: false,
    ...
);

$payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
$payload['wallet_account_id'] = $closedWallet->id;
$response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
// → 201 (accepted)
```

**Impact**

- Closed accounts can move money. The audit trail of "closed" is meaningless.
- Regulatory risk: in many jurisdictions, an inactive account should not have any new transactions.

**Recommendation**

- Add `is_active` check in `WalletTransactionService::createTransaction()`.

**Files**

- `tests/Feature/Wallet/Phases/Phase08NegativeTest.php` (test_inactive_wallet_account_is_still_accepted_FIN_7)

---

### R1-A — `GET /api/v1/wallet/transactions` index route is missing 🟠

**Severity:** HIGH
**Phase first documented:** PHASE 0 (pre-discovery in `wallet-transfer-audit-discovery-20260820.md`)
**Confirmed in:** PHASE 7 (test_index_endpoint_returns_405_INDICATES_MISSING_ROUTE)

**Description**

`routes/api.php` (lines 370-394) does NOT register a GET index route for `/api/v1/wallet/transactions`. The controller has an `index()` method but it is not wired. Calling GET on that path returns 405 (Method Not Allowed).

**Routes present:**

```
GET  /api/v1/wallet/types                          → WalletTypeController@index
GET  /api/v1/wallet/customer-balances              → WalletTransactionController@customerBalances
GET  /api/v1/wallet/customer-statement             → WalletTransactionController@customerStatement
GET  /api/v1/wallet/transactions/daily-summary     → WalletTransactionController@dailySummary
POST /api/v1/wallet/transactions                   → WalletTransactionController@store
PUT  /api/v1/wallet/transactions/{transaction}     → WalletTransactionController@update
DEL  /api/v1/wallet/transactions/{transaction}     → WalletTransactionController@destroy
GET  /api/v1/wallet/transactions/{transaction}     → WalletTransactionController@show
```

**Missing:**

```
GET  /api/v1/wallet/transactions                   → WalletTransactionController@index    ← NOT WIRED
```

**Impact**

- The wallet listing page in the admin UI cannot fetch transactions via the API.
- Anyone calling the listing endpoint gets 405.

**Recommendation**

- Add `Route::get('transactions', [WalletTransactionController::class, 'index'])->name('wallet.transactions.index');` to `routes/api.php`.

**Files**

- `routes/api.php` (lines 370-394)
- `app/Http/Controllers/Api/V1/Wallet/WalletTransactionController.php` (index method exists at line 27)
- `tests/Feature/Wallet/Phases/Phase07PositiveTest.php` (test_index_endpoint_returns_405_INDICATES_MISSING_ROUTE)

---

### R1-B — `routes/finance.php` is dead code (NOT loaded by `bootstrap/app.php`) 🟡

**Severity:** MED
**Phase first documented:** PHASE 0 (pre-discovery)

**Description**

`routes/finance.php` exists at the project root but is NOT registered in `bootstrap/app.php` (the `withRouting(...)` block only references `web.php`, `api.php`, `console.php`). The file is dead code.

**Impact**

- Maintenance confusion: routes that look like they should be live are not.
- If a developer adds a route to `finance.php` it will silently not work.

**Recommendation**

- Either delete `routes/finance.php` or register it in `bootstrap/app.php`.

**Status**

- Per user instruction "do NOT delete routes/finance.php", this is documented as DORMANT SECURITY RISK and left as-is.

**Files**

- `routes/finance.php` (dead code)
- `bootstrap/app.php` (withRouting block)

---

### SEC-1 — Default-employee role grants `manage_treasury` automatically; no per-user permission can deny this 🔴

**Severity:** CRITICAL
**Phase first documented:** PHASE 9 (test_default_employee_with_no_permissions_can_post_FIN_SEC_1, test_employee_with_empty_permissions_still_grants_default)

**Description**

`UserPermissions::effectiveFor()` (line 136-150) returns the default employee module set when the stored `permissions` field is empty or null:

```php
if (in_array($user->role, ['admin', 'owner'], true)) {
    return $stored !== [] ? $stored : self::all();
}

if ($stored !== []) {
    return $stored;
}

return self::defaultEmployeeModules();  // ← FALLBACK
```

`defaultEmployeeModules()` (line 119-129) includes `MANAGE_TREASURY`, which gates `wallet.create`, `fawry.create`, etc.

**Impact**

- ANY user with `role='employee'` and `is_active=true` can post wallet transactions automatically.
- There is no way to explicitly DENY these permissions to an employee without changing their role.
- An attacker who creates a user with `role='employee'` (e.g. via a public registration or compromised admin) gets immediate wallet access.

**Recommendation**

- Revoke the default fall-back. Require explicit `permissions` for any user that doesn't have `role='admin'` or `'owner'`.
- OR change the logic to require explicit grant for each module.

**Files**

- `app/Support/UserPermissions.php` (lines 119-149)
- `app/Http/Middleware/CheckPermission.php` (lines 53-93)
- `tests/Feature/Wallet/Phases/Phase09SecurityTest.php` (test_default_employee_with_no_permissions_can_post_FIN_SEC_1)

---

### SEC-2 — Show / statement endpoints do NOT filter by creator 🟡

**Severity:** MED
**Phase first documented:** PHASE 9 (test_show_other_users_transaction_does_not_filter_by_creator, test_statement_does_not_filter_by_creator)

**Description**

`GET /api/v1/wallet/transactions/{id}` and `GET /api/v1/wallet/customer-statement?client_id=N` do not filter by the authenticated user. Any authenticated user can read any transaction by id, and any statement by customer id.

**Impact**

- A horizontal-privilege escalation (IDOR) is possible: cashier A can read cashier B's transactions.
- Same for the customer-statement endpoint.

**Recommendation**

- Add a `where('created_by', $request->user()->id)` filter unless the viewer has admin role.
- Or add a policy class.

**Files**

- `app/Http/Controllers/Api/V1/Wallet/WalletTransactionController.php` (show + customerStatement)
- `tests/Feature/Wallet/Phases/Phase09SecurityTest.php`

---

### SEC-3 — Cross-branch (cross-tenant) account usage is allowed at the API layer 🟡

**Severity:** MED
**Phase first documented:** PHASE 16 (test_branch_isolation_is_NOT_enforced_at_api_layer)

**Description**

The API does not enforce that `wallet_account_id` and `cash_account_id` belong to the same branch as the authenticated user. A cashier can move money between any accounts in the system.

**Impact**

- Cross-tenant money movement.
- Anti-money-laundering (AML) traceability is broken.

**Recommendation**

- Add a `branch_id` to User (or via FK to Office) and validate account ownership.

**Files**

- `app/Models/User.php` (no branch_id)
- `tests/Feature/Wallet/Phases/Phase16FinalSecurityAuditTest.php`

---

### IDM-1 — No idempotency mechanism 🔴

**Severity:** CRITICAL
**Phase first documented:** PHASE 11 (full phase)

**Description**

The POST `/api/v1/wallet/transactions` endpoint has NO idempotency mechanism:

- The `Idempotency-Key` header is not honored.
- The `X-Request-Id` header is not honored.
- No payload fingerprint is computed.
- Each retry, double-click, or network-flake creates a new transaction.

**Reproduction**

```php
$payload = $this->sendPayloadRegistered($customer, amount: 100.00, fee: 5.00);
$payload['amount_paid'] = 0;

$r1 = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
$r2 = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
// Both return 201 with DIFFERENT transaction IDs.
// 100 identical POSTs create 100 transactions.
```

**Impact**

- Direct financial-loss vector. A user double-clicking "Submit" pays 2x.
- A network retry pays 2x.
- A misconfigured client can drain the wallet with thousands of duplicate transactions.

**Recommendation**

- Implement the standard `Idempotency-Key` header (RFC draft `draft-ietf-httpapi-idempotency-key-header`).
- Store the key in a `idempotency_keys` table with the response payload; return cached response on replay.
- Apply to POST endpoints across the entire app, not just wallet.

**Files**

- `app/Http/Controllers/Api/V1/Wallet/WalletTransactionController.php` (store method)
- `app/Http/Middleware/*` (no idempotency middleware)
- `tests/Feature/Wallet/Phases/Phase11IdempotencyTest.php` (full phase)

---

### CONC-1 — `WalletTransaction::create()` runs BEFORE the `lockForUpdate()` block — outer race window 🟠

**Severity:** HIGH
**Phase first documented:** PHASE 12 (test_tight_loop_50_sends_balance_invariant, test_at_balance_boundary_no_overdraw)

**Description**

In `WalletTransactionService::createTransaction()` (line 79-148):

1. Line 106: `WalletTransaction::create([...])` inserts the WT row.
2. Line 126: `recordIncome()` is called.
3. Line 127: `recordExpense()` is called → which calls `recordJournalTransfer()` → which calls `lockForUpdate()` → which actually checks the balance.

The lock is acquired AFTER the WT row is created. A burst of concurrent sends could each create their WT row, then queue for the lock. The lock+balance-check prevents double-spend correctly, but the WT row count can rise above the number of successful transactions.

**Impact**

- WT row count can be > successful transaction count during heavy bursts.
- Each failed-overdraft attempt leaves a phantom WT row (with stale income_transaction_id / expense_transaction_id).
- The unique-slot guard on income (FIN-2) can compound this.

**Recommendation**

- Move the lock+balance check BEFORE the `WalletTransaction::create()` call.
- Use `DB::transaction` with `lockForUpdate` on the wallet account row as the first action.

**Files**

- `app/Services/Wallet/WalletTransactionService.php` (line 79-148)
- `tests/Feature/Wallet/Phases/Phase12ConcurrencyTest.php`

---

### CONC-2 — `ensureCustomerAccount()` is not protected by a row-level lock 🟡

**Severity:** MED
**Phase first documented:** PHASE 12 (described in CONC-1)

**Description**

`ensureCustomerAccount()` (line 812-858) checks `customer->account_id` and creates a new account if missing. Two concurrent first-time sends for the same customer could:
1. Both read `customer->account_id = null`.
2. Both create a new `Account` row.
3. Both `update(['account_id' => $newId])` — last write wins.

The orphan account(s) remain in the database.

**Impact**

- Orphan customer accounts.
- UI may show the wrong account id.

**Recommendation**

- Wrap the check-and-create in a DB transaction with `lockForUpdate` on the customer row.

**Files**

- `app/Services/Wallet/WalletTransactionService.php` (line 812-858)

---

### REC-1 — Reconciliation between `accounts.balance` and `SUM(credit-debit)` will always report the opening balance as a phantom delta 🟡

**Severity:** MED
**Phase first documented:** PHASE 15 (test_reconciliation_report_matches_opening_balance_sum)

**Description**

A standard reconciliation query:

```sql
SELECT a.id, a.balance, SUM(credit) - SUM(debit) AS derived
FROM accounts a
LEFT JOIN account_entries ae ON ae.account_id = a.id
GROUP BY a.id
```

will produce a delta of exactly the opening balance for any account with non-zero balance. The reconciliation tool (the daily `ledger:reconcile` command) will report this as a problem.

**Impact**

- The reconciliation tool's output is always noisy.
- Real deltas (after the first transaction) are mixed with the phantom ones.

**Recommendation**

- Either fix FIN-1 (auto-create opening-balance entries) OR update the reconciliation query to use `opening_balance + SUM(credit - debit)`.

**Files**

- Same as FIN-1.

---

### UX-1 — All exceptions converted to HTTP 422 🟡

**Severity:** MED
**Phase first documented:** PHASE 8 (test_send_exceeding_wallet_balance_returns_422_not_500)

**Description**

`WalletTransactionController::store()` (line 42-55) catches ALL exceptions and converts them to 422 with the exception message:

```php
} catch (\Exception $e) {
    return ApiResponse::error($e->getMessage(), null, 422);
}
```

This conflates validation errors (422) with business-logic errors (insufficient balance, duplicate income, etc.).

**Impact**

- Clients cannot distinguish between "you sent bad data" and "your data is valid but the system can't process it".
- Recovery logic on the client side is harder.

**Recommendation**

- Use 422 only for `ValidationException`. Use 500 / 409 / 422+specific for business-logic errors.

**Files**

- `app/Http/Controllers/Api/V1/Wallet/WalletTransactionController.php` (line 52-54)
- `tests/Feature/Wallet/Phases/Phase08NegativeTest.php`

---

### VAL-1 — No currency-mismatch validation between `wallet_account` and `cash_account` 🟠

**Severity:** HIGH
**Phase first documented:** PHASE 8 (test_currency_mismatch_wallet_egp_cash_usd_is_accepted)

**Description**

The system does not validate that `wallet_account.currency == cash_account.currency`. A EGP wallet can be paired with a USD cashbox.

**Impact**

- Cross-currency transactions are accepted silently.
- The wallet debits EGP, the cashbox credits USD — no FX conversion is applied.

**Recommendation**

- Reject the payload if currencies don't match.
- OR introduce an explicit FX conversion path.

**Files**

- `app/Http/Requests/Wallet/StoreWalletTransactionRequest.php` (no currency validation)
- `tests/Feature/Wallet/Phases/Phase08NegativeTest.php`, `tests/Feature/Wallet/Phases/Phase10PrecisionTest.php` (test_cross_currency_transaction_is_accepted)

---

### VAL-4 — Notes field is not sanitized — stored as-is (XSS risk if rendered in admin UI) 🟡

**Severity:** MED
**Phase first documented:** PHASE 16 (test_html_in_notes_is_not_executed)

**Description**

The `notes` field is stored as-is by the controller. If the admin UI renders it without escaping, an XSS payload is possible.

**Reproduction**

```php
$payload['notes'] = '<script>alert("xss")</script>';
$response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
// 201 — script tag stored verbatim.
```

**Recommendation**

- Either escape on output (Vue usually does this), or sanitize on input.

**Files**

- `app/Http/Requests/Wallet/StoreWalletTransactionRequest.php` (no notes sanitization)
- `tests/Feature/Wallet/Phases/Phase16FinalSecurityAuditTest.php`

---

## Tests written in this audit

| Phase | Test file | Tests | Assertions |
|---|---|---|---|
| 6 | `tests/Feature/Wallet/Phases/Phase00SmokeTest.php` | 5 | 20 |
| 7 | `tests/Feature/Wallet/Phases/Phase07PositiveTest.php` | 13 | 98 |
| 8 | `tests/Feature/Wallet/Phases/Phase08NegativeTest.php` | 32 | 43 |
| 9 | `tests/Feature/Wallet/Phases/Phase09SecurityTest.php` | 14 | 28 |
| 10 | `tests/Feature/Wallet/Phases/Phase10PrecisionTest.php` | 15 | 44 |
| 11 | `tests/Feature/Wallet/Phases/Phase11IdempotencyTest.php` | 7 | 136 |
| 12 | `tests/Feature/Wallet/Phases/Phase12ConcurrencyTest.php` | 7 | 247 |
| 13 | `tests/Feature/Wallet/Phases/Phase13RollbackTest.php` | 13 | 45 |
| 14 | `tests/Feature/Wallet/Phases/Phase14FullE2ETest.php` | 9 | 58 |
| 15 | `tests/Feature/Wallet/Phases/Phase15ReconciliationTest.php` | 7 | 33 |
| 16 | `tests/Feature/Wallet/Phases/Phase16FinalSecurityAuditTest.php` | 13 | 128 |
| **TOTAL** | | **135 tests** | **880 assertions** |

All Phase* tests run **GREEN** under per-file PHPUnit invocation (`vendor/bin/phpunit tests/Feature/Wallet/Phases/PhaseNNXxx.php`).

---

*End of WALLET_TRANSFER_FINDINGS.md*
