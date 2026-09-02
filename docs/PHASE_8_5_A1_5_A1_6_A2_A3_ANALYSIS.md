# Phase 8.5 — A1.5/A1.6 Test Fix + A2/A3 Proactive Analysis Report

**Date:** 2026-08-19
**Branch:** `phase-8.5-8.6-route-gates-and-actor-strict`
**Status:** ⏳ **AWAITING USER APPROVAL PER RULE #2 (no test edits without review)**

---

## 1. Context

Per user's directive, `docs/PHASE_8_5_STOP_REPORT.md` triggered STOP at A1.5/A1.6 due to `test_employee_can_create_program` asserting `201` for a non-admin employee while the spec mandates admin-gate.

User has confirmed: **the admin gate is the correct business rule** (Filament admin panel is admin-only by design). The test was locked-in incorrect behavior.

This report:
1. Shows the failing test in full context (Step 1).
2. Confirms a parallel admin-can-create-program test exists (Step 2).
3. Audits all assertions in `EmployeeHajjUmraE2ETest` for similar conflicts (Step 3).
4. Proactively analyzes A2 / A3 — which tests would break when payments are gated (Step 4).
5. Proposes the precise test edit (Step 5) — **NOT applied yet**.

---

## 2. Step 1 — Original test (verbatim, no edits)

**File:** `tests/Feature/TourismEmployeeE2E/EmployeeHajjUmraE2ETest.php`
**Lines:** 68-90

```php
public function test_employee_can_create_program(): void
{
    $this->actAs($this->normalEmployee);
    $payload = [
        'program_name' => 'EMP_AUDIT_20260817_Hajj_Program',
        'program_type' => 'hajj',
        'total_nights' => 14,
        'airline' => 'Saudi Airlines',
        'departure_point' => 'Cairo',
        'executing_company' => 'EMP_AUDIT_20260817_Executing',
        'mecca_hotel_name' => 'EMP_AUDIT_20260817_Mekka_Hotel',
        'mecca_nights' => 7,
        'medina_hotel_name' => 'EMP_AUDIT_20260817_Medina_Hotel',
        'medina_nights' => 7,
        'departure_date' => now()->addDays(30)->toDateString(),
        'return_date' => now()->addDays(45)->toDateString(),
        'default_purchase_price' => 25000.0,
        'default_selling_price' => 30000.0,
        'is_active' => true,
    ];
    $response = $this->postJson('/api/v1/hajj-umra/programs', $payload);
    $response->assertStatus(201);   // ← asserts the OLD (open) behavior
}
```

**Current failure (post A1.5 gate):**
```
Expected response status code [201] but received 403.
```

---

## 3. Step 2 — Parallel admin-can-create-program test

**Finding:** ✅ **EXISTS** — confirmed admin happy-path is already in place.

**Test:** `tests/Feature/HajjUmra\HajjUmraApiTest.php::test_store_program_creates_new_record` (admin user, asserts 201).

Also `HajjUmraProgramControllerTest::test_store_program_creates_new_record` is an admin test (currently has a **pre-existing** validation failure unrelated to the gate — `program_type: 'UMRA'` is rejected by validation, separate issue).

**Additional findings from HajjUmraApiTest setUp (L31-44):**
```php
$this->user = User::query()->create([
    'name' => 'Hajj Umrah API Tester',
    'email' => 'hajj-umrah-api@example.com',
    'password' => Hash::make('password'),
    'role' => 'admin',         // ← admin
    'is_active' => true,
]);
Sanctum::actingAs($this->user, ['*']);
```

**Verdict:** No need to add a new admin-can-create-program test. It already exists (in `HajjUmraApiTest`). The new HajjUmraProgramControllerTest equivalent is admin-based but currently has a pre-existing validation bug.

---

## 4. Step 3 — Audit of `EmployeeHajjUmraE2ETest` for similar conflicts

Full assertion map (L33-260):

| Line | Test | Endpoint | Method | Actor | Expected | Compatible with A2/A3? |
|------|------|----------|--------|-------|----------|------------------------|
| 35 | `test_employee_can_list_bookings` | GET /bookings | list | normalEmployee | 200 | ✅ no gate |
| 47 | `test_employee_can_show_booking` | GET /bookings/{id} | show | normalEmployee | 200 | ✅ no gate |
| 59 | `test_employee_can_list_programs` | GET /programs | list | normalEmployee | 200 | ✅ no gate |
| 68 | **`test_employee_can_create_program`** | **POST /programs** | **store** | **normalEmployee** | **201** | **⛔ A1.5 conflict (the one)** |
| 96 | `test_employee_can_create_booking` | POST /bookings | store | normalEmployee | 201 | ✅ no gate |
| 111 | `test_restricted_employee_can_create_booking` | POST /bookings | store | restrictedEmployee | 201 | ✅ no gate |
| 121 | `test_employee_can_update_booking` | PUT /bookings/{id} | update | normalEmployee | 200 | ❗ NO-EDIT-CONTRACT — pre-existing failure |
| 138 | `test_employee_can_record_payment` | POST /bookings/{id}/payments | addPayment | normalEmployee | 201 | ❓ A2 risk — see §5 |
| 162 | `test_employee_cannot_cancel_booking` | POST /bookings/{id}/cancel | cancel | normalEmployee | 403 | ✅ already admin-only |
| 175 | `test_restricted_employee_cannot_refund_booking_without_manage_refunds` | POST /bookings/{id}/refund | refund | restrictedEmployee | 403 | ✅ already managed_refunds |
| 191 | `test_employee_cannot_delete_booking` | DELETE /bookings/{id} | destroy | normalEmployee | 403 | ✅ already admin-only |
| 202 | `test_employee_cannot_delete_program` | DELETE /programs/{id} | destroy | normalEmployee | 403 | ✅ already admin-only |
| 211 | `test_employee_cannot_withdraw_from_executing_company` | POST /executing-companies/{id}/withdraw | withdraw | normalEmployee | 403 | ✅ already admin-only |
| 223 | `test_employee_cannot_repay_executing_company` | POST /executing-companies/{id}/repay | repay | normalEmployee | 403 | ✅ already admin-only |
| 239 | `test_admin_can_cancel_booking` | POST /bookings/{id}/cancel | cancel | admin | 200/201 | ✅ control |
| 255 | `test_employee_can_view_treasury_overview` | GET /treasury/overview | overview | normalEmployee | 200 | ✅ no gate |

**Only 1 conflict** with the A1.5/A1.6 admin gate: line 68 (`test_employee_can_create_program`).

**No financial/admin conflicts** found elsewhere in this file beyond `test_employee_can_update_booking` (L121, pre-existing NO-EDIT-CONTRACT, not Phase 8.5 scope).

---

## 5. Step 4 — Proactive A2/A3 test impact analysis

### 5.1 A2 — `POST /api/v1/hajj-umra/bookings/{hajjUmra}/payments` with `permission:manage_hajj`

The `CheckPermission` middleware (`app/Http/Middleware/CheckPermission.php`) uses `UserPermissions::effectiveFor()`:

```php
// Admins and owners always have full access
if (in_array($user->role, ['admin', 'owner'], true)) {
    return $next($request);                      // admin/owner → bypass
}

// For all other roles (employee, cashier, etc.):
$effective = UserPermissions::effectiveFor($user);
if (! in_array($requiredPermission, $effective, true)) {
    return $this->forbid();                      // 403 if not in effective
}
```

`UserPermissions::effectiveFor()` returns:
- admin/owner → all 10 permissions
- employee with stored `permissions` → stored list
- employee with empty `permissions` → `defaultEmployeeModules()` = **[manage_flights, manage_bus, manage_hajj, manage_online, manage_treasury, manage_refunds]**
- cashier (not in admin/owner list, empty `permissions`) → **same defaultEmployeeModules()** (includes manage_hajj and manage_online)

**Tests that hit POST /api/v1/hajj-umra/bookings/{id}/payments:**

| Test | Actor | effectiveFor() | After A2 gate |
|------|-------|----------------|---------------|
| `EmployeeHajjUmraE2ETest::test_employee_can_record_payment` (L138) | normalEmployee (defaultEmployeeModules) | includes manage_hajj | ✅ **STILL PASSES** |
| `HajjUmraFullModuleE2ETest` (L489) | cashier (no perms → defaultEmployeeModules) | includes manage_hajj | ✅ **STILL PASSES** |
| `HajjUmraAddPaymentRegressionTest` | admin | bypass | ✅ **STILL PASSES** |
| `HajjUmraPaymentIdempotencyTest` | admin | bypass | ✅ **STILL PASSES** |
| `HajjUmraBookingLifecycleCancelTest` | admin | bypass | ✅ **STILL PASSES** |
| `HajjUmraProductionE2ETest` | admin | bypass | ✅ **STILL PASSES** |
| `HajjUmraControllerTest` | admin | bypass | ✅ **STILL PASSES** |
| `BusinessActionsTest::test_hajj_payment_flow` | admin | bypass | ✅ **STILL PASSES** |
| ❗ **POSSIBLE NEW FAILURE:** restrictedEmployee attempting payment | restrictedEmployee (only manage_flights) | does NOT include manage_hajj | ⛔ **NEW 403** — but no such test exists, so no breakage |

**Net A2 conclusion:** ✅ **SAFE TO APPLY** — no existing test will break. The only new behavior is that `restrictedEmployee` (only `manage_flights`) would now get 403 on hajj-umra payments, which is consistent with the existing `test_restricted_employee_cannot_refund_booking_without_manage_refunds` pattern.

### 5.2 A3 — `POST /api/v1/visa/bookings/{visa}/payments` with `permission:manage_online`

**Tests that hit POST /api/v1/visa/bookings/{id}/payments:**

| Test | Actor | effectiveFor() | After A3 gate |
|------|-------|----------------|---------------|
| `EmployeeVisaE2ETest::test_employee_can_record_payment` (L97) | normalEmployee | includes manage_online | ✅ **STILL PASSES** |
| `VisaPermissionTest::test_employee_can_add_payment` (L172) | employeeUser (no perms → defaultEmployeeModules) | includes manage_online | ✅ **STILL PASSES** |
| `VisaPermissionTest`'s comment at L183 says "Payment is NOT marked admin-only in routes" | — | — | ⚠️ **STALE comment** — becomes misleading, but test still passes |
| `VisaBookingControllerTest` (admin) | admin | bypass | ✅ **STILL PASSES** |
| `VisaApiContractTest` (admin) | admin | bypass | ✅ **STILL PASSES** |
| `VisaIdempotencyTest` (admin) | admin | bypass | ✅ **STILL PASSES** |
| `VisaValidationTest` (admin) | admin | bypass | ✅ **STILL PASSES** |
| `VisaEdgeCasesTest` (admin) | admin | bypass | ✅ **STILL PASSES** |
| `VisaProductionE2ETest` (admin) | admin | bypass | ✅ **STILL PASSES** |
| `BusinessActionsTest::test_visa_payment_flow` (admin) | admin | bypass | ✅ **STILL PASSES** |
| ❗ **POSSIBLE NEW FAILURE:** restrictedEmployee attempting payment | restrictedEmployee (only manage_flights) | does NOT include manage_online | ⛔ **NEW 403** — but no such test exists, so no breakage |

**Net A3 conclusion:** ✅ **SAFE TO APPLY** — no existing test will break. `VisaPermissionTest` comment at L183 becomes stale (still passes, but the comment needs updating — flagging for user awareness).

---

## 6. Step 5 — Proposed test edit (PENDING APPROVAL, NOT APPLIED)

### 6.1 Original test (lines 68-90, repeated for clarity)

```php
public function test_employee_can_create_program(): void
{
    $this->actAs($this->normalEmployee);
    $payload = [ /* ...unchanged... */ ];
    $response = $this->postJson('/api/v1/hajj-umra/programs', $payload);
    $response->assertStatus(201);
}
```

### 6.2 Proposed replacement (test name flips, assertion flipped, actor flipped)

```php
public function test_employee_cannot_create_program(): void
{
    // Note: Hajj-Umra programs are managed via the Filament admin panel which
    // is admin-only by business rule. Phase 8.5 A1.5 gates POST /programs
    // with `middleware('admin')` → non-admin employees must get 403.
    $this->actAs($this->normalEmployee);
    $payload = [
        'program_name' => 'EMP_AUDIT_20260817_Hajj_Program',
        'program_type' => 'hajj',
        'total_nights' => 14,
        'airline' => 'Saudi Airlines',
        'departure_point' => 'Cairo',
        'executing_company' => 'EMP_AUDIT_20260817_Executing',
        'mecca_hotel_name' => 'EMP_AUDIT_20260817_Mekka_Hotel',
        'mecca_nights' => 7,
        'medina_hotel_name' => 'EMP_AUDIT_20260817_Medina_Hotel',
        'medina_nights' => 7,
        'departure_date' => now()->addDays(30)->toDateString(),
        'return_date' => now()->addDays(45)->toDateString(),
        'default_purchase_price' => 25000.0,
        'default_selling_price' => 30000.0,
        'is_active' => true,
    ];
    $response = $this->postJson('/api/v1/hajj-umra/programs', $payload);
    $response->assertStatus(403, 'Employee must NOT be able to create a Hajj program (Filament admin panel is admin-only)');
}
```

### 6.3 Proposed commit message (per user directive)

```
Fix test: employee should NOT have Filament admin panel access — hajj-umra programs are admin-only per business rule (see A1.5/A1.6 decision)
```

### 6.4 What this does NOT change

- **No new test added** — admin-can-create-program already exists in `HajjUmraApiTest`.
- **No other Employee* test touched** — full audit in §4 shows no other conflicts.
- **No route file touched** — only the test file.
- **No A2/A3 route change made yet** — separate user approval required.

---

## 7. Step 6 — Verification plan (only after §6 approval)

1. Apply the edit in §6.2.
2. Run the entire HajjUmra module test suite.
3. Report before/after pass counts.
4. **STOP** — do not proceed to A2/A3 without explicit OK.

---

## 8. Awaiting user decision

Three explicit approvals requested:

1. **Approve the test edit in §6.2** (rename + flip assertion + add comment)? If yes, I will:
   - Apply §6.2 to `EmployeeHajjUmraE2ETest.php`
   - Commit with message in §6.3
   - Run HajjUmra test suite
   - Report before/after counts
   - **STOP** awaiting OK for A2/A3

2. **Approve A2** (gate POST /api/v1/hajj-umra/bookings/{hajjUmra}/payments with `permission:manage_hajj`)? Per §5.1, no existing tests break.

3. **Approve A3** (gate POST /api/v1/visa/bookings/{visa}/payments with `permission:manage_online`)? Per §5.2, no existing tests break, but `VisaPermissionTest` L183 comment "Payment is NOT marked admin-only in routes" becomes stale and should be updated to reflect the new state.

**Forbidden until explicit approval:**
- ❌ Modifying `EmployeeHajjUmraE2ETest.php`
- ❌ Applying A2 / A3
- ❌ Updating `VisaPermissionTest` stale comment
- ❌ Starting B1–B6
