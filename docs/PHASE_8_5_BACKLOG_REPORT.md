# Phase 8.5 Backlog — Pre-existing failures NOT touched by Phase 8.5

**Date:** 2026-08-19
**Branch:** `phase-8.5-8.6-route-gates-and-actor-strict`
**Status:** 📋 **BACKLOG ONLY** — out of Phase 8.5 scope, documented for future review.

---

## 1. Context

While applying Phase 8.5 A1.5/A1.6 (admin gate on hajj-umra/programs), two pre-existing failures appeared in `HajjUmraProgramControllerTest` that were already failing at parent commit `6e2e257`. These are **NOT** caused by Phase 8.5 changes — they need separate root-cause investigation outside this branch.

---

## 2. Failures registered

### 2.1 `HajjUmraProgramControllerTest::test_store_program_creates_new_record`

**Failure:** `Expected response status code [201] but received 422.`

**Validation error (verbatim):**
```
{
    "success": false,
    "message": "بيانات المدخلات غير صالحة.",
    "errors": {
        "program_type": [
            "The selected program type is invalid."
        ]
    }
}
```

**Location:** `tests/Feature/HajjUmra/HajjUmraProgramControllerTest.php:107`

**Test payload (verbatim):**
```php
$payload = [
    'program_name'     => 'برنامج جديد',
    'program_type'     => 'UMRA',                  // ← rejected by validation
    'total_nights'     => 7,
    'accommodation_type' => 'QUAD',
    'mecca_hotel_name' => 'فندق جديد',
    'mecca_nights'     => 5,
    'medina_hotel_name' => 'فندق المدينة',
    'medina_nights'    => 2,
    'departure_date'   => now()->addMonths(2)->toDateString(),
    'return_date'      => now()->addMonths(2)->addDays(7)->toDateString(),
    'airline' => 'Saudi Airlines',
    'executing_company' => 'الشركة المنفذة',
    'departure_point' => 'CAI',
    'selling_price' => 30000,
    'purchase_price' => 25000,
    'currency' => 'EGP',
    'is_active' => true,
];
```

**Likely root cause (NOT confirmed):** The `program_type` field may have a constrained enum (e.g. `hajj`, `umra`) and the test uses `'UMRA'` (uppercase). Either:
- The validator rule rejects the uppercase form (needs `Rule::in(['hajj','umra'])` rather than `Rule::in(['HAJJ','UMRA'])`),
- Or the validator uses a different enum mapping.
- Or the test was written against an older draft of the validation rule.

**Status:** ⏸️ Pre-existing in master, NOT touched by Phase 8.5. Will need a future phase to fix.

---

### 2.2 `HajjUmraProgramControllerTest::test_update_program_modifies_record`

**Failure:** `Failed asserting that 0.0 matches expected 55000.0.`

**Location:** `tests/Feature/HajjUmra/HajjUmraProgramControllerTest.php:170`

**Test code (relevant lines):**
```php
$response = $this->putJson("/api/v1/hajj-umra/programs/{$program->id}", [
    'program_name' => 'برنامج محدّث',
    'selling_price' => 55000,
]);
$response->assertOk();

$program->refresh();
$this->assertSame('برنامج محدّث', $program->program_name);
$this->assertEqualsWithDelta(55000.0, (float) $program->selling_price, 0.01);
```

**Failure:** `program_name` IS updated to `'برنامج محدّث'` (assertSame passes), but `selling_price` is still 0.0 instead of 55000.0.

**Likely root cause (NOT confirmed):** The Program model's `selling_price` field may be:
- A column that doesn't exist (so the UPDATE silently loses it),
- Or computed from a separate `default_selling_price` field that's not being updated by the PUT (the test uses `selling_price` but the column might be `default_selling_price`),
- Or guarded by a separate field map.

**Note:** Test fixture `makeProgram()` likely uses `default_selling_price` to set initial value. The PUT sends `selling_price`, but the model may not propagate to the column.

**Status:** ⏸️ Pre-existing in master, NOT touched by Phase 8.5. Will need a future phase to fix.

---

## 3. Other pre-existing failures tracked (not new, out of Phase 8.5 scope)

These are NOT in the A1.5/A1.6 blast radius, but they were already failing at parent commit `6e2e257`:

| Module | Test | Failure type | Notes |
|--------|------|--------------|-------|
| HajjUmra | `HajjUmraBookingLifecycleCancelTest::4_5_cancel_refunded_booking` | RuntimeException | Phase 8 (refund flow) out of scope |
| HajjUmra | `HajjUmraBookingLifecycleCancelTest::4_5_add_payment_on_refunded` | RuntimeException | Phase 8 (refund flow) out of scope |
| HajjUmra | `HajjUmraBookingLifecycleCancelTest::4_6_destroy_refunded_booking` | RuntimeException | Phase 8 (refund flow) out of scope |
| HajjUmra | `HajjUmraControllerTest::refund_flips_status_to_refunded` | — | Phase 8 (refund flow) out of scope |
| HajjUmra | `HajjUmraProductionE2ETest::24_refund_zero_amount_booking_is_safe` | — | Phase 8 (refund flow) out of scope |
| HajjUmra | `TourismDivision\HajjUmraProductionTest::refund_after_cancel_throws` | — | Phase 8 (refund flow) out of scope |
| HajjUmra | `EmployeeHajjUmraE2ETest::employee_can_update_booking` | — | NO-EDIT-CONTRACT, Phase 8 out of scope |
| Flight | `Flight\FlightModuleDeepE2ETest` (many) | — | Phase 8 (edit lock) out of scope |
| Flight | `Flight\FlightBookingPhase2Test` (many) | — | Phase 8 out of scope |
| Flight | `Flight\FlightMultiCurrencyProductionTest` (many) | — | Phase 8 out of scope |
| Flight | `Flight\FlightProductionFullE2ETest` (many) | — | Phase 8 out of scope |
| Flight | `Flight\FlightPaymentReversalTest` (many) | — | Phase 8 out of scope |
| Flight | `Flight\FlightSoftDeleteRealWorldTest` (many) | — | Phase 8 out of scope |
| Flight | `Flight\RefundRequestReversalTest` (many) | — | Phase 8 out of scope |
| Visa | `VisaPermissionTest::employee_cannot_*` (2) | — | Phase 8 Option A related, pre-existing |
| Visa | `VisaBookingControllerTest` (some) | — | Phase 8 out of scope |
| Visa | `VisaProductionE2ETest` (many) | — | Phase 8 out of scope |
| Visa | `Visa\VisaBookingServiceDeadCodeTest` (some) | — | Phase 8 out of scope |
| Visa | `Tourism\TourismNoEditContractTest` (many) | — | Phase 8 NO-EDIT-CONTRACT out of scope |
| Tourism | `TourismEmployeeE2E\EmployeeFlightE2ETest` (many) | — | Phase 8 out of scope |
| Tourism | `TourismEmployeeE2E\EmployeeIDORTest` (many) | — | Phase 8 out of scope |
| Tourism | `TourismEmployeeE2E\EmployeeIdempotencyTest` (many) | — | Phase 8 out of scope |
| Tourism | `TourismEmployeeE2E\EmployeeDatabaseIntegrityTest` (many) | — | Phase 8 out of scope |
| Tourism | `Tourism\TourismNoEditContractTest` (many) | — | Phase 8 NO-EDIT-CONTRACT out of scope |
| Finance | `Finance\TourismTrialBalanceIntegrityTest` (1) | — | Phase 8 out of scope |
| Finance | `FinancialReportTest::debts_report_*` (2) | — | Phase 8 out of scope |
| Other | `Flight\FlightKwdPaymentConversionTest` (some) | — | Phase 8 out of scope |
| Other | `Flight\AviationServiceTest` (some) | — | Phase 8 out of scope |

Full list is captured by `artisan test` counts. None of these are caused by Phase 8.5.

---

## 4. Acceptance criteria for this backlog

- ✅ All failures here are pre-existing at parent commit `6e2e257`.
- ✅ None of them are caused by Phase 8.5 routes/middleware edits.
- ✅ They are explicitly out of Phase 8.5 scope (Phase 8 / Phase 8.6 / refund flow / NO-EDIT-CONTRACT).
- ✅ This backlog is for Phase 8+ re-audit and Phase 9/10.

**DO NOT** action this backlog from Phase 8.5. Forward to Phase 8 (continued) / Phase 9 / Phase 10 planning.
