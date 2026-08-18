# Hajj & Umrah Module — Existing Coverage Matrix

> **Audit date:** 2026-08-14
> **Scope:** Pre-existing PHPUnit tests + Production E2E scenarios mapped against the 18 audit phases
> **Existing baseline:** 113 test methods across 9 files in `tests/Feature/HajjUmra/` + cross-tagged tests in `tests/Feature/TourismDivision/` and `tests/Feature/Finance/`

---

## 1. Existing Test Inventory

| File | Methods | Focus |
|------|--------:|-------|
| `HajjUmraApiTest.php` | 7 | Dashboard, treasury overview, cross-module account rejection, cancel reversal, pay-debt, program CRUD, update selling_price reposts income |
| `HajjUmraControllerTest.php` | 22 | Index pagination, status filter, store (201 + 5 validation paths), show, update, addPayment, cancel, refund, destroy, customer_balances, customer_statement |
| `HajjUmraDashboardControllerTest.php` | 6 | Payload shape, active-accounts-only, bookings count, revenue excludes cancelled, recent 10, cashbox aggregate |
| `HajjUmraExecutingCompanyFinanceControllerTest.php` | 9 | Dues auto-creates account, excludes inactive, zero-state, withdraw (records tx + validation + office-division rejection), repay (records tx + balance check + validation) |
| `HajjUmraFullModuleE2ETest.php` | 11 | Multi-payment, multi-currency, cancel-balance-neutral, refund-blocks-repeat, admin-delete, double-entry integrity, division rule |
| `HajjUmraProductionE2ETest.php` | **36** | Full lifecycle: currency matrix, multi-payment, edit-reposts, cancel/refund/delete idempotency, soft-delete, customer balance, audit trail |
| `HajjUmraProgramControllerTest.php` | 6 | Index, store, show, update, cannot-delete-with-bookings, delete-without-bookings — **2 pre-existing failures (test defects, see §3)** |
| `HajjUmraTreasuryControllerTest.php` | 7 | Overview three sections, active accounts, excludes inactive, executing companies, account transactions pagination, per_page cap 100 |
| `UmrahSupplierApiControllerTest.php` | 9 | Index ordered-by-name + account, store creates 201, auto-account, supplied-account, validations |
| **TOTAL** | **113** | |

### Cross-tagged tests (outside HajjUmra/ folder)

| File | HajjUmra-related tests |
|------|------------------------|
| `tests/Feature/TourismDivision/HajjUmraProductionTest.php` | Cross-module production scenarios |
| `tests/Feature/TourismDivision/HajjUmraExecutingCompanyIntegrationTest.php` | Executing company integration |
| `tests/Feature/TourismDivision/FullDeleteFlowE2ETest.php` | Full delete flow E2E |
| `tests/Feature/TourismDivision/JournalEntryProductionTest.php` | Journal entries |
| `tests/Feature/TourismDivision/MultiCurrencySoftDeleteIntegrityTest.php` | Multi-currency soft-delete integrity |
| `tests/Feature/TourismDivision/DebtorsProductionTest.php` | Customer debtors |
| `tests/Feature/TourismDivision/TrialBalanceProductionTest.php` | Trial balance |
| `tests/Feature/TourismDivision/TourismDivisionFullLoadTest.php` | Tourism full load |
| `tests/Feature/TourismDivision/VisaProductionTest.php` | Cross-module with HajjUmraReferenceController |
| `tests/Feature/Integrity/ForeignKeyIntegrityTest.php` | Validates Phase 6 FK migration |
| `tests/Feature/Integrity/NotNullAndUniqueConstraintsTest.php` | Validates Phase 6 NOT NULL/UNIQUE |
| `tests/Feature/Finance/LiquidityAccountRulesTest.php` | HajjUmraLiquidityAccount rule (6 tests) |
| `tests/Feature/Finance/UnifiedVaultsE2ETest.php` | Tourism-division unified vault |
| `tests/Feature/Reports/CustomerDebtsReportModuleCoverageTest.php` | Customer debts |
| `tests/Feature/Reports/TourismPAndLComprehensiveTest.php` | P&L |
| `tests/Feature/Security/AuthorizationGatesTest.php` | Auth gates |

---

## 2. Coverage Matrix vs 18 Audit Phases

Status legend:
- ✅ **COVERED** — multiple existing tests cover the phase
- 🟡 **PARTIAL** — partial coverage; gap identified
- ❌ **GAP** — no existing coverage
- ⚠️ **UNIT_ONLY** / **API_ONLY** / **E2E_ONLY** — covered only at one layer

| Phase | Description | Existing Coverage | Status | Gap |
|------:|-------------|-------------------|--------|-----|
| 0 | Existing coverage inventory | This document | ✅ | — |
| 1 | Module inventory | `docs/HAJJ_UMRA_MODULE_REPORT.md` (Jul 11) | ✅ | Refresh for Aug 14 audit |
| 2 | Database integrity | `ForeignKeyIntegrityTest`, `NotNullAndUniqueConstraintsTest` | 🟡 | No explicit schema-inspection test (PK, indexes, precision, orphans) |
| 3 | Master data lifecycle | `HajjUmraProgramControllerTest`, `UmrahSupplierApiControllerTest`, partial in `HajjUmraApiTest` | 🟡 | No test for `HajjUmraExecutingCompany` API CRUD, `Hotel`, `TripSupervisor`, `AccommodationType`, `VisaDuration` lifecycle |
| 4 | Booking lifecycle | `HajjUmraProductionE2ETest` (scenarios 1-9, 22, 25), `HajjUmraControllerTest` (store/update) | 🟡 | No test for `passengers` delete+recreate during update; no test for `companion_purchase_price` recalculation flow as standalone |
| 5 | Customer payments | `HajjUmraProductionE2ETest` (4, 5, 6, 7, 15, 18, 19, 23, 24), `HajjUmraApiTest::pay_debt_*`, `HajjUmraControllerTest::add_payment_*` | 🟡 | No 0/negative/missing-amount coverage at controller layer; no cross-currency payment isolation test; no `paid_amount`/`remaining_amount` accessors test |
| 6 | Debt system | `HajjUmraApiTest::pay_debt_*`, `HajjUmraControllerTest::customer_balances`, partial via ProductionE2E | 🟡 | No isolated "20K→5K→7K→8K" cumulative scenario as standalone; no over-settlement customer-impact test |
| 7 | Supplier/agent payables | `HajjUmraExecutingCompanyFinanceControllerTest` (9 tests), `HajjUmraProductionE2ETest` scenarios with supplier | 🟡 | No standalone `UmrahSupplier` direct finance flow (suppliers book cost via booking, not direct payments); no test for full delete→AP-zero restoration |
| 8 | Financial transaction trace | `HajjUmraFullModuleE2ETest` (cross-check), `HajjUmraProductionE2ETest` (scenario 13, 29) | 🟡 | No test that explicitly traces `source → transaction → entry → balance.delta` with assertions on every step |
| 9 | Accounting/GL | `HajjUmraFullModuleE2ETest`, `HajjUmraProductionE2ETest` (13, 29) | 🟡 | No global reconciliation test that loops every account and asserts `balance = Σ credit − Σ debit` |
| 10 | Soft delete + restore | `HajjUmraProductionE2ETest` (30-36), `HajjUmraControllerTest::destroy_*` | 🟡 | No test for `withTrashed()` Filament binding; no test for trashed booking exclusion from `customerBalances`; no test for program restore |
| 11 | API audit (per-endpoint) | All HajjUmraControllerTest cases | 🟡 | Not all 27 endpoints covered with explicit `missing-field`/`invalid-id`/`unauth` matrix |
| 12 | Frontend E2E | None | ❌ | **No JS test framework configured** (no Vitest/Cypress/Playwright); only Pinia store runtime verification possible |
| 13 | Negative/abuse | `HajjUmraControllerTest` (validation tests), `HajjUmraApiTest` (cross-module) | 🟡 | No test for negative prices, payment-after-cancel, double-cancel, manipulated payload |
| 14 | Concurrency | `HajjUmraProductionE2ETest` (test_18 test_19 test_23) | 🟡 | No parallel-request test using `lockForUpdate`; no race on cancel+pay |
| 15 | Full realistic business scenario | `HajjUmraFullModuleE2ETest` + `HajjUmraProductionE2ETest` (partial) | 🟡 | No single E2E that touches customer→program→executing company→booking→multi-payment→modify→cancel passenger→withdraw→repay→refund→soft-delete→restore→reconcile |
| 16 | Data integrity post-audit | Indirect via other tests | ❌ | No standalone orphan/duplicate/GHOST-balance/GL-imbalance detector |
| 17 | Regression | All existing | 🟡 | No automated classification of every failure as A/B/C/D/E |
| 18 | Final report | `HAJJUMRA_PRODUCTION_TEST_REPORT_20260727.md` | 🟡 | Needs refresh for Aug 14 |

---

## 3. Pre-existing Failures (Baseline)

Two test defects discovered when running the full HajjUmra test suite on 2026-08-14:

| Test | Failure | Classification |
|------|---------|----------------|
| `HajjUmraProgramControllerTest::test_store_program_creates_new_record` | `program_type='UMRA'` rejected by validator (must be lowercase `'umra'`; the `prepareForValidation` normalizes `umrah→umra` but does NOT handle `UMRA`) | **B (Test defect)** |
| `HajjUmraProgramControllerTest::test_update_program_modifies_record` | Uses `selling_price` but `Program` model fillable uses `default_selling_price` | **B (Test defect)** |

Both are test-script bugs (wrong assumptions about the application's normalized casing and column names). No application defect; not modified per the audit rule (never modify a test merely to make it pass; these are documented as Class B for tracking).

---

## 4. Coverage Gaps Driving New Tests

Based on the matrix above, the new test files in this audit are:

| New file | Addresses phases | Approx. tests |
|----------|------------------|---------------:|
| `HajjUmraDatabaseIntegrityTest.php` | 2 | ~15 |
| `HajjUmraMasterDataTest.php` | 3 | ~25 |
| `HajjUmraBookingLifecycleTest.php` | 4 | ~20 |
| `HajjUmraPaymentComprehensiveTest.php` | 5 | ~25 |
| `HajjUmraDebtLifecycleTest.php` | 6 | ~15 |
| `HajjUmraPayablesTest.php` | 7 | ~15 |
| `HajjUmraAccountingTraceTest.php` | 8, 9 | ~20 |
| `HajjUmraSoftDeleteComprehensiveTest.php` | 10 | ~15 |
| `HajjUmraApiContractTest.php` | 11 | ~30 |
| `HajjUmraPiniaStoreTest.php` | 12 | ~25 |
| `HajjUmraNegativeTest.php` | 13 | ~25 |
| `HajjUmraConcurrencyTest.php` | 14 | ~10 |
| `HajjUmraFullBusinessScenarioTest.php` | 15 | ~10 |
| `HajjUmraPostAuditIntegrityTest.php` | 16 | ~15 |
| `Total` | — | **~265 new tests** |

---

## 5. Baseline Numbers (Pre-Audit)

- **Existing PHPUnit tests:** 113 methods, 9 files, **936 assertions**, **184 passed / 2 failed / 2 skipped / 35.05s** (run on 2026-08-14 against in-memory SQLite)
- **Production E2E scenarios:** 36 (HajjUmraProductionE2ETest)
- **Cross-module HajjUmra tests:** ~30 (TourismDivision + Integrity + Finance)
- **Baseline verdict:** 184/186 passed = **99.0% green** (2 pre-existing Class-B test defects, no application bugs)
