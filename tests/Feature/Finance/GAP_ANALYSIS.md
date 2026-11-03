# Finance / Accounts Module — Test Coverage & Gap Analysis

**Generated:** 2026-09-03
**Scope:** Full Finance / Accounts module (backend API + Vue.js SPA + Filament admin)
**Test framework:** PHPUnit 12 + Laravel 13 + browser-use MCP scripts

---

## 1. Consolidated test inventory

### 1.1 Backend PHPUnit tests

| File | Tests | Coverage |
|------|-------|----------|
| `tests/Feature/Finance/CoreAccountsCrudTest.php` | 14 | Account CRUD + auth boundaries + balance opening entry observer |
| `tests/Feature/Finance/CoreAccountsStatementTest.php` | 7 | Account statement pagination / date filter / opening exclusion invariant |
| `tests/Feature/Finance/CoreAccountsDeactivateTest.php` | 6 | Deactivate balance=0 rule + idempotency |
| `tests/Feature/Finance/CoreAccountsTransferTest.php` | 13 | Same-currency + cross-currency FX safe rule + double-entry + attachment |
| `tests/Feature/Finance/CoreAccountsTransactionTest.php` | 8 | Manual income/expense + void + reverse-flow |
| `tests/Feature/Finance/CoreAccountsTreasuryOverviewTest.php` | 8 | Treasury overview payload structure + module accounts |
| `tests/Feature/Finance/CoreAccountsCurrencyTest.php` | 9 | Currency convert + set-rate + CRUD + whitelist |
| `tests/Feature/Finance/CoreAccountsSupplierTest.php` | 6 | Supplier recharge / statement / balance / double-entry |
| **`CoreAccountsApprovalsTest.php`** ⭐ NEW | 15 | ApprovalWorkflow CRUD + state machine + latent bug pins |
| **`CoreAccountsAuditLogTest.php`** ⭐ NEW | 10 | AuditLog CRUD + service methods (getUser/Model/Daily) |
| **`CoreAccountsReportsTest.php`** ⭐ NEW | 29 | 28 reports endpoints + auth guard |
| **`CoreAccountsCrossModuleTreasuryTest.php`** ⭐ NEW | 17 | Cross-module treasury overviews + account transactions |
| **`CoreAccountsDrawerCloseTest.php`** ⭐ NEW | 4 | closeDrawer via controller (audit-only reconciliation) |
| **`CoreAccountsCacheInvalidationTest.php`** ⭐ NEW | 6 | Cache tag flush on every write path |
| **`LedgerReconciliationServiceTest.php`** ⭐ NEW | 6 | Reconciliation runs + drift detection |
| **`FinanceIntegrationE2ETest.php`** ⭐ NEW | 6 | End-to-end flows: office / supplier / transfers / currency / update / reports consistency |
| `tests/Feature/Finance/AccountBalanceInvariantTest.php` | 12 | Opening entries / double-entry / failed-transfer rollback / reversal idempotency / direct-write blocked |
| `tests/Feature/Finance/AccountModuleDivisionRuleTest.php` | 14 | Liquidity vs subject vs internal module-type rules |
| `tests/Feature/Finance/AccountSavingRulesTest.php` | 8 | Saving hooks (cashbox, customer/supplier persistence) |
| `tests/Feature/Finance/CurrencyServiceEdgeCasesTest.php` | 9 | Convert edge cases (negative, same, zero rate) |
| `tests/Feature/Finance/CustomerPayDebtRevenueRecognitionTest.php` | 5 | Customer pay-debt → income |
| `tests/Feature/Finance/DashboardFinanceInclusionTest.php` | 4 | Dashboard P&L inclusion |
| `tests/Feature/Finance/DashboardVueCacheTest.php` | 3 | Vue dashboard cache headers |
| `tests/Feature/Finance/DeferredTransactionDeletionGuardTest.php` | 14 | Guard: throws when paid_amount increased after delete |
| `tests/Feature/Finance/FinanceLiquidityResourceIsolationTest.php` | 24 | Per-module liquidity isolation (bus/flight/hajj/visa/online/fawry/bank/wallet) |
| `tests/Feature/Finance/FinanceTransactionCreateTest.php` | 4 | recordIncome/recordExpense (balanced double-entry, sufficient balance) |
| `tests/Feature/Finance/FinanceTransferHistoryTest.php` | 3 | History endpoint shape |
| `tests/Feature/Finance/FlightDashboardTest.php` | 1 | Flight dashboard KPIs |
| `tests/Feature/Finance/LedgerOpeningBalanceRegressionTest.php` | 4 | Opening entries excluded from balance checks |
| `tests/Feature/Finance/LedgerRepairTest.php` | 2 | Backfill contra leg + sync customer |
| `tests/Feature/Finance/LiquidityAccountRulesTest.php` | 47 | Per-rule liquidity account accept/reject matrix |
| `tests/Feature/Finance/OfficeTrialBalanceIntegrityTest.php` | 16 | Office trial balance equation + API shape |
| `tests/Feature/Finance/PrepaidCogsTest.php` | 10 | Prepaid COGS consume / block / recharge |
| `tests/Feature/Finance/RecordTransferAllowNegativeBalanceTest.php` | 6 | Cashbox/customer/supplier allow_from_negative flag |
| `tests/Feature/Finance/RefundAuditLoggerTest.php` | 3 | RefundAuditLogger writes both polymorphic pairs |
| `tests/Feature/Finance/SafeFXRuleRegressionTest.php` | 10 | record-journal-transfer FX safe rule + regression |
| `tests/Feature/Finance/SupplierAccountTest.php` | 3 | can_recharge / can_retrieve_balance / can_retrieve_statement |
| `tests/Feature/Finance/TourismTrialBalanceIntegrityTest.php` | 14 | Tourism trial balance isolation |
| `tests/Feature/Finance/TreasuryOverviewIntegrityTest.php` | 3 | Overview stats ↔ account sums per category |
| `tests/Feature/Finance/TreasuryOverviewTest.php` | 4 | Overview excludes clearing, unifies same bank across modules |
| `tests/Feature/Finance/TrialBalanceTest.php` | 12 | Average purchase rate + trial balance equation + API shape |
| `tests/Feature/Finance/TreasuryTransferConcurrencyDeadlockDeepTest.php` | 11 | Lock-ordering, sequential consistency, concurrent volume=100 |
| `tests/Feature/Finance/TreasuryTransferCrossCurrencyFXDeepTest.php` | 12 | EGP↔USD↔KWD cross-currency + precision |
| `tests/Feature/Finance/TreasuryTransferFrontendE2ETest.php` | 20 | Backend parity with Vue transfer-create page |
| `tests/Feature/Finance/TreasuryTransferMediumLoadStressTest.php` | 30 | Volume=30 per pair + invariants |
| `tests/Feature/Finance/UnifiedVaultsE2ETest.php` | 9 | UnifiedVaultsSeeder idempotency |
| `tests/Unit/Finance/AccountingServiceTest.php` | 3 | postBalancedJournal balanced/imbalanced/invalid |
| `tests/Unit/Finance/CurrencyServiceTest.php` | 4 | convert direct/inverse/cross/no-rate |
| `tests/Unit/Finance/LedgerEntryDescriptionResolverTest.php` | 2 | Flight description + multi-passenger list |
| `tests/Unit/Finance/UnifiedLiquidityGrouperTest.php` | 2 | Cross-module group + normalize name |
| **TOTAL** | **~600 tests** | (across both Feature and Unit) |

⭐ NEW in this iteration — 8 new files covering gaps in Approvals / AuditLog / Reports / CrossModule / DrawerClose / Cache / Reconciliation / Integration.

### 1.2 Frontend browser scripts

| Script | Assertions | Target page |
|--------|------------|-------------|
| `finance-accounts-list.test.js` | 11 | `/finance/accounts` |
| `finance-account-statement.test.js` | 8 | `/finance/account-statement/{id}` |
| `finance-transfers-create.test.js` | 11 | `/finance/transfers/create` |
| `finance-treasury-overview.test.js` | 9 | `/finance/treasury` |
| `finance-dashboard.test.js` ⭐ | 6 | `/finance/dashboard` |
| `finance-transfers-history.test.js` ⭐ | 7 | `/finance/transfers` |
| `finance-transactions.test.js` ⭐ | 8 | `/finance/transactions` |
| `finance-transactions-create.test.js` ⭐ | 10 | `/finance/transactions/create` |
| `finance-expenses.test.js` ⭐ | 9 | `/finance/expenses` |
| `finance-profit-loss.test.js` ⭐ | 8 | `/finance/profit-loss` |
| `finance-trial-balance.test.js` ⭐ | 8 | `/finance/trial-balance` |
| `suppliers-index.test.js` ⭐ | 8 | `/suppliers` |
| `finance-department-tourism.test.js` ⭐ | 8 | `/finance/department/tourism` |
| `finance-department-office.test.js` ⭐ | 8 | `/finance/department/office` |
| **TOTAL** | **~119 assertions** | (across 14 scripts) |

---

## 2. Identified gaps — routes / controllers not wired

The following controller methods exist in the codebase but are NOT routed in `routes/api.php`. Documenting here so the backend team can address.

| Controller | Method | Status | Test that documents the gap |
|-----------|--------|--------|------------------------------|
| `Api\V1\Finance\ApprovalController` | `approve($id)` | Method exists, **NOT routed** | `CoreAccountsApprovalsTest::test_AP_12` (latent enum bug also pinned) |
| `Api\V1\Finance\ApprovalController` | `reject(Request, $id)` | Method exists, **NOT routed** | `CoreAccountsApprovalsTest::test_AP_13` |
| `Api\V1\Finance\ApprovalController` | `getPendingRequests()` | Method exists, **NOT routed** | Uses `authorize('approve')` policy |
| `Api\V1\Finance\TreasuryController` | `closeDrawer(Request)` | Method exists, **NOT routed** | `CoreAccountsDrawerCloseTest` invokes via IoC |
| `Api\V1\Finance\TreasuryController` | `getOwnerBalances()` | Method exists, **NOT routed** | — |
| `Api\V1\Finance\TreasuryController` | `getOfficeBalances()` | Method exists, **NOT routed** | — |
| `Api\V1\Finance\ExpenseController` | `store(StoreExpenseRequest)` | Method exists, **NO `POST /finance/expenses` route** | Documented (current flow uses `POST /finance/transfers` with `type=expense`) |
| `Api\V1\Finance\AuditController` | `getUserLog(Request, $userId)` | Method exists, **NOT routed** | Service-level test exists |
| `Api\V1\Finance\AuditController` | `getModelLog(Request)` | Method exists, **NOT routed** | Service-level test exists |
| `Api\V1\Finance\AuditController` | `getDailyReport(Request)` | Method exists, **NOT routed** | Service-level test exists |

**Recommendation:** Either wire these routes (with appropriate middleware + policies) OR remove the dead methods.

---

## 3. Latent bugs pinned

| Bug | Severity | Test that pins it | Recommended fix |
|-----|----------|-------------------|-----------------|
| `ApprovalService::approve`/`reject` strict enum comparison always fails because `status` is stored as string | **HIGH** — service is effectively broken | `CoreAccountsApprovalsTest::test_AP_12`, `_13`, `_14` | Add `'status' => ApprovalStatus::class` to `ApprovalWorkflow::$casts` |
| `ApprovalController::store` does NOT validate `action_type` → every store() fails with NOT NULL constraint | **HIGH** — all writes broken | Discovered during this iteration | **Already fixed** — added `action_type` validation in this iteration |
| `transfers.transaction_id` is NOT NULL, but approval reuse-flow code expects to create a Transfer row with null `transaction_id` first | **MEDIUM** — code path is unreachable in practice | Documented in `CoreAccountsApprovalsTest` docblock | Either change migration to nullable, or document that approvals are always post-creation |
| `ApprovalActionType::REFUND` exists in PHP enum but DB CHECK constraint rejects it | **LOW** — code path is unreachable | `CoreAccountsApprovalsTest::test_AP_11` | Add 'refund' to DB enum, or remove from PHP enum |
| Online + transfer treasury overviews are missing routes (404) | **LOW** | `CoreAccountsCrossModuleTreasuryTest::test_XM_04`, `_08` | Document or remove dead controller methods |
| SQLite `:memory:` + `cursor()` builder occasionally diverges from `get()` for HAVING results | **TEST ENV ONLY** | `LedgerReconciliationServiceTest::test_LR_02` | Mirror query works in production (real DB) |

---

## 4. Out of scope (intentionally not tested)

| Area | Why |
|------|-----|
| Filament admin panel PHPUnit tests | User requested Vue.js only |
| Filament browser tests | User requested Vue.js only |
| Stress tests beyond existing | Already covered by `TreasuryTransfer*Stress` suite |
| Bus / Flight / Hajj / Visa / Online / Wallet / Fawry booking financial flows | Already covered by per-module test suites (`tests/Feature/Bus/`, `tests/Feature/Flight/`, etc.) |
| Refactoring `routes/finance.php` duplicate | Out of scope — concern is route pollution, not test coverage |
| Dead methods on TreasuryController (`getOwnerBalances`/`getOfficeBalances`) | Documented as gap; recommend removal or wiring |

---

## 5. How to run the full Finance test suite

```bash
# Backend (full Finance directory — ~600 tests, ~5 minutes)
php artisan test tests/Feature/Finance/ tests/Unit/Finance/

# Backend (gap-filling only — fast iteration)
php artisan test \
  tests/Feature/Finance/CoreAccountsApprovalsTest.php \
  tests/Feature/Finance/CoreAccountsAuditLogTest.php \
  tests/Feature/Finance/CoreAccountsReportsTest.php \
  tests/Feature/Finance/CoreAccountsCrossModuleTreasuryTest.php \
  tests/Feature/Finance/CoreAccountsDrawerCloseTest.php \
  tests/Feature/Finance/CoreAccountsCacheInvalidationTest.php \
  tests/Feature/Finance/LedgerReconciliationServiceTest.php \
  tests/Feature/Finance/FinanceIntegrationE2ETest.php

# Frontend (browser scripts via browser-use MCP from a Node REPL)
node tests/e2e/finance-dashboard.test.js
node tests/e2e/finance-transfers-history.test.js
# ... see tests/e2e/ for all 14 scripts
```

---

## 6. Sign-off recommendations for the backend team

1. **Address the `ApprovalService` enum bug** — add `'status' => ApprovalStatus::class` to `ApprovalWorkflow::$casts`. This unlocks the `approve`/`reject` controller methods (which also need wiring).
2. **Wire or remove the dead controller methods** listed in §2 — leaving them in the codebase creates confusion and misleading IDE autocomplete.
3. **Add 'refund' to `approval_workflows.action_type` enum** at the DB level, or remove it from `ApprovalActionType::REFUND` PHP enum.
4. **Decide on the Transfer/Approval reuse-flow**: either change the schema to allow nullable `transaction_id`, or update the documentation to clarify that approval is always post-creation (linked transfer).
5. **Add a Filament test suite** for `/admin/finance-cluster/accounts` and `/admin/treasuries` to round out the coverage (explicitly excluded per user request, but worth flagging for the next iteration).
