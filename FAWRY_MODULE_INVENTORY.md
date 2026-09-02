# FAWRY MODULE — FULL DISCOVERY INVENTORY (PHASE 0)

**Date:** 2026-08-20
**Auditor:** ZCode (autonomous Fawry audit mission, Phase 0)
**Scope:** Full Fawry module — Backend + API + Frontend + Admin + Database + Financial Touchpoints
**Method:** Read-only discovery (no production modifications, no bug fixes yet)

---

## ⚠️ EXECUTIVE SUMMARY (CRITICAL FINDING)

**One critical production-breaking bug discovered during Phase 0:**

| # | Severity | Location | Description |
|---|----------|----------|-------------|
| **B-1** | 🔴 **CRITICAL** | `app/Http/Controllers/Api/V1/Fawry/FawryDashboardController.php:36` | Uses `AccountModuleContract::LIQUIDITY_TYPES` **without** `use App\Support\Finance\AccountModuleContract;` import. Calling `GET /api/v1/fawry/dashboard` throws fatal `Class "App\Http\Controllers\Api\V1\Fawry\AccountModuleContract" does not exist`. Introduced by commit `b5e9843` (2026-08-16 — 4 days after the previous audit). |

This breaks the **single most-used endpoint** in the Fawry module (Dashboard loads on every page refresh of `/fawry/dashboard`, `/fawry/`, and any page that displays KPIs).

**Bug evidence (runtime PHP error reproduction):**
```
PHP Fatal error: Uncaught ReflectionException: Class
"App\Http\Controllers\Api\V1\Fawry\AccountModuleContract" does not exist
```

**Other positive finding:** The previously known `GREATEST()` portability defect flagged by the 2026-08-14 audit has been **silently fixed** (now uses plain `sum('ae.credit')`). The 22 PHPUnit failures that broke CI should now pass on SQLite.

**Recommendation:** STOP discovery. Recommend the user prioritize fixing B-1 immediately (one-line `use` statement addition), then proceed with the remaining audit phases. Per the prompt rule "لا تصلح Bugs أثناء مرحلة Discovery" — I am **not** fixing this bug now and will document it formally in PHASE 11 (Bug Reproduction) instead.

---

## 1. Discovery Methodology

1. ✅ Listed all Fawry-named files in the project (227 files match "fawry").
2. ✅ Read every Fawry controller, model, service, request, resource, enum, rule, factory, seeder, migration.
3. ✅ Read the core financial services Fawry depends on (`TransactionService`, `PrepaidLedgerService`, `LedgerClearingAccounts`, `DeferredTransactionDeletionGuard`, `AccountModuleContract`).
4. ✅ Read the fawry Pinia store + the most-loaded Vue view (`FawryDashboard.vue`).
5. ✅ Verified route registration via `php artisan route:list --path=api/v1/fawry`.
6. ✅ Ran runtime reflection checks against the controller to confirm the suspected bug.
7. ✅ Compared current state against the two previous audits (2026-07-21 Production Test + 2026-08-14 Full E2E Audit).

---

## 2. Module Architecture (Quick Map)

```
┌─────────────────────────────────────────────────────────────────┐
│                     FILAMENT ADMIN (8 resources)                │
│  FawryBanks • FawryCashboxes • FawryWallets • FawryCurrencies   │
│  FawryMachines • FawryOperationTypes • FawryPaymentMethods      │
│  FawryTransactions                                              │
└─────────────────────────────────────────────────────────────────┘
                          ↓ (settings stored in DB)
┌─────────────────────────────────────────────────────────────────┐
│                    DATABASE (5 main tables)                     │
│  fawry_transactions • fawry_machines • fawry_machine_transactions│
│  fawry_operation_types • fawry_payment_methods                  │
│  + shared: accounts • transactions • account_entries • customers │
└─────────────────────────────────────────────────────────────────┘
                          ↑
┌─────────────────────────────────────────────────────────────────┐
│              API LAYER (20 endpoints under /api/v1/fawry)       │
│  FawryDashboardController • FawryMachineApiController           │
│  FawrySettingsController • FawryTransactionController           │
│  FawryTreasuryController • FawryWalkInPaymentController         │
└─────────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────────┐
│                  SERVICE LAYER (2 services)                     │
│  FawryTransactionService (create / update / delete / list)       │
│  FawryMachineRechargeService (recharge from account)            │
│  + shared: TransactionService • PrepaidLedgerService           │
│  + shared: LedgerClearingAccounts • DeferredTransactionDeletionGuard │
└─────────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────────┐
│                  FRONTEND (Vue 3 + Pinia)                       │
│  9 Vue views • 1 Pinia store (fawryStore.js — 18 actions)       │
└─────────────────────────────────────────────────────────────────┘
```

---

## 3. Backend Inventory

### 3.1 Models (`app/Models/Fawry/`)

| Model | Soft Delete | Profit Guard | Balance Guard | Notes |
|-------|:-----------:|:------------:|:-------------:|-------|
| `FawryTransaction` | ✅ | ✅ `ModelProfitMutationGuard` | n/a | Auto-compute `profit` on `creating`; `saving` observer blocks direct `profit` writes. 9 relations, 4 scopes. |
| `FawryMachine` | ✅ | n/a | ✅ strict `updating` observer | `debit()`/`credit()` are the ONLY sanctioned paths. |
| `FawryMachineTransaction` | ❌ | n/a | n/a | Append-only ledger of machine debits/credits. |
| `FawryOperationType` | ✅ | n/a | n/a | Config table. |
| `FawryPaymentMethod` | ✅ | n/a | n/a | Config table. |
| `FawryCurrency` | ❌ | n/a | n/a | FK wrapper around `currencies` with fee tiers. |

### 3.2 Services (`app/Services/Fawry/`)

| Service | LOC | Public Methods | Notable Hardening |
|---------|----:|----------------|-------------------|
| `FawryTransactionService` | 928 | `getAllTransactions`, `createTransaction`, `updateTransaction`, `deleteTransaction`, `getTransactionById`, `getDailySummary` | `lockForUpdate` on account + machine; `runProfitMutation` wrap; idempotent delete; deficit auto-correction; `ensureNoLaterPayment` block; walk-in AR reclamation with FIFO |
| `FawryMachineRechargeService` | 87 | `rechargeFromAccount` | `lockForUpdate` on machine + source; uses `PrepaidLedgerService` for GL; handles cross-currency via posted EGP credit |

### 3.3 Controllers (`app/Http/Controllers/Api/V1/Fawry/`)

| Controller | Endpoints | Auth | Notes |
|------------|-----------|------|-------|
| `FawryDashboardController` | `GET /dashboard` | `auth:sanctum` | **🔴 BUG: missing `AccountModuleContract` import** |
| `FawryMachineApiController` | `GET /machines`, `GET /machines/{id}/transactions`, `POST /machines/{id}/recharge` (admin), `GET /accounts` | `auth:sanctum` (+ `admin` for recharge) | Correctly imports `AccountModuleContract` |
| `FawrySettingsController` | `GET /settings/{operation-types,payment-methods,currencies,all}` | `auth:sanctum` | Read-only config fetch |
| `FawryTransactionController` | `GET/POST/PUT/DELETE /transactions`, `GET /transactions/{id}`, `GET /transactions/daily-summary`, `GET /customer-balances`, `GET /customer-statement` | `auth:sanctum` (+ `permission:fawry.create` for POST) | GL-sourced debt calc |
| `FawryTreasuryController` | `GET /treasury/overview`, `GET /treasury/accounts/{account}/transactions` | `auth:sanctum` | Account-type guard |
| `FawryWalkInPaymentController` | `POST /walk-in/pay-debt` | `permission:fawry.create` | FIFO allocation, overpayment rejection, EGP-only |

### 3.4 Requests / Resources / Enums / Rules

| Path | Purpose | Notes |
|------|---------|-------|
| `app/Http/Requests/Fawry/StoreFawryTransactionRequest.php` | Create validation | Required: `operation_type`, `client_amount`, `fawry_price`, `selling_price`, `employee_id`, `account_id`, `payment_method`, `amount`. ✅ Uses `gt:0` on prices. |
| `app/Http/Requests/Fawry/UpdateFawryTransactionRequest.php` | Update validation | All `sometimes`; uses `gt:0` on prices. |
| `app/Http/Resources/Fawry/FawryTransactionResource.php` | API response shape | Resolves operation_type + payment_method labels via enum + relation. |
| `app/Http/Resources/FawryTransactionResource.php` | **DUPLICATE / ORPHAN** | Different namespace, different shape, **not used by any controller**. Dead code. |
| `app/Enums/FawryOperationType.php` | 4 enum cases | Withdrawal, Deposit, Payment, TravelPermit |
| `app/Enums/FawryPaymentMethod.php` | 5 enum cases | Cash, BankTransfer, CashWallet, OfficeSafe, OfficeDrawer |
| `app/Rules/FawryLiquidityAccount.php` | Validation rule | Accepts `module_type='fawry'` OR `module_type='office'`; rejects tourism division; rejects subject accounts. |
| `app/Filament/Admin/Concerns/BelongsToFawryModuleNavigation.php` | Filament nav grouping | Trait |
| `app/Filament/Admin/Support/FawryModuleNavigation.php` | Nav constants | String constants for nav group/sort |

### 3.5 Filament Resources (8 total)

| Resource | Pages | shouldRegisterNavigation | Model |
|----------|-------|:------------------------:|-------|
| `FawryBanks` | List / Create / Edit | `false` (Account-backed) | `Account` filtered by type=Bank + name contains "فوري" |
| `FawryCashboxes` | List / Create / Edit | `false` (Account-backed) | `Account` filtered by type=Cashbox + name contains "فوري" |
| `FawryWallets` | List / Create / Edit | `false` (Account-backed) | `Account` filtered by type=Wallet + name contains "فوري" |
| `FawryCurrencies` | List / Create / Edit | true | `FawryCurrency` |
| `FawryMachines` | List / Create / Edit | true | `FawryMachine` |
| `FawryOperationTypes` | List / Create / Edit | true | `FawryOperationType` |
| `FawryPaymentMethods` | List / Create / Edit | true | `FawryPaymentMethod` |
| `FawryTransactions` | Manage (single page) | true | `FawryTransaction` (soft-delete + edit + custom delete via service) |

---

## 4. Database Inventory

### 4.1 Migrations (10 Fawry migrations)

| # | Migration | Created | Purpose |
|---|-----------|---------|---------|
| 1 | `2026_04_27_160600_create_fawry_transactions_table.php` | base | Initial schema |
| 2 | `2026_05_02_000001_add_accounting_fields_to_fawry_transactions.php` | +5d | GL pointers (`expense_transaction_id`, `income_transaction_id`) |
| 3 | `2026_05_05_000001_update_fawry_transactions_table.php` | +3d | Schema tweaks |
| 4 | `2026_05_05_000002_create_fawry_settings_tables.php` | same | operation types + payment methods tables |
| 5 | `2026_05_05_000003_fawry_postal_method_and_default_currencies.php` | same | Postal method + currency seeds |
| 6 | `2026_05_14_030001_add_missing_columns_to_fawry_transactions_table.php` | +9d | client_id, payment_details JSON |
| 7 | `2026_06_02_000000_add_audit_columns_to_fawry_transactions_table.php` | +19d | created_by_user_id, updated_by_user_id, client_ip |
| 8 | `2026_06_02_000001_create_fawry_machines_table.php` | same | FawryMachine table |
| 9 | `2026_06_02_000002_create_fawry_machine_transactions_table.php` | same | Append-only machine ledger |
| 10 | `2026_06_02_000003_add_machine_id_to_fawry_transactions.php` | same | fawry_machine_id FK |

### 4.2 Key Tables Schema (verified via migration source)

#### `fawry_transactions` (final shape after all migrations)
```sql
id, client_id (FK customers), client_name, operation_type (enum),
client_amount (12,2), fawry_price (12,2), selling_price (12,2), profit (12,2),
employee_id (FK users), account_id (FK accounts), currency_id (FK currencies),
payment_method (enum), amount (12,2), reference_number, notes,
payment_details (JSON), expense_transaction_id (FK transactions),
income_transaction_id (FK transactions), fawry_machine_id (FK fawry_machines),
created_by_user_id, updated_by_user_id, client_ip,
created_at, updated_at, deleted_at
INDEX: employee_id, payment_method, created_at
```

#### `fawry_machines`
```sql
id, name, type (varchar: fawry/aman/masary/...), balance (12,2), is_active,
notes, created_at, updated_at, deleted_at
```

#### `fawry_machine_transactions`
```sql
id, fawry_machine_id (FK), fawry_transaction_id (FK nullable),
type (debit|credit), amount (12,2), balance_before (12,2), balance_after (12,2),
description, created_by (FK users), created_at, updated_at
```

#### `fawry_operation_types` / `fawry_payment_methods`
Standard config tables with soft-delete.

#### `fawry_currencies` (FK wrapper)
```sql
id, currency_id (FK currencies), exchange_rate (4dp), min_amount, max_amount,
fee_percent (2dp), fixed_fee (2dp), is_active, order, timestamps
```

### 4.3 Shared Tables Fawry Touches
- `accounts` (liquidity + customer + prepaid + walk-in AR)
- `transactions` (GL postings via `recordExpense` / `recordIncome` / `recordJournalTransfer`)
- `account_entries` (double-entry detail rows)
- `customers` (FK target for `client_id`)

---

## 5. API Inventory (20 endpoints under `/api/v1/fawry`)

```
GET    /api/v1/fawry/dashboard                                     [auth:sanctum]
GET    /api/v1/fawry/accounts                                       [auth:sanctum]
GET    /api/v1/fawry/customer-balances                              [auth:sanctum]
GET    /api/v1/fawry/customer-statement                             [auth:sanctum]
GET    /api/v1/fawry/machines                                       [auth:sanctum]
GET    /api/v1/fawry/machines/{id}/transactions                     [auth:sanctum]
POST   /api/v1/fawry/machines/{id}/recharge                         [auth:sanctum + admin]
GET    /api/v1/fawry/settings/all                                   [auth:sanctum]
GET    /api/v1/fawry/settings/currencies                            [auth:sanctum]
GET    /api/v1/fawry/settings/operation-types                       [auth:sanctum]
GET    /api/v1/fawry/settings/payment-methods                       [auth:sanctum]
GET    /api/v1/fawry/transactions                                   [auth:sanctum]
POST   /api/v1/fawry/transactions                                   [auth:sanctum + fawry.create]
GET    /api/v1/fawry/transactions/daily-summary                     [auth:sanctum]
GET    /api/v1/fawry/transactions/{fawryTransaction}                [auth:sanctum]
PUT    /api/v1/fawry/transactions/{fawryTransaction}                [auth:sanctum + admin]
DELETE /api/v1/fawry/transactions/{fawryTransaction}                [auth:sanctum + admin]
GET    /api/v1/fawry/treasury/accounts/{account}/transactions       [auth:sanctum]
GET    /api/v1/fawry/treasury/overview                              [auth:sanctum]
POST   /api/v1/fawry/walk-in/pay-debt                               [auth:sanctum + fawry.create]
```

---

## 6. Frontend Inventory (Vue 3 + Pinia)

### 6.1 Vue Views (9 files in `resources/js/views/fawry/`)
| File | Purpose |
|------|---------|
| `FawryDashboard.vue` | KPI dashboard — calls `fetchFawryDashboard()` |
| `FawryIndex.vue` | Transaction list |
| `FawryCreate.vue` | New transaction form (multi-step wizard) |
| `FawryEdit.vue` | Edit existing transaction |
| `FawryShow.vue` | Transaction detail view |
| `FawryMachinesIndex.vue` | Machine list + recharge UI |
| `FawryTreasury.vue` | Treasury overview for Fawry-module accounts |
| `FawryCustomerBalances.vue` | Customer debt list |
| `FawryApiResponsePanel.vue` | Debug / last API response viewer |

### 6.2 Pinia Store (`resources/js/stores/fawryStore.js`)

| Category | Actions |
|----------|---------|
| Settings | `fetchSettings()` |
| Transactions | `fetchTransactions()`, `createTransaction()`, `updateTransaction()`, `deleteTransaction()`, `fetchTransactionById()`, `fetchDailySummary()`, `transformPayloadForApi()` |
| Dashboard | `fetchFawryDashboard()` |
| Treasury | `fetchFawryTreasuryOverview()`, `fetchAccountFawryTransactions()` |
| Machines | `fetchMachines()`, `fetchMachineTransactions()`, `rechargeMachine()` |
| Accounts | `fetchFawryAccounts()` |
| UI | `addToast()`, `clearLastApiEnvelope()`, `reset()` |

**Notable frontend safety features:**
- AbortController on every fetch (prevents race on rapid page changes)
- `if (this.loading.X) return;` guards against double-submit
- Fallback static config when settings API fails
- Camel/snake-case payload transformer

---

## 7. Admin Inventory (Filament)

| Resource | Operations |
|----------|------------|
| `FawryTransactionResource` | List (with filters: payment_method, employee), Edit, **Delete via `FawryTransactionService::deleteTransaction`** (not raw model delete — service is called so soft-delete + ledger reversal run) |
| `FawryMachineResource` | Full CRUD + balance widgets |
| `FawryOperationTypeResource` | Full CRUD |
| `FawryPaymentMethodResource` | Full CRUD |
| `FawryCurrencyResource` | Full CRUD |
| `FawryBankResource` / `FawryCashboxResource` / `FawryWalletResource` | Account-backed CRUD with name-filter (no `shouldRegisterNavigation`) |

---

## 8. Financial Touchpoints (deep dive)

### 8.1 Fawry Transaction Service — Ledger Post Flow

For every `createTransaction()`:
1. **Settlement account** validated + locked (`Account::lockForUpdate`).
2. If `fawry_machine_id` → machine locked + balance guard check + active check.
3. `profit` computed (`selling_price − fawry_price`).
4. `FawryTransaction::create([...])` wrapped in `runProfitMutation()`.
5. **If machine present** → `machine->debit(fawry_price)` + `FawryMachineTransaction` row created.
6. `postLedgerEntries()` posts GL:
   - **Expense** (always if `fawry_price > 0`):
     - With machine → from prepaid account (`prepaidAccountId('fawry')`)
     - Walk-in, paid now → from settlement cashbox
     - Walk-in, deferred (no payment) → from income-contra account (so the cashbox isn't debited for credit not received)
   - **Income** (always):
     - Registered customer (`client_id`) → customer AR account (created lazily if missing; re-tagged `module_type='fawry'`)
     - Walk-in (`client_id IS NULL`) → walk-in AR account (`fawryWalkInArAccountId()`)
   - **Settlement** (only if registered + partial payment) → cashbox credited
7. Pointers (`income_transaction_id`, `expense_transaction_id`) saved back to the model.

### 8.2 Fawry Transaction Service — Update Flow

For every `updateTransaction()`:
1. Detect 4 GL-affecting field changes (`selling_price`, `fawry_price`, `amount`, `account_id`).
2. Recompute `profit` if prices changed (wrapped in `runProfitMutation`).
3. If any GL-affecting change:
   - Reverse ALL `Transaction` rows where `related_type=FawryTransaction && related_id=$id` (additive, never destructive).
   - Re-post new entries via `postLedgerEntries()`.
   - Save new pointers back.
4. **If `fawry_price` changed AND same machine** → re-adjust machine balance by diff.

### 8.3 Fawry Transaction Service — Delete Flow

For every `deleteTransaction()`:
1. **Idempotency guard**: DB-level check `whereNotNull('deleted_at')` → no-op if already deleted.
2. **Production-safety guard**: `DeferredTransactionDeletionGuard::ensureNoLaterPayment()` blocks delete if any "later payment" was recorded on the customer/AR account (with intelligent exclusions for reversals and cross-operation entries).
3. Capture pre-delete settlement balance.
4. Compute `originalSettlementAmount` from GL (filtered to exclude `عكس` reversal notes).
5. Compute `excessToReclaim` = max(0, `paidAmount − originalSettlementAmount`).
6. **Machine credit** (if machine used) — `fawry_price` returned.
7. **Reverse all linked GL transactions** in reverse chronological order.
8. **Walk-in AR reclamation**: any `excessToReclaim` is re-allocated FIFO to OTHER unpaid walk-in transactions for the same `client_name`; any remaining is journal-transferred back to walk-in AR (debit cashbox, credit AR); finally zero out `amount` on the deleted transaction.
9. **Soft-delete** the row.
10. **Deficit auto-correction**: `correctDeficitIfAny()` posts an idempotent corrective journal transfer if balance drift > 0.01 EGP.

### 8.4 Walk-in Pay-Debt Flow

`POST /api/v1/fawry/walk-in/pay-debt`:
1. Validate `client_name`, `amount > 0`, `account_id` exists, EGP only.
2. Lock walk-in AR account + paying account.
3. Compute current debt (`SUM(selling_price - amount) WHERE client_id IS NULL AND client_name = X`).
4. Reject if `amount > debt + 0.005` (overpayment).
5. Lock and iterate unpaid walk-in transactions FIFO (filter `deleted_at IS NULL`, `selling_price > amount`).
6. For each, bump `amount += allocated` (until $remaining ≤ 0.005).
7. One aggregate journal transfer (no per-transaction linkage) from walk-in AR → settlement cashbox.

### 8.5 Machine Recharge Flow

`POST /api/v1/fawry/machines/{id}/recharge` (admin only):
1. Validate `from_account_id` exists, `amount > 0`.
2. `eligibleFundingAccounts()` filters to `LIQUIDITY_TYPES` with `module_type IN ('fawry', 'office')`.
3. `FawryMachineRechargeService::rechargeFromAccount()`:
   - Lock machine + source account.
   - Reject if machine inactive.
   - `PrepaidLedgerService::recharge()` posts GL: debit source, credit prepaid EGP.
   - Compute machine credit = posted EGP credit (handles cross-currency correctly).
   - `machine->credit(machineCreditAmount)` → posts `FawryMachineTransaction` (credit row).

### 8.6 Config / Account Creation Touchpoints

- **Customer account (registered)**: created lazily by `FawryTransactionService::ensureCustomerAccount()` on first Fawry tx for that customer. Re-tags `module_type='office'` → `'fawry'`. Wrapped in `LedgerBalanceMutationGuard`.
- **Walk-in AR account**: created lazily by `LedgerClearingAccounts::fawryWalkInArAccountId()`. Fixed name `"ذمم عملاء فوري غير مسجلين"`.
- **Income/Expense contra accounts**: created lazily by `LedgerClearingAccounts::incomeContraIdForModule('fawry')` / `expenseContraIdForModule('fawry')`.
- **Prepaid Fawry account**: created lazily by `LedgerClearingAccounts::prepaidAccountId('fawry')`. `module_type='fawry'`.
- **Fawry cashboxes / wallets / banks**: seeded via `FawryModuleProductionTestSeeder` with `module_type='office'` (per `AccountModuleContract` Phase 5 rules).

---

## 9. Existing Test Inventory

### 9.1 PHPUnit Feature Tests (Fawry-specific)

| Test File | Tests | Status |
|-----------|------:|--------|
| `tests/Feature/Fawry/FawryDeleteExcludesFromAccountsDashboardTest.php` | 3 | Was failing on `GREATEST()` — may now pass |
| `tests/Feature/Fawry/FawryFinalGateProductionAuditTest.php` | 2 | Was failing on `GREATEST()` |
| `tests/Feature/Fawry/FawryFullProductionAuditTest.php` | 1 | Was failing on `GREATEST()` |
| `tests/Feature/Fawry/FawryMachineApiControllerTest.php` | ? | — |
| `tests/Feature/Fawry/FawryMachineServiceTest.php` | ? | — |
| `tests/Feature/Fawry/FawryModuleIntegrationTest.php` | 1+ | Was failing on `GREATEST()` |
| `tests/Feature/Fawry/FawryTransactionControllerTest.php` | 2+ | Was failing on `GREATEST()` |
| `tests/Feature/Fawry/FawryTransactionServiceTest.php` | 2+ | Was failing on `GREATEST()` |
| `tests/Feature/Fawry/FawryUiE2EScenariosTest.php` | 1+ | Was failing on `GREATEST()` |
| `tests/Feature/Fawry/FawySecondIndependentSoftDeleteVerificationTest.php` | 6 | Was failing on `GREATEST()` |
| `tests/Feature/Fawry/WalkInFawryPaymentTest.php` | 13 | All PASS |
| `tests/Feature/Filament/FawryWalletFilamentTest.php` | ? | — |
| `tests/Feature/TourismDivision/FawryProductionTest.php` | ? | — |
| `tests/Feature/Finance/DeferredTransactionDeletionGuardTest.php` | 3 | Was failing on `GREATEST()` |
| **Total Fawry tests** | **157** | **22 known to fail pre-fix** |

### 9.2 Unit Tests

| Test File |
|-----------|
| `tests/Unit/Models/Fawry/FawryCurrencyTest.php` |
| `tests/Unit/Models/Fawry/FawryOperationTypeTest.php` |
| `tests/Unit/Models/Fawry/FawryPaymentMethodTest.php` |
| `tests/Unit/Models/Fawry/FawryTransactionTest.php` |
| `tests/Unit/Models/Fawry/FawryWalkInArAccountTest.php` |

### 9.3 Filament Tests

| Test File |
|-----------|
| `tests/Filament/Fawry/FawryTransactionResourceTest.php` |

### 9.4 Script-based Audits

| Script | Last Run | Verdict |
|--------|----------|---------|
| `tests/scripts/fawry_module_e2e_test.php` | 2026-07-21 | PASS (after Bug #1 fix) |
| `tests/scripts/fawry_module_accounting_audit.php` | 2026-07-21 | PASS |
| `tests/scripts/fawry_api_e2e_test.sh` | 2026-07-21 | PASS (after Bug #2/3 fix) |
| `tests/scripts/fawry_module_full_e2e_audit_20260814.php` | 2026-08-14 | CONDITIONAL GO (1 D-class defect: `GREATEST()` — **now fixed**) |
| `tests/scripts/fawry_module_DEEP_E2E.php` | 2026-07-29 | Regression baseline |

### 9.5 Ad-hoc Test Scripts (root-level)

- `fawry_module_production_full_test.php`
- `fawry_module_soft_delete_full_test.php`
- `FAWRY_MODULE_FULL_E2E_AUDIT_20260814.md` (the audit report I cross-referenced)
- `FAWRY_MODULE_PRODUCTION_TEST_REPORT.md`
- `FAWRY_MODULE_SOFT_DELETE_TEST_REPORT_20260727.md`

---

## 10. Cross-References to Other Modules

Fawry depends on the following shared subsystems (any change in these can break Fawry):

| Subsystem | Fawry Uses It For |
|-----------|-------------------|
| `App\Services\Finance\TransactionService` | `recordExpense` / `recordIncome` / `recordJournalTransfer` / `reverseTransaction` |
| `App\Services\Finance\PrepaidLedgerService` | Machine recharge source → prepaid posting |
| `App\Services\Finance\LedgerClearingAccounts` | Income/Expense contra account resolution; walk-in AR account |
| `App\Services\Finance\DeferredTransactionDeletionGuard` | Production-safety block on deletes with later payments |
| `App\Support\Finance\AccountModuleContract` | **THE source of B-1 bug** — `LIQUIDITY_TYPES` constant used without import |
| `App\Support\Finance\LiquidityAccountGroups` | Dashboard + Treasury grouping of cashboxes/wallets/banks |
| `App\Support\Finance\LedgerBalanceMutationGuard` | Wraps direct balance writes on machines/accounts |
| `App\Support\Finance\ModelProfitMutationGuard` | Trait on FawryTransaction — blocks direct `profit` writes |
| `App\Observers\CustomerLedgerObserver` | Lazy-creates customer accounts on Customer row insert |
| `App\Models\Account` | Boot guards on module_type/balance writes |
| `App\Models\Transaction` + `App\Models\AccountEntry` | Double-entry GL backing |
| `App\Models\Setting\Currency` | Source for FawryCurrency exchange rates |

---

## 11. Previous Audit State vs. Current State

| Aspect | 2026-08-14 Audit | Current (2026-08-20) | Δ |
|--------|-------------------|----------------------|---|
| Verdict | CONDITIONAL GO | (not yet reassessed) | TBD |
| Known D-class defects | 1 (`GREATEST()`) | 0 (`GREATEST()` fixed) | ✅ resolved |
| Confirmed B-class defects | 0 | **1 (`FawryDashboardController` import)** | 🔴 **NEW regression** |
| Endpoints count | 20 | 20 | unchanged |
| Fawry models | 6 | 6 | unchanged |
| Filament resources | 8 | 8 | unchanged |
| Vue views | 9 | 9 | unchanged |
| Migrations | 10 | 10 | unchanged |
| Pinia store actions | 18 | 18 | unchanged |
| Test count | 157 | 157 | unchanged |

**Critical regression since 2026-08-14:** Commit `b5e9843` (2026-08-16) "fix(finance): enforce canonical liquidity module classification" modified the dashboard controller to use `AccountModuleContract::LIQUIDITY_TYPES` but did not add the `use` import. This bug was introduced AFTER the audit was completed and was not detected.

---

## 12. Known Design Decisions (carried forward from 2026-08-14 audit)

These are intentional, NOT defects. Documented here for reference:

1. **No refund endpoint** — Cancellation = DELETE (soft-delete + additive ledger reverse).
2. **Duplicate `reference_number` allowed** — No UNIQUE constraint.
3. **No restore-from-soft-delete** — Additive reversal pattern means originals are never destroyed.
4. **Walk-in pay-debt uses FIFO allocation with NO per-`related_id` link** — Aggregate journal entry.
5. **Idempotent DELETE returns HTTP 404** — Route-binding exclusion + service-level guard.

---

## 13. Open Questions for User (Before Proceeding)

The audit prompt says: "ابدأ الآن بـ: PHASE 0 — FULL FAWRY MODULE DISCOVERY وقم أولًا باكتشاف كل Backend + API + Frontend + Admin + Database + Financial touchpoints الخاصة بموديول Fawry،ثم اعرض تقرير Discovery قبل تنفيذ أي تعديل على الكود."

This report is the PHASE 0 deliverable. **No production code changes have been made yet.**

Before I continue to PHASE 1+ (which will require running actual HTTP tests, writing reproduction tests, and possibly long multi-hour stress scenarios), I need direction on:

| Question | Options |
|----------|---------|
| **Q1. Should the B-1 critical bug be fixed NOW?** | A) Fix it inline now (one-line `use` statement addition). B) Document formally in PHASE 11 and fix at the end. C) Pause everything until user reviews. |
| **Q2. Is a re-audit justified given the existing 2026-08-14 audit is only 6 days old?** | A) Re-run full 12-phase audit (5-10 hours of work). B) Run targeted re-audit on changed areas (dashboard controller only). C) Accept the existing audit + this discovery + targeted regression test. |
| **Q3. Should existing PHPUnit tests be re-run before PHASE 1?** | A) Yes — confirm the `GREATEST()` fix unblocked the 22 known failures. B) Defer to PHASE 10 (Existing Test Audit). |
| **Q4. Which testing environment should PHASE 4+ use?** | A) Isolated MySQL DB (mirrors previous audit). B) SQLite in-memory (faster, less realistic). C) MySQL stress DB (`safarak_stress`). |

---

## 14. Phase Completion Status

| Phase | Status | Deliverable |
|-------|:------:|-------------|
| PHASE 0 — Full Discovery | ✅ **DONE** | This document (`FAWRY_MODULE_INVENTORY.md`) |
| PHASE 1 — Business Flow | ⏸ pending | `FAWRY_BUSINESS_FLOW.md` |
| PHASE 2 — Test Matrix | ⏸ pending | `FAWRY_TEST_MATRIX.md` |
| PHASE 3 — Security Audit | ⏸ pending | `FAWRY_SECURITY_AUDIT.md` |
| PHASE 4 — Financial Integrity | ⏸ pending | `FAWRY_FINANCIAL_AUDIT.md` |
| PHASE 5 — Idempotency | ⏸ pending | (included in Test Matrix) |
| PHASE 6 — Concurrency | ⏸ pending | (stress scripts) |
| PHASE 7 — Frontend E2E | ⏸ pending | `FAWRY_E2E_REPORT.md` |
| PHASE 8 — DB Forensics | ⏸ pending | `FAWRY_DATABASE_FORENSICS.md` |
| PHASE 9 — Stress Test | ⏸ pending | (stress scripts) |
| PHASE 10 — Existing Test Audit | ⏸ pending | (audit report) |
| PHASE 11 — Bug Reproduction | ⏸ pending | B-1 reproduction pending |
| PHASE 12 — Final Reconciliation | ⏸ pending | `FAWRY_FINAL_REPORT.md` (GO/NO-GO) |

---

**END OF PHASE 0 DISCOVERY REPORT**
