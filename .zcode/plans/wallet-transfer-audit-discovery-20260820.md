# Wallet & Transfers — Full E2E / Security / Financial Integrity Audit
## Discovery Report (PHASE 0 + PHASE 1)

- **Audit owner:** ZCode (acting as Senior Principal Software Auditor + Financial Systems QA Engineer + Application Security Engineer)
- **Target project:** `C:\travile\SafarakEalayna` (SafarakEalayna — tourism ERP)
- **Date:** 2026-08-20
- **Status:** DISCOVERY ONLY — no code edited, no tests executed yet.
- **Audience:** Project owner (Youssef Abd Elhaleim) for sign-off before PHASE 2+ begins.

---

## 0. TL;DR

The "Wallet & Transfers" surface is **not** a single isolated module — it is **three concentric layers**:

1. **Cashier / day-to-day layer.** `WalletTransaction` (model) + `app/Models/Wallet/` + `/v1/wallet/*` API + `WalletTransactionService.php` + `WalletTransactionsResource` (Filament). One row = one cash/wallet operation against a customer (registered or walk-in) with `send|receive`, `amount`, `service_fee`, `amount_paid`, `income_transaction_id`, `expense_transaction_id`.
2. **GL core (accounting truth).** `Account` + `AccountEntry` (immutable, no SoftDeletes) + `Transaction` (morphTo + module + type + correlation_id + posting context) + `Transfer` (inter-account movement with FX). This is the **double-entry ledger** — every penny that moves in the system eventually touches one of these tables via `TransactionService::recordIncome / recordExpense / recordTransfer / reverseTransaction`.
3. **Admin / inter-account movement.** `/v1/finance/transfers` (POST) calls `TransactionService::recordTransfer($data)` directly. Admin-only. Validated by `StoreTransferRequest` (checks liquidity types, from must be active, currency-match-or-FX, different accounts).

The whole API is locked behind four pieces of defence:
- `auth:sanctum` — token auth
- `EnsureIsActive` — account must be active
- `CaptureFinancialPostingContext` — stamps every financial call with HTTP context
- `RejectBannedFinancialBypassMarkers` — rejects `direct_financial_write` query/body and `X-Allow-Direct-Ledger` header with 403

Then `Account::booted()` enforces an **updating guard** that **throws** on direct `balance` writes outside `LedgerBalanceMutationGuard::run()` — i.e. the only way to mutate balances is through canonical services. This is a non-trivial hardening layer.

A second defensive layer: `config/accounting.php` exposes `balance_guard.block_unauthorized_updates`, `middleware.reject_bypass_markers`, `strict_test_guards`, `strict_double_entry`, `allow_legacy_single_leg_fallback` as runtime knobs — and they exist precisely because prior iterations found bugs there.

**Money-conservation invariants** are explicitly documented in `Account.php`'s class docblock (lines 27–98): every `Transaction` posts balanced `AccountEntry` rows; `Account.balance = SUM(credit) − SUM(debit)` with the PROJECT'S sign convention (credit increases, debit decreases — opposite of classical double-entry).

---

## 1. PHASE 0 — Environment Safety ✅

| Check | Result |
|---|---|
| `APP_ENV` (in `.env`) | `local` (NOT production) |
| `APP_DEBUG` | `true` |
| `DB_CONNECTION` (default) | `mysql` on `127.0.0.1:3306` / `safarakealayna` |
| `phpunit.xml` force-overrides tests to | `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:` |
| `.env.testing` | confirms `APP_ENV=testing`, SQLite `:memory:` |
| Guardian middleware that rejects banned bypass markers | ON (`ACCOUNTING_REJECT_FINANCIAL_BYPASS_MARKERS=true` default) |
| `Account::balance` updating guard | ON by default (`ACCOUNTING_BALANCE_GUARD=true`) unless explicitly disabled in testing |

**Verdict:** ✅ Safe — tests run on a fresh in-memory SQLite DB per PHPUnit run. No `.env` change required. No production data touched.

> Files I did **not** modify during discovery: any `.env*`, `phpunit.xml`, `composer.json`, any source file.

---

## 2. PHASE 1 — Module Inventory

### 2.1 Money tables — column-level schemas (verbatim)

#### `accounts` (central GL row) — Migration `2026_04_27_170117_create_accounts_table.php`
```
id (bigint PK)
name (string)                            — "درج المكتب", "بريد سفرك علينا - جاري", …
type (enum: cashbox|wallet|bank|treasury) ← REPLACED later; current runtime enum
                                          via subsequent migrations: +customer, +supplier,
                                          +expense, +liability, +revenue, +owner
currency (string 3, default 'EGP')
balance (decimal(15,2) default 0)
is_active (bool default true)
owner_type (enum owner|office default owner)
created_by (FK users nullable)
notes (text nullable)
timestamps + softDeletes
indexes: (type, owner_type), (currency)
```
Follow-up migrations added / changed: `module_type`, `is_module_vault`, `wallet_provider`, `wallet_number`, `module` (alias), `customer/supplier/expense/liability/revenue/owner` to enum, etc.

#### `transactions` (the journal header) — Migration `2026_04_27_170117_create_transactions_table.php`
```
id (bigint PK)
type (enum: income|expense|transfer|refund) ← later +writeoff
module (enum: flight|bus|fawry|online|hajj_umra|visa|wallet|general) ← later +tourism, +office, +hajj
amount (decimal(15,2))
currency (string 3 default 'EGP')
from_account_id (FK accounts nullable)
to_account_id   (FK accounts nullable)
approval_workflow_id (FK nullable)
related_type (string, morphClass nullable)
related_id (unsignedBigInt nullable)
created_by (FK users)
notes (text nullable)
timestamps
indexes: (type, module), (from_account_id, to_account_id), (related_type, related_id)
```
Follow-ups: `program_id`, `attachment_path`, `posting_channel`, `correlation_id`, `http_method`, `request_route`, `client_ip`, `user_agent`, `income_unique_key`, soft-deletes on some module tables.

> ⚠️ IMPORTANT NUANCE: `TransactionService` internally stores ALL double-entry postings as `type=transfer` — see comment in `WalletTransactionService.php:380`. So the `transactions.type` enum is partly vestigial at runtime for income/expense flows.

#### `account_entries` (the ledger lines) — Migration `2026_04_27_170118_create_account_entries_table.php`
```
id (bigint PK)
account_id      (FK accounts)
transaction_id  (FK transactions)    ← later made NULLABLE via 2026_05_11 migration
debit           (decimal(15,2) default 0)
credit          (decimal(15,2) default 0)
balance_after   (decimal(15,2))
notes           (text nullable, added later in Phase 3b v3 fix)
timestamps
index: (account_id, transaction_id)
```
- **Append-only** — no `SoftDeletes` trait on `AccountEntry` model. Direct quote from the model:
  > "⚠️ IMMUTABLE FINANCIAL RECORD — DO NOT ADD SoftDeletes TRAIT"
  Reversals are **additive** — a new `Transaction` with `"عكس:"` notes prepended and counter-direction entries.

#### `transfers` (inter-account money movements) — Migration `2026_04_27_170118_create_transfers_table.php`
```
id (bigint PK)
from_account_id (FK accounts)
to_account_id   (FK accounts)
amount           (decimal(15,2))
from_currency    (string 3)
to_currency      (string 3)
exchange_rate    (decimal(10,6) nullable)
converted_amount (decimal(15,2) nullable)
transaction_id   (FK transactions)
approval_workflow_id (FK nullable)
created_by       (FK users)
notes            (text nullable)
timestamps       (+ attachment_path added later)
index: (from_account_id, to_account_id)
```

#### `wallets` (legacy treasury e-wallet row) — Migration `2026_05_04_120001_create_wallets_table.php`
```
id (bigint PK)
name           (string)
wallet_number  (string nullable)
balance        (decimal(15,2) default 0)
notes          (text nullable)
is_active      (bool default true)
timestamps
```
> This is **NOT** user-owned. It's an admin-managed "named e-wallet bucket" (per the model docblock: *"Flight treasury e-wallet row for admin + future Vue booking settlement."*). It has **no `customer_id` / `owner_id`**.

#### `wallet_types` (master list) — Migration `2026_05_06_000001_create_wallet_types_table.php`
```
id (bigint PK)
name (string)
code (string, unique)
is_active (bool default true)
sort_order (unsignedInt default 0)
timestamps
```

#### `wallet_transactions` (cashier / day-to-day operations) — Migration `2026_05_06_000002_create_wallet_transactions_table.php`
```
id (bigint PK)
wallet_type_id          (FK wallet_types)
customer_id             (FK customers nullable, nullOnDelete)
customer_name           (string)              ← captured at write time, fall-back for walk-in
wallet_number           (string 30)
type                    (string)              ← 'send' | 'receive'
amount                  (decimal(15,2))
service_fee             (decimal(15,2) default 0)
total_amount            (decimal(15,2))
wallet_account_id       (FK accounts)         ← the "e-wallet" (e.g. Vodafone Cash)
cash_account_id         (FK accounts)         ← the cashbox account used
income_transaction_id   (FK transactions nullable)
expense_transaction_id  (FK transactions nullable)
employee_id             (FK employees nullable)
created_by              (FK users nullable)
notes                   (text nullable)
timestamps + softDeletes
```
Follow-up `2026_06_02` adds `amount_paid` (decimal(15,2)) — used to track partial cash settlement vs full `total_amount`.

> Pattern: **one `wallet_transactions` row typically produces 2–3 GL transactions** (main income, main expense, optional settlement). The two transaction pointers (`income_transaction_id` / `expense_transaction_id`) are FKs back to the `transactions` table — that's the **double-entry anchor**.

### 2.2 Models (`app/Models/`) — financial core

| FQCN | Table | Note |
|---|---|---|
| `App\Models\Account` | `accounts` | Central GL row; **forbids direct balance writes** via `booted()→updating()`. Division-aware (`office` vs `tourism` + module name). Owns `module_type` invariant (liquidity=division, subject=specific module). |
| `App\Models\AccountEntry` | `account_entries` | Append-only ledger line. NO SoftDeletes. |
| `App\Models\Transaction` | `transactions` | Journal header; `type` enum + `module` enum + `entries()` hasMany + `transfer()` hasOne + `related` morphTo. Carries posting-context columns. |
| `App\Models\Transfer` | `transfers` | Inter-account movement with optional FX (`from_currency`, `to_currency`, `exchange_rate`, `converted_amount`). |
| `App\Models\Wallet` | `wallets` | Legacy admin-only e-wallet row. No customer link. |
| `App\Models\Wallet\WalletTransaction` | `wallet_transactions` | Cashier operation; SoftDeletes; `amount_paid`, `income_transaction_id`, `expense_transaction_id`. |
| `App\Models\Wallet\WalletType` | `wallet_types` | Master data. |
| `App\Models\AuditLog` | `audit_logs` | Generic model-change audit; recent migration `2026_08_19` adds `related_type`/`related_id` index. |
| `App\Models\RefundAuditLog` | `refund_audit_logs` | EMP_REFUND_AUDIT_20260817 — per-refund audit row. |
| `App\Models\LedgerReconciliationRun` | `ledger_reconciliation_runs` | Re-run header. |
| `App\Models\LedgerReconciliationFinding` | `ledger_reconciliation_findings` | Per-finding row. |
| `App\Models\Customer` | `customers` | Owns `account_id` → `Account` (subject / customer type). |
| `App\Models\Supplier` | `suppliers` | Owns `account_id` → `Account` (supplier type). |

### 2.3 Wallet / Transfer endpoints (full HTTP inventory)

#### `/v1/wallet/*` — cashier / day-to-day
| Method | URI | Auth | Implementation |
|---|---|---|---|
| GET | `/v1/wallet/dashboard` | `auth:sanctum`, `active`, `Capture*`, `Reject*` | `Wallet\TransferDashboardController@index` |
| GET | `/v1/wallet/types` | same | `Wallet\WalletTypeController@index` |
| GET | `/v1/wallet/customer-balances` | same | `Wallet\WalletTransactionController@customerBalances` |
| GET | `/v1/wallet/customer-statement` | same | `Wallet\WalletTransactionController@customerStatement` |
| GET | `/v1/wallet/transactions/daily-summary` | same | `Wallet\WalletTransactionController@dailySummary` |
| **POST** | **`/v1/wallet/transactions`** | same **+ `permission:wallet.create`** | `Wallet\WalletTransactionController@store` → `WalletTransactionService::createTransaction` |
| PUT/PATCH | `/v1/wallet/transactions/{transaction}` | same **+ admin** | `WalletTransactionController@update` |
| DELETE | `/v1/wallet/transactions/{transaction}` | same **+ admin** | `WalletTransactionController@destroy` |
| GET | `/v1/wallet/transactions/{transaction}` | same | `WalletTransactionController@show` |
| GET | `/v1/wallet/treasury/overview` | same | `Wallet\TransferTreasuryController@overview` |
| GET | `/v1/wallet/treasury/accounts/{account}/transactions` | same | `Wallet\TransferTreasuryController@accountTransactions` |

> Index = `auth:sanctum + active + Capture* + Reject*` (read access for any authenticated user). Write access for `POST` requires `wallet.create` permission; updates and deletes are **admin-only** (financial documents).

#### `/v1/finance/transfers` — admin inter-account movement
| Method | URI | Auth | Implementation |
|---|---|---|---|
| **POST** | `/v1/finance/transfers` | `auth:sanctum + active + Capture* + Reject* + **admin**` | `AccountController@transfer` → `TransactionService::recordTransfer` |
| GET | `/v1/finance/transfers` | same + admin | `AccountController@transferHistory` |

Validated by `App\Http\Requests\Finance\StoreTransferRequest`:
- `from_account_id`: required, exists. In `withValidator()`: must be in `LIQUIDITY_TYPES` (cashbox/bank/wallet), must be active.
- `to_account_id`: required (or `to_account_name`), must exist, must `different:from_account_id`.
- `to_account_name`: optional free-text. When used, an `expense` account is looked up or auto-created under the resolved `module_type`.
- `amount`: required, numeric, `min:0.01`.
- `converted_amount` & `exchange_rate`: required when `from.currency != to.currency`.
- `module`: optional enum `TransactionModule`. `type`: optional enum `TransactionType`.

#### `/v1/finance/accounts*` & `/v1/finance/transactions` (admin)
- `apiResource(accounts)` (except `index`, `destroy`) → admin.
- `POST /finance/accounts/{account}/deactivate`, `GET /finance/accounts/{account}/statement` → admin.
- `apiResource(transactions)` (except `index`, `show`) → admin.
- `apiResource(approvals)` → admin.
- `apiResource(audits)` → admin.
- `apiResource(currencies)` + `convert` + `set-rate` + `active-rates` → admin.
- `GET /finance/treasuries/*` → admin.

#### `/v1/finance/recharge*` flows (admin only)
- `POST /flight/carriers/{carrier}/recharge` — `FlightCarrierController@recharge`
- `POST /flight/groups/{group}/pay-debt` — `FlightGroupController@payDebt`
- `POST /flight/systems/{system}/recharge` & `carriers/{carrier}/recharge` — `FlightTreasuryController@recharge*`
- `POST /fawry/machines/{id}/recharge` — `Fawry\FawryMachineApiController@recharge`
- `POST /customers/{customer}/pay-debt` — `CustomerController@payDebt`
- `POST /bus/companies/{company}/pay-debt`, `/bus/inventories/{busInventory}/pay-debt` — `BusCompanyController` / `BusInventoryController`
- `POST /hajj-umra/executing-companies/{company}/withdraw,repay` — admin
- `POST /visa/agents/{agent}/withdraw,repay` — admin
- `POST /suppliers/{supplier}/account/recharge` — admin
- `POST /visa/customers/{customer}/pay-debt` — admin

### 2.4 Services — financial core

| Service | Responsibility |
|---|---|
| `App\Services\Finance\AccountingService` | Preferred posting façade (config-flagged); backs `TransactionService`. |
| `App\Services\Finance\TransactionService` | **The heart of double-entry**: `recordIncome`, `recordExpense`, `recordTransfer`, `reverseTransaction`, `recordJournalTransfer`. |
| `App\Services\Finance\AccountService` | Account CRUD + statement + credit/debit primitives. |
| `App\Services\Finance\AccountRechargeService` | Recharge helpers. |
| `App\Services\Finance\PrepaidLedgerService` | Prepaid pool (flight/fawry). |
| `App\Services\Finance\SupplierAccountService` | Supplier payable ledger. |
| `App\Services\Finance\ApprovalService` | Multi-step approval workflow orchestration. |
| `App\Services\Finance\AuditService` | Model-change audit. |
| `App\Services\Finance\TransactionAuditStamper` | Stamps HTTP context onto transactions. |
| `App\Services\Finance\CurrencyService` | FX conversion + active rates. |
| `App\Services\Finance\RefundAuditLogger` | EMP_REFUND_AUDIT_20260817 — per-refund audit logger. |
| `App\Services\Finance\DeferredTransactionDeletionGuard` | Production-safety guard that refuses destructive deletes when later debt payments exist. |
| `App\Services\Finance\LedgerClearingAccounts` | Resolver for clearing accounts (income/expense per module). |
| `App\Services\Finance\LedgerEntryDescriptionResolver` | Builds human-readable entries for statements. |
| `App\Services\Finance\LedgerReconciliationService` | Σ debit vs credit reconciliation. |
| `App\Services\Finance\LedgerRepairService` | Repair drifted balances. |
| `App\Services\Finance\TreasuryAccountResolver` | Maps `TreasuryAccount` enum → `Account`. |
| `App\Services\Finance\TreasuryLedgerMirror` | Mirrors GL balance onto `Treasury` model (legacy). |
| `App\Services\Finance\TreasuryService` | Legacy treasury credit/debit. |
| `App\Services\Finance\TrialBalanceExportService` | Exports the trial balance. |
| `App\Services\Wallet\WalletTransactionService` | The cashier / operational layer for `WalletTransaction`: `createTransaction`, `updateTransaction`, `deleteTransaction`, `getAllTransactions`, `getDailySummary`, `customerBalances`, `customerStatement`. |

### 2.5 Validation rules, guards, middleware

- `App\Rules\TransferLiquidityAccount` — allows only `module_type=wallet_transfer|office` × `type∈LIQUIDITY_TYPES` × `is_active=true` for the wallet-related liquidity accounts.
- `App\Support\Finance\LedgerBalanceMutationGuard` — explicit allow-list marker for services that must mutate `Account.balance` directly; otherwise `Account::booted()→updating()` throws.
- `App\Support\Finance\PostingContext` + `PostingContextRegistry` — request-scoped registry holding `correlation_id`, `http_method`, `request_route`, `client_ip`, `user_agent` per request.
- `App\Support\Finance\AccountModuleContract` — division/module enforcement.
- `App\Support\Finance\AccountModuleDivision` — `office` and `tourism` divisions + membership checks.
- `App\Support\Finance\DeadlockRetry` — transaction retry-on-deadlock helper.
- `App\Http\Middleware\CaptureFinancialPostingContext` — sets the `PostingContextRegistry` for the duration of the request.
- `App\Http\Middleware\RejectBannedFinancialBypassMarkers` — rejects (403) the query/body param `direct_financial_write` and the header `X-Allow-Direct-Ledger`.

### 2.6 Filament resources

The admin panel exposes **per-module wallet account resources**:
- `App\Filament\Admin\Resources\TransferAccounts\*` (bank / cashbox / wallet)
- `BusWallets\*`, `FawryWallets\*`, `FlightWallets\*`, `HajjUmraWallets\*`, `OnlineWallets\*`, `VisaWallets\*`
- `WalletAccounts\WalletAccountResource.php`, `WalletTransactions\WalletTransactionResource.php`, `WalletTypes\WalletTypeResource.php`
- `OfficeAccounts\*`, `TourismAccounts\*` (banks / cashboxes / wallets at division level)
- `Finance\AccountResource` (top-level) + `Page: AccountStatement.php`

### 2.7 Frontend — Vue 3 SPA

Wallet/Transfer/Transaction surfaces in the Vue SPA (Pinia stores: `walletStore`, `financeStore`, `accountStore`, `customerStore`, `supplierStore`; composables: `useCrossCurrencyTransfer`, `useLedgerBalance`, `useTreasuryAccountGroups`):

- `resources/js/views/finance/TransferCreate.vue`
- `resources/js/views/finance/TransferHistory.vue`
- `resources/js/views/finance/TransfersIndex.vue`
- `resources/js/views/finance/TransactionCreate.vue`
- `resources/js/views/finance/TransactionsIndex.vue`
- `resources/js/views/finance/TransactionShow.vue`
- `resources/js/views/finance/AccountStatement.vue`
- `resources/js/views/finance/TreasuryOverview.vue`
- `resources/js/views/finance/TrialBalance.vue`
- `resources/js/views/finance/ProfitLoss.vue`
- `resources/js/views/wallet/*` (operational cashier wallet)
- `resources/js/views/customers/GroupDebtBalancesSection.vue` (financial)

### 2.8 Tests (already on disk)

`tests/Feature/Wallet/`:
- `WalletTransactionCrudTest`
- `WalletTransactionCrossModuleIsolationTest`
- `UseOfficeDepartmentWalletsTest`

`tests/Feature/Finance/` (most relevant for cross-cutting finance):
- `AccountBalanceInvariantTest` — **directly asserts the canonical invariants**.
- `AccountModuleDivisionRuleTest`, `AccountSavingRulesTest`, `LiquidityAccountRulesTest`
- `CurrencyServiceEdgeCasesTest`
- `DeferredTransactionDeletionGuardTest`
- `FinanceLiquidityResourceIsolationTest`
- `FinanceTransactionCreateTest`, `FinanceTransferHistoryTest`
- `LedgerRepairTest`
- `OfficeTrialBalanceIntegrityTest`, `TourismTrialBalanceIntegrityTest`, `TrialBalanceTest`
- `PrepaidCogsTest`, `RecordTransferAllowNegativeBalanceTest`
- `RefundAuditLoggerTest`, `SupplierAccountTest`
- `TreasuryOverviewIntegrityTest`, `TreasuryOverviewTest`, `UnifiedVaultsE2ETest`
- `FlightDashboardTest` (used by Finance flow)

Also `tests/Feature/Reports/OperationsLedgerTest.php`, `tests/Feature/Security/AuthorizationGatesTest.php`, `tests/Feature/Security/RateLimitTest.php`.

`tests/Unit/Finance/AccountingServiceTest.php`, `CurrencyServiceTest.php`, `LedgerEntryDescriptionResolverTest.php`, `UnifiedLiquidityGrouperTest.php`.

---

## 3. Financial Model — Hypothesized from code

> IMPORTANT: the rules below are **derived from code** and **will be re-derived** during PHASE 7–10 by running tests + reading DB. Anything unverified at runtime is flagged.

### 3.1 The ledger

There is **one** authoritative source of money: `account_entries`. Rows are immutable and append-only. `Account.balance` is a **cached snapshot** of `SUM(credit) − SUM(debit)` for that account. Mutations to `Account.balance` are forbidden outside canonical services — enforced by `Account::booted()→updating()` throwing, plus the `LedgerBalanceMutationGuard::run()` allow-list marker.

There is **one** authoritative source of double-entry pairing: `transactions` is the journal header, and `account_entries` lines tie to it via `transaction_id`. Every `transaction_id` SHOULD have its `AccountEntry` rows summing to zero (`SUM(debit) = SUM(credit)`).

There are **three sign-conventions** to be careful with:
1. **On `transactions.amount`** — magnitude; sign comes from `from_account_id` vs `to_account_id` direction.
2. **On `account_entries`** — `debit`/`credit` per PROJECT convention: **credit increases balance, debit decreases balance** (explicit in `Account.php` lines 27–98; the opposite of textbook double-entry).
3. **On `Account.balance`** — the **net of (credit − debit)**, supports negatives per account type (e.g. AP balance < 0 = "we owe supplier"; AR > 0 = "customer owes us").

### 3.2 The three money-movement surfaces (re-stated)

1. **Cashier / wallet cash operations** — `POST /v1/wallet/transactions` (cashier, requires `wallet.create`):
   - Customer-registered flow posts **2–3 `Transaction` rows** (main income, main expense, optional settlement). `POST` updates `WalletTransaction.income_transaction_id` + `expense_transaction_id`. Settlement identifies via account-pair pattern (cash ⇄ customer) NOT by `type`.
   - Anonymous / walk-in flow skips settlement.
   - `total_amount` = `amount ± service_fee` depending on `type` (Send: `amount+fee`; Receive: `amount-fee`).
   - `amount_paid` defaults to `total_amount`; can differ for partial settlement (debt scenario).
   - **For walk-in, `amount_paid` represents the cash flow at write time, NOT a subsequent payment** — affects only later-deletion guard logic.

2. **Admin inter-account transfer** — `POST /v1/finance/transfers` (admin):
   - Validates that `from_account_id` ∈ `LIQUIDITY_TYPES` × `is_active=true`. `to_account_id` must be either liquidity or expense-account; same-currency or FX (requires `converted_amount` + `exchange_rate`).
   - When `to_account_id` is empty and `to_account_name` provided, an `expense` account is looked up by name + module_type or auto-created (with auto-credentialed `name`, `type=expense`, `balance=0`, `owner_type='owner'`).
   - Calls `TransactionService::recordTransfer($data)` → `Transfer` row + paired `Transaction` + paired `AccountEntry` rows.

3. **Recharge / pay-debt flows** (admin only) — `POST /flight/carriers/{id}/recharge`, `/flight/systems/{id}/recharge`, `/flight/groups/{id}/pay-debt`, `/fawry/machines/{id}/recharge`, `/customers/{id}/pay-debt`, `/visa/agents/{id}/withdraw,repay`, `/hajj-umra/executing-companies/{id}/withdraw,repay`, `/bus/companies/{id}/pay-debt`, `/suppliers/{id}/account/recharge`. All gated by `admin`. Each flow runs under its own service (`*RechargeService`, `*FinanceController`, `CustomerController@payDebt`).

### 3.3 The state machine (probable)

| Entity | States (probable) | Notes |
|---|---|---|
| `WalletTransaction` | soft-deleted / not; `type` ∈ {Send, Receive}; `amount_paid` 0…total_amount | No explicit `status` enum — soft-delete is the only "cancel". Reverse is additive at GL. |
| `Transaction` | `type` ∈ {Income, Expense, Transfer, Refund, Writeoff}; entries sum to 0 | No "status" — reverse via new rows with `"عكس:"` notes. |
| `Transfer` | No `status` field — reverse via `reverseTransaction` on its `Transaction`. | Has `approval_workflow_id` (nullable). |
| `Account` | `is_active` boolean; cannot be deleted if balance ≠ 0 OR if entries exist (`canBeDeleted()`). | Liquidity vs Subject classification by `type`. |
| `accounts.type` enum (current) | `cashbox, wallet, bank, customer, supplier, expense, revenue, liability, owner` (the `treasury` + `post` cases were removed in Phase 3.5b cleanup). | `AccountType.php` enum mirrors. |

### 3.4 Currency

- All amounts stored as `decimal(15,2)` with currency `string(3)`.
- FX is computed **explicitly** via `exchange_rate` + `converted_amount` (no auto-implicit FX).
- `CurrencyService` provides `convert`, `setRate`, `getActiveRates`.
- `accounts.type='wallet'` has optional `wallet_provider` enum (`WalletProvider`) + `wallet_number` (string) for finer-grained identification (Vodafone Cash / Etisalat / InstaPay).

### 3.5 Audit

- Every model mutation that goes through services writes an `audit_logs` row (via `AuditService`).
- `WalletTransactionService` writes `wallet_transaction.created/updated/deleted` directly with old/new values and a `scope` (`official_module` if `module='wallet_transfer'`, else `office_department`).
- Refunds write to `RefundAuditLog` (EMP_REFUND_AUDIT_20260817).
- All transaction writes carry `correlation_id`, `http_method`, `request_route`, `client_ip`, `user_agent` — populated by `CaptureFinancialPostingContext` middleware.

### 3.6 Idempotency

| Table | `idempotency_key` added |
|---|---|
| `hajj_umra_payments` | `2026_08_15_143500` |
| `flight_payments` | `2026_08_15_150000` |
| `visa_payments` | `2026_08_15_200000` |
| `refund_requests` | `2026_08_17_120100` |
| `bus_payments` | `2026_08_20_053507` |

> ⚠️ **Notable absence:** there is **NO** `idempotency_key` on `wallet_transactions` or `transfers`. This is a finding the audit must verify — if a cashier retries the same POST `/wallet/transactions`, money may double-move.

---

## 4. Open Questions (must be answered at runtime)

1. **Does `WalletTransactionService::createTransaction` honor `Idempotency-Key`?** → check `StoreWalletTransactionRequest` for header support and `WalletTransaction` table for a column.
2. **What is the exact behaviour of the `RejectBannedFinancialBypassMarkers` middleware in tests?** — does PHPUnit disable it via `ACCOUNTING_REJECT_FINANCIAL_BYPASS_MARKERS=false`? Need to run a probe.
3. **Is `Account::balance` actually used as a cached column, or is it computed on-the-fly?** → A/B compare `Account.balance` vs `SELECT SUM(credit-debit)`; anything above `0.05` tolerance is a finding.
4. **What happens when a `WalletTransaction` for a customer is created when the customer has no `account_id`?** → `ensureCustomerAccount()` is called; check idempotency and the silent re-tag of `module_type` from `office` to `wallet_transfer`.
5. **`accountForSend` vs `accountForReceive` — exact double-entry pairs**:
   - Registered customer Send: Income → customer_account (totalAmount), Expense → wallet_account (amount). Optional settlement: Income → cash_account (amountPaid) with `contra_account_id` = customer.
   - Anonymous Send: Income → cash_account (totalAmount), Expense → wallet_account (amount). No settlement.
   - Registered customer Receive: Income → wallet_account (amount), Expense → customer_account (totalAmount). Optional settlement: Expense → cash_account (amountPaid) with `contra_account_id` = customer.
   - Anonymous Receive: Income → wallet_account (amount), Expense → cash_account (totalAmount).
   - **TOTAL MONEY CONSERVATION:** When fees exist, who debits and who credits? Read `accountForSend/Receive` precisely and check whether fees leave the system, accumulate in a clearing account, or get credited to a `wallet_transfer` income clearing per `config/accounting.php`.
6. **Concurrency:** does the service use `SELECT ... FOR UPDATE` (row lock) on `Account` and `AccountEntry`? Or is the whole flow wrapped in `DB::transaction` only?
7. **Cross-currency transfer** at `/v1/finance/transfers`: precision/rounding behaviour; `converted_amount` interaction with `MIN_AMOUNT`; what if `from.currency != to.currency` but user sends `converted_amount` ≠ `amount × exchange_rate`?
8. **`Account::canBeDeleted()`** — refuses delete if balance ≠ 0 OR entries exist. But the new flow goes through `update` → `deactivate`. Is there a path that bypasses this?
9. **Filament admin policy on `WalletTransactionResource`** — does it allow any role to mutate?
10. **What is the behaviour when an `attachment_path` is submitted at POST /finance/transfers?** — stored on which row? Code shows it's attached to the data sent to `recordTransfer`.
11. **Pagination and listing filters for `/wallet/transactions`** — what is the per-role filter? Service has no explicit role-based filter; the controller relies on Laravel pagination.
12. **Is the JWT / Sanctum token type key-bound or unbounded?** → `auth:sanctum` is standard Sanctum but token expiry is `SANCTUM_EXPIRATION=120` (minutes).

---

## 5. Schema for the upcoming tests (PHASE 6 → PHASE 17)

The audit will reuse the existing test infrastructure (Filament / Feature / phpunit) and **will NOT** spawn its own test runner. The target pattern:

- `tests/Feature/Wallet/` — extend with new dedicated tests (positive / negative / security / financial / concurrency / idempotency / drift / reconciliation).
- `tests/Feature/Finance/` — extend with cross-cutting finance tests.
- `tests/Unit/Finance/` — extend with smaller unit tests on services.

A baseline `WalletTestCase` (analogous to existing `BusTestCase`, `HajjUmraTestCase`, `VisaTestCase`) may be introduced with seeded:
- 1 office `cashbox` account (EGP, balance 1000.00)
- 1 `wallet_transfer`-tagged `Wallet` liquidity account (EGP, balance 500.00)
- 1 cashbox in USD (balance 100.00)
- 1 cashbox in SAR (balance 100.00)
- 1 registered customer (EGP, balance 0.00)
- 1 registered customer (EGP, balance 500.00)
- 1 walk-in transaction fixture
- Various `WalletType` rows
- Currency service with deterministic rates (e.g. 1 USD = 50 EGP exactly, 1 SAR = 13.33 EGP)

> Fixtures are pinned to known balances so the **independent oracle** can verify exact sums without depending on the system under test.

---

## 6. The Audit will NOT modify:

- `composer.json`
- any `.env*`
- `phpunit.xml`
- any production-flag (`ACCOUNTING_*`) default
- any service under test — except where a **finding** demands a documented, user-approved fix in a separate Phase.

The audit **WILL** add:
- new test files under `tests/Feature/Wallet/` and `tests/Feature/Finance/` (audit deliverables)
- new helpers under `tests/` (`WalletTestCase`)
- new docs under `.zcode/plans/` (this discovery + the 8 artifact files listed in the master prompt)

---

## 7. Decision points (need your input before PHASE 2)

These shape what the audit actually runs. Defaults are sensible — answer if you want to override.

| # | Decision | Default I'll use if you don't answer |
|---|---|---|
| 1 | **Audit scope:** only `/v1/wallet/*` and `/v1/finance/transfers` (plus the recharge/pay-debt endpoints that move money between named accounts)? | **Yes** — only wallet + transfer + recharge + pay-debt, since those are the surfaces that touch user-owned wallets and admin-only transfers. Other modules' payment flows (Hajj / Visa / Bus / Flight / Fawry / Online / Payroll) are out of scope for THIS audit but their ledger-style APIs remain usable as test surface if needed. |
| 2 | **Concurrency depth:** use forged `DB::beginTransaction` + raw UPDATE for race tests, or use parallel HTTP via `pcntl_fork` / `popen`? | **`DB::beginTransaction` (same-process) + manual 2 connections when feasible** — keeps tests deterministic on Windows. |
| 3 | **Filament E2E:** audit the Filament admin pages (`WalletTransactionResource`, `WalletAccounts`, `TransferAccounts`, `OfficeAccounts`)? | **Yes, lightweight** — open the pages, render the table, capture the form schema, run a small edit/delete trial. No full browser-automation suite. |
| 4 | **Currency rates in oracle:** lock to deterministic values (`1 USD = 50 EGP`, `1 SAR = 13.33 EGP`)? | **Yes** — so test outcomes are reproducible. |
| 5 | **Idempotency coverage:** test idempotency-key middleware behaviour via `Idempotency-Key` header on POST `/wallet/transactions` and `/finance/transfers`? | **Yes for `/wallet/transactions` and `/finance/transfers`** — those don't yet have idempotency_key columns; the test will document whether the route honours the header at all. |
| 6 | **Decimals/Rounding matrix:** exhaustive matrix (0.01, 0.02, ..., 999.99) or sampled? | **Sampled matrix: 0.01, 0.02, 0.03, 0.10, 0.99, 1.01, 10.01, 100.01, 999.99, plus fee sweep 1%, 2.5%, 5%, 10%**. |
| 7 | **Recon tolerance:** use `config('accounting.reconciliation.tolerance')` (env default `0.02`) for the post-test reconciliation, or stricter (0.005)? | **Strict 0.005** for assertions in tests, falling back to config tolerance only as warning. |
| 8 | **Mass-assignment probes:** test Forge-style attack on `Account.balance` via direct PATCH/PUT (even though middleware rejects), plus via hack request? | **Yes — but only as a defensive probe with `EXPECT 4xx`, never destructive**. |

---

## 8. PHASE 1 — Runtime Refinement (R1, R2, R3, R4)

After the structural discovery, I ran `php artisan route:list` and a project-wide grep to verify some of the code-level claims against the live framework. Three findings worth highlighting before test execution:

### R1 — `GET /api/v1/wallet/transactions` (index) is missing from the route table ⚠️

- The file `routes/api.php` declares: `POST /wallet/transactions`, `GET /wallet/transactions/{transaction}` (show), `PUT/PATCH /wallet/transactions/{transaction}` (update), `DELETE /wallet/transactions/{transaction}` (destroy), `GET /wallet/transactions/daily-summary`.
- There is **NO** `GET /api/v1/wallet/transactions` declared.
- Empirical confirmation — `php artisan route:list | grep wallet`:
  ```
  GET|HEAD  api/v1/wallet/customer-balances     ✔
  GET|HEAD  api/v1/wallet/customer-statement     ✔
  GET|HEAD  api/v1/wallet/dashboard              ✔
  POST      api/v1/wallet/transactions           ✔  (no GET index counterpart)
  GET|HEAD  api/v1/wallet/transactions/daily-summary  ✔
  GET|HEAD  api/v1/wallet/transactions/{transaction}  ✔
  PUT|PATCH api/v1/wallet/transactions/{transaction}  ✔
  DELETE    api/v1/wallet/transactions/{transaction}  ✔
  GET|HEAD  api/v1/wallet/treasury/accounts/{account}/transactions ✔
  GET|HEAD  api/v1/wallet/treasury/overview      ✔
  GET|HEAD  api/v1/wallet/types                  ✔
  ```
- Yet `WalletTransactionController::index()` is implemented and the `walletStore` Vue store calls `/api/v1/wallet/transactions` (GET). Either the SPA gets 404 on this endpoint at runtime, or there's a fallback. **Either way: a defect.** The audit will document this and probe the actual runtime behaviour.

### R2 — `routes/finance.php` exists but is **dead code** at runtime ✅ (no actual exposure)

- The file `routes/finance.php` exists (23 lines, 7 routes):
  ```php
  Route::prefix('finance')->middleware(['auth:sanctum'])->group(function () {
      Route::get('/', [AccountController::class, 'index']);
      Route::post('/', [AccountController::class, 'store']);
      Route::get('/{id}', [AccountController::class, 'show']);
      Route::put('/{id}', [AccountController::class, 'update']);
      Route::patch('/{id}/deactivate', [AccountController::class, 'deactivate']);
      Route::get('/{id}/statement', [AccountController::class, 'statement']);
      Route::post('/transfers', [AccountController::class, 'transfer']);
  });
  Route::prefix('suppliers')->middleware(['auth:sanctum', 'active'])->group(function () {
      Route::get('/{supplier}/account/recharge', [SupplierAccountController::class, 'recharge']);
      Route::post('/{supplier}/account/recharge', [...]);
      Route::get('/{supplier}/account/statement', [...]);
      Route::get('/{supplier}/account/balance', [...]);
  });
  ```
- Critically — note that **`POST /finance/transfers`** here has only `auth:sanctum` (no `admin`!). If this file were loaded, a normal cashier could create admin-level transfers. That would be a **CRITICAL** security defect.
- However: `bootstrap/app.php`'s `withRouting(...)` only loads `web.php`, `api.php`, `console.php`. There is no `RouteServiceProvider` and no `then:` closure pointing to `finance.php`. A project-wide `grep -rn "finance\.php\|require.*finance" --include="*.php"` returned zero references outside the file itself.
- Empirical confirmation — `php artisan route:list | grep finance`:
  ```
  The only `finance` URI present is `api/v1/finance/*` — confirmed via `route:list --json`.
  No `/finance` (without `/api/v1`) URI exists in the route table.
  ```
- **Conclusion:** `routes/finance.php` is **NOT loaded at runtime**. The file is dead code with a dangerous-looking POST endpoint. Audit recommendation: DELETE the file (out of scope; recorded as a hygiene finding).

### R3 — `HajjUmraBookingService::update()` contains an unresolved git merge conflict (out of scope, informational only)

- The third agent reported that `app/Services/HajjUmra/HajjUmraBookingService.php::update()` contains `<<<<<<< Updated upstream / ======= / >>>>>>> Stashed changes` markers.
- This is **out of scope** for this wallet/transfer audit (Hajj module has its own audit thread). It is mentioned only as a project-hygiene signal — please verify and resolve that file separately before any Hajj-related audit touches it.

### R4 — Test environment blocker: PHPUnit cannot start due to project-wide unresolved merge conflicts ⚠️ CRITICAL

**This is a STOP-the-audit blocker discovered while validating the test environment per Rule #2.**

#### Evidence

The pre-PHASE 6 smoke test (`php artisan test --filter=ExampleTest`) failed immediately with:

```
An error occurred inside PHPUnit.
Message:  syntax error, unexpected token "<<", expecting "function"
Location: C:\travile\SafarakEalayna\tests\Feature\HajjUmra\HajjUmraApiTest.php:307
```

A repo-wide grep `grep -rlE '^(<<<<<<<|=======|>>>>>>>)' --include="*.php" . | grep -v vendor` returned **5 files** with unresolved git merge-conflict markers (confirmed by initial git status which showed `UU` for all 5):

| # | File | Conflict pairs | In scope for this audit? |
|---|---|---|---|
| 1 | `app/Services/HajjUmra/HajjUmraBookingService.php` | 2 pairs | ❌ (Hajj module — out of scope per R3) |
| 2 | `app/Services/Visa/VisaBookingService.php` | 1 pair | ❌ (Visa module — out of scope) |
| 3 | `tests/Feature/HajjUmra/HajjUmraApiTest.php` | 1 pair | ❌ (Hajj test — out of scope) |
| 4 | `tests/Feature/HajjUmra/HajjUmraControllerTest.php` | (file 4) | ❌ (Hajj test — out of scope) |
| 5 | `tests/Feature/HajjUmra/HajjUmraProductionE2ETest.php` | 4 pairs | ❌ (Hajj test — out of scope) |

**Total: 30 unresolved `<<<<<<<` / `=======` / `>>>>>>>` markers across 5 files.**

#### Why this stops the audit

PHPUnit scans the entire tests directory before running **any** test. The parse error at `HajjUmraApiTest.php:307` halts loading. As a result:

- **No existing test can be executed** — neither wallet/transfer tests (which don't even exist yet) nor unrelated tests like `ExampleTest`.
- I cannot run the smoke-check that Rule #2 requires ("verify and document the actual test database connection").
- I cannot even prove that PHPUnit honours the in-memory SQLite config in this branch, because PHPUnit never reaches the bootstrap stage of any test suite.
- All five affected files are **out of audit scope** (Hajj/Visa modules). Per R3 / R5 ("Do not modify it") I cannot unilaterally resolve them.

#### Options that require user authorization

| Option | What it touches | Authorization needed |
|---|---|---|
| **A. Resolve the conflicts** | The 5 out-of-scope files | **Explicit override of R3 / R5** ("Do not modify" rule). The audit will then proceed normally. |
| **B. Exclude the broken test files temporarily** | A `phpunit.xml` edit OR `--exclude-group` flag | Requires editing `phpunit.xml` (which Rule 1 forbids), OR a side-by-side config file the framework never reads. |
| **C. Move the broken files aside** | A filesystem move of 3 test files to `tests/.excluded/` | Technically not editing source, but functionally the same as exclusion — risk of confusing later audits. |
| **D. STOP the audit** | None — leave files as-is | No override needed; the audit is parked until Hajj/Visa is fixed. |

**Recommendation:** Option A (resolve the 5 conflict files) — a real upstream maintainer would have done this on the same branch. None of the conflict hunks touch wallet/transfer code, so the audit scope is unaffected after the fix.

#### What I will NOT do

- ❌ Resolve the conflicts unilaterally (R3/R5 forbids it).
- ❌ Edit `phpunit.xml` (Rule 1 forbids it).
- ❌ Modify `composer.json` (Rule 1).
- ❌ Skip the verification step or mark PHASE 6 as "complete" on the basis of stale evidence.

---

## 10. Updated STATUS — Awaiting your sign-off (with blocker)

**STOP POINT — Pre-PHASE 6 blocker (R4).** Per Rule #2 ("If the environment is ambiguous or appears to be production: STOP") and Rule 1 ("REPORT-ONLY. Do not fix discovered defects during the audit"), I have stopped and recorded the blocker. **No tests have been executed. No code has been modified.**

Please choose option A / B / C / D from the R4 table above. Once chosen, I will:

1. Carry out the chosen remediation **without** touching any wallet/transfer source files.
2. Verify the test environment boots cleanly (run `ExampleTest` to confirm green).
3. Resume PHASE 6 → PHASE 17 in the agreed order.

When you approve (with R4 disposition), I will:

**STOP POINT.** Per Rule 1 of the master prompt, no tests have been executed and no production code has been modified.

When you approve, I will:

When you approve, I will:

1. PHASE 2 — Build the database / financial model mapping into a runnable schema.
2. PHASE 3 — Inventory each API endpoint with parameter-by-parameter notes.
3. PHASE 4 — Map the frontend surfaces.
4. PHASE 5 — Codify the financial invariants in PHP (independent oracle).
5. PHASE 6 — Lay out the automated test architecture + `WalletTestCase`.
6. PHASE 7 — Positive tests.
7. PHASE 8 — Negative tests (amounts, decimals, malformed).
8. PHASE 9 — Security / authorization (IDOR, parameter tampering, mass assignment).
9. PHASE 10 — Precision / rounding audit.
10. PHASE 11 — Idempotency / duplicate requests.
11. PHASE 12 — Concurrency / race conditions.
12. PHASE 13 — Failure / rollback testing.
13. PHASE 14 — Full E2E.
14. PHASE 15 — Financial reconciliation.
15. PHASE 16 — Final security audit.
16. PHASE 17 — Final report + verdict.

If anything in this discovery looks wrong, point me at the file & line and I'll re-read. If anything above is incomplete for your audit goals, tell me which area to deepen before tests begin.
