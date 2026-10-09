# SEC-1 — Production Permission Audit (READ-ONLY)

**Date:** 2026-08-20
**Auditor:** Pre-deployment READ-ONLY audit (no writes performed)
**Scope:** Production DB `safarakealayna` (MySQL) — `users` table only
**Goal:** Identify employees/users that would lose or retain access after the SEC-1 deny-by-default deployment.

---

## 1. Critical Finding (Headline)

**In the production DB right now:**

- **0 (zero)** users have `manage_treasury` in their `permissions` column.
- **0 (zero)** users have any wallet/treasury permission of any kind.
- **1** admin has ever created a `wallet_transaction` (`created_by = 1`, the system admin).

**Consequence:** SEC-1 deny-by-default cannot lock anyone OUT of treasury in production today — because no production user has treasury access today. The risk is hypothetical, not actual. The deployment is safe on this dimension.

However, the **absence of any treasury-capable employee** is itself a finding: the system is currently administered only by `admin@safarakealayna.com` (id=1) for all wallet/treasury work. If the admin wants to delegate treasury work, they MUST add explicit `manage_treasury` permission to the delegate's `permissions` array before the deployment OR add the permission immediately after.

---

## 2. Permission Storage & Authorization Path (Verified)

**Where permissions live:** `users.permissions` — JSON column cast to PHP array. Nullable.

**Where roles live:** `users.role` — string column.

**Inheritance:** **NONE.** Permissions are stored directly on the user row, not inherited from a `roles` table. There is no `roles` table in the project (verified by absence in `database/migrations/`).

**Authorization path (verified by code inspection):**

1. Route is gated by middleware `CheckPermission` (alias `permission:` in route definitions).
2. `CheckPermission::handle()` reads the user, returns 401 if no user.
3. If `$user->role` is `admin` or `owner` → bypass ALL checks (`return $next($request)`).
4. Otherwise: call `resolvePermission($permission)`. The legacy `wallet.create`/`wallet.view`/`wallet.*`/`fawry.*` keys resolve to `manage_treasury`. The legacy `finance.*`/`accounts.*`/`transactions.*` keys resolve to `manage_finance`.
5. `effectiveFor($user)`:
   - For admin/owner role: if `stored` (intersected with known keys) is non-empty, return `stored`; else return `all()`.
   - For non-admin/non-owner role: return `stored` ONLY. Empty/NULL stored → `[]` → reject.
6. If the required `manage_*` key is not in `effectiveFor()` → 403.
7. If `resolvePermission` returned `null` (i.e. unknown legacy key) → fall back to `hasLegacyRolePermission($user, $permission)` which uses a hard-coded role→permissions map. **This legacy fallback only triggers for legacy keys NOT in the alias map.** All wallet/treasury/fawry routes use keys that DO resolve, so the legacy fallback is not a bypass vector for treasury routes.

**UI is NOT a security boundary:** Frontend button visibility (`v-if="can('manage_treasury')"`) only mirrors the backend; the backend `CheckPermission` middleware is the actual gate.

---

## 3. Per-User Classification Table (All 41 Production Users)

| # | ID | Role | Email | Current Permissions (raw) | SEC-1 Result | Classification |
|---|----|------|-------|----------------------------|-------------|----------------|
| 1 | 1 | admin | admin@safarakealayna.com | NULL | admin bypass → SAFE |
| 2 | 2 | employee | e2e_emp_final@safarakealayna.com | NULL | loses ALL access (no perms, no admin bypass) | **NEEDS PERMISSION** |
| 3 | 3 | admin | admin_p3_audit@example.com | NULL | admin bypass → SAFE (audit fixture) |
| 4 | 4 | employee | normal_p3_audit@example.com | NULL | loses ALL access (audit fixture) | REVIEW REQUIRED (test fixture) |
| 5 | 5 | admin | p6_admin@example.com | NULL | admin bypass → SAFE (audit fixture) |
| 6 | 6 | employee | p6_normal@example.com | NULL | loses ALL access (audit fixture) | REVIEW REQUIRED (test fixture) |
| 7 | 7 | admin | bus-stress9@example.com | NULL | admin bypass → SAFE (audit fixture) |
| 8 | 8 | employee | TOURISM_FULL_AUDIT_20260818_employee_31996cce@audit.local | `["manage_flights","manage_hajj","manage_online","manage_refunds"]` | keeps 4 modules, loses implicit `manage_bus` (was in `defaultEmployeeModules()`) — audit fixture | REVIEW REQUIRED (test fixture) |
| 9 | 9 | admin | TOURISM_FULL_AUDIT_20260818_admin_2a32835e@audit.local | `["*"]` | admin role bypass + array_intersect strips "*" → returns `all()` → SAFE (audit fixture) |
| 10 | 10 | employee | TOURISM_FULL_AUDIT_20260818_employee_c0063f84@audit.local | `["manage_flights","manage_hajj","manage_online","manage_refunds"]` | keeps 4, audit fixture | REVIEW REQUIRED (test fixture) |
| 11 | 11 | employee | TOURISM_FULL_AUDIT_20260818_employee_e6bb36b8@audit.local | `["manage_flights","manage_hajj","manage_online","manage_refunds"]` | keeps 4, audit fixture | REVIEW REQUIRED (test fixture) |
| 12-24 | 12-24 | admin | TOURISM_FULL_AUDIT_20260818_admin_*@audit.local | `["*"]` | admin bypass → SAFE (audit fixtures, 13 rows) |
| 25 | 25 | employee | TOURISM_FULL_AUDIT_20260818_employee_6215cad3@audit.local | `["manage_flights","manage_hajj","manage_online","manage_refunds"]` | keeps 4, audit fixture | REVIEW REQUIRED (test fixture) |
| 26 | 26 | admin | TOURISM_FULL_AUDIT_20260818_admin_d57e6d70@audit.local | `["*"]` | admin bypass → SAFE (audit fixture) |
| 27 | 27 | employee | TOURISM_FULL_AUDIT_20260818_employee_157bfef5@audit.local | `["manage_flights","manage_hajj","manage_online","manage_refunds"]` | keeps 4, audit fixture | REVIEW REQUIRED (test fixture) |
| 28 | 28 | employee | TOURISM_FULL_AUDIT_20260818_employee_f8d25188@audit.local | `["manage_flights","manage_hajj","manage_online","manage_refunds"]` | keeps 4, audit fixture | REVIEW REQUIRED (test fixture) |
| 29-41 | 29-41 | admin | TOURISM_FULL_AUDIT_20260818_admin_*@audit.local | `["*"]` | admin bypass → SAFE (audit fixtures, 13 rows) |

**Summary counts:**
- Total users checked: 41
- Real production users (non-audit): 2 (id 1 admin, id 2 employee)
- Audit fixtures (cleanup recommended): 39
- Users with `manage_treasury` in permissions: **0**
- Users with NULL permissions: **7** (1 real employee + 6 audit fixtures)
- Users with `[]` permissions: **0**
- Users with `["*"]` (wildcard — interpreted as "all" by admin branch): **27**
- Users with non-wildcard explicit permissions: **6** (all employees, all audit fixtures)
- Users that would LOSE access after SEC-1: **1 real** (id 2, NULL perms employee) + 3 audit fixtures with NULL

---

## 4. Real Production Employees (Non-Audit) — Detailed

### ID 1 — admin@safarakealayna.com — `admin`

- **Role:** `admin`
- **Permissions:** NULL
- **SEC-1 result:** SAFE. The middleware bypasses for `admin` role on line 66 of `CheckPermission.php`. `effectiveFor()` returns `all()` for admin with empty stored perms (line 162).
- **Classification:** SAFE — explicit correct permissions (admin bypass).
- **Action required:** None.

### ID 2 — e2e_emp_final@safarakealayna.com — `employee`

- **Role:** `employee`
- **Permissions:** NULL
- **Created at:** 2026-08-14 05:54:08
- **SEC-1 result:** **LOSES ALL ACCESS.** With `permissions = NULL`, `effectiveFor()` returns `[]`. Every `permission:*` route returns 403.
- **Classification:** **NEEDS PERMISSION** if this employee needs any module access. Otherwise NO FINANCIAL ACCESS.
- **Action required:** Confirm with the admin whether this employee should:
  - (a) Be granted specific module permissions (e.g. `manage_flights`), or
  - (b) Be deactivated (`is_active = false`), or
  - (c) Be promoted to `admin` if they're an admin-equivalent.

**NOTE:** This is the only real production employee with no permissions. The admin should decide what (if anything) this user should access.

---

## 5. Dangerous / Over-Privileged Accounts

**None in production.** Specifically:
- No user has `manage_treasury` (would be required for treasury over-privilege)
- No user has `manage_finance` (would be required for finance over-privilege)
- 27 admin users have `["*"]` which is a wildcard but the admin-role bypass makes it moot; the `*` is also stripped by `array_intersect($stored, self::keys())` so the `effectiveFor()` admin branch returns `all()` regardless. These are anomalous data (audit fixtures with hash-suffixed emails), not real over-privilege.

---

## 6. Required Permissions for Each Legitimate Financial Role

The `manage_*` keys are defined in `app/Support/UserPermissions.php`:

| Role / Function | Required Permission(s) |
|-----------------|------------------------|
| Wallet/Fawry cashier (post transactions) | `manage_treasury` |
| Wallet/Fawry viewer (read-only) | `manage_treasury` |
| Finance/Accounts viewer | `manage_finance` |
| Refund operator | `manage_refunds` |
| Flight module user | `manage_flights` |
| Bus module user | `manage_bus` |
| Hajj/Umra module user | `manage_hajj` |
| Online/Visa module user | `manage_online` |
| Employee/HR | `manage_employees` |
| Reports viewer | `view_reports` |
| User management | `manage_users` |
| Owner | (full via role bypass) |
| Admin | (full via role bypass) |

**Treasury/Wallet is exclusively gated by `manage_treasury`.** No other permission unlocks wallet endpoints.

---

## 7. Recommended Pre-Deployment Permission Plan

### Action A — Before deployment (CRITICAL)

1. **Decide the role of `e2e_emp_final@safarakealayna.com` (id 2)** with the system owner.
   - If this user should keep their current behavior (whatever modules they had), grant explicit permissions.
   - If this user is no longer needed, set `is_active = false`.
   - Do NOT promote to admin without explicit owner sign-off.

### Action B — Before deployment (RECOMMENDED, non-blocking)

2. **Audit-fixture cleanup:** The 39 `@audit.local` and `@example.com` users are clearly test artifacts (suffix-hashed emails, generated by `TOURISM_FULL_AUDIT_20260818_*` runs). They have NO production value and SHOULD be either:
   - Deleted (preferred, with `DELETE FROM users WHERE email LIKE '%@audit.local' OR email LIKE '%@example.com'` — outside this audit's read-only scope), or
   - Deactivated (`is_active = false`).
   - This is hygiene only and has no SEC-1 impact because admin fixtures already bypass via role.

### Action C — During deployment (POST-DEPLOY, manual)

3. **No automatic pre-seeding is required** because no production employee currently has `manage_treasury`. If/when the admin wants to delegate treasury work:
   - `UPDATE users SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'manage_treasury') WHERE id = <delegate_id> AND role != 'admin';`
   - Or use the Filament user-management UI (grants `manage_treasury` explicitly via the `permissions` array).

### Action D — During deployment (POST-DEPLOY, verification)

4. **Verify the deny-by-default closure:**
   - Attempt `/api/v1/wallet/transactions` GET as employee id=2 → expect 403.
   - Attempt `/api/v1/wallet/transactions` POST as employee id=2 → expect 403.
   - Attempt same as admin id=1 → expect 200/201.

---

## 8. Risks Acknowledged (Out-of-Scope, Noted Only)

1. **The `hasLegacyRolePermission` legacy fallback** in `CheckPermission.php` (lines 131-181) hard-codes role-based permission grants for `manager` and `employee`. This is dead code for wallet/treasury routes (because the alias map resolves first), but it IS still active for legacy route keys NOT in the alias map. If any production route uses a legacy key not yet aliased (e.g. `buses.create`, `services.create`, `customers.create`, `reports.create`), an employee role will still have access via this fallback. **SEC-1 does NOT close these legacy holes.** Recommend a follow-up audit to identify all such routes and either add aliases or remove the legacy fallback entirely.

2. **The 27 `["*"]` admin rows** are anomalous data. If `role` were ever changed from `admin` to `employee` on one of these rows, the user would be locked out completely (because `array_intersect(["*"], keys()) = []` and `effectiveFor()` for non-admin returns `[]`). Recommend sanitizing to `[]` or removing these rows.

3. **No roles table / no role hierarchy:** Permissions live entirely on the user. If the project scales to dozens of employees, this becomes a maintenance burden. Recommend a follow-up to add a `roles` table with permission templates.

---

## 9. Audit Closure

- **Total users checked:** 41
- **Users with empty/null permissions:** 7 (1 real + 6 audit fixtures)
- **Users currently holding `manage_treasury`:** **0**
- **Users affected by SEC-1 deployment:** 1 real (id=2, loses all access — needs explicit decision) + 3 audit fixtures (non-blocking)
- **Exact permission changes required before deploy:** None mandatory; only the owner-decision action on id=2 (Action A).
- **Dangerous over-privileged accounts:** None.
- **Recommended pre-deploy permission plan:** Actions A, B, C, D above.

**Final verdict for SEC-1 deployment:** **SAFE TO DEPLOY** from a permission-impact perspective, contingent on Action A (owner decision on id=2). No production employee will be silently locked out of treasury because no production employee currently has treasury access.

---

## 10. SQL/Query Log (for Reproducibility)

The audit used the following read-only queries:

```php
// 1. Total user count
App\Models\User::count()  // → 41

// 2. NULL permissions
DB::table("users")->whereNull("permissions")->count()  // → 7

// 3. [] permissions
DB::table("users")->where("permissions", "[]")->count()  // → 0

// 4. With manage_treasury
DB::table("users")->whereRaw("permissions LIKE ?", ["%manage_treasury%"])->count()  // → 0

// 5. Distinct permission sets
DB::table("users")->select("permissions")->distinct()->get()
//   → 3 distinct values: NULL, ["manage_flights","manage_hajj","manage_online","manage_refunds"], ["*"]

// 6. wallet_transactions.created_by distinct
DB::table("wallet_transactions")->distinct()->pluck("created_by")  // → [1] (admin)
```

No INSERTs, UPDATEs, DELETEs, or schema changes were performed. The audit was strictly SELECT-only.