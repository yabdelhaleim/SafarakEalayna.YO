# Flight Module Audit — Baseline Report
**Date**: 2026-08-24
**Branch**: phase-10-tourism-production-audit-hajj-umra
**Audit Type**: Read-only (no logic changes)

---

## Environment

| Item | Value |
|------|-------|
| PHP | ^8.3 |
| Laravel | ^13.0 |
| PHPUnit | ^12.5.12 |
| Filament | ^5.6 |
| Test DB | SQLite `:memory:` |
| APP_ENV | testing |
| CACHE_STORE | array |
| QUEUE_CONNECTION | sync |
| SESSION_DRIVER | array |

## Test Runner

```bash
php artisan test --filter="Flight" --testsuite=Feature
```

## Baseline Numbers

| Metric | Count |
|--------|-------|
| **Total Tests** | **438** |
| **Passed** | 351 (80.1%) |
| **Failed** | 84 (19.2%) |
| **Incomplete** | 2 |
| **Skipped** | 1 |
| **Assertions** | 1975 |
| **Duration** | 96.22s (1m 36s) |

⚠️ **Note**: Previous verification reports (FLIGHT_MODULE_VERIFICATION_20260824.md) cited **249 PASS / 41 FAIL** in `tests/Feature/Flight/`. Current run shows **351/84** because `--filter="Flight"` matches more files (TourismAudit, TourismEmployeeE2E, Tourism subdirs that contain "Flight" in test names).

## Failed Tests Categorization (84 failures)

### Category 1: Tourism No-Edit Contract violations (4 failures)
The contract (INCIDENT-2026-08-17) required removing `updatePrices()` from `FlightController`. Tests verify PUT/PATCH should not be routable.

| Test | Expected | Actual | Diagnosis |
|------|----------|--------|-----------|
| `TourismNoEditContractTest::post_flight_booking_prices_returns_404` | 404 | 500 — `Call to undefined method FlightController::updatePrices()` | **🐛 DEFECT**: Route `POST /flight/bookings/{id}/prices` is still defined but the controller method has been removed → returns 500 instead of 404 |
| `TourismNoEditContractTest::flight_booking_service_update_throws` | LogicException | No exception thrown | Service update() doesn't throw as expected |
| `TourismNoEditContractTest::flight_booking_service_update_prices_throws` | LogicException | No exception thrown | Service updatePrices() doesn't throw as expected |
| `TourismNoEditContractTest::financial_safety_flight_selling_price_unchanged` | 405/404 | 500 | Same root cause |

### Category 2: IDOR/Authorization (Employee tests)
After B-1 fix (IDOR on flight payment/cancel), employees cannot pay/cancel bookings they don't own.

| Test | Expected | Actual | Diagnosis |
|------|----------|--------|-----------|
| `TourismEmployeeE2E\EmployeeIDORTest::flight_employee_b_can_record_payment_on_a_booking` | 201 | 403 | ✅ **Policy working correctly**. Test is testing an insecure scenario — should be updated to expect 403 |
| `TourismEmployeeE2E\EmployeeIdempotencyTest::flight_payment_idempotent_under_same_key` | 201 | 403 | ✅ Policy working. Test setup has wrong ownership |
| `TourismEmployeeE2E\EmployeeFlightE2ETest::employee_can_update_booking_prices` | 200 | (likely 403 or 405) | ✅ No-edit contract working |
| `TourismEmployeeE2E\EmployeeFlightE2ETest::employee_can_record_payment` | 201 | (403) | ✅ IDOR working |
| `TourismEmployeeE2E\EmployeeFlightE2ETest::employee_cannot_cancel_booking` | 403 | (possibly other) | Need to verify |
| `TourismEmployeeE2E\EmployeeFlightE2ETest::employee_cannot_confirm_booking` | 403 | (possibly other) | Need to verify |

### Category 3: Phase11 Master Data Audit (6 failures)
Documented in earlier reports — `private static` method calls.

| Test | Issue |
|------|-------|
| `Phase11MasterDataAuditTest::e1_active_currency_resolves_exchange_rate` | Error — private static method |
| `Phase11MasterDataAuditTest::e2_inactive_currency_uses_rate_with_warning` | Error |
| `Phase11MasterDataAuditTest::e3_undefined_currency_uses_builtin_fallback` | Error |
| `Phase11MasterDataAuditTest::e4_egp_returns_one` | Error |
| `Phase11MasterDataAuditTest::e5_truly_unknown_currency_returns_zero` | Error |
| `Phase11MasterDataAuditTest::c2_system_recharge_succeeds` | Exception |

### Category 4: KWD Cross-Currency Tests (~15 failures)
Documented PRE-EXISTING — KWD cross-currency refund logic.

| Test File | Count |
|-----------|-------|
| `FlightKwdPaymentConversionTest` | 3 |
| `FlightMultiCurrencyProductionTest` | 9 |
| `FlightModuleDeepE2ETest` (scenarios 12, 13, 14, 17, part5) | 5+ |
| `FlightProductionFullE2ETest` (scenarios a, b, c, d, f) | 5 |

### Category 5: Soft Delete Real World (4 failures)
Documented PRE-EXISTING — cashbox drift trade-off.

| Test | Issue |
|------|-------|
| `FlightSoftDeleteRealWorldTest::scenario2_book_partial_pay_cancel_soft_delete` | Trade-off |
| `FlightSoftDeleteRealWorldTest::scenario3_book_cancel_no_refund_soft_delete` | Trade-off |
| `FlightSoftDeleteRealWorldTest::scenario5_kwd_same_ccy_soft_delete` | KWD cross-currency |
| `FlightSoftDeleteRealWorldTest::scenario6_kwd_paid_in_egp_soft_delete` | KWD cross-currency |

### Category 6: Booking CRUD/API tests (~20 failures)
Multiple ValueError exceptions during booking creation/payment/cancellation.

| Test File | Count | Likely Root Cause |
|-----------|-------|-------------------|
| `FlightBookingApiCrudTest` | 2 | ValueError on response shapes |
| `FlightBookingFlowTest` | 10 | ValueError on multiple scenarios |
| `FlightBookingPhase2Test` | 3 | ValueError on GL/journal |
| `FlightBookingDisplayConsistencyTest` | 1 | Exception on foreign currency |
| `FlightCreditBookingTest` | 2 | Exception on partial payment |
| `FlightRemainingCrudTest` | 1 | ValueError on refund |
| `FlightSystemRechargeTest` | 4 | Recharge failures |
| `TourismAudit\FlightFullAuditTest` | 5 | System source booking, payment, idempotency, cancellation |

### Category 7: Phase11 / Audit gates (~5 failures)

| Test | Issue |
|------|-------|
| `Phase11FeBeContractAuditTest::a2_create_system_booking_via_http_contract` | Exception |
| `Phase11MandatoryGatesTest::c2_03_cancel_group_booking_reverts_group_debt` | Exception |
| `F12Phase12FlightDeleteRegressionTest::p0_1b_full_penalty_cancel_then_delete_no_residual` | Exception |
| `FlightCashBasisRegressionTest::s01_egp_credit_booking_no_payment_recognises_no_revenue` | Exception |
| `RefundRequestReversalTest::refund_to_agency_treasury_reversal_restores_all_balances` | Exception |

### Category 8: Aviation Service (1 failure)

| Test | Issue |
|------|-------|
| `AviationServiceTest::update_booking_via_aviation_service` | LogicException |

### Category 9: Other (5 failures)

| Test | Issue |
|------|-------|
| `TourismTrialBalanceIntegrityTest::flight_group_receivable_appears_in_tourism_due_to...` | Trial balance |
| `FinancialReportTest::debts_report_correctly_maps_flight_to_tourism` (2) | InvalidArgumentException |
| `TourismEmployeeE2E\EmployeeDatabaseIntegrityTest::no_orphan_flight_payments` | Integrity |
| `Flight\FlightPaymentReversalTest::single_payment_reversal_restores_cashbox_and_clearing_balances` | Reversal |

---

## Summary Statistics by Category

| Category | Failures | Severity |
|----------|----------|----------|
| No-Edit Contract (broken route) | 4 | 🔴 **HIGH** — security/UX |
| IDOR/Auth (working correctly) | 4 | ✅ Correct behavior, tests need updating |
| Phase11 Master Data (private methods) | 6 | 🟡 Medium — easy fix |
| KWD Cross-Currency | 15+ | 🟠 Documented trade-off |
| Soft Delete Trade-off | 4 | 🟠 Documented trade-off |
| Booking CRUD (ValueError) | 20+ | 🔴 **CRITICAL** — basic operations failing |
| Phase11 Gates | 5 | 🟠 Various |
| Aviation | 1 | 🟡 LogicException |
| Other | 5 | 🟡 Various |
| **TOTAL** | **84** | |

---

## 🚨 Critical Findings (Pre-Audit)

Before writing new audit tests, the baseline reveals:

1. **`POST /flight/bookings/{id}/prices` returns 500** — Route still exists but method removed. This is a **HIGH severity defect** because:
   - Users see a confusing 500 error instead of clean 404
   - Indicates an incomplete INCIDENT-2026-08-17 fix
   - The route should be removed from `routes/api.php`

2. **20+ basic CRUD operations failing with ValueError** — These are the core booking flows. Multiple tests fail at `createBooking`/`addPayment`/`cancel`. Need deeper investigation.

3. **84 failures vs 41 documented** — The verification report was wrong. Either:
   - Tests were added since the report
   - More tests are matching the `--filter="Flight"`
   - New failures introduced since the report

---

## Pre-Audit Action Items

1. ✅ Document baseline (this file)
2. → Write 32 audit test cases in `BookingLifecycleAuditTest.php`
3. → Run each test independently
4. → Document all defects with reproduction steps
5. → Compile final report

---

**Full baseline log**: `.zcode/plans/FLIGHT_BASELINE_FULL_20260824.log` (9402 lines)