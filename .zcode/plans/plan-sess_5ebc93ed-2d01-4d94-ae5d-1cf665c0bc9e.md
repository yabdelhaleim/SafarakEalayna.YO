# HAJJ & UMRAH MODULE — PHASE 11 PRODUCTION AUDIT
## Fix 5 New Production Defects + Add Regression Tests + Final Consolidated Report

**Branch:** `phase-10-tourism-production-audit-hajj-umra`
**Environment:** SQLite in-memory (`phpunit.xml`) + `RefreshDatabase` + isolated test factories
**Source of truth:** 3 parallel Explore agents inspected 39 test files, 11 controllers, 2 services, 11 models, 27 endpoints, 11 Vue pages, 2 observers, 11 migrations

---

## CONTEXT (what the prior audits missed)

The Phase 10 audits (2026-08-20 GO, 2026-08-24 67 baseline failures, 2026-08-26 retest GO) focused on **backend correctness** and the **`addPayment`/`cancel`/`refund`/`DELETE` lifecycle**. This fresh audit looked at:
- Vue store layer (catches the cancelBooking misroute)
- Dashboard / report aggregations (catches soft-delete leaks)
- Observer registration in `AppServiceProvider` (catches silent omissions)

It identified **5 NEW production defects** the prior audits never tested:

| # | Severity | Defect | File |
|---|----------|--------|------|
| 1 | **CRITICAL** | `hajjUmraStore.cancelBooking()` calls `DELETE` instead of `POST /cancel` — calling it soft-deletes the booking with full financial reversal, instead of light-canceling it | `resources/js/stores/hajjUmraStore.js:181` |
| 2 | **MEDIUM** | `HajjUmraStats` Filament widget sums `profit` / `paid_amount` over **soft-deleted bookings** — dashboard totals inflated by deleted bookings | `app/Filament/Admin/Resources/HajjUmraBookings/HajjUmraBookingResource/Widgets/HajjUmraStats.php:13-23` |
| 3 | **MEDIUM** | `HajjUmraDashboardController::index` counts/sums over **soft-deleted bookings** — dashboard revenue inflated | `app/Http/Controllers/Api/V1/HajjUmra/HajjUmraDashboardController.php:24-27` |
| 4 | **MEDIUM** | `FinancialReportService::getDebtsReport` does NOT filter `deleted_at` on `HajjUmraExecutingCompany` / `UmrahSupplier` — soft-deleted entities surface in unified debts report with phantom AP balances | `app/Services/Reports/FinancialReportService.php:1064,1220` |
| 5 | **MEDIUM** | `UmrahSupplierObserver` is defined in `app/Observers/` but **NEVER registered** in `AppServiceProvider::boot()` — silent omission; the auto-create-account pattern never fires for UmrahSupplier | `app/Providers/AppServiceProvider.php` |
| 6 | **LOW** (test) | `HJ-CCY`: pre-seed FX rates (`EGP↔USD`, `EGP↔SAR`) in `HajjUmraTestCase::setUp()` — closes the ~20 test-side cross-currency failures documented in 2026-08-24 audit | `tests/Feature/HajjUmra/HajjUmraTestCase.php` |

**Defect Status (current):** 1 critical, 4 medium, 1 low — all unfixed.

---

## EXECUTION PLAN

### Step 1 — Apply production fixes (5 files, minimal safe changes)

**1a. CRITICAL: Fix `hajjUmraStore.cancelBooking()` misroute**
- File: `resources/js/stores/hajjUmraStore.js`
- Change action `cancelBooking(id, reason)` to call `axios.post(`/api/v1/hajj-umra/bookings/${id}/cancel`, { reason })` instead of `axios.delete(`/api/v1/hajj-umra/bookings/${id}`)`.
- Keep `deleteBooking(id)` unchanged (correctly targets DELETE).

**1b. MEDIUM: `HajjUmraStats` widget — exclude soft-deleted**
- File: `app/Filament/Admin/Resources/HajjUmraBookings/HajjUmraBookingResource/Widgets/HajjUmraStats.php`
- Add `whereNull('deleted_at')` to all three queries (count, sum profit, sum payment amount).

**1c. MEDIUM: `HajjUmraDashboardController::index` — exclude soft-deleted**
- File: `app/Http/Controllers/Api/V1/HajjUmra/HajjUmraDashboardController.php`
- Add `whereNull('deleted_at')` to count and sum queries.

**1d. MEDIUM: `FinancialReportService::getDebtsReport` — exclude soft-deleted**
- File: `app/Services/Reports/FinancialReportService.php`
- Add `->whereNull('deleted_at')` on the `HajjUmraExecutingCompany::query()` (line 1064) and `UmrahSupplier::query()` (line 1220) chains.

**1e. MEDIUM: Register `UmrahSupplierObserver`**
- File: `app/Providers/AppServiceProvider.php`
- Add `UmrahSupplier::observe(UmrahSupplierObserver::class);` next to the existing `HajjUmraExecutingCompany::observe(...)` registration (line 97-98 area). Import `App\Models\HajjUmra\UmrahSupplier` and `App\Observers\UmrahSupplierObserver`.

### Step 2 — Apply test-fixture fix (1 file)

**2a. LOW: HJ-CCY FX seed in `HajjUmraTestCase::setUp()`**
- File: `tests/Feature/HajjUmra/HajjUmraTestCase.php`
- Add `seedExchangeRate('EGP', 'USD', 0.032)` + inverse + `EGP↔SAR` (e.g. `0.098`) in `setUp()`. Use the existing `seedExchangeRate()` helper or pattern from Flight test base.

### Step 3 — Add regression tests for each defect (1 new test file + 5 tests in existing files)

**3a. NEW: `tests/Feature/HajjUmra/HajjUmraDashboardSoftDeleteLeakTest.php`** (5 tests)
- `test_widget_excludes_soft_deleted_bookings_from_profit_total`
- `test_widget_excludes_soft_deleted_payments_from_amount_total`
- `test_dashboard_endpoint_excludes_soft_deleted_bookings_from_count`
- `test_debts_report_excludes_soft_deleted_executing_companies`
- `test_debts_report_excludes_soft_deleted_umrah_suppliers`

**3b. NEW: `tests/Feature/HajjUmra/HajjUmraObserverRegistrationTest.php`** (2 tests)
- `test_umrah_supplier_observer_auto_creates_account_on_save`
- `test_hajj_umra_executing_company_observer_still_creates_account_on_save` (regression guard)

**3c. NEW: `tests/Feature/HajjUmra/HajjUmraCancelStoreRouteTest.php`** (1 test, unit-level)
- Static-grep assertion: `resources/js/stores/hajjUmraStore.js` contains `cancelBooking` that calls `axios.post` with `/cancel` path (not `axios.delete`). Uses `file_get_contents()` + assertion. Lightweight guard against regression.

### Step 4 — Verify (3 activities)

**4a.** Run full Hajj Umrah suite:
   `php artisan test --testsuite=Feature --filter=HajjUmra`
   Target: ≥ 640 PASS (current ~549 baseline + 91 new + fixes should push to ~640) / 0 unexpected fail.

**4b.** Run cross-module finance tests that touch Hajj:
   `php artisan test --filter="TourismLedgerReconciliation|HajjUmraExecutingCompanyIntegration|VisaUmrahImprovements"`
   Target: all pass.

**4c.** Run final accounting certification:
   - `LedgerReconciliationService::runPostingAndBalanceIntegrityScan` — `SUM(debit) == SUM(credit)`
   - `HajjUmraStats` widget — counts/sums match DB after a delete cycle
   - Customer debt = stored debt after delete cycle

### Step 5 — Write final consolidated report

Create **`docs/PHASE_11_HAJJ_UMRA_FINAL_AUDIT.md`** with:
1. Executive Verdict (GO / CONDITIONAL / NO-GO)
2. Module Inventory (from exploration)
3. Test Results (before/after, exact pass/fail/error/skip counts, assertions)
4. Defect Register (5 new fixes + 6 prior fixes = 11 total)
5. Financial Reconciliation Table (per-account ledger balance, customer debt, supplier payable)
6. Delete Coverage Matrix (entity → mode → reversal → orphan risk)
7. Security/IDOR status (no new findings)
8. Concurrency / Idempotency status (no new findings)
9. Frontend Reconciliation status (Vue store misroute fixed)
10. Final Production Verdict

---

## FILES TO MODIFY (9 total)

**Production (5):**
- `resources/js/stores/hajjUmraStore.js` (cancelBooking fix)
- `app/Filament/Admin/Resources/HajjUmraBookings/HajjUmraBookingResource/Widgets/HajjUmraStats.php` (soft-delete filter)
- `app/Http/Controllers/Api/V1/HajjUmra/HajjUmraDashboardController.php` (soft-delete filter)
- `app/Services/Reports/FinancialReportService.php` (soft-delete filter on 2 queries)
- `app/Providers/AppServiceProvider.php` (register UmrahSupplierObserver)

**Tests (3 new + 1 modified):**
- `tests/Feature/HajjUmra/HajjUmraDashboardSoftDeleteLeakTest.php` (NEW, 5 tests)
- `tests/Feature/HajjUmra/HajjUmraObserverRegistrationTest.php` (NEW, 2 tests)
- `tests/Feature/HajjUmra/HajjUmraCancelStoreRouteTest.php` (NEW, 1 test)
- `tests/Feature/HajjUmra/HajjUmraTestCase.php` (HJ-CCY FX seed)

**Reports (1 new):**
- `docs/PHASE_11_HAJJ_UMRA_FINAL_AUDIT.md` (NEW)

---

## SAFETY GUARDRAILS

- No production data touched (SQLite `:memory:` + `RefreshDatabase`)
- No destructive database operations
- Each fix is minimal & surgical — no unrelated refactoring
- Each fix has a dedicated regression test
- Existing audit reports (`PHASE_10_*`, `HAJJ_UMRA_FINANCIAL_RETEST_20260826.md`) remain immutable — new report does not retroactively modify prior verdicts

---

## SUCCESS CRITERIA

- [ ] All 5 production defects fixed with minimal-diff patches
- [ ] All 8 new regression tests pass
- [ ] HJ-CCY test-fixture fix reduces baseline failures by ~20
- [ ] Full Hajj Umrah suite: 0 unexpected fail
- [ ] Final accounting certification: ledger balanced, balances reconcile, no orphan records
- [ ] `docs/PHASE_11_HAJJ_UMRA_FINAL_AUDIT.md` published with final GO verdict

---

## OUT OF SCOPE (deferred / explicitly NOT touched)

- Visa, Bus, Flight, Wallet, Fawry, Online modules
- Bus/Finance/Wallet/Visa modified files in `git status` (those belong to other audit branches)
- The 30 `HajjUmraLockDownTest` 422-vs-405 test-side failures (documented, require test rewrite to accept 405 after Tourism no-edit contract)
- The 5 `TourismAudit/HajjUmraFullAuditTest` stale seed failures (audit-framework issue, not Hajj)
- Restoring soft-deleted bookings (intentionally unsupported per BRIEF 6)
