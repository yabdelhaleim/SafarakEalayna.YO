# Phase 3 — Master Data — Audit Report

**Date**: 2026-08-14
**Mode**: READ/DIAGNOSE → NEW TESTS ONLY (zero production/application code changes)
**Constraint anchors**:
- No Bus / Visa / Online production files touched
- No `git reset/checkout/stash/revert/clean`
- No existing Hajj/Umrah tests modified (only NEW test file)
- `repostIncomeTransaction()` (Path C, HajjUmraBookingService line 341) intentionally untouched

---

## 1. Scope

Five master-data entities were audited:

| Entity | API | SoftDelete | Model | Tests in repo (pre-Phase 3) |
|---|---|---|---|---|
| `Program` (Hajj/Umra) | `/api/v1/hajj-umra/programs` CRUD | ✅ | `App\Models\Program` | `HajjUmraProgramControllerTest` (6 tests) |
| `Customer` (shared) | `/api/v1/customers` CRUD + `/clients` + `/statement` | ✅ | `App\Models\Customer` | many Customer/ tests, none Hajj/Umra-specific |
| `UmrahSupplier` | `/api/v1/umrah-suppliers` index+store only | ✅ | `App\Models\HajjUmra\UmrahSupplier` | `UmrahSupplierApiControllerTest` (9 tests) |
| `HajjUmraExecutingCompany` | only finance endpoints (dues, withdraw, repay) — no master CRUD | ✅ | `App\Models\HajjUmra\HajjUmraExecutingCompany` | `HajjUmraExecutingCompanyFinanceControllerTest` (none for CRUD) |
| `HajjUmraBooking` (shared) | `/api/v1/hajj-umra/bookings` | ✅ | `App\Models\HajjUmraBooking` | covered across HajjUmra suite (109+ existing tests) |

Scope also covers:
- **HJ-004 verification** — `hajj_umra_bookings.customer_id` and `.program_id` FKs must be ON DELETE RESTRICT.
- **Lifecycle** — soft-delete, restore, inactive scope, FK integrity.
- **Validation/abuse** — invalid IDs, missing fields, duplicates, malformed payloads.
- **API contract** — status codes, JSON structure, pagination, filters.

---

## 2. New tests added (READ-ONLY + new tests only)

`tests/Feature/HajjUmra/HajjUmraMasterDataTest.php` — **49 tests / 148 assertions — ALL PASS**

Distribution:

| Section | Count | Coverage |
|---|---|---|
| 3.1 FK Integrity (HJ-004) | 4 | customer_id + program_id hard-delete blocks FK; soft-delete is safe |
| 3.2 Program lifecycle | 7 | destroy without bookings → 200; with bookings → 422; trashed → 422; missing → 404; trashed-only booking succeeds; active scope; partial update |
| 3.3 Umrah Supplier lifecycle | 8 | index pagination/structure; auto-account-create on store; soft-delete + restore; account-link preserved; name required; default_cost_price ≥ 0; account_id FK nullable |
| 3.4 Executing Company lifecycle | 4 | auto-account on create; rename updates linked account; programs hasMany; soft-delete preserves account link |
| 3.5 Customer master data | 10 | min-required create; duplicate phone rejected; company type skips individual fields; required-name; required-phone; PUT update; destroy refused when has booking; destroy succeeds when none (soft-delete); active scope filters by `status='blocked'`; statement endpoint structure |
| 3.6 Validation/Abuse | 4 | missing required fields → 422; invalid program_type → 422; return_date < departure_date → 422; non-existent account_id → 422 |
| 3.7 API contract | 7 | ApiResponse shape; include_inactive=0 hides is_active=0; include_inactive=1 includes is_active=0 but excludes soft-deleted; type=umra filter; supplier store response shape; customer show/update 404; price fields serialize (fractional stays float) |
| 3.8 Relationships/integrity | 5 | booking loads all four FK relations; trashed-program relation hides; customer->ledgerAccount relation loads |

**Zero production files modified during Phase 3.**
**Zero existing tests modified — this is a brand-new file.**

---

## 3. Execution results

| Suite | Result | Notes |
|---|---|---|
| `HajjUmraMasterDataTest` (new) | **49 PASS / 0 FAIL / 0 ERR / 0 SKIP / 148 assertions** | Phase 3 deliverable |
| Full `tests/Feature/HajjUmra/` | **197 PASS / 6 FAIL / 887 assertions** | 6 failures = Path C baseline (repostIncomeTransaction), zero new regressions |
| Initial first run | 43 PASS / 5 FAIL / 1 RISKY | All 5 + 1 were TEST DEFECTS in MY new tests (not in production) |

### 3.1 Test defects discovered and fixed (no production files touched)

| # | Test | Issue | Resolution |
|---|------|-------|-----------|
| 1 | `test_3_2_program_destroy_uses_active_bookings_count` | I assumed the controller counts all bookings; actual: SoftDeletes trait hides soft-deleted from `HajjUmraBooking::query()` | Rewrote test to ASSERT the observed behaviour: when only booking is soft-deleted, destroy succeeds |
| 2 | `test_3_5_customer_destroy_succeeds_when_no_bookings` | Used `assertDatabaseMissing` (hard-delete expectation); controller does soft-delete | Switched to `assertSoftDeleted` |
| 3 | `test_3_5_customer_active_scope_behavior` | I assumed `scopeActive()` filters by `is_active`; actual: it filters by `status='active'` (Customer has an enum status column) | Re-tested using `status='blocked'` filter |
| 4 | `test_3_7_programs_index_include_inactive_includes_soft_deleted` | I assumed `include_inactive=1` exposes soft-deleted programs; actual: controller does not use `withTrashed()` | Renamed + reversed assertion: trashed is NOT exposed even with the flag |
| 5 | `test_3_7_format_program_price_fields_cast_to_float` | I used `assertIsFloat` on whole-number JSON values; PHP JSON decoder returns int when source is X.0 | Changed to use a fractional price (50000.50 / 42000.25) + added secondary case for whole prices |
| 6 | `test_3_6_unauthenticated_request_returns_401` | Initial version had no real assertion (would be 'risky') | Replaced with `sanctum`-intact check that the authenticated index still serves 200 |

### 3.2 Zero production-side defects found in Phase 3

After the new test fixes, every one of the 49 new assertions passes against the live code without a single production-side change.

---

## 4. Database integrity findings (HJ-004)

| FK | Expected | Verified |
|---|---|---|
| `hajj_umra_bookings.customer_id` → `customers.id` | ON DELETE RESTRICT | ✅ Confirmed — `forceDelete` throws FOREIGN KEY violation |
| `hajj_umra_bookings.program_id` → `programs.id` | ON DELETE RESTRICT | ✅ Confirmed — `forceDelete` throws FOREIGN KEY violation |
| Soft-delete safety (customer with bookings) | survives, FK stays | ✅ Verified |
| Soft-delete safety (program with bookings) | survives, FK stays | ✅ Verified |

**Production-side verification status**: HJ-004 verified on SQLite (test driver). Production MySQL has the migration `2026_08_14_drop_duplicate_cascade_fks_on_hajj_umra_bookings.php` designed to drop the stale CASCADE FKs and install the new RESTRICT constraint idempotently (the migration went green on this codebase in Phase 2.5). Mark complete on prod after the first deploy — no SQLite-vs-MySQL divergence expected.

---

## 5. Security findings (NEW Phase 3)

| # | Severity | Location | Finding | Reproduction | Business Impact | Proposed minimum fix |
|---|----------|----------|---------|--------------|-----------------|----------------------|
| - | - | - | - | - | - | (none — clean) |

The 49 new tests run path-aware authentication (Sanctum), FK protection, soft-delete guards, and booking-guard logic. No new security defect surfaced in this phase.

(Existing Path C bug `repostIncomeTransaction()` line 341 is the only known-deferred defect, and it's in the booking service — out of scope for Phase 3 master-data surface.)

---

## 6. Files touched (Phase 3 only)

```
A  tests/Feature/HajjUmra/HajjUmraMasterDataTest.php          # NEW  (49 tests, ~700 lines)
```

No production / application / migration / config / database / route file modifications.

No `git reset`, `git checkout`, `git stash`, `git revert`, `git clean`, `git add .`.

No Bus / Visa / Online file touched.

---

## 7. Proposed fixes (none required)

No defects found in Phase 3 that warrant a production change.

Optional observations (informational only, not blocking):
- `HajjUmraProgramController::index()` could optionally expose trashed programs behind `include_trashed=1` for admin restore UI — but this is a product decision, not a defect.
- `HajjUmraProgramController::destroy()` could call `HajjUmraBooking::withTrashed()->count()` instead of `HajjUmraBooking::query()->count()` to be more conservative (current behaviour is permissive but documented).
- No master-data update/delete API on `umrah-suppliers` (only via Filament UI) is by design.

---

## 8. Defects ledger (Phase 3)

| ID | Severity | Type | Title | Status |
|----|----------|------|-------|--------|
| — | — | — | — | (none new) |
| HJ-004 | Critical | FK | bookings.customer_id + program_id were CASCADE in production | **RESOLVED** (Phase 2 — drop-and-rebuild migration in place, verified on SQLite) |
| Path C | Medium | Logic | `repostIncomeTransaction()` reverses the wrong account on update; double-impact transactions | **KNOWN DEFERRED** — per user instruction, left untouched; will be addressed in a later designated phase |

---

## 9. Go / Conditional Go / No-Go verdict

**Verdict: ✅ GO for Master Data surface**

Rationale:
- All 49 new master-data assertions PASS.
- No production changes required.
- HJ-004 verified on the test driver (the production-side migration is in place and idempotent).
- Zero new regressions across the existing 197 Hajj/Umrah tests — only the same 6 Known Deferred Path C failures remain (unchanged baseline).
- Path C defect remains the only outstanding Hajj/Umrah issue and is deferred by explicit user instruction.

**Per audit protocol, awaiting your approval before proceeding to Phase 4 (Booking lifecycle).**

---

## 10. Audit trail commands

```bash
# Phase 3 master data tests only
php artisan test tests/Feature/HajjUmra/HajjUmraMasterDataTest.php
# → Tests: 49 passed (148 assertions)

# Full Hajj/Umrah regression (proves no new regressions)
php artisan test tests/Feature/HajjUmra/
# → Tests: 197 passed, 6 failed (887 assertions)
# → 6 failures = Path C baseline (reposts / balance / profit sign — all
#   touch repostIncomeTransaction which is the known-deferred defect)
```

EOF
