# Phase 8.5 STOP Report — Hajj program admin gate conflict

**Date:** 2026-08-19
**Branch:** `phase-8.5-8.6-route-gates-and-actor-strict`
**Status:** ⏸️ **STOPPED** per Rule #3 ("ماتحاولش تصلح الـ test أو تلف حواليه")

---

## 1. Summary

I applied user-spec Phase 8.5 A1.5 + A1.6 (gate `POST /api/v1/hajj-umra/programs` and `PUT/PATCH /api/v1/hajj-umra/programs/{program}` with `middleware('admin')`). The change is **committed** (commit `8c8ac00`).

A previously-passing test now fails because it asserts the OLD behavior (employees can create programs). The user explicitly required this gate, so the test failure is **expected** — but per the strict rule I must STOP and report, not modify the test, not work around it, and not continue to A2/A3/B1–B6.

---

## 2. Commits applied so far (on this branch)

| # | Hash | Message |
|---|------|---------|
| 1 | `6c50eb2` | Phase 8.5 #1: gate pay-debt with admin (routes/api.php:218) |
| 2 | `78a7a6a` | Phase 8.5 #2: gate airline-accounts mutations with admin (routes/api.php:239-247) |
| 3 | `f1401af` | Phase 8.5 #3/#4: gate flight/systems+carriers mutations with admin (routes/api.php:206-220) |
| 4 | `8c8ac00` | Phase 8.5 #5/#6: gate hajj-umra/programs POST+PUT/PATCH with admin (routes/api.php:547-555) |

---

## 3. Test results — boundary per edit

| Edit | Module | Baseline (at `6e2e257`) | After edit | Δ | Verdict |
|------|--------|-------------------------|------------|---|---------|
| A1.1 (pay-debt) | Flight | 54 fail / 244 pass | 54 fail / 244 pass | 0 | ✅ pass-through |
| A1.2 (airline-accounts) | Flight | 54 fail / 244 pass | 54 fail / 244 pass | 0 | ✅ pass-through |
| A1.3+A1.4 (systems+carriers) | Flight | 54 fail / 244 pass | 54 fail / 244 pass | 0 | ✅ pass-through (after restoring apiResource that I accidentally removed — see §4) |
| A1.5+A1.6 (hajj programs) | HajjUmra | 9 fail / 365 pass | 10 fail / 364 pass | **+1 fail** | ⛔ **STOP** |

The single new failure is documented below.

---

## 4. A1.3 / A1.4 — internal note (not a regression, but worth flagging)

My first A1.3 edit accidentally consumed the line `Route::apiResource('carriers', ...)`. After test run showed 3 new failures (`FlightCarrierControllerTest`, `FlightCarrierRechargeServiceTest`, `FlightGroupCarrierIntegrationTest`), I diagnosed and re-added the carriers routes inside the same edit. After the fix, test count returned to baseline (54 fail / 244 pass). The final commit (`f1401af`) carries both A1.3 (systems) and A1.4 (carriers) together. No net regression.

---

## 5. A1.5 / A1.6 — the STOP trigger

### 5.1 The new failure (1)

```
Tests\Feature\TourismEmployeeE2E\EmployeeHajjUmraE2ETest > employee can create program
Expected response status code [201] but received 403.
Failed asserting that 403 is identical to 201.

at tests/Feature/TourismEmployeeE2E/EmployeeHajjUmraE2ETest.php:89
  $response = $this->postJson('/api/v1/hajj-umra/programs', $payload);
  $response->assertStatus(201);
```

### 5.2 The test code (verbatim, for clarity)

```php
public function test_employee_can_create_program(): void
{
    $this->actAs($this->normalEmployee);
    $payload = [
        'program_name' => 'EMP_AUDIT_20260817_Hajj_Program',
        'program_type' => 'hajj',
        // ... full payload
    ];
    $response = $this->postJson('/api/v1/hajj-umra/programs', $payload);
    $response->assertStatus(201);   // <-- asserts the OLD (open) behavior
}
```

The test name **"employee can create program"** is also the assertion: it explicitly wants employee to be able to create programs. After my gate, the route returns 403 for employees — the test will only pass if I assign `$this->admin` (or switch the assertion to 403).

### 5.3 Why this is expected (not a bug in my edit)

The user's spec says (A1.5):

> ✅ "POST /api/v1/hajj-umra/programs — routes/api.php:548" must be gated with `middleware('admin')`.

The test asserts the OPPOSITE behavior. **This is exactly the conflict the user warned about** in Rule #3: "لو أي test فشل، وقف فورًا واكتب تقرير بالسبب".

---

## 6. Pre-existing failures (NOT caused by my edits, NOT in scope)

These 9 HajjUmra failures exist at parent commit `6e2e257` and are unchanged by my edits. Documented for completeness only — they belong to Phase 8 (continued) and Phase 8.6, not Phase 8.5:

1. `HajjUmra\HajjUmraBookingLifecycleCancelTest > 4 5 cancel refunded booking…` (RuntimeException)
2. `HajjUmra\HajjUmraBookingLifecycleCancelTest > 4 5 add payment on refunded…` (RuntimeException)
3. `HajjUmra\HajjUmraBookingLifecycleCancelTest > 4 6 destroy refunded bookin…` (RuntimeException)
4. `HajjUmra\HajjUmraControllerTest > refund flips status to refunded`
5. `HajjUmra\HajjUmraProductionE2ETest > 24 refund zero amount booking is safe`
6. `HajjUmra\HajjUmraProgramControllerTest > store program creates new record` *(pre-existing)*
7. `HajjUmra\HajjUmraProgramControllerTest > update program modifies record` *(pre-existing)*
8. `TourismDivision\HajjUmraProductionTest > refund after cancel throws`
9. `TourismEmployeeE2E\EmployeeHajjUmraE2ETest > employee can update booking`

**Note on #6 and #7:** These were already failing at baseline. They appear to have the same root cause as the new failure (# at §5.1) — the `HajjUmraProgramControllerTest` tests likely use a non-admin actor. Out of Phase 8.5 scope, but worth flagging since the same program-store/update route is now being gated.

---

## 7. What I did NOT do (Rule #2 + #3)

- ❌ Did NOT modify `HajjUmraProgramControllerTest` (out of scope; tests not in permitted list).
- ❌ Did NOT modify `EmployeeHajjUmraE2ETest` (same reason).
- ❌ Did NOT continue to A2 (HajjUmra payments gate).
- ❌ Did NOT continue to A3 (Visa payments gate).
- ❌ Did NOT start B1–B6 (service-layer actor enforcement).
- ❌ Did NOT touch `flight/modifications` (A4 forbidden).
- ❌ Did NOT touch any MEDIUM/LOW-risk patterns (out of scope).
- ❌ Did NOT touch any Phase 8 original items.

---

## 8. Awaiting user direction

The user's spec is internally consistent: gate the program endpoints with admin, AND the test asserts employees can create programs. These are mutually exclusive. Resolution options:

1. **Update the test** to switch to admin actor (or assert 403 for employee) — this is the obvious fix; the user can decide if a separate "negative test" is needed.
2. **Revoke the gate** — admin-gate was wrong; programs should remain open to employees.
3. **Partial admin** — keep gate but expose programs via a different route for employees.

I am **not** taking any of these actions. Awaiting instruction.
