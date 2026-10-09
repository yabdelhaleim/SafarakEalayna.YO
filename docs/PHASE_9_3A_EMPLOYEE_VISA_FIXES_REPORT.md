# Phase 9.3a — Test-Harness Fixes in `EmployeeVisaE2ETest`

**Date:** 2026-08-19
**Branch:** `phase-9-tourism-production-audit-visa`
**Status:** ✅ **PHASE 9.3a COMPLETE** — 4 test-harness failures fixed, 0 regressions, 0 source-code changes.

---

## 1. Scope

Fix the 4 pre-existing test-harness failures in `tests/Feature/TourismEmployeeE2E/EmployeeVisaE2ETest.php` (baseline failures #4–7 from Phase 9.0). All 4 were test assertions that no longer match the **Phase 8.5 A1.5/A1.6 admin gate hardening** and the **no-edit contract (Phase 8.5 B1)**.

**No source code changes.** Pure test-assertion updates.

---

## 2. Changes

| # | Original test | Original assertion | New assertion | Reason |
|---|---------------|--------------------|---------------|--------|
| 1 | `test_employee_can_list_bookings` | `assertStatus(200)` | `assertStatus(403)` | Phase 8.5 A1.5/A1.6 admin-gated `GET /api/v1/visa/bookings` |
| 2 | `test_employee_can_show_booking` | `assertStatus(200)` | `assertStatus(403)` | Same — `GET /api/v1/visa/bookings/{id}` admin-gated |
| 3 | `test_employee_can_update_booking` | `assertStatus(200)` | **REMOVED** | No-edit contract (Phase 8.5 B1) disabled the PUT route entirely. Employee (and any role) now gets 405 Method Not Allowed. The test was asserting a behavior that no longer exists. |
| 4 | `test_employee_can_view_treasury_overview` | `assertStatus(200)` | `assertStatus(403)` | Phase 8.5 A1.5/A1.6 admin-gated `GET /api/v1/visa/treasury/overview` |

**Naming:** Tests were renamed from `test_employee_can_*` to `test_employee_cannot_*` to reflect the actual (admin-only) behavior.

**Docblock:** Updated the file's header table to document the new per-route matrix, with a comment block explaining the Phase 8.5 changes.

---

## 3. Results

| Metric | Before | After |
|--------|--------|-------|
| `EmployeeVisaE2ETest` tests | 14 (4 failing) | 13 (all passing) |
| `EmployeeVisaE2ETest` assertions | 17 (incl. 4 failing) | 16 (all passing) |
| Duration | ~4 s | 4.31 s |
| Source code changes | 0 | 0 |

**Full Visa + Authorization suite delta:**
| | Before Phase 9.3a | After Phase 9.3a |
|---|-------------------|------------------|
| Tests | 376 | 392 (+16, including the refactored EmployeeVisaE2ETest) |
| Passed | 367 | 387 |
| Failed | 9 | 5 (4 test-harness fixed; 3 test-harness in other files + 2 application defects remain) |

**Zero new regressions.** The 5 remaining failures are all pre-existing (documented in Phase 9.0 §3):
- 2 in `AuthorizationGatesTest` (Class-D test-harness, different file)
- 1 in `EmployeeIDORTest` (Class-D test-harness, different file)
- 2 application defects (Class-A, fixed in Phase 9.5a)

---

## 4. Defect Discoveries (Phase 9.3a)

| # | Severity | Description | Resolution |
|---|----------|-------------|------------|
| 1 | Class-D / Test-harness | `assertStatus(200)` for admin-gated read endpoint | Flipped to `assertStatus(403)` |
| 2 | Class-D / Test-harness | `assertStatus(200)` for admin-gated read endpoint | Flipped to `assertStatus(403)` |
| 3 | Class-D / Test-harness | `assertStatus(200)` for route that no longer exists (no-edit contract) | Test removed; comment explains why |
| 4 | Class-D / Test-harness | `assertStatus(200)` for admin-gated read endpoint | Flipped to `assertStatus(403)` |

**No Class-A or Class-B findings.**

---

## 5. Out-of-Scope (deferred to Phase 9.3a-extension or 9.3c)

3 test-harness failures in other files share the same Class-D root cause but are out of the approved Phase 9.3a scope:

| File | Test | Reason |
|------|------|--------|
| `tests/Feature/Security/AuthorizationGatesTest.php:471-476` | `test_employee_can_view_visa_bookings` | Same — admin-gated read |
| `tests/Feature/Security/AuthorizationGatesTest.php:485-490` | `test_employee_can_view_visa_treasury_overview` | Same — admin-gated read |
| `tests/Feature/TourismEmployeeE2E/EmployeeIDORTest.php` | `test_visa_booking_visible_across_employees` | Same — admin-gated read |

These can be addressed in **Phase 9.3a-extension** (or as a follow-up). Per the approved plan, Phase 9.3a scope was strictly the 4 failures in `EmployeeVisaE2ETest`.

---

## 6. Files Changed in Phase 9.3a

| Path | Action | LOC delta |
|------|--------|-----------|
| `tests/Feature/TourismEmployeeE2E/EmployeeVisaE2ETest.php` | **modified** | -1 (4 test renames + 1 test removal + 2 docblock + 4 assertion flips + 5 comments) |
| `docs/PHASE_9_3A_EMPLOYEE_VISA_FIXES_REPORT.md` | **created** | (this file) |

No source code changes. No config changes.

---

## 7. Next Phase

**Phase 9.3b** — Extend `EmployeeVisaE2ETest` with ~10 deeper employee scenarios (per the approved plan):

Suggested extensions:
- `test_employee_can_add_payment_via_idempotency_key` (replay-safe)
- `test_employee_refund_with_manage_refunds_succeeds` (currently no test)
- `test_employee_cannot_approve_or_issue_booking` (status transition guards)
- `test_employee_cannot_view_customer_statement` (admin-only)
- `test_employee_cannot_view_customer_balances` (admin-only)
- `test_employee_cannot_pay_via_wrong_currency_account` (validation guard)
- `test_employee_cannot_view_other_branch_bookings` (Phase 9.11 will have full IDOR coverage)
- `test_employee_can_view_settings_endpoints_if_permitted` (settings are read-only and may still be employee-accessible)
- `test_employee_concurrent_payment_submissions_idempotent` (idempotency_key behavior)
- `test_employee_audit_logs_record_payment_actions` (audit trail)

Final exact list to be confirmed at Phase 9.3b start.
