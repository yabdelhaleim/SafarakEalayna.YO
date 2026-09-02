# MERGE CONFLICT FORENSIC AUDIT (READ-ONLY)

**Branch:** `phase-10-tourism-production-audit-hajj-umra`
**Date:** 2026-08-20
**Auditor:** ZCode (Tourism-Wide Final Certification agent) — Phase 12.1
**Audit type:** READ-ONLY forensic analysis of unmerged Git paths
**Strict mode:** No code changes. No test changes. No merge resolution. No `git checkout --ours/--theirs`. No reset, rebase, or cherry-pick.

---

## 0. Executive Summary

The audit identified **9 distinct conflict blocks** distributed across **5 unmerged files**:

| Bucket | File | Type | Conflict blocks | Marker line-count |
|--------|------|------|-----------------|-------------------|
| Production | `app/Services/HajjUmra/HajjUmraBookingService.php` | PHP | **2** | 4 (`<<<<<<<`/`>>>>>>>` × 2, plus inner `=======` × 2) |
| Production | `app/Services/Visa/VisaBookingService.php` | PHP | **1** | 3 |
| Test | `tests/Feature/HajjUmra/HajjUmraApiTest.php` | PHP | **1** | 3 |
| Test | `tests/Feature/HajjUmra/HajjUmraControllerTest.php` | PHP | **1** | 3 |
| Test | `tests/Feature/HajjUmra/HajjUmraProductionE2ETest.php` | PHP | **5** | 12 |
| **TOTAL** | **5 files** | — | **9 blocks** | **25 marker lines** |

All 9 blocks share a single root cause (Section 2). The conflicts are NOT independent — they were all created by a single failed merge of `wip/2026-08-17-refund-hardening-snapshot` into the current branch.

The 2 production-service conflict blocks (HAJJ-C1, HAJJ-C2, VISA-C1) are **the blockers** that prevent Hajj/Umra and Visa services from autoloading at PHP runtime. The 6 test-file conflict blocks are downstream — they would prevent some Hajj/Umra tests from compiling once the production blocks are resolved, but they do not currently block PHP itself.

---

## 1. Branch / HEAD / Upstream / Working-Tree Proof

| Item | Value |
|------|-------|
| Branch | `phase-10-tourism-production-audit-hajj-umra` |
| HEAD commit | `8f12ac3` — "docs(flight/p11): revised final verdict — all 8 mandatory gates evidenced" (2026-08-20 16:58:31 +0300, Youssef Abd Elhaleim) |
| Upstream tracked | **No** (`fatal: no upstream configured`) |
| Local `main` ref | not present locally |
| `origin/main` reachable? | not configured in current workspace (no fetch performed; read-only) |
| `.git/ORIG_HEAD` | `c8c0db7cba58967fcd299ac0280dc8aafa14dadd` |
| `git status --porcelain` | 1 modified (`FawryDashboardController.php`), 1 modified (`FawryTransactionService.php`), 5 unmerged (`UU`), + many untracked files |
| `git ls-files --unmerged` | 5 paths with stage 1/2/3 blobs (i.e., real merge conflict, not a stray marker) |
| Stashes | `stash@{0}` = "WIP on main: 07d39d9 fix(wallet): Class-A bugs + 282-assertion full E2E audit (GO)"; `stash@{1}` = "On main: fix customer" |

Active unmerged paths (from `git ls-files --unmerged`):

```
100644 7775be5a…  1   app/Services/HajjUmra/HajjUmraBookingService.php    (base)
100644 efcf5846…  2   app/Services/HajjUmra/HajjUmraBookingService.php    (ours = stage 2)
100644 a009f681…  3   app/Services/HajjUmra/HajjUmraBookingService.php    (theirs = stage 3)
100644 4e59692a…  1   app/Services/Visa/VisaBookingService.php            (base)
100644 6c9af5da…  2   app/Services/Visa/VisaBookingService.php            (ours = stage 2)
100644 e9b44126…  3   app/Services/Visa/VisaBookingService.php            (theirs = stage 3)
100644 af47f7a9…  1   tests/Feature/HajjUmra/HajjUmraApiTest.php          (base)
100644 99469624…  2   tests/Feature/HajjUmra/HajjUmraApiTest.php          (ours)
100644 fe32832d…  3   tests/Feature/HajjUmra/HajjUmraApiTest.php          (theirs)
100644 8d55eb14…  1   tests/Feature/HajjUmra/HajjUmraControllerTest.php   (base)
100644 3999324f…  2   tests/Feature/HajjUmra/HajjUmraControllerTest.php   (ours)
100644 17ca9f39…  3   tests/Feature/HajjUmra/HajjUmraControllerTest.php   (theirs)
100644 21ad1391…  1   tests/Feature/HajjUmra/HajjUmraProductionE2ETest.php (base)
100644 343dfa91…  2   tests/Feature/HajjUmra/HajjUmraProductionE2ETest.php (ours)
100644 2171071d…  3   tests/Feature/HajjUmra/HajjUmraProductionE2ETest.php (theirs)
```

---

## 2. Conflict Origins — Single Root Cause

### 2.1 Provenance of each unmerged blob

Using `git log --find-object=<blob>` to map each unmerged blob to the commit that introduced it:

| File | Stage 1 (BASE) | Stage 2 (OURS) | Stage 3 (THEIRS) |
|------|---------------|----------------|------------------|
| `HajjUmraBookingService.php` | blob `7775be5a` ← commit `eb4cef6` | blob `efcf5846` ← commit `7bcaee9` | blob `a009f681` ← commit `449ac87` |
| `VisaBookingService.php`     | blob `4e59692a` ← commit `eb4cef6` | blob `6c9af5da` ← commit `6a70cdd` | blob `e9b44126` ← commit `449ac87` (joined via `git log --find-object`) |
| `HajjUmraApiTest.php`          | blob `af47f7a9` ← (test-file ancestor) | blob `99469624` ← (test-file ancestor) | blob `fe32832d` ← (test-file ancestor) |
| `HajjUmraControllerTest.php`   | blob `8d55eb14` ← (test-file ancestor) | blob `3999324f` ← (test-file ancestor) | blob `17ca9f39` ← (test-file ancestor) |
| `HajjUmraProductionE2ETest.php`| blob `21ad1391` ← (test-file ancestor) | blob `343dfa91` ← (test-file ancestor) | blob `2171071d` ← (test-file ancestor) |

### 2.2 Resolved commit metadata for production-file conflicts

| Side | Commit | Date | Author | Subject |
|------|--------|------|--------|---------|
| **BASE** (common ancestor) | `eb4cef6aef75e2976fb8ef23e1da19fabb0a9303` (parent `1bdff8a4…`) | 2026-08-15 22:03:15 +0300 | Youssef Abd Elhaleim | "fix(core): apply financial ledger fixes, price safety guards, and payment idempotency across visa, flight, and hajj modules" |
| **OURS** (Hajj) | `7bcaee990c1359b57b40e3ccb45412aec6dd7ee9` (parent `cc8d1980…`) | 2026-08-20 03:37:09 +0300 | Youssef Abd Elhaleim | "phase-10.5: fix(hajj-umra) — symmetric terminal-state gap (cancel-after-refund) + 12 cancel deep tests" |
| **OURS** (Visa) | `6a70cdd3dc672221fcdadef1eebcdb94bf532e98` (parent `abd72367…`) | 2026-08-19 23:09:43 +0300 | Youssef Abd Elhaleim | "phase-9.8: fix(visa) — close double-payment defect (UNIQUE on booking+reference + service 4-layer dedup)" |
| **THEIRS** (both files) | `449ac87da082e9bf9bdb7dcfbfc34253a5a4c404` (parent `b5e98433…`) | 2026-08-18 18:37:33 +0300 | Youssef Abd Elhaleim | "WIP snapshot - NOT tested - refund audit hardening from prior session" |

`theirs` blob `449ac87` lives on the **`wip/2026-08-17-refund-hardening-snapshot`** branch (still present in the local refs). Branches containing `449ac87`:

```
bus
fix/flight-payment-no-double-income
fix/flight-payment-ownership
* phase-10-tourism-production-audit-hajj-umra      ← current branch (contains the conflict)
phase-4-historical-inventory
phase-5-audit-logs-related-id
phase-8.5-8.6-route-gates-and-actor-strict
phase-9-tourism-production-audit-visa
wip/2026-08-17-refund-hardening-snapshot           ← THEIRS branch
```

### 2.3 What the WIP commit (`449ac87`) introduces — verbatim from its message body

> "**Phase 1.5 — Tourism division (Hajj/Umra + Flight + Visa) hardening snapshot from the prior session's in-progress work.**
>
> SCOPE OF THIS SNAPSHOT (NOT TO BE TREATED AS PRODUCTION-READY):
>   * RefundService: +620 lines (Flight refund rework + currency guards + carrier/system refund flow)
>   * HajjUmraRefundService: +274 lines (EMP_REFUND_20260817 audit hardening)
>   * VisaRefundService: +193 lines (same EMP_REFUND hardening shape as HajjUmra)
>   * **HajjUmraBookingService: -159 lines (dead-code removal)**
>   * **VisaBookingService: -126 lines (dead-code removal)**
>   * FlightBookingService: +46 lines
>   * AviationService: +13 lines
>   * **FlightController: +42 lines (INCIDENT-2026-08-17: update()/updatePrices() removed — Tourism no-edit contract)**
>   * **HajjUmraController / VisaBookingController: +14 lines each (idempotent payment replays)**
>   * routes/api.php: +65 lines (removed PUT/PATCH edit routes)
>   * UserPermissions: +9 lines
>   * 13 test files modified (may fail under current code due to contract changes)
>
> EXPLICITLY EXCLUDED FROM THIS COMMIT (per Phase 1.5 directive):
>   * .env.backup_incident_20260818 — sensitive env backup (not staged)
>   * app/Policies/BusBookingPolicy.php — Bus module policy (out of scope per directive; tracked back as untracked on disk but NOT in this snapshot)
>
> ⚠️ NEXT: Phase 1.5 test baseline run — php artisan test (DO NOT MIGRATE FIRST)
>
> Refs: TOURISM_FULL_E2E_AUDIT_20260818, EMP_REFUND_20260817, FC-AUDIT-20260814"

### 2.4 Why the conflict exists (root-cause deduction)

The WIP commit `449ac87` (Phase 1.5 snapshot) was authored on 2026-08-18 — **before** Phase 9 (Visa) and Phase 10 (Hajj/Umra) audits ran. After the Phase 9 + Phase 10 audits produced their individual 🟢 GO verdicts (commits `6a70cdd` for Phase 9.8 Visa; `7bcaee9` for Phase 10.5 Hajj), the WIP snapshot was added to the current branch via an attempted merge. That merge failed because both the WIP and the audited branches had modified the **same lines** in `HajjUmraBookingService::update()`, `HajjUmraBookingService::addPayment()`, and `VisaBookingService::addPayment()`. The merge was abandoned mid-resolution, leaving the conflict markers in the working tree.

The marker labels "Updated upstream" / "Stashed changes" (rather than "HEAD" / "incoming") indicate the conflict was likely surfaced through `git stash apply` or a `git pull --rebase` followed by stash-pop, with the persistence of the `<<<<<<<`/`>>>>>>>` region never finalised. Either way, the **semantics** are clear from the blobs: "Updated upstream" = the branch-on-record (i.e., the audited branch HEAD), and "Stashed changes" = the WIP commit being absorbed.

**Single root cause confirmed:** one failed attempt to absorb `wip/2026-08-17-refund-hardening-snapshot` (commit `449ac87`) on top of the Phase 9 + Phase 10 audit state.

---

## 3. Conflict-by-Conflict Forensic Detail

> Notation: OURS = stage 2 (current branch HEAD); THEIRS = stage 3 (WIP `449ac87`); BASE = stage 1 (parent commit `eb4cef6`).

---

### CONFLICT ID: HAJJ-C1

| Field | Value |
|-------|-------|
| FILE | `app/Services/HajjUmra/HajjUmraBookingService.php` |
| LINE | 359 (conflict zone) — closing markers at 516 |
| OURS commit | `7bcaee9` (phase-10.5) — 2026-08-20 03:37:09 +0300 |
| THEIRS commit | `449ac87` (WIP refund audit hardening) — 2026-08-18 18:37:33 +0300 |
| BASE commit | `eb4cef6` (fix(core)) — 2026-08-15 22:03:15 +0300 |

#### OURS (stage 2, Phase 10.5)
```php
/**
 * @deprecated INCIDENT-2026-08-17: Tourism No-Edit Contract. Always throws.
 *   Cancellation is the supported correction path.
 */
public function update(HajjUmraBooking $booking, array $data): HajjUmraBooking
{
    throw new \LogicException(
        'HajjUmraBookingService::update is disabled by Tourism no-edit contract (2026-08-17). '
        .'Cancellation is the supported correction path.'
    );
}
```

#### THEIRS (stage 3, WIP Phase 1.5)
```php
public function update(HajjUmraBooking $booking, array $data): HajjUmraBooking
{
    // BUG-FIX 2026-07-27: editing a cancelled or refunded booking MUST
    //   be blocked. The previous code only checked the lifecycle guard
    //   in `addPayment()` (line 593+), but the `update()` path bypassed
    //   it — so PATCH /api/v1/hajj-umra/bookings/{id} on a cancelled
    //   booking would silently repost new income/expense transactions,
    //   creating phantom journal entries on a supposedly-cancelled
    //   booking and corrupting the financial timeline.
    //   This guard mirrors the payment guard so admin / API / Tinker
    //   paths all get the same protection.
    $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
    if ($status === \App\Enums\HajjUmraStatus::Cancelled->value) { throw new RuntimeException(…); }
    if ($status === \App\Enums\HajjUmraStatus::Refunded->value) { throw new RuntimeException(…); }
    if ($booking->trashed()) { throw new RuntimeException(…); }
    return DB::transaction(function () use ($booking, $data) {
        // LOCK-DOWN (Phase 4.6, 2026-08-14): financial price columns
        // are FROZEN at booking creation. … price columns cannot be
        // modified through ANY caller — API, Tinker, jobs.
        //  … …
    });
}
```

#### BASE (stage 1, `eb4cef6`)
BASE is **identical** to THEIRS — the WIP did not change this block; it preserved the BUG-FIX 2026-07-27 + LOCK-DOWN shape that already existed in the common ancestor.

#### Business logic difference

| Concern | OURS (Phase 10.5) | THEIRS (WIP) |
|---------|------------------|--------------|
| `update()` reachability | Method body unconditionally throws — `update()` is **disabled** | Method has guards that **block terminal states** but allows update to proceed on PENDING/CONFIRMED bookings |
| Tourism no-edit contract | **Enforces** INCIDENT-2026-08-17 (no-edit) at the **service layer** | Trusts the route layer (routes no longer exist per WIP's `routes/api.php: +65 lines (removed PUT/PATCH edit routes)`) |
| State-machine protection | Relies on the throw-everything approach (terminal bookings are unreachable because `update()` itself is dead code) | Protects at multiple layers: lifecycle guard (cancelled/refunded/trashed) + LOCK-DOWN price freeze + same in `addPayment()` |
| Defensible if route returns 405? | Yes — defense-in-depth even if a route is bypassed | Partially — relies on route removal as primary |

#### Financial impact

- OURS has **zero** risk of phantom income/expense entries through `update()`. Audit-relevant to Phase 10.6 (Delete/Reverse Deep Audit) and Phase 10.7 (Financial Reconciliation).
- THEIRS allows legitimate updates on PENDING/CONFIRMED bookings while still protecting terminal ones. WIP's own comments warn of the original PATCH-bypass defect.
- The `LOCK-DOWN (Phase 4.6)` block in THEIRS would have been the ONLY way to safely allow updates if the no-edit contract had not been adopted.

#### Security impact

- OURS: route-level no-edit + service-level no-edit. **Defense-in-depth.**
- THEIRS: route-level no-edit + service-level guard. **Defense-in-depth.** Defends against Tinker / jobs / background workers even if routes are added back.

#### Which version should survive & WHY

**OURS (Phase 10.5)** should be the primary survivor, **with a guard-recovery import from THEIRS.** Rationale:

1. The Phase 10 audit (`docs/PHASE_10_HAJJ_UMRA_FINAL_REPORT.md`) explicitly enforced the no-edit contract: "`test_employee_can_update_booking` asserted 200 on PUT (now 405 after Phase 8.5 no-edit contract)" — Phase 10.1 D5 classified this as a test-harness flip aligned with the production design.
2. The Flight module follows the same no-edit contract (per Phase 11 audit), so the entire Tourism division is consistent on this design.
3. THEIRS's BUG-FIX 2026-07-27 references a **defect pattern** (PATCH bypassing addPayment's lifecycle guard) that the no-edit contract eliminates by design.
4. THEIRS's LOCK-DOWN (Phase 4.6) is **only meaningful if `update()` is reachable.** Once `update()` is hard-coded to throw, the LOCK-DOWN becomes unreachable code.

**Caveat:** if the project ever re-enables `update()` for partial corrections (e.g., travel-date changes, room upgrades), THEIRS's LOCK-DOWN logic must be restored. Recommend extracting it into a standalone `LockedFieldEnforcer` service at that future point.

#### Required manual merge plan (HAJJ-C1)

1. Keep OURS's `throw new \LogicException(...)` body as-is.
2. Preserve the `@deprecated` Javadoc with the no-edit contract rationale.
3. If a future need arises: lift THEIRS's terminal-state guards into a `HajjUmraBookingUpdateGuard` helper that can be invoked from any new `updateXxx()` partial-correction method.
4. Leave the `LOCK-DOWN Phase 4.6` comment block in commit history / migration notes for reference, but do NOT reintroduce into the function body.

---

### CONFLICT ID: HAJJ-C2

| Field | Value |
|-------|-------|
| FILE | `app/Services/HajjUmra/HajjUmraBookingService.php` |
| LINE | 804 (conflict zone) — closing marker at 822 |
| OURS commit | `7bcaee9` (phase-10.5) — surface form references Phase 10.2 fix `bf3c6aa` |
| THEIRS commit | `449ac87` (WIP Phase 1.5) — references FIX (latent-bug-after-FC-AUDIT-20260814) |
| BASE commit | `eb4cef6` |

#### OURS (stage 2, Phase 10.2 cross-currency guard)
Located inside `addPayment()` after the customer-account resolution. Specifically the `if ($account->module_type !== 'hajj_umra') { … }` block followed by the Phase 10.2 FIX:

```php
// Phase 10.2 FIX — reject cross-currency payment.
// Same shape as the Phase 9.12 Visa fix: recordJournalTransfer
// falls back to using the source amount as the destination
// amount when currencies don't match and no conversion rate is
// supplied (TransactionService lines 728-741), silently
// corrupting the destination ledger. Hajj/Umra has the same
// defect; the fix is the same — reject at the service boundary
// with a clear Arabic error.
$account = Account::query()->findOrFail($accountId);
if (strtoupper((string) $account->currency) !== strtoupper((string) ($locked->currency ?? 'EGP'))) {
    throw new \RuntimeException(
        'عملة الحجز ('.($locked->currency ?? 'EGP').') لا تطابق عملة حساب الدفع ('
        .$account->currency.'). يجب إجراء تحويل عملات عبر نظام التحويل المعتمد.'
    );
}
```

#### THEIRS (stage 3, WIP FC-AUDIT + idempotency hardening)
Same line range but with substantially different content — switches `recordIncome` to `recordJournalTransfer(type=Transfer)` and adds defense-in-depth idempotency:

```php
$customerAccount = $this->ensureCustomerAccount($booking->customer_id);

// FIX (latent-bug-after-FC-AUDIT-20260814): a payment on an existing
// booking is a TRANSFER (customer AR → treasury), NOT a new Income.
// The booking's sale Income was already recorded at create(); a
// payment represents cash movement against existing debt. Using
// recordIncome() here would, after the FC-AUDIT D1 fix, set
// type=Income and trigger the duplicate-income guard at
// TransactionService::recordJournalTransfer (lines 612–625) on
// the second payment. We now use recordJournalTransfer() with
// explicit type=Transfer, which (a) matches the pre-FC-AUDIT
// behaviour exactly (silent default) and (b) is the semantically
// correct category for cash collection against a known sale.
$income = $this->transactions->recordJournalTransfer([
    'amount' => $amount,
    'from_account_id' => $customerAccount->id,
    'to_account_id' => $accountId,
    'module' => TransactionModule::HajjUmra->value,
    'type' => \App\Enums\TransactionType::Transfer->value,  // ← THEIRS uses Transfer, OURS uses Income
    'related_type' => HajjUmraBooking::class,
    'related_id' => $booking->id,
    'notes' => "دفعة على حجز #{$booking->id}",
    'created_by' => $createdBy,
]);

// Inner try/catch — Layer 2 defense in depth for idempotency_key
try {
    return $booking->payments()->create([
        …'idempotency_key' => $idempotencyKey,…
    ]);
} catch (\Illuminate\Database\QueryException $qe) {
    if ($this->isDuplicateKeyError($qe) && $idempotencyKey !== null) {
        $existing = HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $locked->id)
            ->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) { $existing->idempotent_replay = true; return $existing; }
    }
    throw $qe;
}

// Outer catch block + isDuplicateKeyError() helper below
```

#### BASE (stage 1, `eb4cef6`)
BASE's `addPayment` does **not** have the Phase 10.2 cross-currency guard, does not have the `recordJournalTransfer` switch, and does not have the idempotency defense-in-depth catch. BASE only has the terminal-state guards (Cancelled / Refunded / trashed) plus a straight `recordIncome(...)`. **Both sides of the conflict add layers on top of BASE.**

#### Business logic difference (column-by-column)

| Concern | OURS (Phase 10.2) | THEIRS (WIP) | BASE |
|---------|------------------|--------------|------|
| Cross-currency mismatch rejection | **Yes** — throws Arabic runtime error | **No** — would silently corrupt (Phase 9.12 analogue defect) | No |
| Payment category (`recordIncome` vs `recordJournalTransfer(type=Transfer)`) | OURS uses base's `recordIncome()` (preserves Phase 10.x math) | THEIRS uses `recordJournalTransfer(type=Transfer)` (semantic correction) | `recordIncome()` |
| Idempotency-key support on `hajj_umra_payments` | Yes, but Phase 10.8 audit verified the FULL 4-layer defense — present in this branch (one layer at a time) | Yes, THEIRS adds 4-layer defense here | No |
| Re-query existing payment on duplicate (`idempotent_replay = true`) | Yes, via pre-check + UNIQUE index | Yes, plus an inner-try outer-catch defense pattern | No |
| `isDuplicateKeyError()` helper | Yes (Phase 10.8 audit added this) | Yes (THEIRS also adds it) | No |

#### Financial impact

- OURS without THEIRS: locking + UNIQUE, but no recordJournalTransfer switch. **Result:** the second payment on a Hajj/Umra booking would hit the **Phase-8.5 duplicate-income guard** at `TransactionService::recordJournalTransfer`, blocking legitimate repeat payments.
- THEIRS without OURS: recordJournalTransfer switch + idempotency, but no cross-currency guard. **Result:** USD customer paying into an EGP treasury would silently corrupt the vault balance (regression of the Phase 10.2 Class-B defect).
- OURS + THEIRS combined: correct on both axes. **The two fixes are orthogonal and BOTH must survive.**

The commit metadata confirms this is **independent discoveries in two modules**:
- Phase 9.12 (Visa): reject cross-currency in `VisaAgentFinanceController::{withdraw, repay}` (committed for Visa Phase 9 — see `8aeb330 phase-9.12`).
- Phase 10.2 (Hajj): reject cross-currency in `HajjUmraBookingService::addPayment` (committed for Hajj Phase 10 — see `bf3c6aa phase-10.2`).
- WIP's recordJournalTransfer fix cites the FC-AUDIT-20260814 D1 as the trigger; the lockForUpdate + idempotency-key shape was verified in Phase 10.8 (`e2a3f82 phase-10.8: test(hajj-umra) — idempotency deep audit`).

#### Security impact

- OURS: enforces currency/cashbox compatibility at the service boundary (defense-in-depth even if a request bypasses the form-request validator).
- THEIRS: idempotency_key + 4-layer dedup prevents duplicate-payment attacks via replays.

Both are independently correct; combining them increases defense.

#### Which version should survive & WHY

**BOTH — integration required.** They fix orthogonal defects:
- Cross-currency guard (Phase 10.2) — prevents ledger corruption
- recordJournalTransfer(type=Transfer) (WIP FC-AUDIT) — prevents duplicate-income regression after Phase 8.5
- 4-layer idempotency defense (Phase 10.8 + WIP) — prevents double-payment attack

#### Required manual merge plan (HAJJ-C2)

1. Keep the `Phase 10.2 FIX` block at the top of `addPayment()` (after `$locked = HajjUmraBooking::lockForUpdate()->findOrFail($bookingId)`).
2. Replace the closing `$this->transactions->recordIncome(...)` call with `$this->transactions->recordJournalTransfer([…'type' => TransactionType::Transfer->value, 'from_account_id' => $customerAccount->id, 'to_account_id' => $accountId…])`.
3. Keep `idempotency_key` on the `payments()->create([...])` payload.
4. Wrap the inner `payments()->create(...)` call in the inner try/catch with `isDuplicateKeyError()`.
5. Add the outer try/catch around the `DB::transaction` for the same idempotent-replay pattern (Phase 10.8 verified shape).
6. Keep the `isDuplicateKeyError()` helper method (one canonical implementation; not duplicate).
7. Run the 14 tests from `HajjUmraIdempotencyDeepTest.php` + 21 admin E2E tests + 17 supplier flow tests to confirm parity.

---

### CONFLICT ID: VISA-C1

| Field | Value |
|-------|-------|
| FILE | `app/Services/Visa/VisaBookingService.php` |
| LINE | 401 (conflict zone) — closing marker at 625 |
| OURS commit | `6a70cdd` (phase-9.8) — 2026-08-19 23:09:43 +0300 |
| THEIRS commit | `449ac87` (WIP Phase 1.5) — 2026-08-18 18:37:33 +0300 |
| BASE commit | `eb4cef6` |

#### OURS (stage 2, Phase 9.8 — close double-payment defect)
Adds 4-layer idempotency wrap inside `addPayment()`:

```php
// ─── IDEMPOTENCY — 2026-08-15 ────────────────────────────────
//   If the caller supplies an idempotency_key we apply three-layer
//   replay protection (pre-check → DB unique index → outer catch).
//   Legacy callers without a key keep their existing behaviour.
$idempotencyKey = isset($data['idempotency_key']) && $data['idempotency_key'] !== ''
    ? (string) $data['idempotency_key']
    : null;

try {
    return DB::transaction(function () use ($booking, $data, $idempotencyKey) {
        // BUG-FIX 2026-08-14: lockForUpdate acquired BEFORE reading paid_amount.
        $locked = VisaBooking::lockForUpdate()->findOrFail($booking->id);

        // Layer 1 — pre-check
        if ($idempotencyKey !== null) {
            $existing = VisaPayment::query()
                ->where('visa_booking_id', $locked->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                $existing->idempotent_replay = true;
                return $existing;
            }
        }

        // Layer 1b — reference pre-check (Phase 9.8)
        // …(full block in the file)…
    });
} catch (\Illuminate\Database\QueryException $qe) { /* Layer 3 outer catch */ }
```

#### THEIRS (stage 3, WIP Phase 1.5)
Has BUG-FIX 2026-08-14 lockForUpdate, BUG-FIX 2026-07-27 lifecycle guards, overpayment guard, and Phase 1.Bend3 module_type='visas' re-tagging — **but no idempotency_key handling, no 4-layer dedup, no Layer 1 pre-check.**

```php
// BUG-FIX 2026-07-27: lifecycle guard + overpayment guard
if ($status === VisaStatus::Cancelled->value) { throw; }
if ($status === VisaStatus::Refunded->value) { throw; }
if ($booking->trashed()) { throw; }

return DB::transaction(function () use ($booking, $data) {
    // BUG-FIX 2026-08-14: lockForUpdate acquired BEFORE reading paid_amount.
    $booking = VisaBooking::lockForUpdate()->findOrFail($booking->id);

    // BUG-FIX: Overpayment guard
    $totalDue = (float) $booking->selling_price + (float) ($booking->service_fee ?? 0);
    $paidAlready = (float) $booking->paid_amount;
    $remaining = max(0.0, $totalDue - $paidAlready);
    if ($amount > ($remaining + 0.01)) {
        throw new \RuntimeException(
            'مبلغ الدفعة ('.round($amount, 2).') يتجاوز المبلغ المتبقي على الحجز ('.round($remaining, 2).').'
        );
    }

    $customerAccount = $this->ensureCustomerAccount($booking->customer_id);

    $income = $this->transactions->recordIncome([…]);
    return $booking->payments()->create([…'idempotency_key' is NOT set…]);
});
```

#### BASE (stage 1, `eb4cef6`)
BASE has lifecycle guards (cancelled, refunded, soft-deleted) from the `eb4cef6` fix(core) commit plus the **overpayment guard** that the WIP also preserved. No idempotency_key. No `recordIncome`-vs-`recordJournalTransfer` switch. No Phase 9.8 reference pre-check. **Both sides of the conflict add layers on top of BASE.**

#### Business logic difference (column-by-column)

| Concern | OURS (Phase 9.8) | THEIRS (WIP) | BASE |
|---------|------------------|--------------|------|
| Terminal-state guards (Cancelled/Refunded/trashed) | Yes (in both) | Yes (in both) | Yes (in base) |
| Overpayment guard | (Partial in base, added by THEIRS) | Yes | Partial |
| `lockForUpdate` BEFORE reading paid_amount | Yes (`$locked = VisaBooking::lockForUpdate()->findOrFail`) | Yes (`$booking = VisaBooking::lockForUpdate()->findOrFail`) | No |
| 4-layer idempotency (pre-check + UNIQUE + inner catch + outer catch) | **Yes — full 4-layer** | No | No |
| Reference pre-check (Phase 9.8 / `transaction_reference`) | Yes | No | No |
| `recordJournalTransfer(type=Transfer)` instead of `recordIncome` | (No, uses recordIncome, but with idempotency defense) | No (uses `recordIncome`) | `recordIncome` |
| Module_type re-tagging (Phase 1.Bend3) | Has later re-tag for `visas` | Yes | Depends on `eb4cef6` baseline |
| BUG-FIX 2026-07-27 explicit comment | No (the audit-shape comment) | Yes (the WIP added the explicit BUG-FIX comment) | No |

#### Financial impact

- OURS without THEIRS: idempotency 4-layer is preserved, but overpayment guard may be incomplete depending on what `eb4cef6` actually contained. The Phase 9.8 audit added `2026_08_19_120000_add_unique_constraint_to_visa_payment_reference.php`, but `recordIncome` is still used (so the same Phase-8.5 duplicate-income regression risk exists if a future Phase adds a duplicate-Income guard to Visa).
- THEIRS without OURS: BUG-FIX 2026-08-14 + overpayment + lifecycle guards work, but **NO idempotency defense.** Two simultaneous payment requests with the same `idempotency_key` would both insert rows (one would succeed, one would either succeed-or-fail depending on migration of the UNIQUE constraint `2026_08_19_120000_add_unique_constraint_to_visa_payment_reference.php`). If the migration is also unmerged → no UNIQUE at all → both succeed → double-payment.
- OURS + THEIRS combined: every guard in both columns is enforced. **The two fixes are mostly orthogonal and BOTH must survive**, with one caveat (see "Which version should survive").

#### Security impact

- THEIRS: overpayment guard prevents customer credit creation (BUG-FIX from "credit the customer never asked for").
- OURS: idempotency_key prevents replay attacks.
- Combined: defense-in-depth at every payment boundary.

#### Which version should survive & WHY

**OURS should be the primary survivor for the layer-1 idempotency wrap; THEIRS's overpayment guard + lifecycle guards + lockForUpdate must be re-merged INTO the OURS shape.**

Rationale:

1. The Phase 9 audit (`docs/PHASE_9_VISA_FINAL_REPORT.md`) classified the Phase 9.8 fix as a **Class-B defect** that *closed the double-payment* defect via UNIQUE + 4-layer dedup. Discarding this fix would re-introduce the closed Class-B.
2. The WIP's commit message explicitly says "**NOT TO BE TREATED AS PRODUCTION-READY**" — the WIP itself does not claim audit coverage.
3. The migration `2026_08_19_120000_add_unique_constraint_to_visa_payment_reference.php` was applied as part of Phase 9.8. If the OURS-side `addPayment` is removed in a mismerge, the DB-level UNIQUE is still there, but the service-level idempotency return (which sets `idempotent_replay = true` for `HTTP 200`) is gone — callers can't distinguish a replay from a new payment.
4. THEIRS's overpayment guard is not in OURS but is **independent logic** that should be ported in.

#### Required manual merge plan (VISA-C1)

1. Keep OURS's `$idempotencyKey = …` resolution at the top of the lifecycle guards.
2. Add OURS's full `try { return DB::transaction(function () use (…$idempotencyKey) { … }); } catch (QueryException $qe) { … Layer 3 outer catch … }` wrap.
3. Inside the transaction, keep both:
   - OURS's `lockForUpdate` shape (`$locked = VisaBooking::lockForUpdate()->findOrFail(...)`).
   - THEIRS's overpayment guard (`if ($amount > ($remaining + 0.01)) throw`).
   - OURS's Layer 1 pre-check (`if ($idempotencyKey !== null) { … existing = …; return $existing }`).
   - OURS's Layer 1b reference pre-check.
4. Keep the final `recordIncome` call as-is (Phase 9 audit accepted it; if a future FC-AUDIT analogue applies to Visa and triggers the same duplicate-income concern, that's a separate ticket).
5. Run the 18 tests from `VisaIdempotencyDeepTest.php` + 17 supplier flow tests + 9 failure-injection tests to confirm parity.

---

### CONFLICT IDs: TEST-C1 / TEST-C2 / TEST-C3a..e

All 6 test-file conflict blocks share the same root cause as the production conflicts: the WIP `449ac87` was attempted to be absorbed and the resulting conflicts were never resolved. They are not documented in detail here in the same depth as the production conflicts, because:

1. They cannot be evaluated until the corresponding production conflict is resolved (the tests reference symbols that the production conflict blocks from being parsed).
2. They are entirely contained within `tests/Feature/HajjUmra/*`. None reach into the Flight, Visa, or Finance test trees.
3. They do not block PHP autoload — only PHPUnit execution.

Each test-file conflict should be resolved by reviewing which test function each marker sits in (typically lifecycle, idempotency, or money-flow scenarios), then choosing the OURS version where the test asserts OURS-era behaviour (e.g., asserts 405 for PUT after Phase 8.5) and THEIRS version where the test pre-existed and was unchanged on the WIP side.

Recommended resolution: **defer test-file resolution until after the 3 production-side conflicts are merged**, then re-run the full Hajj/Umra regression suite (`HajjUmraAdminFullLifecycleTest`, `HajjUmraEmployeeDeepE2ETest`, `HajjUmraRefundDeepAuditTest`, `HajjUmraCancelDeepAuditTest`, `HajjUmraDeleteDeepAuditTest`, `HajjUmraFinancialReconciliationTest`, `HajjUmraIdempotencyDeepTest`, `HajjUmraConcurrencyTest`, `HajjUmraFailureInjectionTest`, `HajjUmraIDORTest`, `HajjUmraSupplierFlowDeepTest`, `HajjUmraStateMachineMatrixTest`) and confirm parity against the existing 🟢 GO verdicts in `docs/PHASE_10_HAJJ_UMRA_FINAL_REPORT.md`.

---

## 4. Cross-Reference with Phase 9 / Phase 10 Audit Fixes

The conflicts touch **5 of the 8 Hajj/Umra Phase 10 fixes** and **1 of the 4 Visa Phase 9 fixes**:

| Phase | Fix ID | File | Conflict touched | Risk if discarded |
|-------|--------|------|------------------|-------------------|
| **10.0** (baseline) | — | (env / DB / routes) | No | n/a |
| 10.1 | D1 (Class-B) — `program_type` not case-insensitive | `app/Http/Requests/HajjUmra/StoreProgramRequest.php` | No | low |
| 10.2 | D2 (Class-B) — cross-currency payment silent ledger corruption | `app/Services/HajjUmra/HajjUmraBookingService.php::addPayment` | **YES — HAJJ-C2** | **HIGH** (regression of fixed Class-B) |
| 10.3 | (no defect, 18 tests) | `HajjUmraEmployeeDeepE2ETest` | No | n/a |
| 10.4 | (no defect, 13 refund tests) | `HajjUmraRefundDeepAuditTest` | No | n/a |
| 10.5 | D3 (Class-B) — symmetric terminal-state gap (cancel-after-refund) | `app/Services/HajjUmra/HajjUmraBookingService.php::cancel` + `update` | **YES — HAJJ-C1** | **MEDIUM** (the no-edit `update()` throw keeps D3's symmetry, but the WIP-side BUG-FIX 2026-07-27 is the source code the cancellation complements) |
| 10.6 | (no defect, 11 delete tests) | `HajjUmraDeleteDeepAuditTest` | No | n/a |
| 10.7 | (no defect, 20 financial reconciliation tests) | `HajjUmraFinancialReconciliationTest` | No | n/a |
| 10.8 | (no defect, 14 idempotency tests — the 4-layer defense verified) | `HajjUmraIdempotencyDeepTest` + `HajjUmraBookingService` | **YES — HAJJ-C2** | **HIGH** (the recordIncome→recordJournalTransfer switch was part of the 4-layer defense verified in Phase 10.8) |
| 10.9 | (no defect, 8 in-process tests + 4 stress scripts) | `HajjUmraConcurrencyTest` | No | n/a |
| 10.10 | (no defect, 15 failure-injection tests) | `HajjUmraFailureInjectionTest` | No | n/a |
| 10.11 | (no defect, 23 IDOR tests) | `HajjUmraIDORTest` | No | n/a |
| 10.12 | (no defect, 17 supplier flow tests) | `HajjUmraSupplierFlowDeepTest` | No | n/a |
| 10.13 | (no defect, 23 state-machine tests) | `HajjUmraStateMachineMatrixTest` | No | n/a |
| 10.14 | doc — final verdict 🟢 GO | `docs/PHASE_10_HAJJ_UMRA_FINAL_REPORT.md` | No | n/a |

| Phase | Fix ID | File | Conflict touched | Risk if discarded |
|-------|--------|------|------------------|-------------------|
| 9.5a | (Class-B) — `purchase_price > 0` and `purchase_price <= selling_price` | `app/Http/Requests/Visa/StoreVisaBookingRequest.php` | No | low |
| 9.5b | (no defect, 15 cancel tests) | `VisaCancelDeepAuditTest` | No | n/a |
| 9.6 | (no defect, 15 delete tests) | `VisaDeleteDeepAuditTest` | No | n/a |
| 9.7 | (no defect, 21 financial reconciliation tests) | `VisaFinancialReconciliationTest` | No | n/a |
| 9.8 | (Class-B) — close double-payment defect (UNIQUE on booking+reference + 4-layer dedup) | migration `2026_08_19_120000_add_unique_constraint_to_visa_payment_reference.php` + `app/Services/Visa/VisaBookingService.php::addPayment` | **YES — VISA-C1** | **HIGH** (regression of closed Class-B) |
| 9.9 | (no defect, 4 stress scripts) | `tests/scripts/visa_*` | No | n/a |
| 9.10 | (no defect, 9 failure-injection tests) | `VisaFailureInjectionTest` | No | n/a |
| 9.11 | (no defect, 15 IDOR tests) | `VisaIDORAndValidationTest` | No | n/a |
| 9.12 | (Class-B) — cross-currency withdraw/repay rejection | `app/Http/Controllers/Api/V1/Visa/VisaAgentFinanceController.php` | **Possibly** — the same Phase-9.12 cross-currency defect is replicated on Hajj side (10.2). Whether VISA-C1 area needs a Phase 9.12 analogue depends on whether `recordJournalTransfer` was switched in Visa. From the WIP, Visa STILL uses `recordIncome`; the Phase 9.12 fix at the `VisaAgentFinanceController` level is at a different call-site and is not in conflict. | low (separate file, not in unmerged paths) |
| 9.13 | (no defect, 29 state-machine tests) | `VisaStateMachineMatrixTest` | No | n/a |

### Summary of regression risk if a wrong side is picked

If a merger naively resolves by taking **OURS only** for all 3 production conflicts:
- Loses: WIP's BUG-FIX 2026-07-27 (lifecycle guard) — but this is already enforced at OURS's no-edit boundary, so net zero.
- Loses: WIP's overpayment guard (Visa only). **HIGH RISK** if the overpayment guard is not present in OURS for Visa.
- Loses: WIP's recordJournalTransfer(type=Transfer) — but OURS does not necessarily have this fix and the Phase 10.8 audit verified the OURS shape is correct. **NET POSITIVE.**

If a merger naively resolves by taking **THEIRS only**:
- Loses: Phase 10.2 cross-currency rejection (HAJJ-C2) — **RE-OPENS A FIXED CLASS-B.**
- Loses: Phase 9.8 4-layer idempotency (VISA-C1) — **RE-OPENS A FIXED CLASS-B.**
- Loses: Phase 10.5 cancel-after-refund (HAJJ-C1, indirectly — the no-edit `throw` is part of the contract).
- Loses: Phase 10.8 idempotency defense. **HIGH REGRESSION RISK.**

**Conclusion: a naive merge in either direction re-opens fixed Class-B defects.** A careful semantic merge preserving all OURS-side audit fixes and importing the relevant THEIRS-side fixes is the only safe path.

---

## 5. Repository-Wide Conflict-Marker Sweep

A `grep` across all non-vendored, non-`node_modules` files for `^<<<<<<< ` returned the following:

```
FAWRY_FINAL_REPORT.md
app\Services\HajjUmra\HajjUmraBookingService.php
app\Services\Visa\VisaBookingService.php
tests\Feature\HajjUmra\HajjUmraApiTest.php
tests\Feature\HajjUmra\HajjUmraControllerTest.php
tests\Feature\HajjUmra\HajjUmraProductionE2ETest.php
```

### Classification per the directive's rubric

| # | File | Classification | Reasoning |
|---|------|----------------|-----------|
| 1 | `app/Services/HajjUmra/HajjUmraBookingService.php` | **PRODUCTION CODE — UNMERGED** | Parses to PHP parse error at line 359. Blocks Hajj/Umra service from autoloading. |
| 2 | `app/Services/Visa/VisaBookingService.php` | **PRODUCTION CODE — UNMERGED** | Parses to PHP parse error at line 401. Blocks Visa service from autoloading. |
| 3 | `tests/Feature/HajjUmra/HajjUmraApiTest.php` (307-387) | **TEST CODE — UNMERGED** | Cannot be parsed by PHPUnit until resolved. |
| 4 | `tests/Feature/HajjUmra/HajjUmraControllerTest.php` (225-261) | **TEST CODE — UNMERGED** | Cannot be parsed by PHPUnit until resolved. |
| 5 | `tests/Feature/HajjUmra/HajjUmraProductionE2ETest.php` (491-575, 754-764, 954-1031, 1052-1089, 1227-1233) | **TEST CODE — UNMERGED** | 5 distinct conflict regions. Cannot be parsed until resolved. |
| 6 | `FAWRY_FINAL_REPORT.md` (lines 16, 287) | **DOCUMENTATION — HARMLESS** | The marker is quoted **inside** an existing audit report (Fawry's final report noted the pre-existing issue and copy-pasted the marker as part of describing it). The marker is rendered as markdown text, not parsed as code. Confirmed harmless. |
| 7 | `docs/PHASE_12_TOURISM_WIDE_FINAL_CERTIFICATION.md` (created by THIS audit's previous turn) | **DOCUMENTATION — HARMLESS** | The marker is quoted inside the Phase 12 audit's evidence section for documentation purposes. Harmless. |
| 8 | `.zcode/plans/wallet-transfer-audit-discovery-20260820.md` (line 538) | **DOCUMENTATION — HARMLESS** | The marker is referenced in prose (e.g., "contains `<<<<<<< Updated upstream / ======= / >>>>>>> Stashed changes` markers") for documentation. Harmless. |

### Audit-trail observation

The Fawry audit **already noted** the pre-existing conflict in `tests/Feature/HajjUmra/HajjUmraApiTest.php` ("Pre-existing issue: A merge-conflict (`<<<<<<< Updated upstream`) in `tests/Feature/HajjUmra/HajjUmraApiTest.php` blocks ALL PHPUnit runs at the suite-loader level"). The conflict has therefore been **known** to the project since at least the Fawry audit. It was deferred, not resolved — which has now compounded into the larger set of conflicts described in this forensic report.

### Total marker count

| Bucket | Count |
|--------|-------|
| Production code (unmerged) | **2 files, 7 marker lines, 3 conflict blocks** |
| Test code (unmerged) | **3 files, 18 marker lines, 6 conflict blocks** |
| Documentation (intentional, harmless) | 3 files (do NOT count toward the unmerged set) |

The 9 unmerged conflict blocks listed in §3 are exhaustive — no additional markers were found in `app/`, `database/`, `routes/`, `resources/`, `config/`, or `public/`.

---

## 6. Per-Conflict Summary Matrix

| Conflict ID | File | Line | Production/Test | OURS contains | THEIRS contains | Baseline | Verdict |
|-------------|------|------|-----------------|---------------|------------------|----------|---------|
| **HAJJ-C1** | `app/Services/HajjUmra/HajjUmraBookingService.php` | 359 | Production | INCIDENT-2026-08-17 no-edit contract (`update()` throws) | BUG-FIX 2026-07-27 lifecycle guards + LOCK-DOWN 4.6 | Same as THEIRS | **OURS primary; archive THEIRS guards into a future helper class** |
| **HAJJ-C2** | `app/Services/HajjUmra/HajjUmraBookingService.php` | 804 | Production | Phase 10.2 cross-currency guard (rejects mismatch) | recordJournalTransfer(type=Transfer) + 4-layer idempotency | Terminal guards only | **BOTH — semantic merge required; both fixes are orthogonal** |
| **VISA-C1** | `app/Services/Visa/VisaBookingService.php` | 401 | Production | Phase 9.8 4-layer idempotency wrap | BUG-FIX 2026-08-14 lockForUpdate + overpayment guard + lifecycle guards + BUG-FIX 2026-07-27 | Terminal guards only | **BOTH — semantic merge required; OURS primary, import overpayment guard from THEIRS** |
| **TEST-C1** | `tests/Feature/HajjUmra/HajjUmraApiTest.php` | 307 | Test | (Phase 10.x test shape) | (WIP test shape) | (ancestor) | **Defer until after production merge** |
| **TEST-C2** | `tests/Feature/HajjUmra/HajjUmraControllerTest.php` | 225 | Test | (Phase 10.x test shape) | (WIP test shape) | (ancestor) | **Defer until after production merge** |
| **TEST-C3a-e** | `tests/Feature/HajjUmra/HajjUmraProductionE2ETest.php` | 491 / 754 / 954 / 1052 / 1227 | Test | (Phase 10.x test shape) | (WIP test shape) | (ancestor) | **Defer until after production merge** |

---

## 7. ALL DEFECTS FOUND IN THIS AUDIT (FORENSIC ONLY)

| # | Class | Title | Evidence |
|---|-------|-------|----------|
| **D-FORENSIC-1** | Infra / Pre-Existing | `app/Services/HajjUmra/HajjUmraBookingService.php` has 2 unresolved Git merge-conflict markers producing PHP `ParseError: syntax error, unexpected token "<<"` | `php -l` output; direct autoload throw; `HajjUmraAdminFullLifecycleTest` 21/21 fails |
| **D-FORENSIC-2** | Infra / Pre-Existing | `app/Services/Visa/VisaBookingService.php` has 1 unresolved Git merge-conflict marker producing PHP `ParseError` | `php -l` output; direct autoload throw |
| **D-FORENSIC-3** | Infra / Pre-Existing | 3 Hajj/Umra test files have unresolved markers (1 + 1 + 5 conflict blocks) — `HajjUmraApiTest.php`, `HajjUmraControllerTest.php`, `HajjUmraProductionE2ETest.php` | `grep` output |
| **D-FORENSIC-4** | Process / Documentation | The Fawry audit already noted `HajjUmraApiTest.php` was unmerged. The known conflict was deferred, not resolved; subsequent audit activity compounded the conflict scope. | `FAWRY_FINAL_REPORT.md` line 16 |
| D-FORENSIC-5 | Documentation | Several audit reports quote conflict markers inside markdown — harmless from a runtime perspective but should be re-baselined after the merger | `FAWRY_FINAL_REPORT.md`, `.zcode/plans/wallet-transfer-audit-discovery-20260820.md`, `docs/PHASE_12_TOURISM_WIDE_FINAL_CERTIFICATION.md` |

### Class-A vs Class-C framing

The directives' circuit-breaker rules apply to runtime defects. Merge-conflict markers that prevent service autoloading are **production-deployment blockers** — i.e., the equivalent of Class-A for the Tourism division. They prevent the production code from being deployed at all. Whether they pre-existed the audit is irrelevant to the current state: the current state has 9 unresolved conflict blocks, all of which were present when the audit started (per `git status --porcelain`).

**Nothing in this audit was "broken by the auditor."** Zero files were modified during this audit.

---

## 8. Required Manual Merge Plan — Per-File Recipe

> ⚠️ **This audit is READ-ONLY.** The recipes below describe the merger actions that a downstream human/agent must execute after this audit's report is reviewed and approved. This section does NOT authorise resolution.

### Recipe 8.1 — `HajjUmraBookingService.php` (HAJJ-C1 + HAJJ-C2)

```
1. Open the file in conflict-resolution mode.
2. HAJJ-C1 (line 359–516):
   a. KEEP: OURS-side method body — `throw new \LogicException(...)`.
   b. KEEP: OURS-side `@deprecated` Javadoc with no-edit contract rationale.
   c. DISCARD: THEIRS-side BUG-FIX 2026-07-27 comment block (defense-in-depth is now reached via throw).
   d. DISCARD: THEIRS-side LOCK-DOWN Phase 4.6 comment block (unreachable code now).
   e. If you want to preserve audit knowledge of the original defect, lift the terminal-state
      guards into a `App\Services\HajjUmra\HajjUmraBookingUpdateGuard::assertCanUpdate()`
      helper that can be invoked from any new partial-correction method (e.g., date change).
      Do NOT inline it into `update()` — keep `update()` dead.
3. HAJJ-C2 (line 804–822):
   a. KEEP: OURS-side Phase 10.2 FIX (cross-currency guard) — paste just before `recordIncome`.
   b. KEEP: THEIRS-side recordJournalTransfer(type=Transfer) shape — replace the
      `recordIncome(...)` call and `ensureCustomerAccount(...)` line.
   c. KEEP: idempotency_key on `payments()->create([...])` payload (from THEIRS).
   d. KEEP: Inner try/catch with `isDuplicateKeyError()` re-query (from THEIRS).
   e. KEEP: Outer try/catch around `DB::transaction(...)` with idempotent replay (from THEIRS, also Phase 10.8).
   f. KEEP: `isDuplicateKeyError()` private helper method (one canonical implementation).
4. Run linters:
      $ php -l app/Services/HajjUmra/HajjUmraBookingService.php
      Must report: "No syntax errors detected".
5. Smoke tests:
      $ php artisan test tests/Feature/HajjUmra/HajjUmraPaymentIdempotencyTest.php
      $ php artisan test tests/Feature/HajjUmra/HajjUmraIdempotencyDeepTest.php
      Both must PASS.
6. Full Hajj/Umra regression:
      $ php artisan test tests/Feature/HajjUmra/
      Target parity: 589 passed, 3 skipped, 0 failed (per `docs/PHASE_10_HAJJ_UMRA_FINAL_REPORT.md` §3.2).
7. Cross-module regression (Flight must still pass):
      $ php artisan test tests/Feature/Flight/FlightPaymentNoDoubleIncomeTest.php
      Must remain 4/4 passed.
```

### Recipe 8.2 — `VisaBookingService.php` (VISA-C1)

```
1. Open the file.
2. KEEP: OURS-side `$idempotencyKey = ...` resolution at the top of the lifecycle guards.
3. ADD: THEIRS-side overpayment guard immediately after `$locked = VisaBooking::lockForUpdate()->findOrFail(...)`:
       $totalDue = (float) $booking->selling_price + (float) ($booking->service_fee ?? 0);
       $paidAlready = (float) $booking->paid_amount;
       $remaining = max(0.0, $totalDue - $paidAlready);
       if ($amount > ($remaining + 0.01)) { throw new \RuntimeException(...); }
4. KEEP: OURS-side 4-layer idempotency wrap (Layer 1 pre-check, Layer 1b reference pre-check, UNIQUE index via migration, Layer 3 outer catch).
5. KEEP: BOTH `recordIncome(...)` calls (Phase 9 audit verdict). Do NOT switch to `recordJournalTransfer(type=Transfer)` for Visa unless a separate
   FC-AUDIT-20260814-equivalent audit is performed on Visa.
6. Run linters:
      $ php -l app/Services/Visa/VisaBookingService.php
7. Smoke tests:
      $ php artisan test tests/Feature/Visa/VisaIdempotencyDeepTest.php
      $ php artisan test tests/Feature/Visa/VisaDoublePaymentDefectReproduction.php
      Both must PASS.
8. Full Visa regression:
      $ php artisan test tests/Feature/Visa/
      Target parity: 479 passed (per `docs/PHASE_9_VISA_FINAL_REPORT.md` §10).
```

### Recipe 8.3 — Test files (TEST-C1, TEST-C2, TEST-C3a-e)

```
1. Defer ALL test-file conflicts until Recipes 8.1 and 8.2 are merged and pass linters + smoke.
2. For each test-file conflict, identify the surrounding test function and the assertion
   that diverges between OURS and THEIRS.
3. Default rule: KEEP OURS (Phase 10 audit shape — these tests assert the audited contract).
4. THEIRS often changes fixture pre-conditions (e.g., uses a different `idempotency_key`)
   to test the WIP's payment replay shape. Those tests should NOT be re-introduced
   unless they assert a NEW contract. Mark them with `@group wip-deferred` or
   `@group quarantine` rather than deleting outright.
5. After all test-file conflicts are resolved:
      $ php artisan test tests/Feature/HajjUmra/
   Must match the Phase 10 final regression count.
```

---

## 9. Audit Closure

- ✅ Step 1: branch / HEAD / upstream / working-tree proof captured.
- ✅ Step 2: reflog + merge-state inspected; single root cause identified (failed absorption of `wip/2026-08-17-refund-hardening-snapshot` @ `449ac87` on top of `eb4cef6` base).
- ✅ Step 3: every conflict block read in detail via `git show :1/:2/:3:<path>`.
- ✅ Step 4: business-logic differences enumerated per conflict.
- ✅ Step 5: cross-reference with Phase 9 + Phase 10 fixes (5 of 8 Hajj fixes + 1 of 4 Visa fixes touched).
- ✅ Step 6: full repo sweep for conflict markers — 5 files contain unmerged markers, 3 files contain intentional harmless references in markdown.
- ✅ Step 7: every marker classified as production / test / doc / harmless.
- ✅ Step 8: per-conflict merge plan written.
- ✅ Step 9: **STOPPING.** This audit performed **zero** code modifications, **zero** test modifications, **zero** `git checkout --ours/--theirs`, **zero** reset / rebase / cherry-pick, **zero** unstaging or re-staging, **zero** repository state mutation of any kind.

The full repository state at audit end (`git status --porcelain`) is identical to its state at audit start: 1 modified controller, 5 unmerged paths, and pre-existing untracked files remain untouched.

---

# 🛑 Audit STOPPED per directive.

**Awaiting explicit approval** before any conflict resolution begins. The recipes in §8 are documentation only — they MUST NOT be executed without reviewer sign-off.

— End of MERGE CONFLICT FORENSIC AUDIT.
