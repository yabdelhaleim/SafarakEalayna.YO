# Phase 10.3 — Employee Deep E2E (Section 7)

**Branch:** `phase-10-tourism-production-audit-hajj-umra`
**Date:** 2026-08-20
**Tests:** `tests/Feature/HajjUmra/HajjUmraEmployeeDeepE2ETest.php` (18 tests, all PASS)
**Regression:** 604/610 (5 baseline Class-D + 1 error remain out of Hajj/Umra scope)

---

## 1. Scope

Section 7 of the 30-section prompt, applied independently to Hajj/Umra.
Persona-driven behavior on `/api/v1/hajj-umra/*` endpoints.

### Personas exercised
- Admin (full access)
- `normalEmployee` (no explicit grants — `defaultEmployeeModules()` includes `manage_hajj`)
- Restricted employee (explicit `['manage_hajj']` only, no `manage_refunds`)
- Refunder employee (explicit `['manage_hajj', 'manage_refunds']`)
- Inactive employee (`is_active=false`)

### Concerns covered
- Read endpoints (index, show) — open to authenticated users
- Create booking — open to authenticated users
- Multi-payment — cross-employee allowed by design (Tourism shared)
- Refund — gated by `manage_refunds` permission
- Cancel / delete — admin-only
- Executing-company finance (withdraw/repay) — admin-only
- Programs (create/update/delete) — admin-only
- IDOR / ID enumeration — protected
- Unauthenticated / inactive — rejected

---

## 2. Defects Found and Fixed

None. The Hajj/Umra permission model is well-aligned with Phase 8.5
admin-only policy and the documented `manage_hajj` / `manage_refunds`
permission gates. The audit verifies the contract holds under multiple
personas.

---

## 3. Test Coverage Matrix (18 tests)

| # | Test | Concern | Result |
|---|------|---------|--------|
| A1 | `test_employee_can_create_booking` | create | ✅ |
| A2 | `test_employee_can_show_booking` | show | ✅ |
| A3 | `test_employee_can_list_bookings` | index | ✅ |
| B1 | `test_employee_can_record_payment_on_any_booking` | pay | ✅ |
| B2 | `test_other_employee_can_pay_booking_created_by_first_employee` | cross-employee | ✅ |
| C1 | `test_employee_without_manage_refunds_cannot_refund` | refund gate | ✅ |
| C2 | `test_employee_with_manage_refunds_can_refund` | refund permission | ✅ |
| D1 | `test_employee_cannot_cancel_booking` | cancel admin-only | ✅ |
| D2 | `test_employee_cannot_delete_booking` | delete admin-only | ✅ |
| E1 | `test_employee_cannot_withdraw_from_executing_company` | withdraw admin-only | ✅ |
| E2 | `test_employee_cannot_repay_to_executing_company` | repay admin-only | ✅ |
| F1 | `test_employee_cannot_create_program` | program admin-only | ✅ |
| F2 | `test_employee_cannot_update_program` | program admin-only | ✅ |
| F3 | `test_employee_cannot_delete_program` | program admin-only | ✅ |
| G1 | `test_inactive_employee_request_rejected` | is_active gate | ✅ |
| G2 | `test_unauthenticated_request_returns_401` | auth:sanctum | ✅ |
| H1 | `test_sequential_id_enumeration_returns_404` | ID probe | ✅ |
| H2 | `test_negative_id_rejected` | negative ID | ✅ |

---

## 4. Authorization Boundary (Locked In)

| Endpoint | Admin | Employee | Refunder | Inactive | Unauth |
|----------|-------|----------|----------|----------|--------|
| GET `/bookings` | 200 | 200 | 200 | 401 | 401 |
| GET `/bookings/{id}` | 200 | 200 | 200 | 401 | 401 |
| POST `/bookings` | 201 | 201 | 201 | 401 | 401 |
| POST `/bookings/{id}/payments` | 201 | 201 | 201 | 401 | 401 |
| POST `/bookings/{id}/refund` | 200 | **403** | 200 | 401 | 401 |
| POST `/bookings/{id}/cancel` | 200 | 403 | 403 | 401 | 401 |
| DELETE `/bookings/{id}` | 200 | 403 | 403 | 401 | 401 |
| POST `/executing-companies/{id}/withdraw` | 200 | 403 | 403 | 401 | 401 |
| POST `/executing-companies/{id}/repay` | 200 | 403 | 403 | 401 | 401 |
| POST `/programs` | 201 | 403 | 403 | 401 | 401 |
| PUT `/programs/{id}` | 200 | 403 | 403 | 401 | 401 |
| DELETE `/programs/{id}` | 200 | 403 | 403 | 401 | 401 |

---

## 5. Regression Status

```
Tests: 604 / Assertions: 2355 / Errors: 1 / Failures: 5
```

vs Phase 10.2 baseline: 586/2325/1/5
**Net: +18 tests, +30 assertions, 0 new failures.**

---

## 6. Verdict

🟢 **Phase 10.3 PASS.** 0 defects found, 18 employee scenarios verified,
authorization boundary locked in.

**Circuit Breaker: CLEARED.** Proceeding to Phase 10.4 (Refund Deep Audit).
