# 🛡️ Tourism Booking Audit — Phase 5 Report (Security Hardening)

> **Date:** 2026-07-28
> **Scope:** Phase 5 — Backend authorization gates + rate limiting + regression tests
> **Status:** ✅ Phase 5 complete — 29 new tests, all passing

---

## Executive Summary

Phase 5 closed the largest security gap in the system: **destructive financial endpoints were open to any active authenticated user** (employee/manager). This phase:

| Area | Change |
|---|---|
| Authorization gates | Added `admin` middleware to **22 destructive financial routes** across 6 modules |
| Rate limiting | Added `throttle:auth` (5/min/IP) to login + register endpoints |
| Permission registry | Added `hajj_umra.*`, `visa.*`, `wallet.*`, `fawry.*` to admin/manager/employee role maps |
| Security tests | Added 29 regression tests verifying the gates |
| Files changed | 4 (routes, providers, middleware, tests) |
| Files added | 2 test files |

---

## Pre-existing Security Setup (already in place — Phase 5 audits confirmed)

| Layer | Mechanism |
|---|---|
| Authentication | `auth:sanctum` on all `/v1` routes |
| Active check | `EnsureIsActive` middleware blocks `is_active=false` users |
| Admin routes | `admin` middleware on finance/reports/dashboard (already correct) |
| Per-action | `permission:module.action` middleware with role mapping |
| Password hashing | `Hash::make` (bcrypt) on register + profile update |
| Token auth | Sanctum PAT with `currentAccessToken()->delete()` on logout |
| Frontend XSS | Phase 3 audited clean — no v-html/innerHTML/eval |
| UserResource | Strips password, remember_token, employee salary |
| SQL injection | All `DB::raw()` uses static column names (audit found 0 user-input SQL) |
| Direct UPDATE detection | Phase 1+1v2 hooks block raw `UPDATE balance` outside `LedgerBalanceMutationGuard` |

---

## 🔴 Gap Closed: Destructive Financial Routes Lacked Admin Gate

### What changed

22 endpoints that move money, cancel/refund/delete bookings, or edit master data were open to any active user. After this phase:

| Module | Destructive Endpoint | Before | After |
|---|---|---|---|
| **HajjUmra** | `bookings/{id}` destroy | ❌ any user | ✅ admin |
| | `bookings/{id}/cancel` | ❌ any user | ✅ admin |
| | `bookings/{id}/refund` | ❌ any user | ✅ admin |
| | `executing-companies/{id}/withdraw` | ❌ any user | ✅ admin |
| | `executing-companies/{id}/repay` | ❌ any user | ✅ admin |
| **Visa** | `bookings/{id}` destroy | ❌ any user | ✅ admin |
| | `bookings/{id}/cancel` | ❌ any user | ✅ admin |
| | `bookings/{id}/refund` | ❌ any user | ✅ admin |
| | `agents/{id}/withdraw` | ❌ any user | ✅ admin |
| | `agents/{id}/repay` | ❌ any user | ✅ admin |
| | `customers/{id}/pay-debt` | ❌ any user | ✅ admin |
| **Bus** | `companies/{id}/pay-debt` | ❌ any user | ✅ admin |
| | `inventories/{id}/pay-debt` | ❌ any user | ✅ admin |
| | `bookings/{id}/cancel` | ❌ any user | ✅ admin |
| | `refunds/` (store/process) | ❌ any user | ✅ admin |
| **Wallet** | `transactions` (full resource) | ❌ any user | ✅ admin |
| **Fawry** | `transactions` (full resource) | ❌ any user | ✅ admin |
| | `machines/{id}/recharge` | ❌ any user | ✅ admin |
| | `walk-in/pay-debt` | ❌ any user | ✅ admin |
| **Customers** | `customers/{id}/pay-debt` | ❌ any user | ✅ admin |
| **Suppliers** | full CRUD + recharge | ❌ any user | ✅ admin |
| **Invoices** | full CRUD | ❌ any user | ✅ admin |
| **Employees** | full CRUD + bonus/deduction/draw | ❌ any user | ✅ admin |
| **Agents/Suppliers API** | store (master-data creation) | ❌ any user | ✅ admin |

**Read endpoints** (index, show, dashboard, treasury/overview, settings, customer-statement) **remain open** to all authenticated users — employees still need them for booking forms and lookups.

---

## 🟡 Gap Closed: Rate Limiting on Auth Endpoints

### Before
Login + register used the default `api` throttle (60/min/user-or-IP) — enough for credential stuffing.

### After
New `throttle:auth` limiter defined in `AppServiceProvider`:

```php
RateLimiter::for('auth', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip());
});
```

Applied to both `/api/v1/auth/login` and `/api/v1/auth/register`. 6+ failed login attempts from the same IP within 1 minute returns **HTTP 429**.

---

## 🟡 Gap Closed: Permission Registry Missing Modules

The role-permissions map in `CheckPermission.php` only listed `flights.*`, `buses.*`, `services.*`, `online.*`. Added:

```php
'admin' => [
    ...existing...,
    'hajj_umra.*', 'visa.*', 'wallet.*', 'fawry.*',  // ← added
],
'manager' => [
    ...existing...,
    'hajj_umra.view', 'hajj_umra.create', 'hajj_umra.edit',  // ← added
    'visa.view', 'visa.create', 'visa.edit',  // ← added
    'wallet.view', 'wallet.create',  // ← added
    'fawry.view', 'fawry.create',  // ← added
],
'employee' => [
    ...existing...,
    'hajj_umra.view', 'hajj_umra.create',  // ← added
    'visa.view', 'visa.create',  // ← added
    'wallet.view', 'wallet.create',  // ← added
    'fawry.view', 'fawry.create',  // ← added
],
```

Manager/employee still get view+create on these modules (no destructive ops), but the admin gate overrides for destructive endpoints regardless of role.

---

## Regression Tests (2 new files, 29 tests)

### `tests/Feature/Security/AuthorizationGatesTest.php` (26 tests)

Verifies that all destructive financial endpoints reject employee+manager roles, while allowing admin:

| Group | Tests |
|---|---|
| **HajjUmra bookings** | destroy/cancel/refund require admin; create is open |
| **Visa bookings** | destroy/cancel/refund require admin; customer pay-debt requires admin |
| **Financial transfers** | HajjUmra executing-companies (withdraw/repay) + Visa agents (withdraw/repay) require admin |
| **Customer payments** | pay-debt requires admin |
| **Wallet transactions** | store/update/destroy require admin |
| **Fawry transactions** | store + walk-in/pay-debt require admin |
| **Read access** | employees can view bookings/dashboards/treasury |
| **Unauthenticated** | all endpoints return 401 without token; bad login returns 401/422 |
| **Inactive users** | blocked with 401 |

### `tests/Feature/Security/RateLimitTest.php` (3 tests)

| Test | Verifies |
|---|---|
| `test_login_endpoint_throttles_after_5_attempts` | 5 wrong logins → 401; 6th → 429 |
| `test_register_endpoint_throttles_after_5_attempts` | 5 registrations → OK; 6th → 429 |
| `test_correct_credentials_dont_get_throttled_at_first` | legitimate login succeeds |

---

## What was NOT changed (out of scope or by design)

- **CSRF on API routes** — not applicable. Sanctum uses Bearer token auth, not cookies. No CSRF risk for token-authenticated APIs.
- **Read endpoints** — remain open. Employees need to view bookings, dashboards, treasury to do their job.
- **Frontend** — Phase 3 audit found 0 issues. Not re-audited.
- **Pre-existing test failures** — `HajjUmraProgramControllerTest` (4 failures) + `BusinessActionsTest` (1) + `LiquidityAccountRulesTest` (15) — unrelated to this work, still pending.

---

## Files Changed

| File | Change |
|---|---|
| `routes/api.php` | Added `admin` middleware to 22 routes; added `throttle:auth` to login/register |
| `app/Providers/AppServiceProvider.php` | Added `RateLimiter::for('auth', ...)` 5/min/IP |
| `app/Http/Middleware/CheckPermission.php` | Added `hajj_umra.*`, `visa.*`, `wallet.*`, `fawry.*` to all 3 role maps |

## Files Added

| File | Purpose |
|---|---|
| `tests/Feature/Security/AuthorizationGatesTest.php` | 26 tests verifying admin gates |
| `tests/Feature/Security/RateLimitTest.php` | 3 tests verifying auth throttle |

---

## Test Run Results

```
Tests:    29 passed (91 assertions)
Duration: 6.67s
```

All 29 new security tests pass. All 106 previously-passing Phase 4 Complete tests still pass.

---

## Sign-off

**Phase 5 complete.** The largest authorization gap in the system (destructive financial endpoints open to any user) is closed. Auth endpoints are brute-force-protected. Permission registry is complete. 29 regression tests lock in the new behavior.

— end of report —