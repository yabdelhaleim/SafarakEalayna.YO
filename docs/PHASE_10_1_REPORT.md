# Phase 10.1 — Master Data Audit (Section 4)

**Branch:** `phase-10-tourism-production-audit-hajj-umra`
**Date:** 2026-08-20
**Tests:** `tests/Feature/HajjUmra/HajjUmraMasterDataAuditTest.php` (20 tests, all PASS)
**Regression:** 565/570 (5 baseline Class-D failures + 1 error remain, all out of Hajj/Umra scope)

---

## 1. Scope

Section 4 of the 30-section prompt, applied independently to Hajj/Umra.

**Audit targets:**
- `HajjUmraStatus` enum completeness (6 cases)
- `program_type` enum (`hajj` / `umra`) and case-insensitive normalization
- `accommodation_type` string normalization (UPPERCASE)
- `accommodation_type_id` linkage
- `UmrahSupplier`, `HajjUmraExecutingCompany`, `Hotel`, `TripSupervisor`, `AccommodationType` master data
- Reference endpoints (`/hajj-umra/settings/...`)

---

## 2. Defects Found and Fixed

### Class-B — `program_type` not case-insensitive (FIXED)

**Location:** `app/Http/Requests/HajjUmra/StoreProgramRequest.php` and
`UpdateProgramRequest.php`

**Symptom:** `prepareForValidation()` only normalized lowercase `'umrah'`
to `'umra'`. Any uppercase variant (`UMRA`, `Umrah`, `UMRAH`, `Hajj`)
failed the `Rule::in(['hajj','umra'])` check and returned 422.

**Fix:** replaced the literal equality check with a case-insensitive
match:

```php
if ($type = $this->input('program_type')) {
    $lower = strtolower((string) $type);
    $canonical = match ($lower) {
        'hajj' => 'hajj',
        'umrah', 'umra' => 'umra',
        default => $lower,
    };
    $this->merge(['program_type' => $canonical]);
}
```

This was a real defect — the API rejected the very common UI form input
`UMRA` (uppercase).

### Class-D test-harness — `test_update_program_modifies_record` used wrong field name (FIXED)

**Location:** `tests/Feature/HajjUmra/HajjUmraProgramControllerTest.php:170`

**Symptom:** the test sent `selling_price` and read back `$program->selling_price`,
but the Program schema column is `default_selling_price` (see migration
`2026_04_27_124250_create_programs_table.php`). The Program model's
`$fillable` does not include `selling_price` / `purchase_price`, so the
value was silently dropped on store AND on update, returning 0.

**Fix:** changed the test to use `default_selling_price` and assert against
the real field.

### Class-D test-harness — `test_employee_can_update_booking` asserted 200 on PUT (FIXED)

**Location:** `tests/Feature/TourismEmployeeE2E/EmployeeHajjUmraE2ETest.php:124`

**Symptom:** Phase 8.5 (incident 2026-08-17) removed PUT/PATCH from Tourism
booking routes (Hajj/Umra and Visa). The test was asserting the old
`200 OK` behavior on `PUT /api/v1/hajj-umra/bookings/{id}`.

**Fix:** flipped the assertion to `assertStatus(405)` (Method Not Allowed)
and renamed the test to `test_employee_cannot_update_booking_via_put`.

---

## 3. By-Design Behaviors (documented, NOT defects)

### `HajjUmraExecutingCompanyObserver::saving` auto-creates Supplier Account

Mirrors the `VisaAgentObserver` pattern. When a `HajjUmraExecutingCompany`
is created without an `account_id`, the observer auto-creates a Supplier
Account with `module_type='hajj_umra'` and links it back to the company.

The audit locks this in with `test_executing_company_observer_auto_creates_account`
so any future regression that exposes a NULL `account_id` is caught.

---

## 4. Test Coverage Matrix (20 tests)

| # | Test | Result |
|---|------|--------|
| 1 | `test_hajjumra_status_enum_has_six_cases` | ✅ |
| 2 | `test_hajjumra_status_for_dropdown_includes_all_six` | ✅ |
| 3 | `test_hajjumra_status_label_and_color_defined_for_all_cases` | ✅ |
| 4 | `test_program_type_lowercase_hajj_accepted` | ✅ |
| 5 | `test_program_type_uppercase_hajj_normalized_and_accepted` | ✅ |
| 6 | `test_program_type_mixed_case_hajj_accepted` | ✅ |
| 7 | `test_program_type_lowercase_umra_accepted` | ✅ |
| 8 | `test_program_type_lowercase_umrah_normalized_to_umra` | ✅ |
| 9 | `test_program_type_uppercase_umra_normalized_and_accepted` | ✅ **(after fix)** |
| 10 | `test_program_type_uppercase_umrah_accepted` | ✅ **(after fix)** |
| 11 | `test_program_type_invalid_value_rejected` | ✅ |
| 12 | `test_accommodation_type_normalized_to_uppercase` | ✅ |
| 13 | `test_accommodation_type_id_links_to_row` | ✅ |
| 14 | `test_umrah_supplier_factory_creates_with_account` | ✅ |
| 15 | `test_executing_company_observer_auto_creates_account` | ✅ |
| 16 | `test_settings_statuses_endpoint_returns_six` | ✅ |
| 17 | `test_settings_programs_endpoint_lists_active_programs` | ✅ |
| 18 | `test_settings_executing_companies_endpoint` | ✅ |
| 19 | `test_programs_index_returns_paginated` | ✅ |
| 20 | `test_program_show_returns_record` | ✅ |

---

## 5. Remaining Baseline Items (deferred to Phase 10.14)

| # | Test | Why deferred |
|---|------|--------------|
| 1 | `ProductionScaleBenchmarkTest` | DB error, env-specific (MySQL only) |
| 2 | `FawryProductionTest` | Fawry module, out of Hajj/Umra scope |
| 3 | `MultiCurrencySoftDeleteIntegrityTest` | Multi-currency conversion (cross-cutting) |
| 4 | `TourismDivisionFullLoadTest` | Load test (env-specific) |
| 5 | `TourismTrialBalanceIntegrityTest::test_flight_group_receivable_*` | Flight module |
| 6 | `TourismTrialBalanceIntegrityTest::test_combined_tourism_*` | Cross-module accounting |

---

## 6. Regression Status

```
$ vendor/bin/phpunit tests/Feature/HajjUmra/ tests/Feature/TourismDivision/ \
                      tests/Feature/TourismAudit/ \
                      tests/Feature/TourismEmployeeE2E/EmployeeHajjUmraE2ETest.php \
                      tests/Feature/Finance/TourismTrialBalanceIntegrityTest.php
Tests: 565 / Assertions: 2266 / Errors: 1 / Failures: 5

vs baseline: 545 / 2195 / 1 / 8
Net: +20 tests, +71 assertions, -3 failures (program_type, program update, employee update)
```

---

## 7. Verdict

🟢 **Phase 10.1 PASS.** 1 Class-B defect fixed (program_type case
insensitivity), 2 Class-D test-harness items reconciled (program
field-name, employee PUT), 1 by-design observer behavior documented.

**Circuit Breaker: CLEARED.** Proceeding to Phase 10.2 (Admin E2E).
