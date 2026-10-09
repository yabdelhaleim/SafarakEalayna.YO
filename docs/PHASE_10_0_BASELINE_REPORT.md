# Phase 10.0 — Pre-flight + Baseline

**Branch:** `phase-10-tourism-production-audit-hajj-umra`
**Date:** 2026-08-20
**Baseline:** 545 tests / 2195 assertions / 0 fail (counted from --exclude-group; actual: 1 error, 8 failures — see below)
**Environment:** `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` (full isolation)

---

## 1. Environment Safety

```
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
PHP 8.3.30
Laravel 13
```

**HARD GUARANTEE:** All tests run on `:memory:` SQLite, fully isolated per
PHPUnit process. No destructive operation (migrate:fresh, db:wipe, TRUNCATE)
touches any production-like database. This is a **read-mostly baseline**.

---

## 2. Pre-existing Implementation Map

### Models
- `app/Models/HajjUmraBooking.php`
- `app/Models/HajjUmraPayment.php`
- `app/Models/HajjUmra/UmrahSupplier.php` (Supplier module_type='hajj_umra')
- `app/Models/HajjUmra/HajjUmraExecutingCompany.php`
- `app/Models/HajjUmra/Hotel.php`
- `app/Models/HajjUmra/TripSupervisor.php`
- `app/Models/HajjUmra/AccommodationType.php`
- `app/Models/Program.php` (not in HajjUmra namespace)

### Enums
- `HajjUmraStatus`: Pending / Confirmed / InProgress / Completed / Cancelled / Refunded (6 states)
- `HajjUmraPaymentMethod`

### Services
- `HajjUmraBookingService`: create, cancel, deleteBookingWithReversal, addPayment, paginate, find
- `HajjUmraRefundService`: refund (full-booking only)

### Controllers (API)
- `HajjUmraController` (bookings CRUD)
- `HajjUmraProgramController` (programs CRUD)
- `HajjUmraDashboardController`
- `HajjUmraTreasuryController`
- `HajjUmraExecutingCompanyFinanceController` (withdraw/repay)
- `UmrahSupplierApiController`
- `HajjUmraReferenceController`

### Pre-existing safeguards (relevant to Phase 10 audit)
1. **Idempotency key UNIQUE index** on `hajj_umra_payments.idempotency_key` (already in place)
2. **AddPayment 4-layer dedup**: pre-check + lockForUpdate + UNIQUE + transaction rollback
3. **`update()` disabled** by Tourism No-Edit Contract (Phase 8.5 incident 2026-08-17)
4. **Actor identity requirement** on `refund()` and `deleteBookingWithReversal()` (Phase 8.6 B1)
5. **AddPayment state gates**: rejects `cancelled`, `refunded`, `trashed`
6. **Cashbox balance guard**: rejects if vault < purchase_price + companion_purchase_price (GAP #HJ-6 fix 2026-07-16)
7. **HajjUmraExecutingCompany auto-account** on create (mirrors VisaAgentObserver)

### Business rules DIFFERENT from Visa
1. **Companion pricing**: separate `companion_purchase_price` and `companion_selling_price`
2. **Passenger breakdowns**: optional `passengers` array
3. **Program dependency**: every booking is linked to a `Program` (no ad-hoc visa)
4. **Executing company (not supplier)**: primary cost-bearing entity is `HajjUmraExecutingCompany`, not `UmrahSupplier`
5. **Refund is FULL-BOOKING only** (Visa is the same, but Hajj includes companion + accommodation_extra_charge)
6. **6-state status machine** (vs Visa's 8): no `Draft`, no `UnderReview`, no `Approved`, no `Rejected`, no `Issued`
7. **Hajj/Umra is a complete product**, not a single visa — accounts for hotel, transport, trip supervisor

---

## 3. Baseline Test Status

```
$ vendor/bin/phpunit tests/Feature/HajjUmra/ tests/Feature/TourismDivision/ \
                      tests/Feature/TourismAudit/ \
                      tests/Feature/TourismEmployeeE2E/EmployeeHajjUmraE2ETest.php \
                      tests/Feature/Finance/TourismTrialBalanceIntegrityTest.php \
                      --no-coverage

Tests: 545
Assertions: 2195
Errors: 1
Failures: 8
```

### 3.1 Failure Inventory

| # | Test | Class | Status |
|---|------|-------|--------|
| 1 | `HajjUmraProgramControllerTest::test_store_program_creates_new_record` | **B** | 422 program_type invalid — **REAL DEFECT** |
| 2 | `HajjUmraProgramControllerTest::test_update_program_modifies_record` | **B** | 0.0 vs 55000.0 — **POTENTIAL DEFECT** (program financial flow) |
| 3 | `EmployeeHajjUmraE2ETest::test_employee_can_update_booking` | **D** | 405 — `update()` disabled by Phase 8.5 no-edit contract. Test asserts old behavior. **TEST-HARNESS** |
| 4 | `TourismTrialBalanceIntegrityTest::test_flight_group_receivable_appears_in_tourism_due_to_us` | **D** | 0 vs 3500 — involves Flight module; out of scope. **DEFER** |
| 5 | `TourismTrialBalanceIntegrityTest::test_combined_tourism_scenario_with_office_pollution` | **D** | 4300 vs 7800 — multi-module accounting — **DEFER** |
| 6 | `MultiCurrencySoftDeleteIntegrityTest::test_multi_currency_soft_delete_and_accounting_all_clean` | **D** | USD AR 1000 vs 50000 — multi-currency conversion test — **DEFER** |
| 7 | `FawryProductionTest::test_fawry_dashboard_endpoint_exists` | **D** | Fawry module — **OUT OF SCOPE** |
| 8 | `ProductionScaleBenchmarkTest::test_production_scale_load_with_all_reports_under_sla` | **D** | DB error in load test, env-specific — **DEFER** |
| 9 (error) | `TourismDivisionFullLoadTest::test_full_tourism_division_under_heavy_load` | **D** | load-test array — **DEFER** |

### 3.2 Two pre-existing items that may be Hajj/Umra real defects

Items **#1** and **#2** above (HajjUmraProgramControllerTest) target the
**Program** sub-resource. They are part of the Hajj/Umra API surface (a
booking references a program), so they are **in scope** for this audit.

#### Item #1 — program_type 422
The test sends a program with `program_type` value, controller returns 422
"The selected program type is invalid". Either:
- The test uses a stale enum value (e.g. `hajj` vs `Hajj`), OR
- The validation rule does not accept a currently-supported value

**Action:** Phase 10.1 (Master Data Audit) — root-cause and either fix the
controller validation OR fix the test to use a valid enum value.

#### Item #2 — program update 0 vs 55000
The test updates a program's `cost` field and expects the change to
propagate to 55000. The actual read returns 0.0. Either:
- The update endpoint does not write the field, OR
- The field is read from a different column

**Action:** Phase 10.1 — root-cause and either fix the controller's
update() OR fix the test to read the actual stored field.

These are **NOT yet classified as Class-B** until we verify with code
inspection. They are flagged for Phase 10.1 follow-up.

---

## 4. Phase 10 Plan (Hajj/Umra — independent from Visa findings)

Per the user's instruction, Phase 10 runs **independently from scratch**
and does NOT assume Visa findings. Visa fixes are NOT auto-applied to
Hajj/Umra. Each defect is verified against the Hajj/Umra codebase.

### 10.1 — Master Data Audit
- `HajjUmraStatus` enum completeness
- `Program` model: program_type enum, cost flow, executing_company linkage
- `UmrahSupplier` model
- `HajjUmraExecutingCompany` model
- `Hotel` / `TripSupervisor` / `AccommodationType` master data
- Reference endpoints (`/hajj-umra/settings/...`)

### 10.2 — Admin E2E
- Full lifecycle: create → pay multi-payment → cancel → delete
- Multi-payment across methods (cash + bank + wallet)
- Confirmed → InProgress → Completed transitions

### 10.3 — Employee Deep E2E
- Cross-employee payment
- Cross-employee refund
- IDOR checks
- Permission boundaries (manage_hajj)

### 10.4 — Refund Deep Audit
- Full-refund math, double-refund, refund on already-cancelled
- Refund on 0-payment booking (Phase 8.6 Gate behavior)
- Refund > paid rejected

### 10.5 — Cancel Deep Audit
- Partial-cancel (NOTE: Visa has no partial-cancel; Hajj may have it)
- Agent AP after cancel
- Customer AR restoration
- Income/expense reversal integrity

### 10.6 — Delete/Reverse Deep Audit
- Zero-ghost invariant
- `deleteBookingWithReversal` actor identity
- Soft-delete + state-machine integrity

### 10.7 — Financial Reconciliation
- Per-booking: Purchase, Companion Purchase, Selling, Companion Selling, Accommodation Extra, Customer Paid, Customer AR, Supplier Payable, Profit
- Supplier AP across bookings
- Global ledger balance per scenario

### 10.8 — Idempotency Deep Audit
- (Already 4-layer protected; Phase 10.8 verifies under attack)

### 10.9 — TRUE HTTP Concurrency
- 25x simultaneous payments on same booking
- 100x hot-booking ops
- Cancel + payment race

### 10.10 — Failure Injection
- DB transaction rollback paths
- Exception handling

### 10.11 — Validation + Auth/IDOR
- Employee vs admin boundaries
- Cross-division Hajj/Umra↔Visa bookings
- IDOR / ID enumeration

### 10.12 — Supplier Flow Deep
- Executing company finance
- Umrah supplier flow

### 10.13 — State Machine Matrix
- 6 statuses × {cancel, refund, addPayment, delete}

### 10.14 — Final Verdict + Report

---

## 5. Branch

```
$ git branch --show-current
phase-10-tourism-production-audit-hajj-umra
```

---

## 6. Verdict

🟡 **Phase 10.0 BASELINE CAPTURED** — environment safe, 545 tests / 1 error /
8 failures classified. 2 baseline items (#1, #2 in §3.1) flagged for
Phase 10.1 root-cause analysis (Hajj/Umra Program endpoint).

Circuit Breaker: **CLEARED** to proceed. No production-like DB, no
destructive operations. Proceeding to Phase 10.1.
