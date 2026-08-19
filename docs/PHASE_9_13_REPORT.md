# Phase 9.13 — State Machine Matrix (Section 23)

**Branch:** `phase-9-tourism-production-audit-visa`
**Date:** 2026-08-19
**Tests:** `tests/Feature/Visa/VisaStateMachineMatrixTest.php` (29 tests, all PASS)
**Regression:** 479/479 PASS, 1384 assertions, 0 regressions

---

## 1. Scope

Section 23 of the 30-section prompt — state machine coverage. The Visa state
machine has 8 statuses:

| Status | Reachable on create | Reachable from service |
|--------|---------------------|------------------------|
| Draft | yes (status override) | no |
| Submitted | yes (default) | no |
| UnderReview | yes (status override) | no |
| Approved | yes (status override) | no |
| Rejected | yes (status override) | no |
| Issued | yes (status override) | no |
| Cancelled | yes (status override) | **yes** (cancel) |
| Refunded | yes (status override) | **yes** (refund) |

There is no explicit `update status` API endpoint — Phase 8.5 removed
PUT/PATCH from Tourism. Status is only mutated by service methods
(`cancel`, `refund`, `deleteWithReversal`).

---

## 2. Test Coverage Matrix (29 tests)

### Section A — Starting status coverage (8 tests)

| Test | Status | Result |
|------|--------|--------|
| `..._draft` | draft | ✅ |
| `..._submitted` | submitted | ✅ |
| `..._under_review` | under_review | ✅ |
| `..._approved` | approved | ✅ |
| `..._rejected` | rejected | ✅ |
| `..._issued` | issued | ✅ |
| `..._cancelled` | cancelled | ✅ |
| `..._refunded` | refunded | ✅ |

### Section B — Legal cancel transitions (6 tests)

Starting state → Cancelled is **allowed from**:

| From | Result |
|------|--------|
| Draft | ✅ |
| Submitted | ✅ |
| UnderReview | ✅ |
| Approved | ✅ |
| Rejected | ✅ |
| Issued (no payment) | ✅ |

### Section C — Legal refund transitions (4 tests)

Starting state → Refunded is **allowed from**:

| From | Result |
|------|--------|
| Submitted | ✅ |
| UnderReview | ✅ |
| Approved | ✅ |
| Issued | ✅ |

### Section D — Illegal transitions (4 tests)

| Transition | Expected | Actual | Notes |
|-----------|----------|--------|-------|
| Cancelled → Cancelled | 422 | 422 | `VisaRefundService::cancel` throws RuntimeException; controller wraps 422 |
| Refunded → Refunded | 422 | 422 | `VisaRefundService::refund` throws RuntimeException; controller wraps 422 |
| Cancelled → Refunded | 422 | 422 | `refund()` rejects with "لا يمكن استرداد طلب تأشيرة مُلغى" |
| Refunded → Cancelled | 422 | 422 | `cancel()` rejects with "لا يمكن إلغاء طلب تأشيرة تم استرداده بالكامل" |

### Section E — Payment state gates (2 tests)

| Starting state | Operation | Expected | Actual |
|---------------|-----------|----------|--------|
| Cancelled | addPayment | 422 | 422 |
| Refunded | addPayment | 422 | 422 |

### Section F — Soft-delete terminal (3 tests)

| Scenario | Expected | Actual | Notes |
|----------|----------|--------|-------|
| Soft-deleted → cancel | 404 | 404 | default Eloquent scope excludes soft-deleted |
| Soft-deleted → refund | 404 | 404 | same |
| Soft-deleted → soft-delete | 422 | 422 | `destroy()` rejects with "هذا الطلب محذوف بالفعل" |

### Section G — Lifecycle (1 test)

| Scenario | Result |
|----------|--------|
| Draft → payment → refund (status: Draft → Refunded) | ✅ PASS |

### Section H — Error message quality (1 test)

| Concern | Result |
|---------|--------|
| double-cancel error contains 'cancelled' indicator | ✅ PASS |

---

## 3. Defects Found

None. The state machine is governed by:

1. **`VisaRefundService::cancel`** — guards `≠Cancelled`, `≠Refunded`, `≠trashed`
2. **`VisaRefundService::refund`** — guards `≠Cancelled`, `≠Refunded`, `≠trashed`
3. **`VisaBookingService::addPayment`** — guards `≠Cancelled`, `≠Refunded`, `≠trashed`
4. **`VisaBookingController::destroy`** — guards `≠trashed`, also relies on
   default Eloquent scope for soft-deleted (404)

All 8 states are reachable on create. All terminal-state transitions are
rejected with the correct HTTP status codes (422 for double-action, 404 for
soft-deleted route-model-binding miss, 422 for double-soft-delete).

---

## 4. Boundary Cases Documented

| Behavior | Status | Why |
|----------|--------|-----|
| Cancel `Issued` booking (no payment) → Cancelled | allowed | financial reversals empty; status flip is harmless |
| Refund `Issued` booking → Refunded | allowed | refund code path is the correct final state |
| Soft-deleted booking via route-model-binding | 404 not 422 | Eloquent default scope is the gate |
| Service-layer `cancel()` after `trashed` | unreachable from API | binding-level 404 happens first |

---

## 5. Regression Status

```
$ vendor/bin/phpunit tests/Feature/Visa/ tests/Feature/Security/AuthorizationGatesTest.php \
                      tests/Feature/TourismEmployeeE2E/EmployeeVisaE2ETest.php
OK (479 tests, 1384 assertions)
```

---

## 6. Verdict

🟢 **Phase 9.13 PASS.** 0 defects found, 29 transition scenarios verified,
state-machine contract documented.

Circuit Breaker: CLEARED. Proceeding to Phase 9.14 (Final Verdict + Report).
