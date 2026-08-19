# Phase 9.5a — Fix Zero-Purchase-Price Application Defect

**Date:** 2026-08-19
**Branch:** `phase-9-tourism-production-audit-visa`
**Status:** ✅ **PHASE 9.5a COMPLETE** — Class-A application defect fixed, 2 pre-existing tests now pass, 9 new regression tests added.

---

## 1. Defect

| Severity | Class | Module | File:Line | Description |
|----------|-------|--------|-----------|-------------|
| **Class-A** | Financial | Visa | `app/Http/Requests/Visa/StoreVisaBookingRequest.php:85-87` | `purchase_price` accepted `0` and `selling_price` accepted `< purchase_price` |

**Root cause:** Validation rules used `min:0` instead of `gt:0` for `purchase_price`, and there was no cross-field rule for `selling_price >= purchase_price`.

**Financial impact (before fix):**
- A booking with `purchase_price = 0` and `selling_price = 1500` would create a `1500.0` income entry with a `0.0` expense entry → profit `1500.0` reported but no cost incurred, distorting P&L.
- A booking with `purchase_price = 1000` and `selling_price = 500` would create a loss (`-500`) on the same booking, also distorting profit and supplier-AP accounting.

The Bus module had this validation already applied (per `docs/BUS_MODULE_HARDENING_REPORT.md`); Visa was missed.

---

## 2. Source Code Change

**File:** `app/Http/Requests/Visa/StoreVisaBookingRequest.php`

```diff
-            'purchase_price' => ['required', 'numeric', 'min:0'],
-            'selling_price' => ['required', 'numeric', 'min:0'],
+            'purchase_price' => ['required', 'numeric', 'gt:0'],
+            'selling_price' => ['required', 'numeric', 'min:0', 'gte:purchase_price'],
             'service_fee' => ['nullable', 'numeric', 'min:0'],
```

**Effect:**
- `purchase_price = 0` → 422 (was 201)
- `purchase_price = -100` → 422 (was 422 already — `min:0` already rejected negatives)
- `selling_price < purchase_price` → 422 (was 201)
- `selling_price == purchase_price` → 201 (zero-profit allowed)
- `service_fee = 0` → 201 (unchanged, legitimate "no fee" case)
- `service_fee = -50` → 422 (unchanged)

**No service-layer changes.** The validation is the single source of truth; `VisaBookingService::create()` is not affected.

---

## 3. Tests Added

**New file:** `tests/Feature/Visa/VisaPurchasePriceValidationTest.php` (9 tests, 14 assertions, 3.53s)

| # | Test | What it locks in |
|---|------|------------------|
| 1 | `test_purchase_price_zero_is_rejected_with_422` | 0 purchase → 422 with `purchase_price` error key |
| 2 | `test_purchase_price_negative_is_rejected_with_422` | -100 purchase → 422 |
| 3 | `test_purchase_price_one_piagtre_succeeds` | 0.01 (1 piastre) is the minimum valid value |
| 4 | `test_selling_price_below_purchase_price_is_rejected_with_422` | 1000/500 → 422 with `selling_price` error key |
| 5 | `test_selling_price_equal_to_purchase_price_succeeds` | 1000/1000 (zero profit) → 201 |
| 6 | `test_selling_price_above_purchase_price_succeeds` | 1000/1500/100 (positive profit) → 201 |
| 7 | `test_service_fee_zero_is_allowed` | 0 fee → 201 (unchanged behavior) |
| 8 | `test_service_fee_negative_is_rejected_with_422` | -50 fee → 422 |
| 9 | `test_zero_purchase_with_zero_selling_is_rejected_with_422` | 0/0 → 422 (gt:0 fires first) |

**Plus** the 2 pre-existing failing tests now pass without modification:
- `tests/Feature/Visa/VisaEdgeCasesTest.php:19 test_zero_egp_booking_rejected`
- `tests/Feature/Visa/VisaValidationTest.php:162 test_zero_purchase_price_returns_422`

---

## 4. Results

| Metric | Before fix | After fix |
|--------|------------|-----------|
| `VisaPurchasePriceValidationTest` tests | 0 (file didn't exist) | 9 pass |
| `VisaEdgeCasesTest::test_zero_egp_booking_rejected` | FAIL (201) | PASS (422) |
| `VisaValidationTest::test_zero_purchase_price_returns_422` | FAIL (201) | PASS (422) |
| Full Visa suite failures | 5 (3 test-harness + 2 application) | 3 (test-harness only) |
| Full Visa suite total | 375 | 384 (+9) |
| Full Visa suite assertions | 1244 | 1257 (+13) |
| Source code changes | 0 | 1 (StoreVisaBookingRequest.php) |
| Migration changes | 0 | 0 (no schema change — validation only) |

**Zero regressions.** The 3 remaining failures are the test-harness ones in `AuthorizationGatesTest` (2) and `EmployeeIDORTest` (1) — all in other files and explicitly out of Phase 9.5a scope.

---

## 5. Verification of Existing Tests

The fix MUST NOT break any existing tests. I verified by running the full Visa suite — 384 tests run, only the 3 expected failures remain. All other tests continue to pass with the new validation, because:

- `VisaTestCase::bookingPayload()` uses `purchase_price: 1000, selling_price: 1500, service_fee: 100` — all valid under new rules.
- `VisaProductionE2ETest` uses various values (5000/7000/0, 1500/2200/100, etc.) — all valid.
- No existing test relied on `purchase_price = 0` or `selling_price < purchase_price`.

---

## 6. Files Changed in Phase 9.5a

| Path | Action | LOC delta |
|------|--------|-----------|
| `app/Http/Requests/Visa/StoreVisaBookingRequest.php` | **modified** | +2 / -2 (rule change) |
| `tests/Feature/Visa/VisaPurchasePriceValidationTest.php` | **created** | +126 (9 tests) |
| `docs/PHASE_9_5A_ZERO_PURCHASE_FIX_REPORT.md` | **created** | (this file) |

---

## 7. Defect Status — Class-A Resolved

This was the highest-severity finding from the Phase 9.0 baseline. Now FIXED.

**Remaining Class-A defects in the Visa audit scope: 0.**

(Phase 9.8 will address the next Class-A: idempotency on double-payment post — the DB column already exists, just the service-layer integration needs verification.)

---

## 8. Next Phase

Per the approved plan, the next phase is **Phase 9.3b** (extend `EmployeeVisaE2ETest` with ~10 deeper employee scenarios) OR **Phase 9.4** (Refund Deep Audit, Section 8 of 30).
