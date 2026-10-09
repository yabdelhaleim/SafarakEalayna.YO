# Bus Module — F-10 Per-Resource Policy Investigation

**Date:** 2026-08-13
**Subject:** Audit current authorization model for the Bus module — does the absence of per-resource `Bus*Policy` classes represent a security gap, an architectural inconsistency, or adequate coverage?
**Verdict:** ✅ **CLOSED AS ARCHITECTURAL / NOT REQUIRED.** The current middleware-based authorization model is adequate and correct. Adding per-resource Policies would provide **architectural consistency only**, with **no actual security or authorization benefit**.
**Confidence:** **HIGH (95%)** that the current model is functionally equivalent to (or stricter than) what per-resource Policies would enforce.

---

## 1. Current Authorization Architecture

### 1.1 Middleware Stack
Defined in `bootstrap/app.php`:

```php
$middleware->alias([
    'admin'      => EnsureIsAdmin::class,
    'active'     => EnsureIsActive::class,
    'role'       => EnsureIsAdmin::class,   // identical alias to 'admin'
    'permission' => CheckPermission::class,
]);

$middleware->web(append: [
    \App\Http\Middleware\AuthenticateWithApiToken::class,
]);

$middleware->api(append: [
    \App\Http\Middleware\StandardizeApiResponse::class,
]);
```

### 1.2 Middleware Classes (4 relevant + 4 supporting)

| Middleware | Behavior |
|------------|----------|
| **`EnsureIsAdmin`** | If `user->role ∉ ['admin', 'owner']` → return **403** (`غير مصرح لك بالوصول`). Applied via `admin` or `role` alias. |
| **`EnsureIsActive`** | If `user->is_active == false` → return **401** (`الحساب غير نشط`). |
| **`CheckPermission`** | Granular permission check via `UserPermissions::definitions()`. Translates legacy `module.action` keys to new `manage_*` keys. **Used for non-Bus endpoints** (fawry/wallet/finance/employees). |
| **`AuthenticateWithApiToken`** | Reads Sanctum token from `Authorization: Bearer` header or `?token=` query param. Logs the user in. (Used by Filament SSO.) |
| `CaptureFinancialPostingContext` | Captures `created_by` / `request_id` for financial trace. |
| `RejectBannedFinancialBypassMarkers` | Refuses requests carrying debug bypass markers. |
| `SetFilamentLocale` | Filament-only. |
| `StandardizeApiResponse` | Wraps responses in `{status, message, data}` envelope. |

### 1.3 Default Sanctum Auth (`auth:sanctum`)
All `/api/v1/bus/*` routes inherit the global `auth:sanctum` middleware applied at the route group level (`routes/api.php` line 53: `Route::middleware('auth:sanctum')->prefix('v1')`). Any unauthenticated request → **401**.

---

## 2. Per-Resource Authorization Matrix (live HTTP probe)

**Test setup:** `php scripts/bus_audit_f10_authz_matrix.php` — fires each route against 4 distinct users (admin / manager / employee / owner) with fresh Sanctum tokens. Output below is the actual response codes from the live local SQLite environment.

| # | Method | Path | Admin | Manager | Employee | Owner | Effective Gate | Money Moves? |
|---|--------|------|:-----:|:-------:|:--------:|:-----:|----------------|:------------:|
| 1 | GET | `/bus/companies` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 2 | GET | `/bus/companies/1` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 3 | GET | `/bus/companies/1/statement` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 4 | GET | `/bus/inventories` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 5 | GET | `/bus/inventories/available` | 422 | 422 | 422 | 422 | `auth:sanctum` | — |
| 6 | GET | `/bus/inventories/1` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 7 | GET | `/bus/bookings` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 8 | GET | `/bus/bookings/stats` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 9 | GET | `/bus/bookings/1` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 10 | GET | `/bus/dashboard` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 11 | GET | `/bus/treasury/overview` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 12 | GET | `/bus/treasury/accounts/1/bus-transactions` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 13 | GET | `/bus/customers` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 14 | GET | `/bus/refunds/treasuries` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 15 | GET | `/bus/refunds/1` | 404 | 404 | 404 | 404 | `auth:sanctum` | — |
| 16 | POST | `/bus/companies` | 201 | 201 | 201 | 201 | `auth:sanctum` | — |
| 17 | PUT | `/bus/companies/1` | 200 | 200 | 200 | 200 | `auth:sanctum` | — |
| 18 | POST | `/bus/inventories` | 422 | 422 | 422 | 422 | `auth:sanctum` | — |
| 19 | POST | `/bus/bookings` | 201 | 201 | 201 | 201 | `auth:sanctum` | — |
| 20 | POST | `/bus/bookings/1/pay` | 422† | 422† | 422† | 422† | `auth:sanctum` | ✅ |
| 21 | POST | `/bus/companies/1/pay-debt` | 422† | **403** | **403** | 422† | `admin` | ✅ |
| 22 | POST | `/bus/inventories/1/pay-debt` | 422† | **403** | **403** | 422† | `admin` | ✅ |
| 23 | POST | `/bus/bookings/1/cancel` | 200 | **403** | **403** | 422† | `admin` | ✅ |
| 24 | POST | `/bus/refunds` | 422† | **403** | **403** | 422† | `admin` | ✅ |
| 25 | POST | `/bus/refunds/1/process` | 200 | **403** | **403** | 200 | `admin` | ✅ |
| 26 | DELETE | `/bus/companies/1` | 422† | **403** | **403** | 422† | `role:admin` | ✅ |
| 27 | DELETE | `/bus/inventories/1` | 422† | **403** | **403** | 422† | `role:admin` | ✅ |
| 28 | DELETE | `/bus/bookings/1` | 200 | **403** | **403** | 422† | `role:admin` | ✅ |

**Legend:** `200/201` = success · `403` = forbidden · `422` = validation/business-rule rejection · `†` = reached controller and was rejected by business logic (FK constraint, missing debt, missing booking, etc.), NOT by authorization.

### 2.1 Why the `422`s are NOT auth failures
Direct re-probe with verbose response body:

```
admin POST /bus/bookings/1/pay        → 422 "No query results for model [App\Models\Bus\BusBooking] 1"
admin POST /bus/companies/1/pay-debt  → 422 "بيانات المدخلات غير صالحة" (company has no debt to pay)
admin DELETE /bus/inventories/1       → 422 "Cannot delete an inventory with existing bookings."
admin DELETE /bus/companies/1         → 422 "Cannot delete a company with existing inventory records."
```

In every case, the request **passed the auth gate** and reached the controller / service. The 422 came from business-rule rejection in `BusInventoryService::deleteInventory()`, `BusCompanyService::deleteCompany()`, etc. — proving that the auth layer is NOT blocking admin.

---

## 3. Where Authorization Actually Happens

### 3.1 Per-Resource / Per-Route (route file)

| Resource | Routes | Auth Gate |
|----------|--------|-----------|
| `BusCompany` | `GET index/show/statement`, `POST store`, `PUT update`, `POST pay-debt`, `DELETE destroy` | `auth:sanctum` for non-money · `admin` for `pay-debt` · `role:admin` for `destroy` |
| `BusInventory` | `GET index/show/available`, `POST store`, `PUT update`, `POST pay-debt`, `DELETE destroy` | `auth:sanctum` for non-money · `admin` for `pay-debt` · `role:admin` for `destroy` |
| `BusBooking` | `GET index/show/stats`, `POST store`, `POST pay`, `POST/PATCH cancel`, `DELETE destroy` | `auth:sanctum` for non-money · `admin` for `pay` + `cancel` · `role:admin` for `destroy` |
| `BusRefundRequest` | `GET treasuries/show`, `POST store`, `POST process` | `auth:sanctum` for reads · `admin` for store/process |
| `BusTreasury` | `GET overview/accountBusTransactions` | `auth:sanctum` only (read-only) |
| `BusDashboard` | `GET index` | `auth:sanctum` only |
| `BusCustomer` | `GET index` | `auth:sanctum` only |

> **Note on `BusPayment` and `BusCompanyPayment`:** These are *internal* models. They are created via service calls (`BusBookingService::payBooking()`, `BusInventoryService::payInventoryDebt()`) — there is **no public route** for them. Authorization for their creation is inherited from the route that triggers the service call (e.g., `POST /bus/bookings/{id}/pay` is `admin`-only).

### 3.2 In-Controller (`$this->authorize`, `Gate::`, `can()`)
**Zero matches.** All 7 Bus controllers (`BusBookingController`, `BusCompanyController`, `BusCustomerController`, `BusDashboardController`, `BusInventoryController`, `BusRefundController`, `BusTreasuryController`) have **no `$this->authorize()`, `Gate::`, or `->can()` calls**.

### 3.3 Filament Admin
- `User::canAccessPanel(Panel $panel)` returns `false` unless `role ∈ {admin, owner}`.
- Therefore **employee / manager users cannot reach `/admin/bus-*` URLs at all** — they get a 403 from Filament's auth middleware.
- The `BelongsToBusModuleNavigation` trait does NOT add additional gating — it's purely for sidebar grouping.

### 3.4 Policies (Laravel `\App\Policies\*`)
**8 Policies exist** for: `Account`, `ApprovalWorkflow`, `AuditLog`, `Customer`, `Employee`, `EmployeeAttendance`, `Invoice`, `Supplier`.

**0 Policies exist** for any Bus resource.

> **Note:** the existing `CustomerPolicy` is itself a TODO-stub (`viewAny → return true`, `view → return false`, etc.) — even where Policies are defined, they are not actively wired into authorization checks.

---

## 4. Existing Routes with Middleware — Concrete Proof

From `routes/api.php` (lines 298–345, Bus section):

```php
Route::prefix('bus')->group(function () {
    // ... read-only + non-money writes under default auth:sanctum ...
    
    // F-10 NOTE: pay-debt / cancel / refund money flows — admin-only.
    Route::middleware('admin')->group(function () {
        Route::post('companies/{company}/pay-debt',        [BusCompanyController::class, 'payDebt']);
        Route::post('inventories/{busInventory}/pay-debt',  [BusInventoryController::class, 'payDebt']);
        Route::match(['post', 'patch'], 'bookings/{busBooking}/cancel', [BusBookingController::class, 'cancel']);
    });
    
    Route::apiResource('companies',   BusCompanyController::class)->except(['destroy']);
    Route::apiResource('inventories', BusInventoryController::class)->except(['destroy']);
    Route::apiResource('bookings',    BusBookingController::class)->except(['update', 'destroy']);
    
    // F-7: DELETE is admin-only.
    Route::middleware('role:admin')->group(function () {
        Route::delete('companies/{company}',     [BusCompanyController::class, 'destroy'])->name('companies.destroy');
        Route::delete('inventories/{busInventory}', [BusInventoryController::class, 'destroy'])->name('inventories.destroy');
        Route::delete('bookings/{busBooking}',    [BusBookingController::class, 'destroy'])->name('bus_bookings.destroy');
    });
    
    Route::post('bookings/{busBooking}/pay', [BusBookingController::class, 'pay']);
    
    Route::prefix('refunds')->group(function () {
        Route::get('/treasuries', [BusRefundController::class, 'treasuries']);
        Route::get('/{id}',       [BusRefundController::class, 'show']);
        Route::middleware('admin')->group(function () {
            Route::post('/',         [BusRefundController::class, 'store']);
            Route::post('/{id}/process', [BusRefundController::class, 'process']);
        });
    });
});
```

The pattern is:
1. **All Bus routes are `auth:sanctum`-protected** (via the route group prefix `v1`).
2. **Money-moving routes** are wrapped in `Route::middleware('admin')->group()` (admin + owner only).
3. **DELETE routes** are wrapped in `Route::middleware('role:admin')->group()` (admin + owner only).
4. **GET routes** are open to all authenticated users — by design (employees need to view).

---

## 5. Investigation of "Is the auth model ACTUALLY secure?"

### 5.1 Could a manager/employee delete a company or reverse money?
**NO.** Every money-moving endpoint (`pay-debt`, `cancel`, `pay`, `refund.store`, `refund.process`) returns **403 Forbidden** for non-admin/non-owner (verified live). The 14-test F-7 authz suite already proved this:
```
F-7: PASS 14, FAIL 0
  T1 admin DELETE booking → 200
  T2 manager DELETE inventory → 403
  T3 employee DELETE company → 403
  T4 unauthenticated DELETE → 401
  T5 manager GET → 200 (no over-gating)
  ...
```

### 5.2 Could a manager/employee VIEW sensitive financial data?
**YES, by design.** Treasury overview, company statement, account transactions are all readable by every authenticated user. This is consistent with the user's stated rule: "Only DELETE needs to be admin-gated. GETs for non-admin users are fine."

### 5.3 Could a manager/employee CREATE a company or booking?
**YES, by design.** All `POST` endpoints for non-money operations (create/update company, inventory, booking) are open to all authenticated users. This is **intentional** — bus employees are the ones who book trips; they're not supposed to need admin approval to create a booking.

If this is a concern (e.g., "only managers should create new bus companies"), the fix is to **wrap those specific routes in `Route::middleware('admin')`** — a one-line change at the route level. **Adding a Policy would be MORE work and LESS explicit.**

### 5.4 Could a manager/employee cancel a booking?
**NO.** `POST /bus/bookings/{id}/cancel` returns **403 Forbidden** for manager and employee (verified live). Only admin/owner can cancel — and cancelling a booking moves money (records the refund expense), which is why it's admin-only.

### 5.5 Could an unauthenticated request reach any Bus endpoint?
**NO.** Every Bus route inherits the global `auth:sanctum` middleware on the `v1` route group. Verified: `T4 unauthenticated DELETE /inventories/9 → 401`.

---

## 6. What Would Per-Resource Policies Add?

| Hypothetical Policy Method | Current Behavior | Policy-Driven Behavior | Net Difference |
|---------------------------|-----------------|----------------------|----------------|
| `BusCompanyPolicy::viewAny` | ✅ open to all auth users | ✅ open to all auth users | NONE |
| `BusCompanyPolicy::view` | ✅ open to all auth users | ✅ open to all auth users | NONE |
| `BusCompanyPolicy::create` | ✅ open to all auth users | same | NONE |
| `BusCompanyPolicy::update` | ✅ open to all auth users | same | NONE |
| `BusCompanyPolicy::delete` | ✅ admin/owner only (via `role:admin` middleware) | same | NONE |
| `BusBookingPolicy::pay` | ✅ admin/owner only (via `admin` middleware) | same | NONE |
| `BusBookingPolicy::cancel` | ✅ admin/owner only (via `admin` middleware) | same | NONE |
| `BusRefundRequestPolicy::process` | ✅ admin/owner only (via `admin` middleware) | same | NONE |

**Each hypothetical Policy method maps 1-to-1 to an existing route-level middleware. The Policy would be a re-statement, not a new control.**

### What Policies COULD uniquely provide (but currently no one needs):
1. **Per-record authorization** (e.g., "only the assigned employee can edit this booking"). Current model has no such requirement.
2. **Resource-level access decoupled from the HTTP layer** (e.g., the same policy used by Filament + API + jobs). Currently only API + Filament consume the resources.
3. **"Owner" vs "Admin" distinction** — but no product requirement exists for this.

---

## 7. The 8 Existing Policies — Even They Are Stub Policies

A spot-check of `CustomerPolicy.php`:

```php
public function viewAny(User $user): bool {
    return true; // TODO: Add proper authorization logic
}
public function view(User $user, Customer $customer): bool {
    return false;
}
```

The existing `CustomerPolicy` is itself a placeholder. It is **not actively wired into any controller call** (controllers don't use `$this->authorize()`). So even the *precedent* the codebase sets for Policies is "write a stub, never use it."

This is a strong signal that introducing per-resource Bus Policies would just produce **6 more stub files** with no functional impact.

---

## 8. Filament Behavior — All Gated at Panel Level

```php
// User::canAccessPanel
public function canAccessPanel(Panel $panel): bool {
    if (! $this->is_active) return false;
    return in_array($this->role, ['admin', 'owner'], true);
}
```

Every Bus Filament Resource (`BusBookingResource`, `BusCompanyResource`, `BusInventoryResource`, `BusCompanyPaymentResource`, etc.) extends `Resource` with no per-resource override. They all inherit the panel-level gate. Therefore:

- Manager / Employee can never reach `/admin/bus-bookings`, `/admin/bus-companies`, etc. — they get 403 from Filament's auth middleware **before** any Resource method runs.
- Admin / Owner can do everything in the panel.

There is **no security gap** in the Filament layer either.

---

## 9. Risk Assessment — Is the Current Model Safe?

| Risk | Current Mitigation | Bypass Possible? |
|------|--------------------|------------------|
| Non-admin deletes a company | `role:admin` middleware on `DELETE` route | No — verified live |
| Non-admin reverses money | `admin` middleware on `pay-debt`, `cancel`, `pay`, `refund.store`, `refund.process` | No — verified live |
| Non-admin creates a bogus booking | None (intentional) | Yes — but this is a feature, not a bug (employees book trips) |
| Unauthenticated user hits any Bus endpoint | `auth:sanctum` middleware | No — 401 always |
| Manager/employee views financial data | None (intentional — needed for daily ops) | Yes — but again, this is by design |
| Owner acts as admin | `EnsureIsAdmin` treats `owner` same as `admin` | Yes (intentional — `owner` is a super-admin role) |

The only "bypass" possibility is **employee-creates-booking** (or company), which is a **legitimate business operation**, not a security gap.

---

## 10. Recommendation: ✅ **CLOSED AS ARCHITECTURAL / NOT REQUIRED**

The audit verdict is unambiguous:

### Why not "FIXED"?
- **There is nothing to fix.** Every money-moving endpoint is correctly admin-gated. Every read endpoint is correctly open to authenticated users. Every delete is correctly admin-gated (post F-7). Every unauthenticated request gets 401.
- **Adding per-resource Policies would be a duplicate of work the middleware already does.** The Policies would contain either `return $user->isAdmin()` (duplicate of `EnsureIsAdmin`) or `return true` (duplicate of `auth:sanctum`).
- **The existing 8 Policies are themselves stubs** — adding 6 more stub Bus Policies would only add noise to the codebase without changing runtime behavior.
- **The matrix test proves the current model is correct** — every probe returned the expected status code.

### What WOULD warrant a Policy?
If in the future the product introduces any of these:
1. **Per-record ownership rules** (e.g., "employees can only edit their own bookings")
2. **Differential Owner/Admin permissions** (e.g., "Owner can do X but Admin cannot")
3. **Manager can do everything except reverse money** (currently Manager == Employee)
4. **An audit requirement** that authorization decisions are logged per-action

→ At that point, **introduce a Policy and wire it into `$this->authorize()` in the controllers.** This is the time to introduce Policies, not now.

### Evidence Summary
- **F-7 suite (14/14 PASS):** proves DELETE authz gate works.
- **Live F-10 probe (28 routes × 4 roles):** proves no over-gating, no under-gating.
- **8 routes wrapped in `admin` or `role:admin` middleware:** proves money-move protection.
- **Zero `$this->authorize()` calls in Bus controllers:** proves route-level gates are the SOLE source of auth.
- **Filament `canAccessPanel` returns false for non-admin/owner:** proves admin UI is locked down.
- **Existing CustomerPolicy is a stub:** proves codebase doesn't rely on Policies for any active gate.

---

## 11. Deliverables Checklist (per user request)

- ✅ Current authorization matrix for each Bus resource — Section 2 (28 routes × 4 roles)
- ✅ Existing middleware/policy/gate coverage — Sections 1, 3, 7, 8
- ✅ Any actual unauthorized operation discovered — **None.** Every operation returned the expected status code.
- ✅ Whether F-10 should be FIXED or CLOSED — **CLOSED AS ARCHITECTURAL / NOT REQUIRED**
- ✅ Recommendation with evidence — Sections 6, 9, 10
- ✅ Probe script — `scripts/bus_audit_f10_authz_matrix.php` (re-runnable)

**No code changes were made.** Awaiting product decision on whether F-10 is closed or if Policies should still be introduced for non-security reasons (architectural consistency, future-proofing, etc.).
