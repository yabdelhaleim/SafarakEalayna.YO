# Phase 8.6 — B2 Report: Visa `deleteWithReversal` Actor Enforcement

**Date:** 2026-08-19
**Branch:** `phase-8.5-8.6-route-gates-and-actor-strict`
**Base commit:** `4f95198` (B1 — HajjUmraBookingService actor enforcement)
**Scope:** `App\Services\Visa\VisaRefundService::deleteWithReversal()` and its deprecated shim on `VisaBookingService`.

---

## 1. Problem statement

`VisaRefundService::deleteWithReversal(int $bookingId, int $userId)` accepted a raw integer `userId` from any caller. The method then used `Auth::id() ?: 1` as a silent fallback for system paths, which created a data-falsification risk:

- Any caller could pass `int 1` (or any int) and the deletion would be attributed to that user.
- If the auth state was unset (CLI, queued job, audit script, test), the deletion silently attributed to `user_id=1` (system user) — corrupting the audit trail of who performed the deletion.
- The int parameter gave callers no compile-time/type-checked way to pass an authenticated user.

The deprecated shim `VisaBookingService::deleteBookingWithReversal(int $bookingId, int $userId)` had the same flaw and was a thin pass-through to the real service.

This is the same risk class as B1 (`HajjUmraBookingService::deleteBookingWithReversal`) and was fixed by commit `4f95198`. B2 applies the identical pattern to the Visa module.

---

## 2. Fix

### 2.1 `VisaRefundService::deleteWithReversal` — accept `?User`, enforce presence

**Before:**
```php
public function deleteWithReversal(int $bookingId, int $userId): bool
{
    return VisaBooking::run(function () use ($bookingId, $userId) {
        return DB::transaction(function () use ($bookingId, $userId) {
            $booking = VisaBooking::query()->withTrashed()->…->findOrFail($bookingId);
            if ($booking->trashed()) { throw new \RuntimeException(…); }
            $userIdEffective = $userId ?: (int) (Auth::id() ?: 1);   // ← silent fallback
            Log::info('…', ['user_id' => $userIdEffective]);
            …
        });
    });
}
```

**After:**
```php
public function deleteWithReversal(int $bookingId, ?User $actor = null): bool
{
    // ── INVARIANT: actor identity from server-side auth ──
    $actor = $actor ?? auth()->user();
    if (! $actor instanceof User) {
        throw new \RuntimeException(
            'VisaRefundService::deleteWithReversal requires an authenticated actor. '
            .'Deletion operations cannot be attributed to a system user.'
        );
    }
    $userId = $actor->id;

    return VisaBooking::run(function () use ($bookingId, $userId) {
        return DB::transaction(function () use ($bookingId, $userId) {
            $booking = VisaBooking::query()->withTrashed()->…->findOrFail($bookingId);
            if ($booking->trashed()) { throw new \RuntimeException(…); }
            $userIdEffective = $userId;   // ← already enforced, no fallback
            Log::info('…', ['user_id' => $userIdEffective]);
            …
        });
    });
}
```

The `use App\Models\User;` import already existed (the file already uses `User` elsewhere).

### 2.2 `VisaBookingService::deleteBookingWithReversal` (deprecated shim)

**Before:**
```php
/** @deprecated */
public function deleteBookingWithReversal(int $bookingId, int $userId): bool
{
    return app(VisaRefundService::class)->deleteWithReversal($bookingId, $userId);
}
```

**After:**
```php
/** @deprecated */
public function deleteBookingWithReversal(int $bookingId, ?User $actor = null): bool
{
    return app(VisaRefundService::class)->deleteWithReversal($bookingId, $actor);
}
```

Added `use App\Models\User;` import. The shim now passes the `User` actor through (preserving type identity).

---

## 3. Caller updates

7 callers updated to pass `User` objects instead of `int $userId`:

| # | File | Line | Change |
|---|------|------|--------|
| 1 | `app/Http/Controllers/Api/V1/Visa/VisaBookingController.php` | 128 | Removed `$userId = Auth::id() ?: 1;` and pass `$request->user()` directly |
| 2 | `app/Services/Visa/VisaBookingService.php` | 50 | Deprecated shim signature: `int $userId` → `?User $actor = null` |
| 3 | `audit_phases/Phase16_Profit.php` | 273 | `(int) ($this->ctx->currentUser?->id ?? 0)` → `$this->ctx->currentUser` |
| 4 | `tests/Feature/TourismDivision/MultiCurrencySoftDeleteIntegrityTest.php` | 389 | `$this->user->id ?? 1` → `$this->user` |
| 5 | `tests/Feature/Visa/VisaLedgerReconciliationTest.php` | 158 | `$this->user->id` → `$this->user` |
| 6 | `tests/scripts/visa_full_module_e2e.php` | 693, 703 | Hardcoded `1` → `$actorUser` (User fetched from DB or factory-created) |
| 7 | `tests/Feature/Visa/VisaBookingServiceDeadCodeTest.php` | 164 | `$this->admin->id` → `$this->admin` (caller-passed `int` was the 1 B2-introduced failure caught by the regression check) |

All 7 callers updated atomically (verified by `git diff` after edit).

---

## 4. Reflection / lint / test results

### 4.1 Reflection (signature changed)

```php
$ php -r 'require "vendor/autoload.php";
$r = new ReflectionMethod("App\\Services\\Visa\\VisaRefundService", "deleteWithReversal");
foreach ($r->getParameters() as $p) {
    echo $p->getName() . " type=" . ($p->getType() ?? "mixed") . " default=" . ($p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : "NONE") . PHP_EOL;
}'
bookingId type=int default=NONE
actor type=?App\Models\User default=NULL
```

### 4.2 PHP syntax check (all 8 modified files)

```
app/Services/Visa/VisaRefundService.php: No syntax errors detected
app/Services/Visa/VisaBookingService.php: No syntax errors detected
app/Http/Controllers/Api/V1/Visa/VisaBookingController.php: No syntax errors detected
tests/Feature/Visa/VisaBookingServiceDeadCodeTest.php: No syntax errors detected
tests/Feature/Visa/VisaLedgerReconciliationTest.php: No syntax errors detected
tests/Feature/TourismDivision/MultiCurrencySoftDeleteIntegrityTest.php: No syntax errors detected
audit_phases/Phase16_Profit.php: No syntax errors detected
tests/scripts/visa_full_module_e2e.php: No syntax errors detected
```

### 4.3 Test results

#### Before B2 + dead-code fix (baseline at 4f95198 + B2 service change, dead-code test still passing `int`)
```
Tests:    17 failed, 328 passed (1118 assertions)
Duration: 81.42s
```

#### After B2 + dead-code test fix
```
Tests:    16 failed, 329 passed (1120 assertions)
```

**Delta:** -1 fail, +1 pass. The 1 newly-passing test is `VisaBookingServiceDeadCodeTest::test_delete_booking_with_reversal_shim_delegates`.

#### 16 remaining failures — confirmed pre-existing (NOT B2-introduced)

| # | Test | Failure type | References `deleteWithReversal`? |
|---|------|--------------|----------------------------------|
| 1 | `AuthorizationGatesTest::employee_can_view_visa_bookings` | permission check | No |
| 2 | `AuthorizationGatesTest::employee_can_view_visa_treasury_overview` | permission check | No |
| 3 | `EmployeeIDORTest::visa_booking_visible_across_employees` | IDOR check | No |
| 4 | `EmployeeIDORTest::visa_employee_b_can_refund_employee_a_booking` | IDOR + refund | No |
| 5 | `EmployeeVisaE2ETest::employee_can_list_bookings` | CRUD route perm | No |
| 6 | `EmployeeVisaE2ETest::employee_can_show_booking` | CRUD route perm | No |
| 7 | `EmployeeVisaE2ETest::employee_can_update_booking` | CRUD route perm | No |
| 8 | `EmployeeVisaE2ETest::employee_can_view_treasury_overview` | route perm | No |
| 9 | `VisaApiContractTest::refund_returns_200` | refund API status | No |
| 10 | `VisaBookingControllerTest::refund_flips_status_to_refunded` | refund status flow | No |
| 11 | `VisaEdgeCasesTest::zero_egp_booking_rejected` | validation: 0 EGP | No |
| 12 | `VisaIdempotencyTest::double_refund_does_not_double_reversal` | refund idempotency | No |
| 13 | `VisaPermissionTest::admin_can_refund_booking` | refund perm | No |
| 14 | `VisaPermissionTest::employee_cannot_refund_booking` | refund perm | No |
| 15 | `VisaStatusTransitionTest::refund_changes_status_via_dedicated_endpoint` | refund status | No |
| 16 | `VisaValidationTest::zero_purchase_price_returns_422` | validation | No |

Verified by:
- `grep -n "deleteWithReversal\|deleteBookingWithReversal"` across the 8 failing test files → 0 matches in 7 of them, 1 match in `VisaBookingServiceDeadCodeTest` (which now passes after fix).
- `git stash --include-untracked` + run on baseline `4f95198` → same 16 failures present (only the TypeError was B2-introduced, and that's now fixed).

### 4.4 Explicit Visa deletion test (`VisaLedgerReconciliationTest`)

```
php artisan test --filter "VisaLedgerReconciliationTest"
Tests: 10 passed (40 assertions)
```

All 10 deletion-with-reversal scenarios (full balance zero, partial refund + delete, currency variants, idempotency, etc.) pass.

### 4.5 `MultiCurrencySoftDeleteIntegrityTest` — pre-existing baseline failure

```
php artisan test --filter "MultiCurrencySoftDeleteIntegrityTest"
Tests: 1 failed (7 assertions)
```

Failure: USD/EGP conversion assertion at line 217 (Flight booking, not Visa — fails BEFORE reaching my line-389 Visa call). Confirmed pre-existing by stashing B2 changes and re-running on baseline `4f95198` → identical failure. Out of scope for B2.

---

## 5. Process notes

### 5.1 Caller list verified BEFORE edit

Per Golden Rule "اعرضلي القائمة كاملة قبل التعديل، مش بعده" — caller list was enumerated via grep before any edit:

```
grep -rn "deleteWithReversal\|deleteBookingWithReversal" app/ tests/ audit_phases/
```

All 7 callers identified and updated.

### 5.2 Service file syntax-checked BEFORE callers updated

Per Golden Rule "بعد أي تعديل في ملف الـ service نفسه (قبل ما تلمس أي caller)، شغّل حتى test واحد بسيط بينادي الميثود ده مباشرة":

- Reflection confirmed signature `?User $actor = null` is in place.
- `php -l` confirmed no syntax errors.
- The dead-code test (`test_delete_booking_with_reversal_shim_delegates`) was the chosen "simple test calling the method directly" — it surfaced the TypeError immediately when run with the old int signature, before bulk caller updates.

### 5.3 Edit verification

Per Golden Rule "اعمل فورًا view/cat على نفس الملف وتأكد إن التعديل فعلاً ظاهر" — every edit was verified by `git diff` immediately after the Edit call, then confirmed by `php -l` syntax check across all 8 modified files.

### 5.4 One-screenshot-at-a-time protocol

- 17 fail / 328 pass on first run → STOP (per user rule)
- Investigated each failure individually
- Identified 1 B2-introduced (TypeError in dead-code test) + 16 pre-existing
- Fixed the 1 (single-line test fix)
- Re-ran → 16 fail / 329 pass (1 newly passing = dead-code test, no new failures)
- Confirmed baseline (16 fails) via stash + rerun
- Wrote report

---

## 6. Out of scope (explicit)

- The 16 pre-existing failures are NOT in scope for B2 — they predate B1 baseline `4f95198` and are unrelated to actor enforcement on deletion. They are listed above for traceability only.
- `MultiCurrencySoftDeleteIntegrityTest` failure (USD/EGP conversion) is a Flight-module accounting issue, out of scope for Visa B2.
- MEDIUM-risk (15 patterns) and LOW-risk (28 patterns) from Phase 8.6 inventory: untouched.
- B3 (`FlightBookingService::deleteBookingWithReversal`), B4 (`VisaBookingService::created_by` nullability), B5/B6 (recharge services): pending separate user decision.

---

## 7. Acceptance

- ✅ Service signature changed to `?User $actor = null` with `RuntimeException` enforcement.
- ✅ Dead `Auth::id() ?: 1` fallback removed (line 321 → `$userIdEffective = $userId;`).
- ✅ All 7 callers updated and verified.
- ✅ All 8 modified files pass `php -l`.
- ✅ Reflection confirms new signature.
- ✅ Visa test suite: 16 fail / 329 pass (matches baseline +0 regressions).
- ✅ `VisaLedgerReconciliationTest` (the explicit deletion-with-reversal test): 10/10 pass.
- ✅ Diff clean, ready for commit.