# Tourism Employee Refund — Atomicity / Transaction Safety Audit

**Audit ID**: `EMP_REFUND_ATOMICITY_CHECK_20260817`
**Date**: 2026-08-17
**Type**: READ-ONLY (no code, migration, schema, permission, or test changes)

---

## 🎯 THE ONE QUESTION

> **Can a Tourism refund financially succeed while `refund_audit_logs` fails to persist?**

# **YES — confirmed. There IS a window where the financial refund can commit without a `refund_audit_logs` row.**

---

## Summary

| Question | Answer | Severity |
|---|---|---|
| Is the financial refund inside a DB transaction? | **YES** | — |
| Is `refund_audit_logs` inserted inside the SAME DB transaction? | **YES** | — |
| Is `audit_logs` inserted inside the SAME DB transaction? | **YES** | — |
| Can `RefundAuditLogger::logRefund()` catch an exception and return `null` without rolling back the parent transaction? | **YES — by design** | ⚠️ **CRITICAL** |
| Can the financial refund commit before the audit is persisted? | **YES** | ⚠️ **CRITICAL** |
| Are there nested transactions? | **YES** (Flight only — `LedgerBalanceMutationGuard::run` → `DB::transaction`) | ⚠️ |
| Does SQLite provide atomic behavior for the operations being used? | **YES** | — |

---

## 1. Refund Flow Trace

### Flight Refund (processRefundRequest)
| Step | File | Lines |
|---|---|---|
| Route | `routes/api.php` | 229-236 |
| Controller | `app/Http/Controllers/Api/V1/Flight/RefundController.php` | `process()` |
| Service | `app/Services/Flight/RefundService.php` | `processRefundRequest()` 272-558 |
| DB transaction wrapper | `app/Services/Flight/RefundService.php` | 274-558 (`withDeadlockRetry` → `LedgerBalanceMutationGuard::run` → `DB::transaction`) |
| Financial reversal | `app/Services/Flight/RefundService.php` | Inside transaction closure (varies by branch — `airline_credit` or `agency_treasury`) |
| Audit log call | `app/Services/Flight/RefundService.php` | **536** (inside transaction) |
| Commit | `app/Services/Flight/RefundService.php` | 556 (close of `DB::transaction` closure) |

### Flight Refund Reversal (reverseRefundRequest — admin only)
| Step | File | Lines |
|---|---|---|
| Route | `routes/api.php` | 229-236 (DELETE) |
| Service | `app/Services/Flight/RefundService.php` | `reverseRefundRequest()` 597-799 |
| DB transaction wrapper | `app/Services/Flight/RefundService.php` | 599-799 (`LedgerBalanceMutationGuard::run` → `DB::transaction`) |
| Financial reversal + soft-delete | `app/Services/Flight/RefundService.php` | Inside transaction closure |
| Audit log call | `app/Services/Flight/RefundService.php` | **780** (inside transaction) |
| Commit | `app/Services/Flight/RefundService.php` | 799 (close of `DB::transaction` closure) |

### Hajj/Umrah Refund
| Step | File | Lines |
|---|---|---|
| Route | `routes/api.php` | 571-575 |
| Controller | `app/Http/Controllers/Api/V1/HajjUmraController.php` | `refund()` 179-200 |
| Service | `app/Services/HajjUmra/HajjUmraRefundService.php` | `refund()` 49-173 |
| DB transaction wrapper | `app/Services/HajjUmra/HajjUmraRefundService.php` | **53** (`DB::transaction(function () { … })`) |
| Financial reversal | `app/Services/HajjUmra/HajjUmraRefundService.php` | 97-109 (payments, income, expense) |
| Booking status update | `app/Services/HajjUmra/HajjUmraRefundService.php` | 119-125 |
| Audit log call | `app/Services/HajjUmra/HajjUmraRefundService.php` | **147** (inside transaction) |
| Commit | `app/Services/HajjUmra/HajjUmraRefundService.php` | Implicit at end of closure at line 53 |

### Visa Refund
| Step | File | Lines |
|---|---|---|
| Route | `routes/api.php` | 600-605 |
| Controller | `app/Http/Controllers/Api/V1/Visa/VisaBookingController.php` | `refund()` |
| Service | `app/Services/Visa/VisaRefundService.php` | `refund()` 103-198 |
| DB transaction wrapper | `app/Services/Visa/VisaRefundService.php` | **108** (`DB::transaction(function () { … })`) |
| Financial reversal | `app/Services/Visa/VisaRefundService.php` | 141-142 (`reversePayments`, `reverseBookingTransactions`) |
| Booking status update | `app/Services/Visa/VisaRefundService.php` | 144-149 |
| Audit log call | `app/Services/Visa/VisaRefundService.php` | **171** (inside transaction) |
| Commit | `app/Services/Visa/VisaRefundService.php` | Implicit at end of closure at line 108 |

---

## 2. Database Transaction Analysis

### Per-module answers

| Question | Hajj/Umrah | Visa | Flight (process) | Flight (reverse) |
|---|---|---|---|---|
| 1. Refund inside `DB::transaction`? | ✅ L53 | ✅ L108 | ✅ L276 | ✅ L601 |
| 2. `refund_audit_logs` inside same tx? | ✅ L147 | ✅ L171 | ✅ L536 | ✅ L780 |
| 3. `audit_logs` inside same tx? | ✅ L147 (delegated to RefundAuditLogger) | ✅ L171 | ✅ L536 | ✅ L780 |
| 4. Can `logRefund()` swallow exception & return `null` without rolling back? | **YES** | **YES** | **YES** | **YES** |
| 5. Can refund commit before audit is persisted? | **YES** | **YES** | **YES** | **YES** |
| 6. Nested transactions? | No | No | **Yes** (`LedgerBalanceMutationGuard::run` → `DB::transaction`) | **Yes** |
| 7. SQLite atomic for these ops? | ✅ | ✅ | ✅ | ✅ |

### The exact mechanism

`RefundAuditLogger::logRefund()` at `app/Services/Finance/RefundAuditLogger.php` has TWO nested try/catch blocks:

1. **Outer try/catch (L59-131)**: Wraps the entire body.
   - If `RefundAuditLog::create([...])` (L72) throws, the catch at L124 logs the error and **returns `null`** (L130).
   - **This null return propagates to the caller.**

2. **Inner try/catch (L96-121)**: Wraps ONLY the `AuditLog::create([...])` call.
   - If `audit_logs` insert fails, it logs the error and continues — but does NOT return null (returns the `$refundAudit` from L123).
   - The outer try/catch is NOT triggered, so the caller gets a `RefundAuditLog` instance back. **audit_logs can silently be lost while refund_audit_logs succeeds.**

### How the callers treat the return value

| Caller | File:Line | Treats return value? |
|---|---|---|
| Hajj refund | `HajjUmraRefundService.php:147` | **NO** — bare statement, return value discarded |
| Visa refund | `VisaRefundService.php:171` | **NO** — bare statement, return value discarded |
| Flight process | `RefundService.php:536` | **NO** — bare statement, return value discarded |
| Flight reverse | `RefundService.php:780` | **NO** — bare statement, return value discarded |

**All four callers ignore the return value.** They never inspect for `null` and never throw on `null`.

---

## 3. RefundAuditLogger — Detailed Inspection

`app/Services/Finance/RefundAuditLogger.php`

### How `refund_audit_logs` is inserted (L72-92)
Direct `RefundAuditLog::create([...])` Eloquent call. Not a separate query — it goes through the active connection inside the surrounding `DB::transaction`.

### How `audit_logs` is inserted (L96-115)
Direct `AuditLog::create([...])` Eloquent call. Also inside the surrounding `DB::transaction`.

### Whether exceptions are swallowed — **YES (both layers)**

```php
// L59-131: outer try/catch swallows refund_audit_logs failures
try {
    ...
    $refundAudit = RefundAuditLog::create([...]);   // can throw
    try {                                            // L96-121: inner try/catch
        AuditLog::create([...]);                    // can throw
    } catch (\Throwable $auditEx) {
        Log::error(...);                            // swallowed
    }
    return $refundAudit;
} catch (\Throwable $e) {
    Log::error('RefundAuditLogger: refund_audit_logs insert failed', ...); // swallowed
    return null;                                   // SILENT — caller never knows
}
```

### Whether it uses try/catch — **YES**
Both layers.

### Whether it returns null after an exception — **YES** (outer layer only)

### Whether the caller checks that return value — **NO**
Verified above for all 4 call sites.

### Whether the caller throws/reverts the financial operation when audit persistence fails — **NO**

---

## 4. Why This Matters — The Failure Window

### Scenario A: `refund_audit_logs` insert fails

1. Employee calls `POST /api/v1/hajj-umra/bookings/{id}/refund`.
2. `HajjUmraRefundService::refund()` opens a `DB::transaction` (L53).
3. Payment/income/expense transactions are reversed (L97-109). These persist inside the open tx.
4. Booking status is set to `refunded` (L119-125). Persists inside the open tx.
5. `RefundAuditLogger::logRefund()` is called (L147).
6. Inside `logRefund()`, `RefundAuditLog::create([...])` throws (e.g. DB write error, constraint violation, FK violation, NOT NULL violation on a required column).
7. **The outer try/catch at L124 catches it.** The error is logged. `null` is returned.
8. Back in `HajjUmraRefundService::refund()`, the return value is ignored. **No exception propagates.**
9. The `DB::transaction` closure completes normally. **It commits.**
10. **Result**: customer has been refunded (transactions reversed, status=refunded), but **NO `refund_audit_logs` row exists**. The audit trail is silently lost.

### Scenario B: `audit_logs` insert fails (after `refund_audit_logs` succeeded)

1-5. Same as above.
6. `RefundAuditLog::create([...])` succeeds (L72).
7. Inside `AuditLog::create([...])` throws (e.g. `model_type` FK violation, NOT NULL violation).
8. **The inner try/catch at L116 catches it.** Error is logged.
9. `logRefund()` returns the `$refundAudit` (L123) — **NOT null**.
10. Caller ignores return value.
11. `DB::transaction` commits.
12. **Result**: `refund_audit_logs` row exists, `audit_logs` row does NOT. **The generic audit timeline is silently lost.**

Both scenarios are reachable today.

---

## 5. Documentation Says This Is Intentional

`RefundAuditLogger.php` lines 27-31 (docblock):

> **FAILURE POLICY**: This helper NEVER throws to the caller. If the audit write fails (e.g. DB outage), it logs the error and returns null. Rationale: the user's actual refund has succeeded by this point — we must not roll back their success, but we MUST log that audit failed so an admin can investigate.

This is a **deliberate design choice**, not an accidental bug. The implementation faithfully matches the policy in the docblock.

However, this design choice **violates** the original audit requirement (which the same audit produced):

> "Mandatory Refund Audit Trail (every Employee Refund MUST record: ...)"

The word **MUST** implies enforcement. The current implementation permits silent failure of the MUST.

---

## 6. Tests Covering the Failure Path

**None.** Searched all of `tests/`:
- `grep -rn "audit.*fail\|audit.*null\|RefundAuditLogger.*fail" tests/` → only hits in production-audit scripts, no assertions.
- `tests/Feature/TourismEmployeeE2E/EmployeeRefundAuditTest.php` has 40 tests, none of which simulate `RefundAuditLog::create()` failure.

The existing 137-test suite does NOT exercise the audit-write-failure path. The gap is real and untested.

---

## 7. Verdict

# ⚠️ GO WITH WARNINGS

The implementation is functionally correct for the **happy path** — when `refund_audit_logs` succeeds, the audit trail is complete (40 of 40 new tests cover this).

However, the **failure path** (audit insert throws) is not atomically protected. The financial refund WILL commit even if the audit insert fails. This violates the "MUST" requirement.

### Warnings

1. **Silent audit-loss window** — A `refund_audit_logs` insert failure will not roll back the financial refund. The customer gets their money back; the audit trail silently loses the row.

2. **Silent `audit_logs`-loss window** — Even when `refund_audit_logs` succeeds, a subsequent `audit_logs` insert failure is silently swallowed (no `null` returned, no rollback).

3. **No alerting mechanism** — The only signal is a `Log::error` line. There is no failed-audit queue, no admin notification, no reconciliation job.

4. **No test coverage** — The failure path is untested.

---

## 8. Recommended Remediations (for future work — NOT in scope of this audit)

These are **recommendations only**. This audit is read-only and does NOT implement any of them.

| # | Recommendation | Severity |
|---|---|---|
| 1 | Reorder: insert audit row BEFORE any financial mutation. Then if audit fails, no money moves. | High |
| 2 | Have callers CHECK the return value of `logRefund()` and throw if `null` — this triggers `DB::rollback` for the entire refund. | High |
| 3 | Add a `failed_refund_audits` queue/log table that captures failed audit writes for admin reconciliation. | Medium |
| 4 | Add a test that simulates `RefundAuditLog::create()` throwing (mock or constraint violation) and verifies the financial refund IS rolled back. | High |
| 5 | Add a test that simulates `AuditLog::create()` throwing and verifies both rollback AND that the user receives an error response. | Medium |
| 6 | Add an admin-facing "Failed Refund Audits" view backed by the queue. | Low |
| 7 | Document the "audit failure = refund rollback" policy explicitly in the docblock. Update if policy is to keep current behavior (silent loss). | Low |

---

## 9. Files Inspected (READ-ONLY)

| File | Lines |
|---|---|
| `app/Services/Finance/RefundAuditLogger.php` | All (146 lines) |
| `app/Services/HajjUmra/HajjUmraRefundService.php` | 1-173 |
| `app/Services/Visa/VisaRefundService.php` | 1-200 |
| `app/Services/Flight/RefundService.php` | 270-799 (processRefundRequest + reverseRefundRequest) |
| `app/Http/Controllers/Api/V1/HajjUmraController.php` | 179-200 |
| `routes/api.php` | 229-236, 571-575, 600-605 |
| `tests/Feature/TourismEmployeeE2E/EmployeeRefundAuditTest.php` | All (search for audit-failure-path tests) |
| `tests/reports/TOURISM_EMPLOYEE_REFUND_AUDIT_20260817.md` | Final report (read for context) |

No files were modified, no tests run, no migrations applied, no production code touched.