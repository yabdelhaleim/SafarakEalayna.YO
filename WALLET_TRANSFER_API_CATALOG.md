# WALLET_TRANSFER_API_CATALOG.md

**Audit:** Wallet & Transfers
**Date:** 2026-08-20

This file catalogs every API endpoint, SQL path, and admin route related to the Wallet & Transfers module. Each entry notes its source-of-truth file, the audit's finding (if any), and the Phase test that exercises it.

---

## API endpoints (routes/api.php prefix `wallet`)

Defined in `routes/api.php` lines 370-394.

| Method | Path | Controller | Middleware | Source | Test | Finding |
|---|---|---|---|---|---|---|
| GET | `/api/v1/wallet/types` | `WalletTypeController@index` | `auth:sanctum, active, ...` | `routes/api.php:373` | `Phase07PositiveTest::test_can_list_wallet_types` (existing) | — |
| GET | `/api/v1/wallet/dashboard` | `TransferDashboardController@index` | `auth:sanctum, active, ...` | `routes/api.php:372` | out of scope for this audit | — |
| GET | `/api/v1/wallet/customer-balances` | `WalletTransactionController@customerBalances` | `auth:sanctum, active, ...` | `routes/api.php:374` | `Phase14FullE2ETest::test_e2e_customer_balances_returns_aggregated` | — |
| GET | `/api/v1/wallet/customer-statement` | `WalletTransactionController@customerStatement` | `auth:sanctum, active, ...` | `routes/api.php:375` | `Phase14FullE2ETest::test_e2e_customer_statement_returns_transactions` | SEC-2 (no creator filter) |
| GET | `/api/v1/wallet/transactions` | `WalletTransactionController@index` | **NOT REGISTERED** | — | `Phase07PositiveTest::test_index_endpoint_returns_405_INDICATES_MISSING_ROUTE` | **R1-A** |
| GET | `/api/v1/wallet/transactions/daily-summary` | `WalletTransactionController@dailySummary` | `auth:sanctum, active, ...` | `routes/api.php:376` | `Phase07PositiveTest::test_daily_summary_uses_amount_not_total_amount_FIN_4` | FIN-4 |
| POST | `/api/v1/wallet/transactions` | `WalletTransactionController@store` | `auth:sanctum, active, permission:wallet.create, ...` | `routes/api.php:383-384` | `Phase07PositiveTest` (entire file) | SEC-1, IDM-1, FIN-2, FIN-3, FIN-6, FIN-7, VAL-1, UX-1 |
| PUT/PATCH | `/api/v1/wallet/transactions/{transaction}` | `WalletTransactionController@update` | `auth:sanctum, active, admin, ...` | `routes/api.php:385-386` | `Phase09SecurityTest::test_update_payload_injection_is_blocked` | — |
| DELETE | `/api/v1/wallet/transactions/{transaction}` | `WalletTransactionController@destroy` | `auth:sanctum, active, admin, ...` | `routes/api.php:387-388` | `Phase13RollbackTest::test_delete_reverses_journal_with_reversal_prefix` | — |
| GET | `/api/v1/wallet/transactions/{transaction}` | `WalletTransactionController@show` | `auth:sanctum, active, ...` | `routes/api.php:389` | `Phase07PositiveTest::test_show_endpoint_succeeds_for_known_transaction` | SEC-2 |
| GET | `/api/v1/wallet/treasury/overview` | `TransferTreasuryController@overview` | `auth:sanctum, active, ...` | `routes/api.php:392` | out of scope | — |
| GET | `/api/v1/wallet/treasury/accounts/{account}/transactions` | `TransferTreasuryController@accountTransactions` | `auth:sanctum, active, ...` | `routes/api.php:393` | out of scope | — |

---

## Service paths

### `WalletTransactionService` (`app/Services/Wallet/WalletTransactionService.php`)

| Method | Lines | Purpose | Tested by | Finding |
|---|---|---|---|---|
| `getAllTransactions(array $filters)` | 32-77 | (No HTTP route — index method exists but is unwired) | R1-A | R1-A |
| `createTransaction(array $data)` | 79-148 | Main POST entry point | `Phase07PositiveTest` (full) | FIN-2, FIN-3, CONC-1, IDM-1 |
| `updateTransaction($transaction, $data)` | 175-242 | PUT entry point | `Phase09SecurityTest::test_update_payload_injection_is_blocked` | — |
| `deleteTransaction($transaction)` | 250-326 | DELETE entry point | `Phase13RollbackTest` | — |
| `getTransactionById(int $id)` | 860-866 | GET entry point | `Phase07PositiveTest::test_show_endpoint_succeeds_for_known_transaction` | SEC-2 |
| `getDailySummary(string $date)` | 868-889 | GET daily-summary | `Phase07PositiveTest::test_daily_summary_uses_amount_not_total_amount_FIN_4` | FIN-4 |
| `accountForSend(...)` | 483-500 | Sends main pair + settlement | `Phase07PositiveTest` (with amount_paid=0) | FIN-2 |
| `accountForReceive(...)` | 611-628 | Receives main pair + settlement | `Phase07PositiveTest::test_receive_with_registered_customer_succeeds` | — |
| `postMainSendPair(...)` | 508-566 | INCOME + EXPENSE-AS-TRANSFER | `Phase07PositiveTest::test_send_creates_two_journal_transactions_with_expected_types` | FIN-3 |
| `postSettlementSend(...)` | 573-600 | Optional settlement INCOME | bypassed in tests via `amount_paid=0` | FIN-2 |
| `ensureCustomerAccount(int $customerId)` | 812-858 | Lazy-create customer Account | `Phase07PositiveTest::test_send_updates_accounts_correctly_for_send` | CONC-2 |

### `TransactionService` (`app/Services/Finance/TransactionService.php`) — wallet paths

| Method | Lines | Purpose | Finding |
|---|---|---|---|
| `recordIncome(array $data)` | 138-280 | INCOME transaction (uses clearing accounts) | FIN-2 (duplicate guard line 650-674) |
| `recordExpense(array $data)` | 56-137 | EXPENSE-AS-TRANSFER (clearing account path) | FIN-3 |
| `recordJournalTransfer(...)` | (after line 280) | Journal transfer | FIN-3 |
| `reverseTransaction(...)` | 280-360 | Reversal with "عكس:" prefix | (covered by `Phase13RollbackTest`) |

---

## Admin / Filament routes

| Path | Resource | Notes |
|---|---|---|
| `/admin/wallet-transactions` | `WalletTransactionsResource` (Filament) | Out of scope for this audit (not API). |
| `/admin/accounts` | `AccountResource` | Out of scope. |

---

## SQL queries used by the wallet module

### `account_entries` — append-only

| Operation | Source | Notes |
|---|---|---|
| `INSERT INTO account_entries (account_id, transaction_id, debit, credit, balance_after)` | `TransactionService::recordIncome`, `recordExpense`, `recordJournalTransfer` | One entry per side of each journal. |
| `UPDATE account_entries SET notes='عكس القيد #N'` | `TransactionService::reverseTransaction` | Reversal entries are flagged in notes. No UPDATE on debit/credit. |
| `SELECT SUM(credit) - SUM(debit) FROM account_entries WHERE account_id=?` | `AccountState::entriesDerivedBalance` | Reconciliation. |
| `SELECT SUM(debit) FROM account_entries WHERE transaction_id=?` | `Phase12ConcurrencyTest::test_tight_loop_ledger_integrity` | Invariant #2 check. |

### `accounts` — mutable balance

| Operation | Source | Notes |
|---|---|---|
| `UPDATE accounts SET balance = balance - X` | `Account::updating` boot guard (FORBIDDEN unless `LedgerBalanceMutationGuard::isAllowed()`) | The system forbids direct balance writes (TX line 188-194). |
| `INSERT INTO accounts (balance, ...)` | `Account::create()` calls | No opening-balance entry created. |
| `SELECT balance FROM accounts WHERE id=?` | `AccountState::balance` | Reading. |

### `transactions` — journal transfer

| Operation | Source | Notes |
|---|---|---|
| `INSERT INTO transactions (type, amount, related_type, related_id, from_account_id, to_account_id, ...)` | `recordIncome`, `recordExpense`, `recordJournalTransfer` | type='income' / 'transfer' (NOT 'expense' for FIN-3). |
| `UPDATE transactions SET notes='عكس: ...'` | `reverseTransaction` | Reversal prefix. |
| `SELECT FROM transactions WHERE related_type=? AND related_id=?` | `Phase07PositiveTest::test_send_creates_two_journal_transactions_with_expected_types` | Relationship traversal. |

### `wallet_transactions` — cashier-facing

| Operation | Source | Notes |
|---|---|---|
| `INSERT INTO wallet_transactions` | `createTransaction` | Race window (CONC-1). |
| `UPDATE wallet_transactions SET notes=?, updated_at` | `updateTransaction` | Mass-assignment protection tested. |
| `UPDATE wallet_transactions SET deleted_at` | `deleteTransaction` (soft delete) | Reversal. |
| `SELECT FROM wallet_transactions [filters]` | `getAllTransactions` (unwired — R1-A) | — |

### `audit_logs` — project audit trail

| Operation | Source | Notes |
|---|---|---|
| `INSERT INTO audit_logs (model_type, model_id, action, user_id, old_values, new_values, ...)` | `WalletTransactionService::writeAuditLog` | Uses `model_type`/`model_id` (FIN-5). |

### `customers` — entity linkage

| Operation | Source | Notes |
|---|---|---|
| `UPDATE customers SET account_id = ?` | `ensureCustomerAccount` | Race condition (CONC-2). |

### `account_entries` (legacy non-blocking-account path) and `transactions` columns

- `transactions.type` is an enum: `income`, `expense`, `transfer`, `refund`, `writeoff`.
- Production code uses `income` and `transfer` only for wallet paths (FIN-3).
- `expense` column on `transactions` is reserved for the legacy single-leg fallback path that is now NEVER taken in wallet flows.

---

## Validation rules

`app/Http/Requests/Wallet/StoreWalletTransactionRequest.php` (lines 16-32):

| Field | Rule | Note |
|---|---|---|
| `wallet_type_id` | required, integer, exists:wallet_types,id | — |
| `customer_id` | nullable, integer, exists:customers,id | — |
| `customer_name` | required, string, max:200 | — |
| `wallet_number` | required, string, max:30 | — |
| `type` | required, in:WalletTransactionType enum | — |
| `amount` | required, numeric, min:0.01 | (No max — VAL-3) |
| `service_fee` | nullable, numeric, min:0 | — |
| `amount_paid` | nullable, numeric, min:0 | — |
| `wallet_account_id` | required, integer, exists:accounts,id | (No `is_active` check — FIN-7) |
| `cash_account_id` | required, integer, exists:accounts,id | (No currency check — VAL-1) |
| `employee_id` | nullable, integer, exists:employees,id | — |
| `notes` | nullable, string, max:1000 | (No sanitization — VAL-4) |

`app/Http/Requests/Wallet/UpdateWalletTransactionRequest.php` (lines 16-19):

| Field | Rule | Note |
|---|---|---|
| `notes` | nullable, string, max:1000 | — |

**Missing critical validations (extracted from PHASE 8 negatives):**

- `wallet_account_id != cash_account_id` (FIN-6)
- `wallet_account.is_active` (FIN-7)
- `wallet_account.currency == cash_account.currency` (VAL-1)
- `amount <= SOME_MAX` (VAL-3)
- `notes` HTML/script sanitization (VAL-4)

---

## Middleware stack

Global (applied to all `api/*` routes via `bootstrap/app.php` + `routes/api.php` group at line 105):

1. `auth:sanctum` — Sanctum token authentication
2. `active` — `EnsureIsActive` middleware (user must be `is_active=true`)
3. `CaptureFinancialPostingContext` — captures financial context for audit
4. `RejectBannedFinancialBypassMarkers` — rejects banned bypass markers

Per-route:

- POST `/wallet/transactions` → `permission:wallet.create` (translated to `manage_treasury`)
- PUT/PATCH `/wallet/transactions/{id}` → `admin` (`EnsureIsAdmin`)
- DELETE `/wallet/transactions/{id}` → `admin` (`EnsureIsAdmin`)

---

## Dead code (not in current request path)

- `routes/finance.php` — NOT loaded by `bootstrap/app.php` (R1-B)

---

*End of WALLET_TRANSFER_API_CATALOG.md*
