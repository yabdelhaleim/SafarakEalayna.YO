# Phase 9.0 — Baseline Report (Visa Production-Readiness Audit)

**Date:** 2026-08-19
**Branch:** `phase-9-tourism-production-audit-visa` (created from `phase-8.5-8.6-route-gates-and-actor-strict` @ `4f95198`)
**Status:** ✅ **PHASE 9.0 COMPLETE** — baseline captured, 9 known failures classified, audit plan revised.

---

## 1. Environment Baseline

| Item | Value |
|------|-------|
| `APP_ENV` | `local` (NOT `production`/`prod`/`live` ✓ safe) |
| `DB_CONNECTION` | `mysql` (test override → `sqlite :memory:` per `phpunit.xml:31-32`) |
| `DB_DATABASE` | `safarakealayna` (live target, **not** to be written by audit) |
| `APP_URL` | `http://127.0.0.1:8000` (dev server) |
| PHP | 8.3.30 (ZTS Visual C++ 2019 x64) |
| Laravel | 13.6.0 |
| MySQL @ 127.0.0.1:3306 | **NOT RUNNING** at audit time (port refused) |
| Parent branch | `phase-8.5-8.6-route-gates-and-actor-strict` |
| Parent commit | `4f95198` |
| Uncommitted changes | 13 files (carried over from user — not touched by Phase 9.0) |

**Environment notes:**
- `phpunit.xml:31-32` forces `DB_CONNECTION=sqlite, DB_DATABASE=:memory:` for ALL `phpunit.xml` runs → baseline is reproducible, no MySQL needed for Feature suite.
- MySQL is **not running** on this host. The audit's stress tier will use the **SQLite file-backed** alternative (`storage/app/stress.sqlite`) per `tests/Stress/Support/StressSafetyGuard.php:40` rather than the unavailable `safarak_stress` MySQL schema.
- Live financial-state snapshot is **deferred** until MySQL is available, OR replaced with a synthetic baseline from the sqlite test fixtures.

---

## 2. Test Suite Baseline (read-only via `:memory:` SQLite)

Command: `vendor/bin/phpunit --testsuite Feature --filter "Visa" --no-coverage`

| Metric | Value |
|--------|-------|
| Total tests | **345** |
| Passed | **336** |
| Failed | **9** |
| Skipped | 0 |
| Warnings | 1 |
| Duration | 01:04.555 |
| Memory | 152.00 MB |

> **Note:** `PHASE_8_5_FINAL_REPORT.md` cited 329/16 — the difference (336/9 vs 329/16) is because the working tree at `4f95198` already includes 7 new tests from `tests/Feature/TourismEmployeeE2E/EmployeeVisaE2ETest.php` (14 tests) and other uncommitted additions.

---

## 3. Classification of the 9 Pre-existing Visa Failures

Per `PHASE_8_5_BACKLOG_REPORT.md` §3, pre-existing failures are **out of Phase 8.5 scope** and are documented for separate investigation. Phase 9.0 classifies them for Phase 9 planning.

| # | Test | Expected | Got | Defect class | Root cause | Resolution phase |
|---|------|----------|-----|--------------|-----------|------------------|
| 1 | `AuthorizationGatesTest::test_employee_can_view_visa_bookings` (`AuthorizationGatesTest.php:471-476`) | `≠403` | `403` | **Class-D / Test-harness** | Phase 8.5 A1.5/A1.6 admin-gated 8 read endpoints; this assertion was written before that. | **Phase 9.3a** — flip assertion to 403 (assertion must match the new admin-only policy). |
| 2 | `AuthorizationGatesTest::test_employee_can_view_visa_treasury_overview` (`AuthorizationGatesTest.php:485-490`) | `≠403` | `403` | **Class-D / Test-harness** | Same — treasury/overview is now admin-only. | **Phase 9.3a** — flip assertion. |
| 3 | `EmployeeIDORTest::test_visa_booking_visible_across_employees` (`EmployeeIDORTest.php`) | `200` | `403` | **Class-D / Test-harness** | The test asserts cross-employee visibility, but the read endpoint is now admin-gated. The class docblock (`EmployeeIDORTest.php:10-23`) explicitly documents the intent ("bookings are team resources"); tightening has changed the intent. | **Phase 9.3a** — either remove or flip to expect 403 (decide based on user's intent for cross-employee IDOR). |
| 4 | `EmployeeVisaE2ETest::test_employee_can_list_bookings` (`EmployeeVisaE2ETest.php:33-42`) | `200` | `403` | **Class-D / Test-harness** | Same as #1 — list endpoint now admin-gated. | **Phase 9.3a** — flip to expect 403. |
| 5 | `EmployeeVisaE2ETest::test_employee_can_show_booking` (`EmployeeVisaE2ETest.php:44-53`) | `200` | `403` | **Class-D / Test-harness** | Same — show endpoint now admin-gated. | **Phase 9.3a** — flip to expect 403. |
| 6 | `EmployeeVisaE2ETest::test_employee_can_update_booking` (`EmployeeVisaE2ETest.php:81-91`) | `200` | `405` | **Class-D / Test-harness** | PUT route is intentionally disabled by the no-edit contract (see `Phase 8.5 B1` / `docs/PHASE_8_5_FINAL_REPORT.md`). | **Phase 9.3a** — flip to expect 403 (POST/PUT/PATCH/DELETE for bookings all blocked). Or delete the test since the no-edit contract makes employee-update physically impossible. |
| 7 | `EmployeeVisaE2ETest::test_employee_can_view_treasury_overview` (`EmployeeVisaE2ETest.php:210-215`) | `200` | `403` | **Class-D / Test-harness** | Same as #2. | **Phase 9.3a** — flip to expect 403. |
| 8 | `VisaEdgeCasesTest::test_zero_egp_booking_rejected` (`VisaEdgeCasesTest.php:19-...`) | `422` | `201` | **Class-A / Application defect** | The system **accepts** `purchase_price = 0`. The Bus module has this validation (recently applied per `BUS_MODULE_HARDENING_REPORT.md`); Visa was missed. Financial impact: zero-cost booking creates negative profit, distorted supplier AP. | **Phase 9.5a** — fix `Visa\StoreVisaBookingRequest` validation: add `purchase_price > 0`, `selling_price >= purchase_price`, `service_fee >= 0`. |
| 9 | `VisaValidationTest::test_zero_purchase_price_returns_422` (`VisaValidationTest.php:165-...`) | `422` | `201` | **Class-A / Application defect** | Same as #8 (duplicate test). | **Phase 9.5a** — same fix. |

**Net audit-to-do classification:**

| Class | Count | Description | Phase |
|-------|-------|-------------|-------|
| **Class-D (Test-harness)** | 7 | Test assertions no longer match tightened gates / no-edit contract | 9.3a — flip assertions (or delete dead tests) |
| **Class-A (Application defect — Finance)** | 2 | Visa accepts `purchase_price=0` (Bus fix was missed) | 9.5a — add validation, regression tests |

The originally-known defect from `VisaIdempotencyTest:53-55` (no idempotency key on double-payment post) is **separately tracked** for **Phase 9.8** as planned.

---

## 4. Critical Audit-Plan Revisions

The pre-flight discovered two major facts that **change the original plan**:

### 4.1 `EmployeeVisaE2ETest` ALREADY EXISTS

- **Path:** `tests/Feature/TourismEmployeeE2E/EmployeeVisaE2ETest.php` (260 lines, 14 tests)
- **Coverage:** List/show/create/update (employee + restricted), payments, cancel/delete/withdraw/repay/pay-debt (REJECT), refund (normal ALLOWED, restricted REJECT), treasury overview.
- **Status:** 4 tests fail (assertions don't match tightened gates — see §3 #4-7).
- **Conclusion:** **Phase 9.3 (User E2E — create new file) is OBSOLETE.** The work is now:
  - **Phase 9.3a:** Fix the 4 test-harness failures (flip assertions to match tightened gates).
  - **Phase 9.3b (new):** Extend `EmployeeVisaE2ETest` with deeper employee-driven scenarios (e.g. partial refund by employee, customer-debt pay by employee with `manage_refunds`, IDOR cross-employee test with current gate, employee-attempted update on Paid booking, etc.). ~10 new tests.

### 4.2 New APPLICATION DEFECT discovered: zero-purchase-price accepted

- **`VisaEdgeCasesTest::test_zero_egp_booking_rejected` and `VisaValidationTest::test_zero_purchase_price_returns_422` both fail (got 201, expected 422).**
- This is a **Class-A financial defect** (the system creates a booking with 0 cost, which means the supplier-AP and profit entries are wrong).
- **Phase 9.5a (new):** Add validation to `Visa\StoreVisaBookingRequest`:
  ```php
  'purchase_price' => ['required', 'numeric', 'gt:0'],
  'selling_price'  => ['required', 'numeric', 'gte:purchase_price'],
  'service_fee'    => ['nullable', 'numeric', 'gte:0'],
  ```
  And update `VisaBookingService::create()` to defend-in-depth.
- **Then verify:** both failing tests now pass + add 3 more regression tests for the boundary cases (negative, missing, equal-to-selling).

### 4.3 MySQL is not running

- The audit was designed for `safarak_stress` MySQL, but `127.0.0.1:3306` is refusing connections.
- **Adaptation:** Use the **SQLite file-backed** tier (`storage/app/stress.sqlite`) per `StressSafetyGuard::STRESS_SQLITE_PATH`. The script `tests/scripts/stress_setup_mysql.php` (line 119, exit 0 = ready) supports both tiers via the `expectedTier` parameter — we pass `'sqlite'` instead of `'mysql'`.
- **Re-runnable on MySQL later** — same scripts, different tier.
- This is a **minor scope reduction for Phase 9.9** (TRUE HTTP Concurrency) — SQLite has table-level locking and will serialize writes. We'll use the existing pattern: many parallel HTTP `curl_multi` requests; the SQLite lock will surface as **lock-wait timeouts** rather than race-condition corruption. Defects will still be detected, just with different signatures (timeout vs duplicate insert).

### 4.4 Revised Phase Plan (replaces approved plan)

| Phase | Was | Now |
|-------|-----|-----|
| **9.0** | Pre-flight + baseline | ✅ **DONE** (this report) |
| **9.1** | Master Data Audit (Section 4) | unchanged |
| **9.2** | Admin E2E (Section 6) | unchanged |
| **9.3** | ~~User E2E — create new file~~ | **9.3a**: Fix 4 test-harness failures in existing `EmployeeVisaE2ETest` + **9.3b**: Extend with ~10 deeper employee scenarios. |
| **9.4** | Refund Deep (Section 8) | unchanged |
| **9.5** | Cancel Deep (Section 9) | **9.5a**: NEW — fix the zero-purchase-price application defect (1 source-code change + 2 regression tests). Then **9.5b** = original cancel deep audit. |
| **9.6** | Delete/Reverse Deep (Section 10) | unchanged |
| **9.7** | Financial Accounting (Sections 11-13) | unchanged |
| **9.8** | Idempotency + fix known defect (Section 14) | unchanged |
| **9.9** | TRUE Concurrency (Sections 15-17) | **9.9a**: Setup stress tier — try MySQL first; if unavailable, fall back to sqlite file-backed. **9.9b/c/d**: existing concurrency scripts. |
| **9.10–9.13** | unchanged | unchanged |
| **9.14** | Final Verdict | unchanged |

**Net impact:** Same scope, but **3 application defects to fix** (zero-purchase-price + idempotency) instead of 1, and **7 test-harness fixes** instead of "create new file."

---

## 5. Files Created in Phase 9.0

| Path | Purpose |
|------|---------|
| `docs/PHASE_9_0_BASELINE_REPORT.md` | This file |
| `tests/Stress/Visa/` (empty directory) | Reserved for future stress-tier Visa tests |
| `tests/scripts/visa/` (empty directory) | Reserved for future Visa audit scripts |

No source code changes in Phase 9.0. Branch is `phase-9-tourism-production-audit-visa` (just created from `4f95198`).

---

## 6. Pending: User Acknowledgement of Plan Revisions

Phase 9.3 (User E2E) and Phase 9.5 (Cancel Deep) have changed scope. Phase 9.0 commits the **baseline report only**; the next phase (9.1 Master Data Audit) does not depend on the revision approval. The revisions will be presented formally in Phase 9.3a / 9.5a with explicit "do you approve this code change?" prompts.

---

## 7. Summary Verdict

| Area | Status | Notes |
|------|--------|-------|
| Environment | ✅ SAFE | `APP_ENV=local`, MySQL not running (will use sqlite tier) |
| Branch | ✅ CREATED | `phase-9-tourism-production-audit-visa` |
| Baseline | ✅ CAPTURED | 336/9/0/1 in 1:04.555 |
| Failure classification | ✅ DONE | 7 test-harness + 2 application defects |
| New defects discovered | 1 (zero-purchase-price) | To be fixed in Phase 9.5a |
| Plan revisions | ✅ DOCUMENTED | 9.3 and 9.5 split into 9.3a/9.3b and 9.5a/9.5b |

**Phase 9.0 is COMPLETE. Ready to proceed to Phase 9.1 (Master Data Audit).**
