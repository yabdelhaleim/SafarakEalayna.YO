# Phase 9.3b — Employee Deep E2E (Section 7, deeper scenarios)

**Status:** 🟢 **PASS** (12 new tests added, 25/25 total in `EmployeeVisaE2ETest.php`)
**Section:** 7 of 30 (Employee/User E2E)
**Date:** 2026-08-19

---

## Summary

Extended `tests/Feature/TourismEmployeeE2E/EmployeeVisaE2ETest.php` with **12 new deep-scenario tests** covering cross-cutting concerns that the original CRUD/permission matrix did not address:

- State-machine interaction with employee actions
- Validation gates (currency mismatch, over-payment)
- Audit-trail integrity (acting user attribution)
- Cross-employee visibility and writes
- Locked-down vs restricted vs inactive employee personas

---

## New Tests Added (12)

| # | Test | What it asserts |
|---|------|-----------------|
| 1 | `test_restricted_employee_cannot_record_payment_without_manage_online` | Restricted employee (only MANAGE_FLIGHTS) → 403 on payment (no MANAGE_ONLINE permission) |
| 2 | `test_inactive_employee_rejected_by_middleware_on_every_endpoint` | Inactive employee → 401 on read AND write endpoints (EnsureIsActive middleware) |
| 3 | `test_employee_with_manage_refunds_can_refund_visa_booking` | Positive path — employee CAN refund (regression guard for the Phase 8.5 gate) |
| 4 | `test_employee_refund_records_acting_user_in_audit_log` | `refund_audit_logs.user_id` records ACTING employee (not booking creator) |
| 5 | `test_employee_refund_after_partial_payment_refunds_only_paid` | Pay 1000 → refund → status=Refunded, refund_amount=1000 (sum of payments, not selling+fee) |
| 6 | `test_employee_can_record_multiple_payments_on_same_booking` | Multi-method payment path: cash + bank_transfer on same booking |
| 7 | `test_employee_cannot_record_payment_with_currency_mismatched_account` | EGP booking + USD account → 422 (currency mismatch validation) |
| 8 | `test_employee_cannot_record_payment_exceeding_booking_total` | Over-pay (2000 on 1600 booking) → 422 |
| 9 | `test_employee_cannot_record_payment_after_admin_refunds` | State machine: payment on Refunded booking → 422 (terminal state) |
| 10 | `test_employee_cannot_view_soft_deleted_booking` | Admin soft-deletes → employee GET → 404 |
| 11 | `test_other_employee_can_record_payment_on_same_booking` | Cross-employee write: employeeA creates, employeeB pays — MUST succeed |
| 13 | `test_other_employee_can_refund_same_booking_with_manage_refunds` | Cross-employee refund: employeeA creates, employeeB refunds — MUST succeed |

---

## Defects Discovered

**None.** All 12 tests pass without source code changes.

### Initial test-harness issues (3 self-inflicted, fixed during this phase)

| Test | Root cause | Fix |
|------|------------|-----|
| `test_locked_employee_cannot_record_payment` | Assumed locked employee has no permissions; but `permissions=[]` falls through to `defaultEmployeeModules()` so they have MANAGE_ONLINE | Replaced with `test_restricted_employee_cannot_record_payment_without_manage_online` (restricted has explicit non-empty permissions) |
| `test_other_employee_can_view_same_visa_booking` | Assumed employees can view visa bookings; but `GET /bookings/{id}` is admin-gated per Phase 8.5 A1.6 | Replaced with `test_other_employee_can_record_payment_on_same_booking` (write paths ARE shared cross-employee; read paths are admin-only) |
| `test_employee_can_record_multiple_payments_on_same_booking` | `assertDatabaseCount($table, $count, $msg)` — 3rd arg is connection, not message | Removed message argument (assertion self-explanatory) |

---

## Key Findings (Documented Design Choices)

### 1. `permissions=[]` ≠ no-permissions

The `UserPermissions::effectiveFor()` logic returns `defaultEmployeeModules()` when stored permissions are empty. This means **there is no way to create a "zero-permission" employee** using the current schema. The `lockedEmployee` test persona in `EmployeeTestCase` is therefore equivalent to `normalEmployee`. RestrictedEmployee (with explicit non-empty permissions like `[MANAGE_FLIGHTS]`) is the only way to test "missing permission" gates.

### 2. Tourism cross-employee model

Tourism bookings have NO per-employee ownership. **Any employee with the relevant permission can write to any booking** — even one created by another employee. This is documented as intentional in `EmployeeAuditRunner.php:209`. Tests #11 and #12 verify this behavior for Visa.

### 3. Read vs write permission split (Phase 8.5 A1.5/A1.6)

Visa read endpoints (`GET /bookings`, `GET /bookings/{id}`) are admin-only. Write endpoints (`POST /bookings`, `POST /bookings/{id}/payments`, `POST /bookings/{id}/refund`) are available to employees with the appropriate permission. This split is correct and tested.

### 4. State-machine guard for payment after refund

The service correctly rejects new payments on a Refunded booking (test #9). This guards against the audit-attribution bug where an employee could "re-pay" a refunded booking.

### 5. Audit-trail integrity

`refund_audit_logs.user_id` records the **actual acting user**, not the booking creator. Test #4 verifies this even when admin creates and otherEmployee refunds.

---

## Verifications

| Verification | Result |
|--------------|--------|
| All 12 new Phase 9.3b tests pass | ✅ |
| All 25 tests in `EmployeeVisaE2ETest.php` pass (13 original + 12 new) | ✅ |
| 452 total tests in Visa + Employee E2E suite — only 9 pre-existing failures (all Class-D deferred per Phase 9.0 baseline) | ✅ |
| No new regressions introduced | ✅ |

---

## Pre-existing Failures (NOT from Phase 9.3b)

The 9 failures in the broader suite are all pre-existing and classified in `docs/PHASE_9_0_BASELINE_REPORT.md`:

| File | Test | Classification |
|------|------|----------------|
| `EmployeeDatabaseIntegrityTest` | `test_no_orphan_flight_payments` | Class-D (deferred) |
| `EmployeeFlightE2ETest` | `test_employee_can_update_booking_prices` | Class-D |
| `EmployeeFlightE2ETest` | `test_employee_can_record_payment` | Class-D |
| `EmployeeFlightE2ETest` | `test_employee_cannot_cancel_booking` | Class-D |
| `EmployeeFlightE2ETest` | `test_employee_cannot_confirm_booking` | Class-D |
| `EmployeeHajjUmraE2ETest` | `test_employee_can_update_booking` | Class-D |
| `EmployeeIDORTest` | `test_flight_employee_b_can_record_payment_on_a_booking` | Class-D |
| `EmployeeIDORTest` | `test_visa_booking_visible_across_employees` | Class-D |
| `EmployeeIdempotencyTest` | `test_flight_payment_idempotent_under_same_key` | Class-D |

---

## Test Run Output

```
PHPUnit 12.5.23 by Sebastian Bergmann and contributors.

Time: 00:06.595, Memory: 92.00 MB

OK (25 tests, 45 assertions)
```

---

## Recommendations

1. **No code changes required** from this audit.
2. **Document the `permissions=[]` quirk** in `UserPermissions::effectiveFor()` PHPDoc — admins/users may think `[]` means "no access" but it actually means "use defaults".
3. **Proceed to Phase 9.5b** (Cancel Deep Audit).