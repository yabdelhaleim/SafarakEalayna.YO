# 🚨 PHASE 8 — B-1 CIRCUIT BREAKER REPORT (EMERGENCY)

**Date:** 2026-08-19
**Phase:** 8 (Visa module full audit)
**Status:** **STOPPED IMMEDIATELY** per Circuit Breaker directive
**Severity:** **CRITICAL — B-1 level (cross-customer access + financial manipulation)**

---

## ⚠️ CIRCUIT BREAKER INVOKED

Per the user's directive (verbatim):
> "لو في أي Phase (خصوصًا Phase 8 — فحص Visa) لقيت مشكلة من نفس خطورة B-1 (يعني: ثغرة أمان تسمح بالوصول/الدفع/التعديل على بيانات عميل تاني، أو تلاعب مالي حقيقي مش مجرد تكرار تقارير) — **توقف فورًا في نفس اللحظة**، لا تكمل باقي الـ Phase، اكتب تقرير طارئ منفصل، وانتظر توجيه."

Multiple B-1-level authorization vulnerabilities were discovered during the Visa route inspection. Phase 8 is **STOPPED** pending user direction. No further Visa audit work will proceed until the user reviews this report.

---

## ROOT CAUSE

`routes/api.php` has **two route groups that both register Visa and Hajj/Umra endpoints**:

1. **Inside `Route::prefix('v1')->group(...)`** (lines 572–627) — has correct `admin`/`permission:manage_refunds` middleware on destructive endpoints.
2. **Inside `Route::middleware('auth:sanctum')->group(...)` "Global API aliases"** (lines 658–667) — has **NO admin gate** and registers the same endpoints a second time at un-prefixed URIs (`/visa-agents` instead of `/api/v1/visa-agents`).

Both route groups load the same controllers. The un-prefixed aliases at L660-666 therefore act as **unauthenticated-from-an-authz-perspective write endpoints** reachable by any active, non-banned user (including the default `employee` role created by `User::factory()`).

Additionally, several **read endpoints inside the v1 group itself** (lines 593–624) lack ownership scoping — any authenticated user can read all visa bookings, all customer statements, and all treasury data.

---

## FINDINGS

### 🔴 B-1.1 — `POST /visa-agents` (routes/api.php:661) — **NO admin gate**
- **Group:** `Route::middleware('auth:sanctum')->group(...)` (L658-667) — auth only, **no admin gate**.
- **Same route inside v1 group at L650** has `admin` middleware — so the duplicate at L661 is reachable by any authenticated user.
- **Controller:** `App\Http\Controllers\Api\V1\Visa\VisaAgentApiController@store` (L38-83).
- **Effect:** Creates:
  - A new `VisaAgent` (master data row).
  - **A new `Supplier` `Account` row** with `module_type='visas'`, `type='supplier'`, balance=0, is_active=true (L50-63).
- **Attack scenario:** Any employee logs in → calls `POST /visa-agents` → creates a fake VisaAgent + an AR account tied to it → later selects that account as `account_id` in a `VisaBookingService::create()` call (no policy check on `account_id` ownership) → routes a real customer's payment to attacker-controlled AR account → withdraws via `/agents/{agent}/repay` if they can escalate, or simply removes the agent and the AR account is orphaned with funds.

**Verdict: B-1 financial manipulation.** Real fraud path, not just info disclosure.

---

### 🔴 B-1.2 — `POST /umrah-suppliers` (routes/api.php:664) — **NO admin gate**
- **Group:** same auth-only group at L658-667.
- **Same route inside v1 group at L651** has `admin` middleware.
- **Controller:** `App\Http\Controllers\Api\V1\HajjUmra\UmrahSupplierApiController@store` (L33-73).
- **Effect:** Creates:
  - A new `UmrahSupplier` row.
  - **A new `Supplier` `Account` row** with `module_type='hajj_umra'`, `type='supplier'`.
- **Attack scenario:** Same pattern as B-1.1 but targeting Hajj/Umra supplier AR accounts.

**Verdict: B-1 financial manipulation.** Symmetric to B-1.1.

---

### 🔴 B-1.3 — `GET /api/v1/visa/customer-balances` (routes/api.php:623) — **NO admin gate**
- **Group:** inside v1 prefix group at L589-627, but with **no additional middleware** beyond global `auth:sanctum, active, CaptureFinancialPostingContext, RejectBannedFinancialBypassMarkers`.
- **Controller:** `App\Http\Controllers\Api\V1\VisaController@customerBalances` (L36-94).
- **Effect:** Returns AR rollup per customer for **all** customers in the database (total_sales, total_paid, total_debt, booking_count, last_booking). No filter, no scoping.
- **Attack scenario:** Any employee enumerates customer debt balances → identifies high-value targets for phishing, social engineering, or extortion.

**Verdict: B-1 cross-customer info disclosure.**

---

### 🔴 B-1.4 — `GET /api/v1/visa/customer-statement?client_id=N` (routes/api.php:624) — **NO admin gate**
- **Controller:** `App\Http\Controllers\Api\V1\VisaController@customerStatement` (L97-203).
- **Effect:** Returns the **full statement** of **any** customer when `?client_id=` is provided:
  - Customer PII: `full_name`, `phone`.
  - Every booking (id, date, type, selling_price + service_fee = total selling).
  - Every payment (id, date, amount, payment_method, paid_by, createdBy name).
  - AccountEntry rows for the customer's account with full description and employee names.
  - Running balance.
- **No `customer_id == auth()->user()->customer_id` scoping** — any authenticated user can request any `client_id`.
- **Attack scenario:** Read any customer's complete financial history + identify which employees handled which transactions + profile customers for follow-on attacks.

**Verdict: B-1 cross-customer info disclosure (severe — full statement including employee names).**

---

### 🔴 B-1.5 — `GET /api/v1/visa/bookings` (routes/api.php:615) — **NO ownership scoping**
- **Controller:** `VisaBookingController@index`.
- **Effect:** Returns ALL visa bookings to any authenticated user. No filter on `employee_id == auth()->id()` or `customer_id == auth()->user()->customer_id`.
- **Attack scenario:** Enumerate every active visa booking + customer + status.

**Verdict: B-1 cross-customer info disclosure.**

---

### 🔴 B-1.6 — `GET /api/v1/visa/bookings/{visa}` (routes/api.php:617) — **NO ownership scoping**
- **Controller:** `VisaBookingController@show` (route-model binding).
- **Effect:** Returns any booking's full details (selling_price, payments, expense/income transactions, employee names) to any authenticated user.

**Verdict: B-1 cross-customer info disclosure.**

---

### 🔴 B-1.7 — `GET /api/v1/visa/bookings/{visa}/modifications` (routes/api.php:620) — **NO admin gate**
- **Controller:** `VisaBookingController@modifications` → `VisaModificationService::history()`.
- **Effect:** Returns the modification history of any booking — every change to price/status/payments, the old and new values, and which employee made each change.

**Verdict: B-1 cross-customer info disclosure (high sensitivity — exposes employee actions).**

---

### 🔴 B-1.8 — `GET /api/v1/visa/treasury/overview` (routes/api.php:593) — **NO admin gate**
- **Controller:** `VisaTreasuryController@overview`.
- **Effect:** Returns accounts + active agents + recent 40 visa-module transactions (including amounts).

**Verdict: B-1 financial info disclosure.**

---

### 🔴 B-1.9 — `GET /api/v1/visa/treasury/accounts/{account}/transactions` (routes/api.php:594) — **NO admin gate**
- **Controller:** `VisaTreasuryController@accountVisaTransactions`.
- **Effect:** Returns paginated transactions for any account where `module='visa'`.

**Verdict: B-1 cross-account financial disclosure.**

---

### 🔴 B-1.10 — `GET /api/v1/visa/agents/dues` (routes/api.php:598) — **NO admin gate**
- **Controller:** `VisaAgentFinanceController@dues`.
- **Effect:** Returns net due per active visa agent.

**Verdict: B-1 cross-agent financial disclosure.**

---

## ROOT-CAUSE FIX RECOMMENDATION

The cleanest single fix is to **delete the unprefixed alias group at L658-667 entirely**, since all those routes already exist inside the v1 prefix group (L589-627) where the admin gates are correctly applied:

```php
// routes/api.php — DELETE lines 658-667:
// ──────────────────────────────────────
// Global API aliases without v1 prefix for backward compatibility and specific tool queries
// Route::middleware('auth:sanctum')->group(function () {
//     Route::get('visa-agents', [VisaAgentApiController::class, 'index']);
//     Route::post('visa-agents', [VisaAgentApiController::class, 'store']);
//     Route::get('visa-agents/{id}/cost-price', [VisaAgentApiController::class, 'costPrice']);
//     Route::get('umrah-suppliers', [UmrahSupplierApiController::class, 'index']);
//     Route::post('umrah-suppliers', [UmrahSupplierApiController::class, 'store']);
//     Route::get('clients', [CustomerController::class, 'search']);
//     Route::get('accounts', [AccountController::class, 'index']);
// });
```

This eliminates **B-1.1 and B-1.2** in one stroke.

For the read-endpoint findings (B-1.3 through B-1.10), the recommended fix is **add `admin` or `permission:view_visa_*` middleware** to each — or, if "all staff can view" is the intended business rule, document it explicitly and add it to the security model. A future hardening pass should add `VisaBookingPolicy` + `CustomerPolicy::viewAny()` ownership scoping.

---

## OTHER OBSERVATIONS (NOT B-1, for completeness)

- **Hard-coded user fallback** `Auth::id() ?: 1` appears in `VisaBookingController::destroy` (L131), `VisaRefundService::deleteWithReversal` (L306), `VisaController::payCustomerDebt` (L229, L281, L302). These are admin-gated so currently low risk, but represent systemic "system user" attribution in audit logs.
- **`VisaAgentApiController::store`** uses inline `$request->validate()` instead of a FormRequest — lower-severity consistency issue.
- **`VisaBooking::paid_amount` accessor** triggers a `payments()` aggregate query when not eager-loaded — potential N+1, performance concern.
- **`VisaTreasuryController::overview`** does not filter `Transaction` by `deleted_at` consistently; may surface soft-deleted transactions.
- **`VisaLiquidityAccount` rule** accepts legacy alias `'visa'` in addition to canonical `'visas'` — broadens surface area intentionally.

---

## ACTIONS TAKEN

1. ✅ **Phase 8 stopped immediately.** No code edits, no test runs.
2. ✅ **Emergency report written** to `docs/PHASE_8_B1_CIRCUIT_BREAKER_REPORT.md`.
3. ⏸️ **Phase 8.2 (routes audit), 8.3 (refund flow), 8.4 (cross-module ID), 8.5 (financial mutations)** — all paused.
4. ⏸️ **Phase 9 (UnifiedVaultsSeeder) + Phase 10 (Final Report)** — pending Phase 8 resolution.

---

## RECOMMENDED NEXT STEPS (awaiting user direction)

**Option A — Apply root-cause fix and continue:**
1. Delete `routes/api.php` L658-667.
2. Add `admin` middleware (or `permission:view_visa_*`) to L593, L594, L598, L615, L617, L620, L623, L624 inside the v1 prefix group.
3. Verify with Phase 14 cross-module attack tests + new ownership-scoping tests.
4. Resume Phase 8.3–8.5.

**Option B — Full security review:**
1. Apply Option A.
2. Run `php artisan route:list --columns=method,uri,name,middleware` and audit every endpoint for missing ownership scoping.
3. Add `VisaBookingPolicy`, `CustomerPolicy::viewAny()` with per-role scoping.
4. Document "intentionally public to all staff" endpoints explicitly.

**Option C — Hold all changes:**
1. Do not modify anything until product owner confirms which endpoints are intentionally read-by-all-staff vs restricted.

---

**Branch:** `phase-5-audit-logs-related-id` (uncommitted Visa-related findings remain in working tree until user direction).
**Awaiting explicit user decision before any further Phase 8 work.**