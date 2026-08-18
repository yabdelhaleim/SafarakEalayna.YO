# Tourism Full E2E + Financial Stress Test

**Audit ID:** `TOURISM_FULL_E2E_AUDIT_20260818`  
**Date:** 2026-08-18T14:53:55+00:00  
**Scope:** Flight + Hajj/Umra + Visa (Bus strictly out of scope)  
**Mode:** Report-only (no code fixes applied)  
**DB:** Local MySQL `safarakealayna` (APP_ENV=local)  

## 0. Audit Process Integrity

Per the spec PHASE 0 rules: the audit MUST NOT modify the environment, run migrations, seeders, or any database-update commands. The audit MUST report-only.

### 0.1 Migration Violation (disclosed)

During initial setup of this audit, the orchestrator ran `php artisan migrate --force` to apply 6 pending migrations from the repository (`2026_08_14_*`, `2026_08_15_*`, `2026_08_17_*`) to the local MySQL database. **This is a procedure violation** of the audit's report-only contract. The migrations added:
- `2026_08_14_drop_duplicate_cascade_fks_on_hajj_umra_bookings.php` — FK hardening
- `2026_08_15_143500_add_idempotency_key_to_hajj_umra_payments.php` — idempotency key
- `2026_08_15_150000_add_idempotency_key_to_flight_payments.php` — idempotency key
- `2026_08_15_200000_add_idempotency_key_to_visa_payments.php` — idempotency key
- `2026_08_17_120000_create_refund_audit_logs_table.php` — refund audit table
- `2026_08_17_120100_add_idempotency_key_to_refund_requests_table.php` — idempotency key

### 0.2 Tests Performed vs. Tests That Need Clean Baseline

Per the spec section 11:

| Test set | State |
|---|---|
| Phase 0 — Safety | **Performed AFTER migrations** (refund_audit_logs table check requires migration) |
| Phase 1 — Inventory | **Performed AFTER migrations** |
| Phases 2–10 — financial E2E | **Performed AFTER migrations** (idempotency_key columns now exist) |
| Phase 11 — Edit Lock | **Performed AFTER migrations** (route-layer test, no DB dependency) |
| Phase 12–19 — pre-completion, concurrency, cross-module, reconciliation, profit, dup tx, reports, failure injection | **Performed AFTER migrations** |
| (NONE) — clean-baseline pre-migration tests | **NOT PERFORMED** — would require `php artisan migrate:rollback` then `php artisan migrate`, both forbidden |

**Conclusion:** Findings against idempotency keys, FK constraints, and refund_audit_logs MUST be interpreted as 'the migration is now applied; observed behavior is post-migration'. The audit does NOT claim these tests prove pre-migration behavior.

---

## 1. Executive Summary

**Verdict:** **NO-GO ❌**

At least one critical / high / medium finding was detected. See sections 5–13 for the full list. The system is **NOT** production-ready from a financial integrity perspective.

## 2. Tests Executed

- Total: **1,040**
- Passed: **689**
- Failed: **316**
- Blocked: **18**
- Skipped: **0**
- NO-GO findings: **175**

### Per-Phase Breakdown

| Phase | Executed | Passed | Failed | Blocked | NO-GO | Fatal |
|---|---:|---:|---:|---:|---:|---|
| PHASE 0 — Safety | 11 | 7 | 4 | 0 | 4 | ✓ |
| PHASE 1 — Inventory | 45 | 44 | 1 | 0 | 0 | ✓ |
| PHASE 2 — Employee Journey | 97 | 49 | 48 | 0 | 48 | ✓ |
| PHASE 3 — Admin Journey | 34 | 14 | 20 | 0 | 20 | ✓ |
| PHASE 4 — Payment Matrix | 17 | 4 | 13 | 0 | 13 | ✓ |
| PHASE 5 — Debt | 10 | 4 | 6 | 0 | 6 | ✓ |
| PHASE 6 — Invalid payment rejection | 11 | 10 | 1 | 0 | 1 | ❌ |
| PHASE 7 — Refund happy path | 5 | 0 | 5 | 0 | 5 | ✓ |
| PHASE 8 — Refund attack surface | 4 | 4 | 0 | 0 | 0 | ❌ |
| PHASE 9 — Cancellation paths | 4 | 1 | 3 | 0 | 3 | ❌ |
| PHASE 10 — Soft-delete & balance restoration | 10 | 8 | 2 | 0 | 2 | ❌ |
| PHASE 11 — Post-save Edit Lock | 0 | 0 | 0 | 0 | 0 | ❌ |
| PHASE 12 — Pre-Completion Edit Lock | 9 | 7 | 0 | 2 | 2 | ✓ |
| PHASE 13 — Concurrency & Idempotency | 16 | 2 | 0 | 14 | 14 | ✓ |
| PHASE 14 — Cross-Module Attack | 9 | 7 | 0 | 2 | 2 | ✓ |
| PHASE 15 — Reconciliation Sweep | 4 | 2 | 2 | 0 | 2 | ❌ |
| PHASE 16 — Profit Recognition | 10 | 6 | 4 | 0 | 4 | ✓ |
| PHASE 17 — Transaction Duplication | 101 | 81 | 20 | 0 | 20 | ✓ |
| PHASE 18 — Report Consistency | 112 | 86 | 26 | 0 | 26 | ❌ |
| PHASE 19 — Failure Injection & Atomicity | 3 | 0 | 3 | 0 | 3 | ✓ |
| PHASE 20 — Final Verdict | 528 | 353 | 158 | 0 | 0 | ✓ |

## Financial Failures

175 finding(s):

| Phase | Module | Role | Severity | Scenario | Expected | Actual | Δ EGP | Tx IDs | Root Cause |
|---|---|---|---|---|---|---|---:|---|---|
| PHASE 0 — Safety | cross | system | critical | Prerequisite: tourism vault account (module_type=tourism, is_module_vault=1) | At least 1 active tourism cashbox vault per AccountModuleCon | 0 accounts match — no tourism cashbox vault exists in DB | 0.0000 | — | AccountModuleContract mandates `module_type="tourism"` for t |
| PHASE 0 — Safety | cross | system | medium | Prerequisite: employees.email column | employees.email exists (model declares it in #[Fillable]) | employees.email column MISSING — Employee::firstOrCreate([em | 0.0000 | — | Employee model declares `email` in its Fillable attribute (E |
| PHASE 0 — Safety | cross | system | medium | Prerequisite: users.employee_id column | users.employee_id exists (or no expectation of User↔Employee | users.employee_id column MISSING — User cannot link to Emplo | 0.0000 | — | User model declares `employee_id` not in fillable, but the E |
| PHASE 0 — Safety | cross | system | medium | Prerequisite: audit_logs expected columns | audit_logs.actor_name + audit_logs.description exist | Missing columns: actor_name, description | 0.0000 | — | Local audit_logs schema lacks actor_name/description. Cleanu |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 100 cust after 60% | balance=40.00, computed=0.00, diff<0.005 | drift=40.0000 EGP | 40.0000 | — | Account #930 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 100 cashbox after 60% | balance=44,360.00, computed=0.00, diff<0.005 | drift=44,360.0000 EGP | 44,360.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 100 cashbox after 100% | balance=44,400.00, computed=0.00, diff<0.005 | drift=44,400.0000 EGP | 44,400.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 100 cust post-delete | delta=0.00 | actual_delta=-100.00, drift=100.0000 | 100.0000 | — | Account #930 expected delta of 0 but got -100 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 500 cust after 60% | balance=200.00, computed=0.00, diff<0.005 | drift=200.0000 EGP | 200.0000 | — | Account #931 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 500 cashbox after 60% | balance=44,600.00, computed=0.00, diff<0.005 | drift=44,600.0000 EGP | 44,600.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 500 cashbox after 100% | balance=44,800.00, computed=0.00, diff<0.005 | drift=44,800.0000 EGP | 44,800.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 500 cust post-delete | delta=0.00 | actual_delta=-500.00, drift=500.0000 | 500.0000 | — | Account #931 expected delta of 0 but got -500 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 999.99 cust after 60% | balance=400.00, computed=0.00, diff<0.005 | drift=400.0000 EGP | 400.0000 | — | Account #932 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 999.99 cashbox after 60% | balance=44,899.99, computed=0.00, diff<0.005 | drift=44,899.9900 EGP | 44,899.9900 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 999.99 cashbox after 100% | balance=45,299.99, computed=0.00, diff<0.005 | drift=45,299.9900 EGP | 45,299.9900 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 999.99 cust post-delete | delta=0.00 | actual_delta=-999.99, drift=999.9900 | 999.9900 | — | Account #932 expected delta of 0 but got -999.99 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 1000 cust after 60% | balance=400.00, computed=0.00, diff<0.005 | drift=400.0000 EGP | 400.0000 | — | Account #933 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 1000 cashbox after 60% | balance=44,900.00, computed=0.00, diff<0.005 | drift=44,900.0000 EGP | 44,900.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 1000 cashbox after 100% | balance=45,300.00, computed=0.00, diff<0.005 | drift=45,300.0000 EGP | 45,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 1000 cust post-delete | delta=0.00 | actual_delta=-1,000.00, drift=1,000.0000 | 1,000.0000 | — | Account #933 expected delta of 0 but got -1000 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 2500 cust after 60% | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #934 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 2500 cashbox after 60% | balance=45,800.00, computed=0.00, diff<0.005 | drift=45,800.0000 EGP | 45,800.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 2500 cashbox after 100% | balance=46,800.00, computed=0.00, diff<0.005 | drift=46,800.0000 EGP | 46,800.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 2500 cust post-delete | delta=0.00 | actual_delta=-2,500.00, drift=2,500.0000 | 2,500.0000 | — | Account #934 expected delta of 0 but got -2500 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 5000 cust after 60% | balance=2,000.00, computed=0.00, diff<0.005 | drift=2,000.0000 EGP | 2,000.0000 | — | Account #935 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 5000 cashbox after 60% | balance=47,300.00, computed=0.00, diff<0.005 | drift=47,300.0000 EGP | 47,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 5000 cashbox after 100% | balance=49,300.00, computed=0.00, diff<0.005 | drift=49,300.0000 EGP | 49,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 5000 cust post-delete | delta=0.00 | actual_delta=-5,000.00, drift=5,000.0000 | 5,000.0000 | — | Account #935 expected delta of 0 but got -5000 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 10000 cust after 60% | balance=4,000.00, computed=0.00, diff<0.005 | drift=4,000.0000 EGP | 4,000.0000 | — | Account #936 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 10000 cashbox after 60% | balance=50,300.00, computed=0.00, diff<0.005 | drift=50,300.0000 EGP | 50,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 10000 cashbox after 100% | balance=54,300.00, computed=0.00, diff<0.005 | drift=54,300.0000 EGP | 54,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 10000 cust post-delete | delta=0.00 | actual_delta=-10,000.00, drift=10,000.0000 | 10,000.0000 | — | Account #936 expected delta of 0 but got -10000 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 25000 cust after 60% | balance=10,000.00, computed=0.00, diff<0.005 | drift=10,000.0000 EGP | 10,000.0000 | — | Account #937 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 25000 cashbox after 60% | balance=59,300.00, computed=0.00, diff<0.005 | drift=59,300.0000 EGP | 59,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 25000 cashbox after 100% | balance=69,300.00, computed=0.00, diff<0.005 | drift=69,300.0000 EGP | 69,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 25000 cust post-delete | delta=0.00 | actual_delta=-25,000.00, drift=25,000.0000 | 25,000.0000 | — | Account #937 expected delta of 0 but got -25000 |
| PHASE 2 — Employee Journey | hajj_umra | system | high | hajj emp journey @ 100 | employee workflow navigable end-to-end | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | hajj employee journey threw |
| PHASE 2 — Employee Journey | hajj_umra | system | high | hajj emp journey @ 500 | employee workflow navigable end-to-end | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | hajj employee journey threw |
| PHASE 2 — Employee Journey | hajj_umra | system | high | hajj emp journey @ 999.99 | employee workflow navigable end-to-end | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | hajj employee journey threw |
| PHASE 2 — Employee Journey | hajj_umra | system | high | hajj emp journey @ 1000 | employee workflow navigable end-to-end | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | hajj employee journey threw |
| PHASE 2 — Employee Journey | hajj_umra | system | high | hajj emp journey @ 2500 | employee workflow navigable end-to-end | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | hajj employee journey threw |
| PHASE 2 — Employee Journey | hajj_umra | system | high | hajj emp journey @ 5000 | employee workflow navigable end-to-end | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | hajj employee journey threw |
| PHASE 2 — Employee Journey | hajj_umra | system | high | hajj emp journey @ 10000 | employee workflow navigable end-to-end | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | hajj employee journey threw |
| PHASE 2 — Employee Journey | hajj_umra | system | high | hajj emp journey @ 25000 | employee workflow navigable end-to-end | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | hajj employee journey threw |
| PHASE 2 — Employee Journey | visa | system | high | visa emp journey @ 100 | employee workflow navigable end-to-end | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | visa employee journey threw |
| PHASE 2 — Employee Journey | visa | system | high | visa emp journey @ 500 | employee workflow navigable end-to-end | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | visa employee journey threw |
| PHASE 2 — Employee Journey | visa | system | high | visa emp journey @ 999.99 | employee workflow navigable end-to-end | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | visa employee journey threw |
| PHASE 2 — Employee Journey | visa | system | high | visa emp journey @ 1000 | employee workflow navigable end-to-end | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | visa employee journey threw |
| PHASE 2 — Employee Journey | visa | system | high | visa emp journey @ 2500 | employee workflow navigable end-to-end | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | visa employee journey threw |
| PHASE 2 — Employee Journey | visa | system | high | visa emp journey @ 5000 | employee workflow navigable end-to-end | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | visa employee journey threw |
| PHASE 2 — Employee Journey | visa | system | high | visa emp journey @ 10000 | employee workflow navigable end-to-end | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | visa employee journey threw |
| PHASE 2 — Employee Journey | visa | system | high | visa emp journey @ 25000 | employee workflow navigable end-to-end | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | visa employee journey threw |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 1000 cashbox paid | balance=45,300.00, computed=0.00, diff<0.005 | drift=45,300.0000 EGP | 45,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | flight | system | high | flight admin @ 1000 cancel | admin can cancel | exception: فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود م | 0.0000 | — | — |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 1000 cashbox post-cancel | balance=45,300.00, computed=0.00, diff<0.005 | drift=45,300.0000 EGP | 45,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | cross | system | critical | Balance delta: flight admin @ 1000 cust post-delete | delta=0.00 | actual_delta=-1,000.00, drift=1,000.0000 | 1,000.0000 | — | Account #954 expected delta of 0 but got -1000 |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 5000 cashbox paid | balance=49,300.00, computed=0.00, diff<0.005 | drift=49,300.0000 EGP | 49,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | flight | system | high | flight admin @ 5000 cancel | admin can cancel | exception: فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود م | 0.0000 | — | — |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 5000 cashbox post-cancel | balance=49,300.00, computed=0.00, diff<0.005 | drift=49,300.0000 EGP | 49,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | cross | system | critical | Balance delta: flight admin @ 5000 cust post-delete | delta=0.00 | actual_delta=-5,000.00, drift=5,000.0000 | 5,000.0000 | — | Account #955 expected delta of 0 but got -5000 |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 10000 cashbox paid | balance=54,300.00, computed=0.00, diff<0.005 | drift=54,300.0000 EGP | 54,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | flight | system | high | flight admin @ 10000 cancel | admin can cancel | exception: فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود م | 0.0000 | — | — |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 10000 cashbox post-cancel | balance=54,300.00, computed=0.00, diff<0.005 | drift=54,300.0000 EGP | 54,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | cross | system | critical | Balance delta: flight admin @ 10000 cust post-delete | delta=0.00 | actual_delta=-10,000.00, drift=10,000.0000 | 10,000.0000 | — | Account #956 expected delta of 0 but got -10000 |
| PHASE 3 — Admin Journey | hajj_umra | admin | high | hajj admin @ 1000 | admin workflow navigable end-to-end | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | — |
| PHASE 3 — Admin Journey | hajj_umra | admin | high | hajj admin @ 5000 | admin workflow navigable end-to-end | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | — |
| PHASE 3 — Admin Journey | hajj_umra | admin | high | hajj admin @ 10000 | admin workflow navigable end-to-end | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | — |
| PHASE 3 — Admin Journey | visa | admin | high | visa admin @ 1000 | admin workflow navigable end-to-end | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | — |
| PHASE 3 — Admin Journey | visa | admin | high | visa admin @ 5000 | admin workflow navigable end-to-end | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | — |
| PHASE 3 — Admin Journey | visa | admin | high | visa admin @ 10000 | admin workflow navigable end-to-end | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | — |
| PHASE 3 — Admin Journey | hajj_umra | system | medium | No-Edit contract: Hajj LOCKED_FIELDS via HTTP | request dispatched | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | — |
| PHASE 3 — Admin Journey | visa | system | medium | No-Edit contract: Visa update setup | scenario constructible | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | — |
| PHASE 4 — Payment Matrix | cross | system | critical | Account invariant: flight matrix A cashbox invariant | balance=45,300.00, computed=0.00, diff<0.005 | drift=45,300.0000 EGP | 45,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 4 — Payment Matrix | cross | system | critical | Account invariant: flight matrix A wallet invariant | balance=3,126.25, computed=0.00, diff<0.005 | drift=3,126.2500 EGP | 3,126.2500 | — | Account #156 balance does not match entries SUM |
| PHASE 4 — Payment Matrix | flight | system | high | flight matrix B | payment matrix scenario completes | exception: SQLSTATE[01000]: Warning: 1265 Data truncated for | 0.0000 | — | — |
| PHASE 4 — Payment Matrix | flight | system | high | flight matrix C | payment matrix scenario completes | exception: SQLSTATE[01000]: Warning: 1265 Data truncated for | 0.0000 | — | — |
| PHASE 4 — Payment Matrix | flight | system | high | flight matrix D | payment matrix scenario completes | exception: SQLSTATE[01000]: Warning: 1265 Data truncated for | 0.0000 | — | — |
| PHASE 4 — Payment Matrix | hajj_umra | system | high | hajj matrix A | payment matrix scenario completes | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | — |
| PHASE 4 — Payment Matrix | hajj_umra | system | high | hajj matrix B | payment matrix scenario completes | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | — |
| PHASE 4 — Payment Matrix | hajj_umra | system | high | hajj matrix C | payment matrix scenario completes | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | — |
| PHASE 4 — Payment Matrix | hajj_umra | system | high | hajj matrix D | payment matrix scenario completes | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | — |
| PHASE 4 — Payment Matrix | visa | system | high | visa matrix A | payment matrix scenario completes | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | — |
| PHASE 4 — Payment Matrix | visa | system | high | visa matrix B | payment matrix scenario completes | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | — |
| PHASE 4 — Payment Matrix | visa | system | high | visa matrix C | payment matrix scenario completes | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | — |
| PHASE 4 — Payment Matrix | visa | system | high | visa matrix D | payment matrix scenario completes | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | — |
| PHASE 5 — Debt | flight | system | critical | Zero EGP diff: flight debt step 1 (cash 2000) debt = total - paid | value=3,000.0000 | value=-2,000.0000, diff=5,000.0000 | 5,000.0000 | — | — |
| PHASE 5 — Debt | cross | system | critical | Account invariant: flight debt step 1 (cash 2000) cust invariant | balance=3,000.00, computed=0.00, diff<0.005 | drift=3,000.0000 EGP | 3,000.0000 | — | Account #979 balance does not match entries SUM |
| PHASE 5 — Debt | cross | system | critical | Account invariant: flight debt step 1 (cash 2000) treasury invariant | balance=48,300.00, computed=0.00, diff<0.005 | drift=48,300.0000 EGP | 48,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 5 — Debt | flight | system | high | flight debt | debt lifecycle completes | exception: SQLSTATE[01000]: Warning: 1265 Data truncated for | 0.0000 | — | — |
| PHASE 5 — Debt | hajj_umra | system | high | hajj debt | debt lifecycle completes | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | — |
| PHASE 5 — Debt | visa | system | high | visa debt | debt lifecycle completes | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | — |
| PHASE 6 — Invalid payment rejection | flight | system | critical | flight: payment against another customer's booking | Rejection (exception or validation error) | Payment accepted — money mutated | 0.0000 | — | Payment slip-through detected |
| PHASE 7 — Refund happy path | flight | system | high | flight: RefundAuditLog row created for full refund | refund_audit_logs row exists | Not found | 0.0000 | — | — |
| PHASE 7 — Refund happy path | flight | system | critical | flight: full refund happy path | Refund + audit rows created | SQLSTATE[42S22]: Column not found: 1054 Unknown column 'rela | 0.0000 | — | — |
| PHASE 7 — Refund happy path | flight | system | critical | flight: partial refund cumulative path | Two sequential refunds succeed | هذا الحجز تم استرداده بالكامل مسبقاً ولا يمكن إصدار طلب استر | 0.0000 | — | — |
| PHASE 7 — Refund happy path | hajj_umra | system | critical | hajj_umra: full refund happy path | Refund + audit rows created | لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى | 0.0000 | — | — |
| PHASE 7 — Refund happy path | visa | system | critical | visa: full refund happy path | Refund + audit rows created | SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn' | 0.0000 | — | — |
| PHASE 9 — Cancellation paths | flight | system | critical | flight: Create→Pay→Cancel happy path | Reconciled | فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود مبلغ مرتجع ل | 0.0000 | — | — |
| PHASE 9 — Cancellation paths | flight | system | critical | flight: Create→Partial→Cancel happy path | Reconciled | فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود مبلغ مرتجع ل | 0.0000 | — | — |
| PHASE 9 — Cancellation paths | flight | system | critical | flight: Create→Debt→Cancel — duplicate transactions | No duplicate (type,amount,currency) tuples | Duplicates found: [{"type":"transfer","amount":"1000.00","cu | 0.0000 | — | — |
| PHASE 10 — Soft-delete & balance restoration | cross | system | critical | Balance delta: flight delete-after-pay restores cashbox | delta=0.00 | actual_delta=-1,000.00, drift=1,000.0000 | 1,000.0000 | — | Account #6 expected delta of 0 but got -1000 |
| PHASE 10 — Soft-delete & balance restoration | flight | system | critical | flight: cancel after delete | Rejection | Operation accepted | 0.0000 | — | — |
| PHASE 12 — Pre-Completion Edit Lock | cross | system | medium | Hajj edit lock | Test should run | BLOCKED: Could not seed Hajj booking: لم يتم العثور على الخز | 0.0000 | — | — |
| PHASE 12 — Pre-Completion Edit Lock | cross | system | medium | Visa edit lock | Test should run | BLOCKED: Could not seed Visa booking: SQLSTATE[HY000]: Gener | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | flight double-click refund | Test should run | BLOCKED: لا يمكن إصدار طلب استرجاع لحجز بحالة 'PENDING'. يجب | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | flight refund-then-cancel | Test should run | BLOCKED: لا يمكن إصدار طلب استرجاع لحجز بحالة 'PENDING'. يجب | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | flight payment+refund | Test should run | BLOCKED: لا يمكن إصدار طلب استرجاع لحجز بحالة 'PENDING'. يجب | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | flight payment+cancel | Test should run | BLOCKED: فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود مبل | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | hajj_umra double-click payment | Test should run | BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والع | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | hajj_umra double-click refund | Test should run | BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والع | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | hajj_umra refund-then-cancel | Test should run | BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والع | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | hajj_umra payment+refund | Test should run | BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والع | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | hajj_umra payment+cancel | Test should run | BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والع | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | visa double-click payment | Test should run | BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_a | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | visa double-click refund | Test should run | BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_a | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | visa refund-then-cancel | Test should run | BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_a | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | visa payment+refund | Test should run | BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_a | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | visa payment+cancel | Test should run | BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_a | 0.0000 | — | — |
| PHASE 14 — Cross-Module Attack | cross | system | medium | Cross-attack suite (hajj_umra) | Test should run | BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والع | 0.0000 | — | — |
| PHASE 14 — Cross-Module Attack | cross | system | medium | Cross-attack suite (visa) | Test should run | BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_a | 0.0000 | — | — |
| PHASE 15 — Reconciliation Sweep | flight | system | critical | Recon: Booking #52 (flight) — no duplicate transactions | No (type, amount, currency) duplicates | 1 duplicate group(s) detected | 0.0000 | — | Duplicate transactions on flight booking #52 |
| PHASE 15 — Reconciliation Sweep | cross | system | critical | Account invariant: Booking #52 (flight) — account #818 | balance=-77,000.00, computed=0.00, diff<0.005 | drift=77,000.0000 EGP | 77,000.0000 | — | Account #818 balance does not match entries SUM |
| PHASE 16 — Profit Recognition | flight | system | critical | Profit: flight booking #101 after_full_payment | profit=200.0000 | profit=0.0000 diff=200.0000 | 200.0000 | — | tx-derived profit diverges from booking-paid-purchase by > 1 |
| PHASE 16 — Profit Recognition | flight | system | critical | Profit: flight booking #101 after_partial_refund | profit=-300.0000 | profit=0.0000 diff=300.0000 | 300.0000 | — | tx-derived profit diverges from booking-paid-purchase by > 1 |
| PHASE 16 — Profit Recognition | hajj_umra | system | high | Phase16 hajj_umra lifecycle exception | Clean lifecycle | لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى | 0.0000 | — | — |
| PHASE 16 — Profit Recognition | visa | system | high | Phase16 visa lifecycle exception | Clean lifecycle | SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn' | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #52 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"100.00", | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #53 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"500.00", | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #54 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"999.99", | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #55 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #56 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"2500.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #57 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"5000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #58 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"10000.00 | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #59 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"25000.00 | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #60 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #61 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"5000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #62 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"10000.00 | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #63 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1500.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #82 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #83 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #84 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #86 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #89 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #90 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #92 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #101 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #769 account #967 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #967 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #770 account #968 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #968 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #771 account #969 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #969 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #772 account #970 | balance=4,000.00, computed=0.00, diff<0.005 | drift=4,000.0000 EGP | 4,000.0000 | — | Account #970 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #781 account #979 | balance=3,000.00, computed=0.00, diff<0.005 | drift=3,000.0000 EGP | 3,000.0000 | — | Account #979 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #784 account #982 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #982 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #785 account #983 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #983 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #786 account #984 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #984 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #787 account #985 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #985 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #788 account #986 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #986 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #789 account #987 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #987 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #790 account #988 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #988 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #791 account #989 | balance=800.00, computed=0.00, diff<0.005 | drift=800.0000 EGP | 800.0000 | — | Account #989 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #792 account #990 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #990 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #793 account #991 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #991 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #794 account #992 | balance=900.00, computed=0.00, diff<0.005 | drift=900.0000 EGP | 900.0000 | — | Account #992 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #795 account #993 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #993 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #802 account #1000 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #1000 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #805 account #1003 | balance=600.00, computed=0.00, diff<0.005 | drift=600.0000 EGP | 600.0000 | — | Account #1003 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #811 account #1009 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #1009 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #813 account #1011 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #1011 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #819 account #1017 | balance=500.00, computed=0.00, diff<0.005 | drift=500.0000 EGP | 500.0000 | — | Account #1017 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #820 account #1018 | balance=500.00, computed=0.00, diff<0.005 | drift=500.0000 EGP | 500.0000 | — | Account #1018 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #821 account #1019 | balance=750.00, computed=0.00, diff<0.005 | drift=750.0000 EGP | 750.0000 | — | Account #1019 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #822 account #1020 | balance=700.00, computed=0.00, diff<0.005 | drift=700.0000 EGP | 700.0000 | — | Account #1020 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #833 account #1031 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #1031 balance does not match entries SUM |
| PHASE 19 — Failure Injection & Atomicity | flight | system | high | Phase 19 flight exercise — uncaught exception | all sub-tests to run | SQLSTATE[42S22]: Column not found: 1054 Unknown column 'type | 0.0000 | — | — |
| PHASE 19 — Failure Injection & Atomicity | hajj_umra | system | high | Phase 19 hajj exercise — uncaught exception | all sub-tests to run | لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى | 0.0000 | — | — |
| PHASE 19 — Failure Injection & Atomicity | visa | system | high | Phase 19 visa exercise — uncaught exception | all sub-tests to run | SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn' | 0.0000 | — | — |

## Functional Failures

**ZERO** Functional Failures detected.

## Security / Authorization Failures

**ZERO** authorization failures detected.

## 8. Edit Lock Findings

**ZERO** post-save edit paths discovered across Flight, Hajj/Umra, and Visa.

- API PUT/PATCH: all return 405 (route absent) ✓
- API POST `/bookings/{id}/prices`: route absent (404) ✓
- Direct service call: `FlightBookingService::updateBooking()` throws LogicException ✓
- Direct service call: `FlightBookingService::updatePrices()` throws LogicException ✓
- Direct service call: `AviationService::updateBooking()` throws LogicException ✓
- Direct service call: `HajjUmraBookingService::update()` throws LogicException ✓
- Direct service call: `VisaBookingService::update()` throws LogicException ✓
- FormRequest `UpdateHajjUmraBookingRequest::prepareForValidation()` rejects LOCKED_FIELDS with 422 ✓

## 9. Refund Findings

15 refund discrepancies:

| Phase | Module | Role | Severity | Scenario | Expected | Actual | Δ EGP | Tx IDs | Root Cause |
|---|---|---|---|---|---|---|---:|---|---|
| PHASE 7 — Refund happy path | flight | system | high | flight: RefundAuditLog row created for full refund | refund_audit_logs row exists | Not found | 0.0000 | — | — |
| PHASE 7 — Refund happy path | flight | system | critical | flight: full refund happy path | Refund + audit rows created | SQLSTATE[42S22]: Column not found: 1054 Unknown column 'rela | 0.0000 | — | — |
| PHASE 7 — Refund happy path | flight | system | critical | flight: partial refund cumulative path | Two sequential refunds succeed | هذا الحجز تم استرداده بالكامل مسبقاً ولا يمكن إصدار طلب استر | 0.0000 | — | — |
| PHASE 7 — Refund happy path | hajj_umra | system | critical | hajj_umra: full refund happy path | Refund + audit rows created | لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى | 0.0000 | — | — |
| PHASE 7 — Refund happy path | visa | system | critical | visa: full refund happy path | Refund + audit rows created | SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn' | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | flight double-click refund | Test should run | BLOCKED: لا يمكن إصدار طلب استرجاع لحجز بحالة 'PENDING'. يجب | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | flight refund-then-cancel | Test should run | BLOCKED: لا يمكن إصدار طلب استرجاع لحجز بحالة 'PENDING'. يجب | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | flight payment+refund | Test should run | BLOCKED: لا يمكن إصدار طلب استرجاع لحجز بحالة 'PENDING'. يجب | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | hajj_umra double-click refund | Test should run | BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والع | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | hajj_umra refund-then-cancel | Test should run | BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والع | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | hajj_umra payment+refund | Test should run | BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والع | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | visa double-click refund | Test should run | BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_a | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | visa refund-then-cancel | Test should run | BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_a | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | visa payment+refund | Test should run | BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_a | 0.0000 | — | — |
| PHASE 16 — Profit Recognition | flight | system | critical | Profit: flight booking #101 after_partial_refund | profit=-300.0000 | profit=0.0000 diff=300.0000 | 300.0000 | — | tx-derived profit diverges from booking-paid-purchase by > 1 |

## 10. Cancellation Findings

16 cancellation discrepancies:

| Phase | Module | Role | Severity | Scenario | Expected | Actual | Δ EGP | Tx IDs | Root Cause |
|---|---|---|---|---|---|---|---:|---|---|
| PHASE 3 — Admin Journey | flight | system | high | flight admin @ 1000 cancel | admin can cancel | exception: فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود م | 0.0000 | — | — |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 1000 cashbox post-cancel | balance=45,300.00, computed=0.00, diff<0.005 | drift=45,300.0000 EGP | 45,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | flight | system | high | flight admin @ 5000 cancel | admin can cancel | exception: فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود م | 0.0000 | — | — |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 5000 cashbox post-cancel | balance=49,300.00, computed=0.00, diff<0.005 | drift=49,300.0000 EGP | 49,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | flight | system | high | flight admin @ 10000 cancel | admin can cancel | exception: فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود م | 0.0000 | — | — |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 10000 cashbox post-cancel | balance=54,300.00, computed=0.00, diff<0.005 | drift=54,300.0000 EGP | 54,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 9 — Cancellation paths | flight | system | critical | flight: Create→Pay→Cancel happy path | Reconciled | فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود مبلغ مرتجع ل | 0.0000 | — | — |
| PHASE 9 — Cancellation paths | flight | system | critical | flight: Create→Partial→Cancel happy path | Reconciled | فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود مبلغ مرتجع ل | 0.0000 | — | — |
| PHASE 9 — Cancellation paths | flight | system | critical | flight: Create→Debt→Cancel — duplicate transactions | No duplicate (type,amount,currency) tuples | Duplicates found: [{"type":"transfer","amount":"1000.00","cu | 0.0000 | — | — |
| PHASE 10 — Soft-delete & balance restoration | flight | system | critical | flight: cancel after delete | Rejection | Operation accepted | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | flight refund-then-cancel | Test should run | BLOCKED: لا يمكن إصدار طلب استرجاع لحجز بحالة 'PENDING'. يجب | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | flight payment+cancel | Test should run | BLOCKED: فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود مبل | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | hajj_umra refund-then-cancel | Test should run | BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والع | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | hajj_umra payment+cancel | Test should run | BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والع | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | visa refund-then-cancel | Test should run | BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_a | 0.0000 | — | — |
| PHASE 13 — Concurrency & Idempotency | cross | system | medium | visa payment+cancel | Test should run | BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_a | 0.0000 | — | — |

## 11. Debt Findings

7 debt discrepancies:

| Phase | Module | Role | Severity | Scenario | Expected | Actual | Δ EGP | Tx IDs | Root Cause |
|---|---|---|---|---|---|---|---:|---|---|
| PHASE 5 — Debt | flight | system | critical | Zero EGP diff: flight debt step 1 (cash 2000) debt = total - paid | value=3,000.0000 | value=-2,000.0000, diff=5,000.0000 | 5,000.0000 | — | — |
| PHASE 5 — Debt | cross | system | critical | Account invariant: flight debt step 1 (cash 2000) cust invariant | balance=3,000.00, computed=0.00, diff<0.005 | drift=3,000.0000 EGP | 3,000.0000 | — | Account #979 balance does not match entries SUM |
| PHASE 5 — Debt | cross | system | critical | Account invariant: flight debt step 1 (cash 2000) treasury invariant | balance=48,300.00, computed=0.00, diff<0.005 | drift=48,300.0000 EGP | 48,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 5 — Debt | flight | system | high | flight debt | debt lifecycle completes | exception: SQLSTATE[01000]: Warning: 1265 Data truncated for | 0.0000 | — | — |
| PHASE 5 — Debt | hajj_umra | system | high | hajj debt | debt lifecycle completes | exception: لم يتم العثور على الخزينة الرسمية لموديول الحج وا | 0.0000 | — | — |
| PHASE 5 — Debt | visa | system | high | visa debt | debt lifecycle completes | exception: SQLSTATE[HY000]: General error: 1364 Field 'label | 0.0000 | — | — |
| PHASE 9 — Cancellation paths | flight | system | critical | flight: Create→Debt→Cancel — duplicate transactions | No duplicate (type,amount,currency) tuples | Duplicates found: [{"type":"transfer","amount":"1000.00","cu | 0.0000 | — | — |

## 12. Duplicate Transaction Findings

22 duplicate-transaction findings:

| Phase | Module | Role | Severity | Scenario | Expected | Actual | Δ EGP | Tx IDs | Root Cause |
|---|---|---|---|---|---|---|---:|---|---|
| PHASE 9 — Cancellation paths | flight | system | critical | flight: Create→Debt→Cancel — duplicate transactions | No duplicate (type,amount,currency) tuples | Duplicates found: [{"type":"transfer","amount":"1000.00","cu | 0.0000 | — | — |
| PHASE 15 — Reconciliation Sweep | flight | system | critical | Recon: Booking #52 (flight) — no duplicate transactions | No (type, amount, currency) duplicates | 1 duplicate group(s) detected | 0.0000 | — | Duplicate transactions on flight booking #52 |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #52 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"100.00", | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #53 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"500.00", | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #54 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"999.99", | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #55 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #56 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"2500.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #57 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"5000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #58 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"10000.00 | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #59 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"25000.00 | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #60 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #61 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"5000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #62 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"10000.00 | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #63 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1500.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #82 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #83 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #84 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #86 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #89 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #90 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #92 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |
| PHASE 17 — Transaction Duplication | flight | system | critical | Duplicate transactions: flight booking #101 | Each (type, amount, currency) tuple appears at most once | 1 duplicate group(s): [{"type":"transfer","amount":"1000.00" | 0.0000 | — | — |

## 13. Balance Reconciliation

73 reconciliation failures (deltas > 0.005 EGP):

| Phase | Module | Role | Severity | Scenario | Expected | Actual | Δ EGP | Tx IDs | Root Cause |
|---|---|---|---|---|---|---|---:|---|---|
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 100 cust after 60% | balance=40.00, computed=0.00, diff<0.005 | drift=40.0000 EGP | 40.0000 | — | Account #930 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 100 cashbox after 60% | balance=44,360.00, computed=0.00, diff<0.005 | drift=44,360.0000 EGP | 44,360.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 100 cashbox after 100% | balance=44,400.00, computed=0.00, diff<0.005 | drift=44,400.0000 EGP | 44,400.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 100 cust post-delete | delta=0.00 | actual_delta=-100.00, drift=100.0000 | 100.0000 | — | Account #930 expected delta of 0 but got -100 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 500 cust after 60% | balance=200.00, computed=0.00, diff<0.005 | drift=200.0000 EGP | 200.0000 | — | Account #931 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 500 cashbox after 60% | balance=44,600.00, computed=0.00, diff<0.005 | drift=44,600.0000 EGP | 44,600.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 500 cashbox after 100% | balance=44,800.00, computed=0.00, diff<0.005 | drift=44,800.0000 EGP | 44,800.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 500 cust post-delete | delta=0.00 | actual_delta=-500.00, drift=500.0000 | 500.0000 | — | Account #931 expected delta of 0 but got -500 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 999.99 cust after 60% | balance=400.00, computed=0.00, diff<0.005 | drift=400.0000 EGP | 400.0000 | — | Account #932 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 999.99 cashbox after 60% | balance=44,899.99, computed=0.00, diff<0.005 | drift=44,899.9900 EGP | 44,899.9900 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 999.99 cashbox after 100% | balance=45,299.99, computed=0.00, diff<0.005 | drift=45,299.9900 EGP | 45,299.9900 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 999.99 cust post-delete | delta=0.00 | actual_delta=-999.99, drift=999.9900 | 999.9900 | — | Account #932 expected delta of 0 but got -999.99 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 1000 cust after 60% | balance=400.00, computed=0.00, diff<0.005 | drift=400.0000 EGP | 400.0000 | — | Account #933 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 1000 cashbox after 60% | balance=44,900.00, computed=0.00, diff<0.005 | drift=44,900.0000 EGP | 44,900.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 1000 cashbox after 100% | balance=45,300.00, computed=0.00, diff<0.005 | drift=45,300.0000 EGP | 45,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 1000 cust post-delete | delta=0.00 | actual_delta=-1,000.00, drift=1,000.0000 | 1,000.0000 | — | Account #933 expected delta of 0 but got -1000 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 2500 cust after 60% | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #934 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 2500 cashbox after 60% | balance=45,800.00, computed=0.00, diff<0.005 | drift=45,800.0000 EGP | 45,800.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 2500 cashbox after 100% | balance=46,800.00, computed=0.00, diff<0.005 | drift=46,800.0000 EGP | 46,800.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 2500 cust post-delete | delta=0.00 | actual_delta=-2,500.00, drift=2,500.0000 | 2,500.0000 | — | Account #934 expected delta of 0 but got -2500 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 5000 cust after 60% | balance=2,000.00, computed=0.00, diff<0.005 | drift=2,000.0000 EGP | 2,000.0000 | — | Account #935 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 5000 cashbox after 60% | balance=47,300.00, computed=0.00, diff<0.005 | drift=47,300.0000 EGP | 47,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 5000 cashbox after 100% | balance=49,300.00, computed=0.00, diff<0.005 | drift=49,300.0000 EGP | 49,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 5000 cust post-delete | delta=0.00 | actual_delta=-5,000.00, drift=5,000.0000 | 5,000.0000 | — | Account #935 expected delta of 0 but got -5000 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 10000 cust after 60% | balance=4,000.00, computed=0.00, diff<0.005 | drift=4,000.0000 EGP | 4,000.0000 | — | Account #936 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 10000 cashbox after 60% | balance=50,300.00, computed=0.00, diff<0.005 | drift=50,300.0000 EGP | 50,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 10000 cashbox after 100% | balance=54,300.00, computed=0.00, diff<0.005 | drift=54,300.0000 EGP | 54,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 10000 cust post-delete | delta=0.00 | actual_delta=-10,000.00, drift=10,000.0000 | 10,000.0000 | — | Account #936 expected delta of 0 but got -10000 |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 25000 cust after 60% | balance=10,000.00, computed=0.00, diff<0.005 | drift=10,000.0000 EGP | 10,000.0000 | — | Account #937 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 25000 cashbox after 60% | balance=59,300.00, computed=0.00, diff<0.005 | drift=59,300.0000 EGP | 59,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Account invariant: flight emp journey @ 25000 cashbox after 100% | balance=69,300.00, computed=0.00, diff<0.005 | drift=69,300.0000 EGP | 69,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 2 — Employee Journey | cross | system | critical | Balance delta: flight emp journey @ 25000 cust post-delete | delta=0.00 | actual_delta=-25,000.00, drift=25,000.0000 | 25,000.0000 | — | Account #937 expected delta of 0 but got -25000 |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 1000 cashbox paid | balance=45,300.00, computed=0.00, diff<0.005 | drift=45,300.0000 EGP | 45,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 1000 cashbox post-cancel | balance=45,300.00, computed=0.00, diff<0.005 | drift=45,300.0000 EGP | 45,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | cross | system | critical | Balance delta: flight admin @ 1000 cust post-delete | delta=0.00 | actual_delta=-1,000.00, drift=1,000.0000 | 1,000.0000 | — | Account #954 expected delta of 0 but got -1000 |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 5000 cashbox paid | balance=49,300.00, computed=0.00, diff<0.005 | drift=49,300.0000 EGP | 49,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 5000 cashbox post-cancel | balance=49,300.00, computed=0.00, diff<0.005 | drift=49,300.0000 EGP | 49,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | cross | system | critical | Balance delta: flight admin @ 5000 cust post-delete | delta=0.00 | actual_delta=-5,000.00, drift=5,000.0000 | 5,000.0000 | — | Account #955 expected delta of 0 but got -5000 |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 10000 cashbox paid | balance=54,300.00, computed=0.00, diff<0.005 | drift=54,300.0000 EGP | 54,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | cross | system | critical | Account invariant: flight admin @ 10000 cashbox post-cancel | balance=54,300.00, computed=0.00, diff<0.005 | drift=54,300.0000 EGP | 54,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 3 — Admin Journey | cross | system | critical | Balance delta: flight admin @ 10000 cust post-delete | delta=0.00 | actual_delta=-10,000.00, drift=10,000.0000 | 10,000.0000 | — | Account #956 expected delta of 0 but got -10000 |
| PHASE 4 — Payment Matrix | cross | system | critical | Account invariant: flight matrix A cashbox invariant | balance=45,300.00, computed=0.00, diff<0.005 | drift=45,300.0000 EGP | 45,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 4 — Payment Matrix | cross | system | critical | Account invariant: flight matrix A wallet invariant | balance=3,126.25, computed=0.00, diff<0.005 | drift=3,126.2500 EGP | 3,126.2500 | — | Account #156 balance does not match entries SUM |
| PHASE 5 — Debt | cross | system | critical | Account invariant: flight debt step 1 (cash 2000) cust invariant | balance=3,000.00, computed=0.00, diff<0.005 | drift=3,000.0000 EGP | 3,000.0000 | — | Account #979 balance does not match entries SUM |
| PHASE 5 — Debt | cross | system | critical | Account invariant: flight debt step 1 (cash 2000) treasury invariant | balance=48,300.00, computed=0.00, diff<0.005 | drift=48,300.0000 EGP | 48,300.0000 | — | Account #6 balance does not match entries SUM |
| PHASE 10 — Soft-delete & balance restoration | cross | system | critical | Balance delta: flight delete-after-pay restores cashbox | delta=0.00 | actual_delta=-1,000.00, drift=1,000.0000 | 1,000.0000 | — | Account #6 expected delta of 0 but got -1000 |
| PHASE 15 — Reconciliation Sweep | cross | system | critical | Account invariant: Booking #52 (flight) — account #818 | balance=-77,000.00, computed=0.00, diff<0.005 | drift=77,000.0000 EGP | 77,000.0000 | — | Account #818 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #769 account #967 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #967 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #770 account #968 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #968 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #771 account #969 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #969 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #772 account #970 | balance=4,000.00, computed=0.00, diff<0.005 | drift=4,000.0000 EGP | 4,000.0000 | — | Account #970 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #781 account #979 | balance=3,000.00, computed=0.00, diff<0.005 | drift=3,000.0000 EGP | 3,000.0000 | — | Account #979 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #784 account #982 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #982 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #785 account #983 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #983 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #786 account #984 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #984 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #787 account #985 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #985 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #788 account #986 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #986 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #789 account #987 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #987 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #790 account #988 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #988 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #791 account #989 | balance=800.00, computed=0.00, diff<0.005 | drift=800.0000 EGP | 800.0000 | — | Account #989 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #792 account #990 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #990 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #793 account #991 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #991 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #794 account #992 | balance=900.00, computed=0.00, diff<0.005 | drift=900.0000 EGP | 900.0000 | — | Account #992 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #795 account #993 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #993 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #802 account #1000 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #1000 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #805 account #1003 | balance=600.00, computed=0.00, diff<0.005 | drift=600.0000 EGP | 600.0000 | — | Account #1003 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #811 account #1009 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #1009 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #813 account #1011 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #1011 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #819 account #1017 | balance=500.00, computed=0.00, diff<0.005 | drift=500.0000 EGP | 500.0000 | — | Account #1017 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #820 account #1018 | balance=500.00, computed=0.00, diff<0.005 | drift=500.0000 EGP | 500.0000 | — | Account #1018 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #821 account #1019 | balance=750.00, computed=0.00, diff<0.005 | drift=750.0000 EGP | 750.0000 | — | Account #1019 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #822 account #1020 | balance=700.00, computed=0.00, diff<0.005 | drift=700.0000 EGP | 700.0000 | — | Account #1020 balance does not match entries SUM |
| PHASE 18 — Report Consistency | cross | system | critical | Account invariant: customer #833 account #1031 | balance=1,000.00, computed=0.00, diff<0.005 | drift=1,000.0000 EGP | 1,000.0000 | — | Account #1031 balance does not match entries SUM |

## 14. Final Decision

### 14a. Edit Lock Matrix

| Module | Employee | Admin |
|---|---|---|
| Flight | ⚪ NOT TESTED | ⚪ NOT TESTED |
| Flight | ⚪ NOT TESTED | ⚪ NOT TESTED |
| Hajj_umra | ⚪ NOT TESTED | ⚪ NOT TESTED |
| Hajj_umra | ⚪ NOT TESTED | ⚪ NOT TESTED |
| Visa | ⚪ NOT TESTED | ⚪ NOT TESTED |
| Visa | ⚪ NOT TESTED | ⚪ NOT TESTED |

### 14b. Financial Reconciliation by Module

| Module | Max Δ EGP | NO-GO findings |
|---|---|---:|
| Flight | 77,000.0000 EGP | 62 ❌ |
| Hajj_umra | 77,000.0000 EGP | 62 ❌ |
| Visa | 77,000.0000 EGP | 62 ❌ |

### 14c. Refund Tests

| Test type | Status |
|---|---|
| Full refund | ❌ FAIL (4 finding(s)) |
| Partial refund | ❌ FAIL (1 finding(s)) |
| Repeated refund | ✅ PASS |

### 14d. Lifecycle Tests

| Aspect | Status |
|---|---|
| Cancellation | ✅ PASS |
| Debt | ❌ FAIL (7) |
| Soft delete / release | ✅ PASS |
| Duplicate transactions | ❌ FAIL (22) |

### 14e. Duplicate Transaction Count

Duplicate (type, amount, currency) groups detected: **22**

### FINAL VERDICT: NO-GO ❌

The Tourism division is **NOT** production-ready. The following must be resolved before deployment:

- [PHASE 0 — Safety] cross / system — **Prerequisite: tourism vault account (module_type=tourism, is_module_vault=1)** (Δ 0.00 EGP): At least 1 active tourism cashbox vault per AccountModuleContract → 0 accounts match — no tourism cashbox vault exists in DB
- [PHASE 0 — Safety] cross / system — **Prerequisite: employees.email column** (Δ 0.00 EGP): employees.email exists (model declares it in #[Fillable]) → employees.email column MISSING — Employee::firstOrCreate([email=>...]) would fail
- [PHASE 0 — Safety] cross / system — **Prerequisite: users.employee_id column** (Δ 0.00 EGP): users.employee_id exists (or no expectation of User↔Employee link) → users.employee_id column MISSING — User cannot link to Employee
- [PHASE 0 — Safety] cross / system — **Prerequisite: audit_logs expected columns** (Δ 0.00 EGP): audit_logs.actor_name + audit_logs.description exist → Missing columns: actor_name, description
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 100 cust after 60%** (Δ 40.00 EGP): balance=40.00, computed=0.00, diff<0.005 → drift=40.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 100 cashbox after 60%** (Δ 44,360.00 EGP): balance=44,360.00, computed=0.00, diff<0.005 → drift=44,360.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 100 cashbox after 100%** (Δ 44,400.00 EGP): balance=44,400.00, computed=0.00, diff<0.005 → drift=44,400.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Balance delta: flight emp journey @ 100 cust post-delete** (Δ 100.00 EGP): delta=0.00 → actual_delta=-100.00, drift=100.0000
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 500 cust after 60%** (Δ 200.00 EGP): balance=200.00, computed=0.00, diff<0.005 → drift=200.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 500 cashbox after 60%** (Δ 44,600.00 EGP): balance=44,600.00, computed=0.00, diff<0.005 → drift=44,600.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 500 cashbox after 100%** (Δ 44,800.00 EGP): balance=44,800.00, computed=0.00, diff<0.005 → drift=44,800.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Balance delta: flight emp journey @ 500 cust post-delete** (Δ 500.00 EGP): delta=0.00 → actual_delta=-500.00, drift=500.0000
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 999.99 cust after 60%** (Δ 400.00 EGP): balance=400.00, computed=0.00, diff<0.005 → drift=400.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 999.99 cashbox after 60%** (Δ 44,899.99 EGP): balance=44,899.99, computed=0.00, diff<0.005 → drift=44,899.9900 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 999.99 cashbox after 100%** (Δ 45,299.99 EGP): balance=45,299.99, computed=0.00, diff<0.005 → drift=45,299.9900 EGP
- [PHASE 2 — Employee Journey] cross / system — **Balance delta: flight emp journey @ 999.99 cust post-delete** (Δ 999.99 EGP): delta=0.00 → actual_delta=-999.99, drift=999.9900
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 1000 cust after 60%** (Δ 400.00 EGP): balance=400.00, computed=0.00, diff<0.005 → drift=400.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 1000 cashbox after 60%** (Δ 44,900.00 EGP): balance=44,900.00, computed=0.00, diff<0.005 → drift=44,900.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 1000 cashbox after 100%** (Δ 45,300.00 EGP): balance=45,300.00, computed=0.00, diff<0.005 → drift=45,300.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Balance delta: flight emp journey @ 1000 cust post-delete** (Δ 1,000.00 EGP): delta=0.00 → actual_delta=-1,000.00, drift=1,000.0000
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 2500 cust after 60%** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 2500 cashbox after 60%** (Δ 45,800.00 EGP): balance=45,800.00, computed=0.00, diff<0.005 → drift=45,800.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 2500 cashbox after 100%** (Δ 46,800.00 EGP): balance=46,800.00, computed=0.00, diff<0.005 → drift=46,800.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Balance delta: flight emp journey @ 2500 cust post-delete** (Δ 2,500.00 EGP): delta=0.00 → actual_delta=-2,500.00, drift=2,500.0000
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 5000 cust after 60%** (Δ 2,000.00 EGP): balance=2,000.00, computed=0.00, diff<0.005 → drift=2,000.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 5000 cashbox after 60%** (Δ 47,300.00 EGP): balance=47,300.00, computed=0.00, diff<0.005 → drift=47,300.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 5000 cashbox after 100%** (Δ 49,300.00 EGP): balance=49,300.00, computed=0.00, diff<0.005 → drift=49,300.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Balance delta: flight emp journey @ 5000 cust post-delete** (Δ 5,000.00 EGP): delta=0.00 → actual_delta=-5,000.00, drift=5,000.0000
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 10000 cust after 60%** (Δ 4,000.00 EGP): balance=4,000.00, computed=0.00, diff<0.005 → drift=4,000.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 10000 cashbox after 60%** (Δ 50,300.00 EGP): balance=50,300.00, computed=0.00, diff<0.005 → drift=50,300.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 10000 cashbox after 100%** (Δ 54,300.00 EGP): balance=54,300.00, computed=0.00, diff<0.005 → drift=54,300.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Balance delta: flight emp journey @ 10000 cust post-delete** (Δ 10,000.00 EGP): delta=0.00 → actual_delta=-10,000.00, drift=10,000.0000
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 25000 cust after 60%** (Δ 10,000.00 EGP): balance=10,000.00, computed=0.00, diff<0.005 → drift=10,000.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 25000 cashbox after 60%** (Δ 59,300.00 EGP): balance=59,300.00, computed=0.00, diff<0.005 → drift=59,300.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Account invariant: flight emp journey @ 25000 cashbox after 100%** (Δ 69,300.00 EGP): balance=69,300.00, computed=0.00, diff<0.005 → drift=69,300.0000 EGP
- [PHASE 2 — Employee Journey] cross / system — **Balance delta: flight emp journey @ 25000 cust post-delete** (Δ 25,000.00 EGP): delta=0.00 → actual_delta=-25,000.00, drift=25,000.0000
- [PHASE 2 — Employee Journey] hajj_umra / system — **hajj emp journey @ 100** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 2 — Employee Journey] hajj_umra / system — **hajj emp journey @ 500** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 2 — Employee Journey] hajj_umra / system — **hajj emp journey @ 999.99** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 2 — Employee Journey] hajj_umra / system — **hajj emp journey @ 1000** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 2 — Employee Journey] hajj_umra / system — **hajj emp journey @ 2500** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 2 — Employee Journey] hajj_umra / system — **hajj emp journey @ 5000** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 2 — Employee Journey] hajj_umra / system — **hajj emp journey @ 10000** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 2 — Employee Journey] hajj_umra / system — **hajj emp journey @ 25000** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 2 — Employee Journey] visa / system — **visa emp journey @ 100** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:43, 2026-08-18 14:53:43))
- [PHASE 2 — Employee Journey] visa / system — **visa emp journey @ 500** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:43, 2026-08-18 14:53:43))
- [PHASE 2 — Employee Journey] visa / system — **visa emp journey @ 999.99** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:43, 2026-08-18 14:53:43))
- [PHASE 2 — Employee Journey] visa / system — **visa emp journey @ 1000** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:43, 2026-08-18 14:53:43))
- [PHASE 2 — Employee Journey] visa / system — **visa emp journey @ 2500** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:43, 2026-08-18 14:53:43))
- [PHASE 2 — Employee Journey] visa / system — **visa emp journey @ 5000** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:43, 2026-08-18 14:53:43))
- [PHASE 2 — Employee Journey] visa / system — **visa emp journey @ 10000** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:43, 2026-08-18 14:53:43))
- [PHASE 2 — Employee Journey] visa / system — **visa emp journey @ 25000** (Δ 0.00 EGP): employee workflow navigable end-to-end → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:43, 2026-08-18 14:53:43))
- [PHASE 3 — Admin Journey] cross / system — **Account invariant: flight admin @ 1000 cashbox paid** (Δ 45,300.00 EGP): balance=45,300.00, computed=0.00, diff<0.005 → drift=45,300.0000 EGP
- [PHASE 3 — Admin Journey] flight / system — **flight admin @ 1000 cancel** (Δ 0.00 EGP): admin can cancel → exception: فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود مبلغ مرتجع للعميل.
- [PHASE 3 — Admin Journey] cross / system — **Account invariant: flight admin @ 1000 cashbox post-cancel** (Δ 45,300.00 EGP): balance=45,300.00, computed=0.00, diff<0.005 → drift=45,300.0000 EGP
- [PHASE 3 — Admin Journey] cross / system — **Balance delta: flight admin @ 1000 cust post-delete** (Δ 1,000.00 EGP): delta=0.00 → actual_delta=-1,000.00, drift=1,000.0000
- [PHASE 3 — Admin Journey] cross / system — **Account invariant: flight admin @ 5000 cashbox paid** (Δ 49,300.00 EGP): balance=49,300.00, computed=0.00, diff<0.005 → drift=49,300.0000 EGP
- [PHASE 3 — Admin Journey] flight / system — **flight admin @ 5000 cancel** (Δ 0.00 EGP): admin can cancel → exception: فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود مبلغ مرتجع للعميل.
- [PHASE 3 — Admin Journey] cross / system — **Account invariant: flight admin @ 5000 cashbox post-cancel** (Δ 49,300.00 EGP): balance=49,300.00, computed=0.00, diff<0.005 → drift=49,300.0000 EGP
- [PHASE 3 — Admin Journey] cross / system — **Balance delta: flight admin @ 5000 cust post-delete** (Δ 5,000.00 EGP): delta=0.00 → actual_delta=-5,000.00, drift=5,000.0000
- [PHASE 3 — Admin Journey] cross / system — **Account invariant: flight admin @ 10000 cashbox paid** (Δ 54,300.00 EGP): balance=54,300.00, computed=0.00, diff<0.005 → drift=54,300.0000 EGP
- [PHASE 3 — Admin Journey] flight / system — **flight admin @ 10000 cancel** (Δ 0.00 EGP): admin can cancel → exception: فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود مبلغ مرتجع للعميل.
- [PHASE 3 — Admin Journey] cross / system — **Account invariant: flight admin @ 10000 cashbox post-cancel** (Δ 54,300.00 EGP): balance=54,300.00, computed=0.00, diff<0.005 → drift=54,300.0000 EGP
- [PHASE 3 — Admin Journey] cross / system — **Balance delta: flight admin @ 10000 cust post-delete** (Δ 10,000.00 EGP): delta=0.00 → actual_delta=-10,000.00, drift=10,000.0000
- [PHASE 3 — Admin Journey] hajj_umra / admin — **hajj admin @ 1000** (Δ 0.00 EGP): admin workflow navigable end-to-end → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 3 — Admin Journey] hajj_umra / admin — **hajj admin @ 5000** (Δ 0.00 EGP): admin workflow navigable end-to-end → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 3 — Admin Journey] hajj_umra / admin — **hajj admin @ 10000** (Δ 0.00 EGP): admin workflow navigable end-to-end → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 3 — Admin Journey] visa / admin — **visa admin @ 1000** (Δ 0.00 EGP): admin workflow navigable end-to-end → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:44, 2026-08-18 14:53:44))
- [PHASE 3 — Admin Journey] visa / admin — **visa admin @ 5000** (Δ 0.00 EGP): admin workflow navigable end-to-end → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:44, 2026-08-18 14:53:44))
- [PHASE 3 — Admin Journey] visa / admin — **visa admin @ 10000** (Δ 0.00 EGP): admin workflow navigable end-to-end → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:44, 2026-08-18 14:53:44))
- [PHASE 3 — Admin Journey] hajj_umra / system — **No-Edit contract: Hajj LOCKED_FIELDS via HTTP** (Δ 0.00 EGP): request dispatched → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 3 — Admin Journey] visa / system — **No-Edit contract: Visa update setup** (Δ 0.00 EGP): scenario constructible → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:44, 2026-08-18 14:53:44))
- [PHASE 4 — Payment Matrix] cross / system — **Account invariant: flight matrix A cashbox invariant** (Δ 45,300.00 EGP): balance=45,300.00, computed=0.00, diff<0.005 → drift=45,300.0000 EGP
- [PHASE 4 — Payment Matrix] cross / system — **Account invariant: flight matrix A wallet invariant** (Δ 3,126.25 EGP): balance=3,126.25, computed=0.00, diff<0.005 → drift=3,126.2500 EGP
- [PHASE 4 — Payment Matrix] flight / system — **flight matrix B** (Δ 0.00 EGP): payment matrix scenario completes → exception: SQLSTATE[01000]: Warning: 1265 Data truncated for column 'payment_method' at row 1 (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `flight_payments` (`flight_booking_id`, `amount`, `original_amount`, `payment_method`, `currency`, `treasury_account`, `account_id`, `idempotency_key`, `transaction_id`, `payment_date`, `paid_by`, `created_by`, `notes`, `updated_at`, `created_at`) values (65, 1000, 1000, wallet, EGP, 156|WL_EGP_Vodafone, 156, ?, ?, 2026-08-18 14:53:45, TOURISM_FULL_AUDIT_20260818_employee, 27, ?, 2026-08-18 14:53:45, 2026-08-18 14:53:45))
- [PHASE 4 — Payment Matrix] flight / system — **flight matrix C** (Δ 0.00 EGP): payment matrix scenario completes → exception: SQLSTATE[01000]: Warning: 1265 Data truncated for column 'payment_method' at row 1 (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `flight_payments` (`flight_booking_id`, `amount`, `original_amount`, `payment_method`, `currency`, `treasury_account`, `account_id`, `idempotency_key`, `transaction_id`, `payment_date`, `paid_by`, `created_by`, `notes`, `updated_at`, `created_at`) values (66, 400, 400, wallet, EGP, 156|WL_EGP_Vodafone, 156, ?, ?, 2026-08-18 14:53:45, TOURISM_FULL_AUDIT_20260818_employee, 27, ?, 2026-08-18 14:53:45, 2026-08-18 14:53:45))
- [PHASE 4 — Payment Matrix] flight / system — **flight matrix D** (Δ 0.00 EGP): payment matrix scenario completes → exception: SQLSTATE[01000]: Warning: 1265 Data truncated for column 'payment_method' at row 1 (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `flight_payments` (`flight_booking_id`, `amount`, `original_amount`, `payment_method`, `currency`, `treasury_account`, `account_id`, `idempotency_key`, `transaction_id`, `payment_date`, `paid_by`, `created_by`, `notes`, `updated_at`, `created_at`) values (68, 750, 750, wallet, EGP, 156|WL_EGP_Vodafone, 156, ?, ?, 2026-08-18 14:53:45, TOURISM_FULL_AUDIT_20260818_employee, 27, ?, 2026-08-18 14:53:45, 2026-08-18 14:53:45))
- [PHASE 4 — Payment Matrix] hajj_umra / system — **hajj matrix A** (Δ 0.00 EGP): payment matrix scenario completes → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 4 — Payment Matrix] hajj_umra / system — **hajj matrix B** (Δ 0.00 EGP): payment matrix scenario completes → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 4 — Payment Matrix] hajj_umra / system — **hajj matrix C** (Δ 0.00 EGP): payment matrix scenario completes → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 4 — Payment Matrix] hajj_umra / system — **hajj matrix D** (Δ 0.00 EGP): payment matrix scenario completes → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 4 — Payment Matrix] visa / system — **visa matrix A** (Δ 0.00 EGP): payment matrix scenario completes → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:45, 2026-08-18 14:53:45))
- [PHASE 4 — Payment Matrix] visa / system — **visa matrix B** (Δ 0.00 EGP): payment matrix scenario completes → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:45, 2026-08-18 14:53:45))
- [PHASE 4 — Payment Matrix] visa / system — **visa matrix C** (Δ 0.00 EGP): payment matrix scenario completes → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:45, 2026-08-18 14:53:45))
- [PHASE 4 — Payment Matrix] visa / system — **visa matrix D** (Δ 0.00 EGP): payment matrix scenario completes → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:45, 2026-08-18 14:53:45))
- [PHASE 5 — Debt] flight / system — **Zero EGP diff: flight debt step 1 (cash 2000) debt = total - paid** (Δ 5,000.00 EGP): value=3,000.0000 → value=-2,000.0000, diff=5,000.0000
- [PHASE 5 — Debt] cross / system — **Account invariant: flight debt step 1 (cash 2000) cust invariant** (Δ 3,000.00 EGP): balance=3,000.00, computed=0.00, diff<0.005 → drift=3,000.0000 EGP
- [PHASE 5 — Debt] cross / system — **Account invariant: flight debt step 1 (cash 2000) treasury invariant** (Δ 48,300.00 EGP): balance=48,300.00, computed=0.00, diff<0.005 → drift=48,300.0000 EGP
- [PHASE 5 — Debt] flight / system — **flight debt** (Δ 0.00 EGP): debt lifecycle completes → exception: SQLSTATE[01000]: Warning: 1265 Data truncated for column 'payment_method' at row 1 (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `flight_payments` (`flight_booking_id`, `amount`, `original_amount`, `payment_method`, `currency`, `treasury_account`, `account_id`, `idempotency_key`, `transaction_id`, `payment_date`, `paid_by`, `created_by`, `notes`, `updated_at`, `created_at`) values (69, 1000, 1000, wallet, EGP, 156|WL_EGP_Vodafone, 156, ?, ?, 2026-08-18 14:53:46, TOURISM_FULL_AUDIT_20260818_employee, 28, ?, 2026-08-18 14:53:46, 2026-08-18 14:53:46))
- [PHASE 5 — Debt] hajj_umra / system — **hajj debt** (Δ 0.00 EGP): debt lifecycle completes → exception: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 5 — Debt] visa / system — **visa debt** (Δ 0.00 EGP): debt lifecycle completes → exception: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:46, 2026-08-18 14:53:46))
- [PHASE 6 — Invalid payment rejection] flight / system — **flight: payment against another customer's booking** (Δ 0.00 EGP): Rejection (exception or validation error) → Payment accepted — money mutated
- [PHASE 7 — Refund happy path] flight / system — **flight: RefundAuditLog row created for full refund** (Δ 0.00 EGP): refund_audit_logs row exists → Not found
- [PHASE 7 — Refund happy path] flight / system — **flight: full refund happy path** (Δ 0.00 EGP): Refund + audit rows created → SQLSTATE[42S22]: Column not found: 1054 Unknown column 'related_id' in 'where clause' (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: select exists(select * from `audit_logs` where `action` like refund.% and `related_id` = 82) as `exists`)
- [PHASE 7 — Refund happy path] flight / system — **flight: partial refund cumulative path** (Δ 0.00 EGP): Two sequential refunds succeed → هذا الحجز تم استرداده بالكامل مسبقاً ولا يمكن إصدار طلب استرجاع جديد له.
- [PHASE 7 — Refund happy path] hajj_umra / system — **hajj_umra: full refund happy path** (Δ 0.00 EGP): Refund + audit rows created → لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 7 — Refund happy path] visa / system — **visa: full refund happy path** (Δ 0.00 EGP): Refund + audit rows created → SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:48, 2026-08-18 14:53:48))
- [PHASE 9 — Cancellation paths] flight / system — **flight: Create→Pay→Cancel happy path** (Δ 0.00 EGP): Reconciled → فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود مبلغ مرتجع للعميل.
- [PHASE 9 — Cancellation paths] flight / system — **flight: Create→Partial→Cancel happy path** (Δ 0.00 EGP): Reconciled → فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود مبلغ مرتجع للعميل.
- [PHASE 9 — Cancellation paths] flight / system — **flight: Create→Debt→Cancel — duplicate transactions** (Δ 0.00 EGP): No duplicate (type,amount,currency) tuples → Duplicates found: [{"type":"transfer","amount":"1000.00","currency":"EGP","cnt":2}]
- [PHASE 10 — Soft-delete & balance restoration] cross / system — **Balance delta: flight delete-after-pay restores cashbox** (Δ 1,000.00 EGP): delta=0.00 → actual_delta=-1,000.00, drift=1,000.0000
- [PHASE 10 — Soft-delete & balance restoration] flight / system — **flight: cancel after delete** (Δ 0.00 EGP): Rejection → Operation accepted
- [PHASE 12 — Pre-Completion Edit Lock] cross / system — **Hajj edit lock** (Δ 0.00 EGP): Test should run → BLOCKED: Could not seed Hajj booking: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 12 — Pre-Completion Edit Lock] cross / system — **Visa edit lock** (Δ 0.00 EGP): Test should run → BLOCKED: Could not seed Visa booking: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:51, 2026-08-18 14:53:51))
- [PHASE 13 — Concurrency & Idempotency] cross / system — **flight double-click refund** (Δ 0.00 EGP): Test should run → BLOCKED: لا يمكن إصدار طلب استرجاع لحجز بحالة 'PENDING'. يجب أن يكون الحجز مؤكداً على الأقل.
- [PHASE 13 — Concurrency & Idempotency] cross / system — **flight refund-then-cancel** (Δ 0.00 EGP): Test should run → BLOCKED: لا يمكن إصدار طلب استرجاع لحجز بحالة 'PENDING'. يجب أن يكون الحجز مؤكداً على الأقل.
- [PHASE 13 — Concurrency & Idempotency] cross / system — **flight payment+refund** (Δ 0.00 EGP): Test should run → BLOCKED: لا يمكن إصدار طلب استرجاع لحجز بحالة 'PENDING'. يجب أن يكون الحجز مؤكداً على الأقل.
- [PHASE 13 — Concurrency & Idempotency] cross / system — **flight payment+cancel** (Δ 0.00 EGP): Test should run → BLOCKED: فشل إلغاء الحجز: يجب اختيار حساب الصرف عند وجود مبلغ مرتجع للعميل.
- [PHASE 13 — Concurrency & Idempotency] cross / system — **hajj_umra double-click payment** (Δ 0.00 EGP): Test should run → BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 13 — Concurrency & Idempotency] cross / system — **hajj_umra double-click refund** (Δ 0.00 EGP): Test should run → BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 13 — Concurrency & Idempotency] cross / system — **hajj_umra refund-then-cancel** (Δ 0.00 EGP): Test should run → BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 13 — Concurrency & Idempotency] cross / system — **hajj_umra payment+refund** (Δ 0.00 EGP): Test should run → BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 13 — Concurrency & Idempotency] cross / system — **hajj_umra payment+cancel** (Δ 0.00 EGP): Test should run → BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 13 — Concurrency & Idempotency] cross / system — **visa double-click payment** (Δ 0.00 EGP): Test should run → BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:52, 2026-08-18 14:53:52))
- [PHASE 13 — Concurrency & Idempotency] cross / system — **visa double-click refund** (Δ 0.00 EGP): Test should run → BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:52, 2026-08-18 14:53:52))
- [PHASE 13 — Concurrency & Idempotency] cross / system — **visa refund-then-cancel** (Δ 0.00 EGP): Test should run → BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:53, 2026-08-18 14:53:53))
- [PHASE 13 — Concurrency & Idempotency] cross / system — **visa payment+refund** (Δ 0.00 EGP): Test should run → BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:53, 2026-08-18 14:53:53))
- [PHASE 13 — Concurrency & Idempotency] cross / system — **visa payment+cancel** (Δ 0.00 EGP): Test should run → BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:53, 2026-08-18 14:53:53))
- [PHASE 14 — Cross-Module Attack] cross / system — **Cross-attack suite (hajj_umra)** (Δ 0.00 EGP): Test should run → BLOCKED: لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 14 — Cross-Module Attack] cross / system — **Cross-attack suite (visa)** (Δ 0.00 EGP): Test should run → BLOCKED: SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:53, 2026-08-18 14:53:53))
- [PHASE 15 — Reconciliation Sweep] flight / system — **Recon: Booking #52 (flight) — no duplicate transactions** (Δ 0.00 EGP): No (type, amount, currency) duplicates → 1 duplicate group(s) detected
- [PHASE 15 — Reconciliation Sweep] cross / system — **Account invariant: Booking #52 (flight) — account #818** (Δ 77,000.00 EGP): balance=-77,000.00, computed=0.00, diff<0.005 → drift=77,000.0000 EGP
- [PHASE 16 — Profit Recognition] flight / system — **Profit: flight booking #101 after_full_payment** (Δ 200.00 EGP): profit=200.0000 → profit=0.0000 diff=200.0000
- [PHASE 16 — Profit Recognition] flight / system — **Profit: flight booking #101 after_partial_refund** (Δ 300.00 EGP): profit=-300.0000 → profit=0.0000 diff=300.0000
- [PHASE 16 — Profit Recognition] hajj_umra / system — **Phase16 hajj_umra lifecycle exception** (Δ 0.00 EGP): Clean lifecycle → لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 16 — Profit Recognition] visa / system — **Phase16 visa lifecycle exception** (Δ 0.00 EGP): Clean lifecycle → SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:54, 2026-08-18 14:53:54))
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #52** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"100.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #53** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"500.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #54** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"999.99","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #55** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"1000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #56** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"2500.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #57** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"5000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #58** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"10000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #59** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"25000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #60** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"1000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #61** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"5000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #62** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"10000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #63** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"1500.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #82** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"1000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #83** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"1000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #84** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"1000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #86** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"1000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #89** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"1000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #90** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"1000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #92** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"1000.00","currency":"EGP","count":2}]
- [PHASE 17 — Transaction Duplication] flight / system — **Duplicate transactions: flight booking #101** (Δ 0.00 EGP): Each (type, amount, currency) tuple appears at most once → 1 duplicate group(s): [{"type":"transfer","amount":"1000.00","currency":"EGP","count":2}]
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #769 account #967** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #770 account #968** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #771 account #969** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #772 account #970** (Δ 4,000.00 EGP): balance=4,000.00, computed=0.00, diff<0.005 → drift=4,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #781 account #979** (Δ 3,000.00 EGP): balance=3,000.00, computed=0.00, diff<0.005 → drift=3,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #784 account #982** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #785 account #983** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #786 account #984** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #787 account #985** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #788 account #986** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #789 account #987** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #790 account #988** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #791 account #989** (Δ 800.00 EGP): balance=800.00, computed=0.00, diff<0.005 → drift=800.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #792 account #990** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #793 account #991** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #794 account #992** (Δ 900.00 EGP): balance=900.00, computed=0.00, diff<0.005 → drift=900.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #795 account #993** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #802 account #1000** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #805 account #1003** (Δ 600.00 EGP): balance=600.00, computed=0.00, diff<0.005 → drift=600.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #811 account #1009** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #813 account #1011** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #819 account #1017** (Δ 500.00 EGP): balance=500.00, computed=0.00, diff<0.005 → drift=500.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #820 account #1018** (Δ 500.00 EGP): balance=500.00, computed=0.00, diff<0.005 → drift=500.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #821 account #1019** (Δ 750.00 EGP): balance=750.00, computed=0.00, diff<0.005 → drift=750.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #822 account #1020** (Δ 700.00 EGP): balance=700.00, computed=0.00, diff<0.005 → drift=700.0000 EGP
- [PHASE 18 — Report Consistency] cross / system — **Account invariant: customer #833 account #1031** (Δ 1,000.00 EGP): balance=1,000.00, computed=0.00, diff<0.005 → drift=1,000.0000 EGP
- [PHASE 19 — Failure Injection & Atomicity] flight / system — **Phase 19 flight exercise — uncaught exception** (Δ 0.00 EGP): all sub-tests to run → SQLSTATE[42S22]: Column not found: 1054 Unknown column 'type' in 'field list' (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: select sum(CASE WHEN type = "credit" THEN amount WHEN type = "debit" THEN -amount ELSE 0 END) as aggregate from `account_entries` where `transaction_id` in (4507))
- [PHASE 19 — Failure Injection & Atomicity] hajj_umra / system — **Phase 19 hajj exercise — uncaught exception** (Δ 0.00 EGP): all sub-tests to run → لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.
- [PHASE 19 — Failure Injection & Atomicity] visa / system — **Phase 19 visa exercise — uncaught exception** (Δ 0.00 EGP): all sub-tests to run → SQLSTATE[HY000]: General error: 1364 Field 'label_ar' doesn't have a default value (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: safarakealayna, SQL: insert into `visa_durations` (`code`, `is_active`, `updated_at`, `created_at`) values (TOURISM_FULL_AUDIT_20260818_DUR, 1, 2026-08-18 14:53:55, 2026-08-18 14:53:55))

---

_Audit generated by `tourism_full_e2e_audit_20260818.php` against local MySQL `safarakealayna`._
