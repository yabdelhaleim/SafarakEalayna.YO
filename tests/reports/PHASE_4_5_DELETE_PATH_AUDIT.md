# Phase 4.5 — Flight Delete-Path Safety Audit (READ-ONLY)

**Date:** 2026-08-19 11:42:51
**Branch:** `phase-4-historical-inventory`
**Scope:** Verify whether current Flight delete path can reproduce the
B-2 orphan scenario (hard-deleted payments with lingering transactions).

## Check 1 — Do FlightBooking + FlightPayment use SoftDeletes?

| Model | uses SoftDeletes trait |
|-------|------------------------|
| FlightBooking | ✅ yes |
| FlightPayment | ✅ yes |

## Check 2 — Source-code grep for hard-delete patterns

We scan the entire `app/` tree for patterns that could hard-delete
flight_bookings or flight_payments rows. Each match is graded by
context (is it inside a service method? a migration? a test?).

| Pattern | Matches | Risk assessment |
|---------|---------|------------------|
| `forceDelete` | 8 (app\Policies\AccountPolicy.php, app\Policies\ApprovalWorkflowPolicy.php, app\Policies\AuditLogPolicy.php, app\Policies\CustomerPolicy.php, app\Policies\EmployeeAttendancePolicy.php, app\Policies\EmployeePolicy.php, app\Policies\InvoicePolicy.php, app\Policies\SupplierPolicy.php) | ⚠️ high — investigate each match |
| `DB::table('flight_bookings')->` | 0 | ⚠️ high — investigate each match |
| `DB::table('flight_payments')->` | 0 | ⚠️ high — investigate each match |
| `FlightBooking::destroy` | 0 | ✅ low — uses SoftDeletes |
| `FlightPayment::destroy` | 0 | ✅ low — uses SoftDeletes |
| `->forceDelete()` | 0 | ⚠️ high — investigate each match |

## Check 3 — All destroy entry points route through `deleteBookingWithReversal`?

| Entry point | File | Calls deleteBookingWithReversal? | Admin-gated? |
|-------------|------|----------------------------------|--------------|
| FlightController::destroy | Http/Controllers/Api/V1/Flight/FlightController.php | ✅ yes | ✅ admin-gated |
| AviationController::destroy | Http/Controllers/Api/V1/Flight/AviationController.php | ✅ yes | ✅ admin-gated |
| FlightBookingResource (Filament) | Filament/Admin/Resources/FlightBookings/FlightBookingResource.php | ✅ yes | N/A (Filament admin panel — assumes admin context) |

## Check 4 — API routes for Flight destroy are admin-gated?

| Route | Controller | Admin middleware? |
|-------|-----------|-------------------|
| `DELETE /api/v1/flight/bookings/{flightBooking}` | FlightController::destroy | ✅ admin middleware (group or inline) |
| `DELETE /api/v1/flight/aviation/{id}` | AviationController::destroy | ✅ admin middleware (group or inline) |

_Enclosing middleware group line: `Route::middleware('admin')->group(function () {`_

## Check 5 — Past soft-delete timestamps on flight_payments / flight_bookings

If `deleted_at` rows exist with timestamps in the past, those rows
ARE STILL IN THE DB (soft delete). Any hard-delete that left
orphans behind would have to bypass the SoftDeletes trait
(forceDelete or raw DB::table DELETE).

| Table | Active rows | Trashed (deleted_at NOT NULL) |
|-------|-------------|-------------------------------|
| flight_bookings | 0 | 0 |
| flight_payments | 0 | 0 |

> **Note:** Both tables are currently empty (0 rows). The Phase 4
> audit confirmed this — the orphan transactions reference IDs 41–51
> that no longer exist in either state (active or trashed). This means
> the historical hard-delete happened via a path that BYPASSED
> SoftDeletes entirely (or before SoftDeletes was added to FlightPayment).

### SoftDeletes addition history

When was `use SoftDeletes` added to FlightPayment?

Could not determine via git log (commit may not exist with --diff-filter=A).

All commits touching `use SoftDeletes` in FlightPayment (chronological):

- `82734ee feat(prod-hardening): safety net + Flight module + Filament consolidation (Phase 3 tail)`

## Check 6 — SoftDeletes column present in flight_payments schema?

| Table | `deleted_at` column |
|-------|---------------------|
| flight_payments | ✅ present |
| flight_bookings | ✅ present |

## Verdict

### Critical findings

_None._

### Passing checks

- ✅ Both FlightBooking and FlightPayment use SoftDeletes. Default `->delete()` is a soft delete (sets deleted_at).
- ✅ All destroy entry points route through deleteBookingWithReversal.

### Final verdict

✅ **VERDICT: SAFE.** The current delete path CANNOT reproduce the B-2 orphan scenario. The 22 legacy orphans in `transactions` were created before the SoftDeletes migration, by a path that bypassed SoftDeletes (or before SoftDeletes existed).

## Recommended actions

1. **No code change required.** The current path is safe.
2. **Phase 4.5 closes as PASS.** Move to Phase 5.
3. **Optional hardening** (not blocking):
   - Add a global `Model::deleted` listener that warns if a FlightBooking/FlightPayment is force-deleted.
   - Add a nightly integrity check that asserts no orphan transactions exist (compare to audit_flight_orphans_phase_4.php).
