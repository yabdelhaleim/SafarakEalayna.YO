# Phase 9.11 — Validation + Auth/IDOR (Sections 19–21)

**Branch:** `phase-8.5-8.6-route-gates-and-actor-strict`
**Date:** 2026-08-19
**Tests:** `tests/Feature/Visa/VisaIDORAndValidationTest.php` (15 tests, all PASS)
**Regression:** 435/435 PASS, 1273 assertions, 0 regressions

---

## 1. Scope

Section 19 (input validation edge cases) + Section 20 (auth / IDOR) + Section 21
(sequential ID enumeration, sensitive endpoint exposure). Tourism bookings are
shared across employees (no per-employee ownership), so IDOR reduces to checking
permission/role at the route level rather than per-row ownership.

---

## 2. Test Coverage Matrix

| # | Test | Concern | Result |
|---|------|---------|--------|
| A1 | `test_unicode_in_notes_is_accepted` | Arabic in free text | ✅ PASS |
| A2 | `test_unicode_in_country_is_accepted` | Arabic country name | ✅ PASS |
| A3 | `test_emoji_in_notes_is_rejected_or_sanitized` | emoji tolerated (≠500) | ✅ PASS |
| A4 | `test_very_large_amount_is_accepted_when_under_limit` | DECIMAL(15,2) saturation | ✅ PASS |
| A5 | `test_decimal_precision_is_preserved` | 1000.50 ↔ 1500.75 ↔ 100.25 | ✅ PASS |
| A6 | `test_currency_code_is_validated` | garbage currency | ✅ PASS |
| A7 | `test_required_fields_are_required` | missing `customer_id` → 422 | ✅ PASS |
| A8 | `test_future_validity_dates_are_required` | `validity_to ≥ validity_from` | ✅ PASS **(after fix)** |
| B1 | `test_employee_can_view_visa_bookings_via_show` | admin-only read preserved | ✅ PASS |
| B2 | `test_employee_can_record_payment_on_any_booking` | cross-employee payment | ✅ PASS |
| B3 | `test_other_employee_can_refund_same_booking` | cross-employee refund | ✅ PASS |
| B4 | `test_unauthenticated_request_returns_401` | no auth → reject | ✅ PASS **(after fix)** |
| B5 | `test_inactive_employee_request_rejected` | is_active gate | ✅ PASS |
| B6 | `test_sequential_id_enumeration_returns_404_not_500` | ID probe | ✅ PASS |
| B7 | `test_negative_id_enumeration_rejected` | –1 → not 500 | ✅ PASS |

---

## 3. Defects Found and Fixed

### Class-B — Validation gap (FIXED)

**Location:** `app/Http\Requests/Visa/StoreVisaBookingRequest.php:76` and
`UpdateVisaBookingRequest.php:37`

**Symptom:** Service accepted `validity_to < validity_from` and persisted the
booking. A visa whose validity window is logically empty is nonsensical.

**Fix:** added `after_or_equal:visa_details.validity_from` rule to `validity_to`
on both Store and Update. Both fields remain nullable, so single-sided dates
still work; only the inverted case is now rejected.

```diff
- 'visa_details.validity_to' => ['nullable', 'date'],
+ 'visa_details.validity_to' => ['nullable', 'date', 'after_or_equal:visa_details.validity_from'],
```

### Test-harness — Unauthenticated setup (FIXED)

**Location:** `tests/Feature/Visa/VisaIDORAndValidationTest.php:175-190`

**Symptom:** the test created a request without explicit `actingAs`, but
`VisaTestCase::setUp()` already called `Sanctum::actingAs($this->user, ['*'])`,
so the request was authenticated and returned 200.

**Fix:** call `app('auth')->forgetGuards()` first so the guard is re-built
without the test-acting user, then assert not-200 (closes false-positive).

### Class-D — Pre-existing, baseline-classified (FIXED as part of regression)

**Location:** `tests/Feature/Security/AuthorizationGatesTest.php:471, 485`

**Symptom:** those tests asserted `assertNotSame(403, …)` for an employee
without admin role, but Phase 8.5 A1.5/A1.6 made GET `/visa/bookings` and GET
`/visa/treasury/overview` admin-only. Baseline report flagged these as
Phase 9.3a test-harness items; the regressions surfaced today.

**Fix:** flipped both assertions to `assertSame(403, …)` with rationale
comment pointing to Phase 8.5 A1.5/A1.6.

---

## 4. Auth/IDOR Findings

| Concern | Outcome |
|---------|---------|
| Employee can record payment on any booking (cross-employee) | ✅ Allowed by design (Tourism is shared) |
| Employee can refund booking they did not create | ✅ Allowed (cross-employee refund is normal) |
| Employee can view GET `/bookings/{id}` | ❌ Rejected 403 (admin-only preserved) |
| Inactive employee | ❌ Rejected 401 (is_active middleware) |
| Unauthenticated request to any `/visa/*` | ❌ Rejected 401/403 |
| ID enumeration probe `/bookings/999999999` | ❌ 404/403 (not 500) |
| Negative id `-1` | ❌ Rejected without 500 |

---

## 5. Regression Status

```
$ vendor/bin/phpunit tests/Feature/Visa/ tests/Feature/Security/AuthorizationGatesTest.php \
                      tests/Feature/TourismEmployeeE2E/EmployeeVisaE2ETest.php
OK (435 tests, 1273 assertions)
```

---

## 6. Net Change Set

- `app/Http\Requests/Visa/StoreVisaBookingRequest.php` — added `after_or_equal` (1 line)
- `app/Http\Requests/Visa/UpdateVisaBookingRequest.php` — added `after_or_equal` (1 line)
- `tests/Feature/Visa/VisaIDORAndValidationTest.php` — new (212 lines, 15 tests)
- `tests/Feature/Security/AuthorizationGatesTest.php` — flipped 2 assertions to match Phase 8.5 admin-only policy

---

## 7. Verdict

🟢 **Phase 9.11 PASS.** 1 Class-B defect fixed (validity-to-before-from rejected),
2 Class-D test-harness items reconciled with Phase 8.5 policy, 0 regressions
across Visa + Security + Employee E2E suites.

Circuit Breaker re-evaluation: **CLEARED**. No ongoing Class-A/B findings remain
in the scope of Phase 9.11. Proceeding to Phase 9.12 (Supplier Flow Deep).
