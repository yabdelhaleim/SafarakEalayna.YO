# Phase 8.6 B1 — HajjUmraBookingService Actor Enforcement

**Date:** 2026-08-19
**Branch:** `phase-8.5-8.6-route-gates-and-actor-strict`
**Scope:** HIGH-risk — `HajjUmraBookingService::deleteBookingWithReversal()`
**Status:** ✅ COMPLETE — 0 regressions vs baseline

---

## 1. Problem Statement

The `HajjUmraBookingService::deleteBookingWithReversal(int $bookingId, int $userId)` method
accepted an `int $userId` and used the caller's value directly. If a controller passed
`Auth::id() ?: 1` (or any caller passed `0`), the deletion would be attributed to a
**system user** or **unauthenticated actor** — making every deletion appear to come
from the same identity, breaking audit trails and creating a falsification risk.

The B-1 attack pattern: any caller could forge the actor identity by passing an
arbitrary `int $userId`. Even worse: the fallback pattern `Auth::id() ?: 1` would
silently attribute deletions to user #1 when no actor was authenticated.

---

## 2. Fix

Mirror the existing `HajjUmraRefundService::refund()` enforcement pattern (lines 65-70):
- **Signature:** `?User $actor = null` (nullable User instance)
- **Enforcement block:** If no actor supplied AND `auth()->user()` returns null → throw
  `\RuntimeException` with a clear Arabic + English message
- **Use import:** `use App\Models\User;` at the top of the service

The enforcement happens INSIDE the service so every caller (controller, tests, stress
scripts, audit phases, validation scripts) is protected — there's no way to bypass it.

---

## 3. Files Changed (11 total, +31 / -17 lines)

### Service (1 file)
| File | Change |
|------|--------|
| `app/Services/HajjUmra/HajjUmraBookingService.php` | +1 import (`use App\Models\User;`), signature change `int $userId` → `?User $actor = null`, +15 lines enforcement block (mirror of `HajjUmraRefundService::refund()`), removed dead `Auth::id() ?: 1` fallback at line 495 |

### Production controller (1 file)
| File | Change |
|------|--------|
| `app/Http/Controllers/Api/V1/HajjUmraController.php` | Pass `$request->user()` (User instance) to service, removed `$userId = Auth::id() ?: 1;` line |

### Tests (1 file)
| File | Change |
|------|--------|
| `tests/Feature/HajjUmra/HajjUmraBookingLifecycleCancelTest.php:275` | Pass `$this->admin` (User) instead of `$this->admin->id` (int) |

### Stress / validation scripts (2 files)
| File | Change |
|------|--------|
| `tests/scripts/stress_booking_lifecycle_gate.php:680` | Pass `$actor` (User) instead of `(int) $actor->id` |
| `scripts_temp_validate_hajjumra_reversal.php:250, 281` | Pass `$realUser` (User) instead of `$realUser->id` |

### Audit phase scripts (6 files, 7 HajjUmra sites total)
| File | Sites |
|------|-------|
| `audit_phases/Phase10_SoftDelete.php` | 2 sites (lines 291, 376) |
| `audit_phases/Phase9_Cancellation.php` | 2 sites (lines 352, 373) |
| `audit_phases/Phase8_RefundAttack.php` | 1 site (line 387) |
| `audit_phases/Phase3_AdminJourney.php` | 1 site (line 212) |
| `audit_phases/Phase2_EmployeeJourney.php` | 1 site (line 269) |
| `audit_phases/Phase16_Profit.php` | 1 site (line 209) |

Flight and Visa call sites in the same audit phase files were intentionally NOT
modified — those are out of scope for B1 and will be addressed in B3 (Flight) and B2
(Visa) when those are tackled.

---

## 4. Test Results

### Unit / Feature suite
```
$ php artisan test --filter "HajjUmra"

Tests:    9 failed, 2 skipped, 365 passed (1514 assertions)
Duration: 68.10s
```

**Diff vs baseline:** 0 — no regressions introduced.

The 9 failing tests are all PRE-EXISTING failures (all in tests touching REFUND or
UPDATE operations, not delete):
1. `HajjUmraProgramControllerTest > store program creates new record`
2. `HajjUmraProgramControllerTest > update program modifies record`
3. `HajjUmraBookingLifecycleCancelTest > 4 5 cancel refunded booking is rejected by guard` (refund, not delete)
4. `HajjUmraBookingLifecycleCancelTest > 4 5 add payment on refunded booking is blocked` (refund)
5. `HajjUmraBookingLifecycleCancelTest > 4 6 destroy refunded booking is safe to run` (refund)
6. `HajjUmraControllerTest > refund flips status to refunded`
7. `HajjUmraProductionE2ETest > 24 refund zero amount booking is safe`
8. `TourismDivision\HajjUmraProductionTest > refund after cancel throws`
9. `TourismEmployeeE2E\EmployeeHajjUmraE2ETest > employee can update booking`

The `test_4_5_cancel_soft_deleted_booking_404_or_422` test (which calls
`deleteBookingWithReversal` and was the test I modified) is now **PASSING** (✓).

### Reflection check
```
Method: deleteBookingWithReversal
Class:  App\Services\HajjUmra\HajjUmraBookingService
  $bookingId type=int default=NO_DEFAULT
  $actor type=?App\Models\User default=NULL
Return: bool
```

### Lint check
All 11 modified files: `php -l` returns "No syntax errors detected."

### Static check — no leftover `Auth::id() ?: 1` pattern
The only remaining occurrence is in a COMMENT (line 460) describing what the
enforcement is preventing — this is intentional documentation.

---

## 5. Explicit Deletion Test (Create + Pay + Delete + Verify Reversal)

Test setup on local MySQL `safarakealayna`:
- User id=1 (System Admin) as actor
- Cashbox account id=6
- Customer account id=1
- Treasury account id=1045 (B1 test treasury, `is_module_vault=true`, `module='hajj_umra'`)
- Test entities prefixed `B1_DEL_TEST_20260819_`

### Ledger story

| Step | Customer AR (id=1) | Cashbox (id=6) | Note |
|------|-------------------:|---------------:|------|
| Pre-booking state (from prior tests) | 1590 | 50 | Reference baseline |
| `create()` — Income tx 4512: customer credit +1000 | **2590** | 50 | New sale recorded |
| `addPayment()` — Transfer tx 4513: customer debit -1000, cashbox credit +1000 | 1590 | **1050** | Cash collected, AR cleared |
| `deleteBookingWithReversal()` runs (with User actor) | | | |
| ↳ reverse tx 4513: customer credit +1000, cashbox debit -1000 | 2590 | 50 | Payment reversed |
| ↳ reverse tx 4512: customer debit -1000, revenue credit +1000 | **1590** | 50 | Income reversed |
| **Final state after delete** | **1590 ✓** | **50 ✓** | **Back to pre-booking state** |

**Both accounts fully restored to pre-booking state. Full reversal confirmed.**

### Idempotency test
```
[2nd delete on same booking]
✓ Threw: RuntimeException — "هذا الحجز محذوف بالفعل (soft delete) — لا يمكن عكسه مرة ثانية."
```

### Enforcement test (no actor + no auth)
```
[delete with no actor param + Auth::logout()]
✓ Threw: RuntimeException — "HajjUmraBookingService::deleteBookingWithReversal requires an authenticated actor. Deletion operations cannot be attributed to a system user."
```

---

## 6. Process Notes

### Revert incident (worth documenting)
During B1 implementation, a **parallel bus validation agent** (commits
`b2ee50e` and `bd6bf71`) made commits on the same branch while I was working.
A `git reset` operation at 18:52:22 caused 7 of my 11 file changes to be silently
reverted (mtime cluster 18:53:08 affected service, controller, test, stress,
validation, Phase 10, Phase 9). Phase 8, 3, 2, 16 had been modified later (mtimes
18:53:17, 18:54:16, 18:54:29, 18:54:47) and survived.

I detected the revert via a comprehensive `git diff` check + state grep after
the apparent "all done" status. Re-applied all 7 reverted changes in one
batch with verification, then re-ran the test suite (still 9/365 baseline).

**Lesson learned:** Always verify file state with `git diff` AND `grep` against
the actual line content (not just `git status` which only shows file-level
modifications). When working on a shared branch with parallel agents, treat
working-tree state as untrusted and re-verify after every reflog event.

### Verify protocol (going forward)
1. Make edit (Edit tool, sed, awk, Write)
2. Immediately Read the file with `git diff -- <file>` to confirm
3. `grep` the specific line content to confirm
4. Only then move to the next file

### Lint / reflection
- `php -l <file>` for each modified file
- Reflection check via `php -r` to verify method signature

---

## 7. Diff Summary

```
 app/Http/Controllers/Api/V1/HajjUmraController.php      |  3 +--
 app/Services/HajjUmra/HajjUmraBookingService.php        | 17 ++++++++++++++++-
 audit_phases/Phase10_SoftDelete.php                     |  4 ++--
 audit_phases/Phase16_Profit.php                         |  2 +-
 audit_phases/Phase2_EmployeeJourney.php                 |  4 ++--
 audit_phases/Phase3_AdminJourney.php                    |  4 ++--
 audit_phases/Phase8_RefundAttack.php                    |  2 +-
 audit_phases/Phase9_Cancellation.php                    |  4 ++--
 scripts_temp_validate_hajjumra_reversal.php             |  4 ++--
 tests/Feature/HajjUmra/HajjUmraBookingLifecycleCancelTest.php |  2 +-
 tests/scripts/stress_booking_lifecycle_gate.php         |  2 +-
 11 files changed, 31 insertions(+), 17 deletions(-)
```

---

## 8. Out of Scope (Next Phases)

- **B2** — `VisaRefundService::deleteWithReversal` actor enforcement
- **B3** — `FlightBookingService::deleteBookingWithReversal` actor enforcement
  (most callers: FlightController, 4 sites in FlightModuleDeepE2ETest, 1 in
  FlightMultiCurrencyProductionTest, 1 in flight_e2e_phase2.php, 2 in
  Filament FlightBookingResource)
- **B4** — Visa `created_by` nullability check (STOP — needs user decision)
- **B5** — `FlightCarrierRechargeService::rechargeFromAccount` (STOP — needs user decision)
- **B6** — `FlightSystemRechargeService::rechargeFromAccount` (STOP — needs user decision)
- **MEDIUM-risk (15 patterns)** — separate phase
- **LOW-risk (28 patterns)** — separate phase
