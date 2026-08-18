# Tourism Employee Refund — Implementation Plan

## High-level strategy (per user's answer to Q1)
**Audit existing artifacts first; keep if compliant; extend only where the prompt's spec requires more.** No rewrites for the sake of rewriting.

## Background — confirmed from prior session + git status
- Tourism **No-Edit Contract** is fully enforced (Route 405 + Service LogicException + Controllers) — 16/16 PASS ✓
- `app/Models/RefundAuditLog.php` exists
- `app/Services/Finance/RefundAuditLogger.php` exists
- `database/migrations/2026_08_17_120000_create_refund_audit_logs_table.php` exists
- `database/migrations/2026_08_17_120100_add_idempotency_key_to_refund_requests_table.php` exists
- `app/Support/UserPermissions.php` modified (per git status)

## Decisions (matching the prompt's hard rules)

### A. `manage_refunds` permission
- Define `UserPermissions::MANAGE_REFUNDS = 'manage_refunds'`
- Do **NOT** auto-add to `defaultEmployeeModules()` (per PHASE 2 critical rule)
- Refund routes get `middleware('permission:manage_refunds')` independently of module-access permission
- Use `restrictedEmployee` fixture (no `manage_refunds`) for 403 tests; `normalEmployee` for 200 tests

### B. Actor identity (PHASE 6 hard rule)
- Every refund Service receives `$actorUser` argument — no `Auth::id() ?: 1` anywhere
- Reject if `$actorUser` null at service boundary
- FormRequest blocks `user_id` / `performed_by` / `actor_id` / `employee_id` from payload
- Capture `user_id`, `user_name`, `ip_address`, `user_agent` from authenticated context

### C. Idempotency (PHASE 3 hard rule)
- **Flight**: enforce `flight_booking_id + idempotency_key` unique constraint (DB level). Pre-check for existing duplicate values in DB before applying the unique index. If duplicates exist and can't be auto-resolved, STOP and report.
- **Hajj/Umrah + Visa**: real idempotency, not invented. Will use:
  - FormRequest accepts optional `idempotency_key` (UUID).
  - RefundRequest row keyed by `idempotency_key` (already wired via existing migration).
  - If row exists → return original payload (no new financial effect).
  - Different keys = separate valid refund attempts; cumulative cap enforced.
- If Flight's existing migration was added without DB-level unique constraint, will harden it now (with pre-flight duplicate check).

### D. Atomicity (PHASE 5 hard rule)
- Financial mutation + `refund_audit_logs` + `audit_logs` all in **one** `DB::transaction()`. If any step fails → full ROLLBACK (no partial state).
- Existing `runProfitMutation` / `ModelProfitMutationGuard` / `runJournalTransfer` patterns reused for Flight (already used by `FlightRefundService` per prior session context).

### E. Audit events (PHASE 5 distinction)
- Audit log rows distinguish: `refund.requested` / `refund.processed` / `refund.reversed`
- Reversal is NOT a `processed` row — separate event type
- `account_entry_ids` cast to array
- Both `refund_audit_logs` AND `audit_logs` written in same transaction

### F. Routes (PHASE 8 hard rule)
- `manage_refunds` on **per-route** middleware, NOT on parent `/flight` / `/hajj-umra` / `/visa` — module access stays under existing module permission
- Admin-only operations (`cancel`, `delete`, `confirm`, `recharge`, `airline-account CRUD`, `refund reversal`) keep their existing guards — do NOT widen

### G. Frontend (PHASE 9 hard rule)
- Refund button on `HajjUmraShow.vue` + `VisaShow.vue` (hidden when cancelled/refunded, reason modal, POST to existing refund endpoint)
- `Flight RefundWizard.vue` — add idempotency_key support without breaking existing flow
- Frontend never sends `user_id` / `performed_by` / `actor_id` / `employee_id`
- Frontend permission is UX-only; backend 403 is authoritative

### H. No-Edit Contract invariant (PHASE 10 hard rule)
- The Tourism No-Edit Contract **must remain intact** — `TourismNoEditContractTest` 16/16 still PASS after this task
- Refund is **financial reversal**, never Edit-like behavior
- Never reopen Tourism booking financial fields

## Files that will likely be touched

### Backend
| File | Action |
|---|---|
| `routes/api.php` | Add `manage_refunds` middleware on POST refund routes (Flight/Hajj/Visa) |
| `app/Support/UserPermissions.php` | Add `MANAGE_REFUNDS` constant (per user's Option A answer) |
| `app/Http/Controllers/Api/V1/Flight/RefundController.php` | Inject actor, capture actor identity, reject payload overrides |
| `app/Http/Controllers/Api/V1/HajjUmraController.php` (refund action) | Same |
| `app/Http/Controllers/Api/V1/Visa/VisaBookingController.php` (refund action) | Same |
| `app/Services/Flight/RefundService.php` | `DB::transaction()` for mutation + audit; actor arg required; idempotency check first |
| `app/Services/HajjUmra/HajjUmraRefundService.php` | Same |
| `app/Services/Visa/VisaRefundService.php` | Same |
| `app/Models/RefundAuditLog.php` | Audit existing; extend if missing event-type column |
| `app/Services/Finance/RefundAuditLogger.php` | Audit existing; add `refund.requested / refund.processed / refund.reversed` distinction if missing |
| Migration(s) | Only HARDEN existing ones if audit shows gaps (e.g., add DB unique constraint to Flight with pre-flight duplicate check). No destroy/rebuild. |
| `tests/Feature/Online/OnlineTestCase.php` (or similar) | Add `EmployeeFixture` helper if not present |
| `tests/Feature/Flight/RefundDiagnosisTest.php` + related | Add new employee scenarios |
| `tests/Feature/HajjUmra/*RefundTest.php` | New + new employee tests |
| `tests/Feature/Visa/*RefundTest.php` | New + new employee tests |
| New tests | EmployeeRefundAuthorizationTest + EmployeeRefundIdempotencyTest + EmployeeRefundRollbackTest + EmployeeRefundCrossModuleIsolationTest |

### Frontend
| File | Action |
|---|---|
| `resources/js/views/hajjUmra/HajjUmraShow.vue` | Refund button + reason modal + POST + hide if cancelled/refunded |
| `resources/js/views/visa/VisaShow.vue` | Same |
| `resources/js/views/flights/RefundWizard.vue` (existing) | Add idempotency_key UUID generation without breaking |
| `resources/js/stores/*` (flight/hajjUmra/visa) | Add `refund()` action that does NOT send actor fields |

### Reporting
- Generate `tests/reports/TOURISM_EMPLOYEE_REFUND_AUDIT_20260817.md` + `.json` per PHASE 15
- Verdict: `GO` / `GO WITH WARNINGS` / `NO-GO` based on actual test evidence (not by declaration)

## Execution order
1. **PHASE 0** — env safety gate (read `APP_ENV`, `DB_CONNECTION`, `DB_DATABASE`)
2. **PHASE 1** — wait for Explore agents to finish, then read additional files as needed
3. **PHASE 2** — UserPermissions MANAGE_REFUNDS constant
4. **PHASE 3 / 4 / 5** — audit existing refund_audit_logs migration + RefundAuditLog model + RefundAuditLogger service; harden only gaps
5. **PHASE 6** — actor identity enforcement (FormRequest + Service signature)
6. **PHASE 7** — Refund Service refactor (3 modules)
7. **PHASE 8** — routes
8. **PHASE 9** — frontend
9. **PHASE 10** — re-run `TourismNoEditContractTest` to confirm No-Edit invariant
10. **PHASE 11 / 12 / 13** — write all required tests + run regression
11. **PHASE 14** — financial verification (independent SUM check)
12. **PHASE 15** — generate report with verdict based on actual evidence

## Production safety (FINAL HARD RULES — preserved)
- DO NOT touch production/staging
- DO NOT auto-grant `manage_refunds` to all employees
- DO NOT weaken tests to make them pass
- DO NOT delete failing tests merely because they fail
- DO NOT reintroduce Tourism Edit
- DO NOT trust actor identity from request payload
- DO NOT bypass existing financial services
- DO NOT write `GO` until implementation + tests prove it

## Definition of Done
Mirrors the prompt's full Definition of Done block. Implementation is complete **only** when all 11 categories of tests pass with real evidence in the report.

---

I'll proceed in this order, making one logical change at a time and testing after each. If the Explore agents return architectural conflicts, I'll STOP and report rather than apply workarounds (per prompt's hard rule).