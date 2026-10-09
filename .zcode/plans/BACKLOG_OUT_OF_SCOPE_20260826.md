# Out-of-Scope Backlog

> **Purpose**: This file tracks defects and risks that were *discovered* during the
> flight module production-readiness audit (Phase 10) but that **fall outside the
> flight module scope** of that audit. They are preserved here so they are not
> forgotten and so a future audit cycle (Visa / Hajj / Online / Bus / Fawry) can
> pick them up.
>
> **Do NOT address items in this file as part of the flight audit work.** Each
> item here belongs to a different module's audit cycle.

---

## BACKLOG-001 — VisaBookingService::update() does not enforce the no-edit contract

- **Discovered**: 2026-08-26, during DEFECT-011 verification
- **Test that exposes it**: `tests/Feature/Tourism/TourismNoEditContractTest::visa_booking_service_update_throws`
- **Symptom**:
  - Test calls `app(VisaBookingService::class)->update($booking, ['selling_price' => 30])`
  - Test expects `\LogicException` with message matching `/Tourism no-edit contract/`
  - Actual: `\TypeError` thrown from `VisaBookingService::find()` — `Argument #1 ($id) must be of type int, null given, called in .../VisaBookingService.php on line 389`
  - Net result: the `update()` path is NOT protected. A LogicException never fires; the test fails because a different exception type bubbles up.
- **Root cause**:
  - `AviationService::updateBooking()` was hardened during INCIDENT-2026-08-17 to throw LogicException at the top of the method (reference pattern: see `app/Services/Flight/AviationService.php:302-310`).
  - `FlightBookingService::updateBooking()` and `updatePrices()` were hardened in DEFECT-011 (commit 98ebafb).
  - `VisaBookingService::update()` was **NOT** hardened — it still contains the original implementation, which calls `find($booking->id)` then runs a full update flow.
- **Why it's out of scope**:
  - The flight module audit (Phase 10, branch `phase-10-tourism-production-audit-hajj-umra`) targets the flight module specifically.
  - The Visa service lives in `app/Services/Visa/` and is owned by the Visa module.
  - The Visa module's production-readiness audit is a separate workstream.
- **Expected fix shape (for the Visa audit owner to design)**:
  - Add a throw-stub at the top of `VisaBookingService::update()` similar to AviationService::updateBooking() and the now-hardened FlightBookingService methods.
  - Test must flip from FAILING to PASSING.
  - Mirror the same approach in any other Tourism module that still has live `update()` paths.
- **Owner**: Visa module audit
- **Status**: OPEN — preserved for future Visa audit cycle

---

## BACKLOG-002 — Out-of-band scripts call disabled service methods directly

- **Discovered**: 2026-08-26, during DEFECT-011 verification
- **Scope**: Manual / ad-hoc test scripts that live under `tests/e2e/` and `tests/scripts/`. These are NOT registered in `phpunit.xml` (`<testsuite>` blocks list `tests/Unit` and `tests/Feature` only), so they do not run in CI. They will fail if invoked manually after DEFECT-011.
- **Files affected**:
  1. `tests/e2e/flights_e2e_staging.php`
     - Line 290: `$service->updatePrices($booking, 6500, 8500)`
     - Line 317: `$service->updateBooking($booking, [...])`
  2. `tests/scripts/flight_final_full_audit_20260815.php`
     - Line 639: `$svc->updatePrices($bRec, -100, 1000)`
     - Line 647: `$svc->updatePrices($bRec, 1000, -100)`
     - Line 680: `$svc->updatePrices($bRec, 0, 1000)`
  3. `tests/scripts/flight_final_audit_phase2.php`
     - Line 201: `$svc->updatePrices($b->fresh(), -100, 1000)`
     - Line 205: `$svc->updatePrices($b->fresh(), 1000, -500)`
     - Line 209: `$svc->updatePrices($b->fresh(), 0, 1000)`
- **Why these are out of scope**:
  - These are one-off audit / staging scripts, not part of the test suite.
  - DEFECT-011 only commits a top-of-file deprecation header (per audit owner instruction), not a removal or skip-flag rewrite.
- **Disposition (per audit owner instruction, 2026-08-26)**:
  - DEPRECATED header added at the top of each file.
  - No behavioral change to the scripts themselves.
- **Status**: DEPRECIATED — preserved as-is, header warning added, deletion deferred to a future cleanup pass.
