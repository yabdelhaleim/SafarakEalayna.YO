# Phase 4 — Historical Correction Plan (READ-ONLY inventory completed)

**Date:** 2026-08-19
**Branch:** `phase-4-historical-inventory` (off `fix/flight-payment-no-double-income`)
**Status:** 🟡 **PLAN ONLY — awaiting user approval. Nothing has been modified.**

---

## 1. Executive summary

The "22 legacy cases" referenced in the original Phase 4 scope are
**22 orphan transactions in the `transactions` table** that reference
`flight_payments.id ∈ {41,42,43,44,45,46,47,48,49,50,51}` — but the
`flight_bookings` and `flight_payments` tables are **completely empty**
(0 rows, including trashed rows). The parent bookings were hard-deleted
at some prior point; only the ledger transactions survived.

| # | Finding | Severity |
|---|---------|----------|
| 1 | 22 orphan transactions (`related_type=App\Models\Flight\FlightPayment`) | Medium — data integrity |
| 2 | 11 of them are ACTIVE `type=Income` (no `عكس:` prefix) | **High — over-counts income reports by 8,099.99 EGP** |
| 3 | Each orphan Income has a companion orphan Transfer with `عكس:` prefix | Low — net cashbox impact = 0.00 EGP |
| 4 | HajjUmra / Visa / Bus modules: **0 orphans** | None — clean |

**Net financial impact on trial balance: 0.00 EGP.** The Income + Transfer
pairs already net to zero on every affected account (verified — see
report §D). The bug is purely a **reporting** issue: any query that
counts `type=Income AND notes NOT LIKE 'عكس:%'` will return 11 spurious
rows totaling 8,099.99 EGP.

---

## 2. Affected transactions — full list

11 pairs. Each pair = 1 spurious Income + 1 companion عكس Transfer.

| Income tx_id | related_id | amount | matched عكس Transfer tx_id | matched notes |
|--------------|------------|--------|-----------------------------|---------------|
| 4365 | 41 | 60.00 | 4367 | عكس دفعة (حذف حجز) — دفعة #41 |
| 4366 | 42 | 40.00 | 4368 | عكس دفعة (حذف حجز) — دفعة #42 |
| 4371 | 43 | 300.00 | 4373 | عكس دفعة (حذف حجز) — دفعة #43 |
| 4372 | 44 | 200.00 | 4374 | عكس دفعة (حذف حجز) — دفعة #44 |
| 4377 | 45 | 599.99 | 4379 | عكس دفعة (حذف حجز) — دفعة #45 |
| 4378 | 46 | 400.00 | 4380 | عكس دفعة (حذف حجز) — دفعة #46 |
| 4383 | 47 | 600.00 | 4385 | عكس دفعة (حذف حجز) — دفعة #47 |
| 4384 | 48 | 400.00 | 4386 | عكس دفعة (حذف حجز) — دفعة #48 |
| 4389 | 49 | 1,500.00 | 4391 | عكس دفعة (حذف حجز) — دفعة #49 |
| 4390 | 50 | 1,000.00 | 4392 | عكس دفعة (حذف حجز) — دفعة #50 |
| 4395 | 51 | 3,000.00 | 4397 | عكس دفعة (حذف حجز) — دفعة #51 |
| **Total** | | **8,099.99** | | |

Source: `tests/reports/PHASE_4_FLIGHT_ORPHANS.csv` (also rendered as
markdown in `tests/reports/PHASE_4_FLIGHT_ORPHANS.md`).

---

## 3. Correction options (evaluated)

### Option A — Soft-reverse the 11 orphan Income rows (RECOMMENDED ✅)

**Action:** `UPDATE transactions SET notes = CONCAT('عكس: ', COALESCE(notes, '(legacy B-2 duplicate)')) WHERE id IN (4365, 4366, 4371, 4372, 4377, 4378, 4383, 4384, 4389, 4390, 4395);`

**Pros:**
- Zero data loss — full audit trail preserved
- Reversible (just `UPDATE` back)
- Matches the existing canonical pattern (`TransactionService::reverseTransaction`
  already uses the `عكس:` prefix convention)
- Single statement, single transaction, <1 second runtime
- After: reports filter correctly → 0 spurious income rows
- The companion عكس Transfers (4367, 4368, …) stay as-is — they are
  the ACTUAL record of "this payment was reversed"

**Cons:**
- 11 rows' `notes` column values are mutated (but additive — original
  null notes are preserved as `(legacy B-2 duplicate)` suffix)
- Doesn't address the orphan-ness itself (transactions still reference
  non-existent flight_payments) — but that's a separate concern and
  doesn't affect any report

### Option B — Hard delete the 22 orphan rows

**Action:** `DELETE FROM transactions WHERE id IN (4365,4366,4367,4368,4371,4372,4373,4374,4377,4378,4379,4380,4383,4384,4385,4386,4389,4390,4391,4392,4395,4397);`

**Pros:**
- Cleanest data — orphan rows gone

**Cons:**
- **Destructive** — violates the standing "no destructive operations
  on historical data" directive (Phase 1.5 rule)
- Loses the audit trail of what went wrong (forensic value lost)
- Affects AccountEntries + TransactionReversals tables via cascades
  (would need to verify the full cascade)
- Cannot be undone without a backup

### Option C — Leave as-is

**Action:** None.

**Pros:**
- Zero risk

**Cons:**
- Reports will continue to over-count income by 8,099.99 EGP
- Auditor confusion when reviewing legacy data
- The 22 orphan rows will sit in the table indefinitely

---

## 4. Recommendation: **Option A**

Reasons:
1. Aligns with the canonical reversal pattern already in use
2. Fully reversible
3. Non-destructive
4. Single statement, easily reviewable in PR
5. Matches the user's stated principle: *"أي فحص لازم يكون على نسخة أو داخل transaction بترجعها"* (Phase 1.5 rule)

**Expected outcome:**

| Query | Before | After Option A | Δ |
|-------|--------|----------------|---|
| `SELECT COUNT(*) FROM transactions WHERE module='flight' AND type='income' AND notes NOT LIKE 'عكس:%'` | 11 | **0** | −11 |
| `SELECT SUM(amount) FROM transactions WHERE module='flight' AND type='income' AND notes NOT LIKE 'عكس:%'` | 8,099.99 | **0.00** | −8,099.99 |
| `SELECT COUNT(*) FROM transactions WHERE related_type='App\\Models\\Flight\\FlightPayment'` | 22 | 22 | 0 (orphans persist but are inert) |
| Net cashbox / income-clearing balances | unchanged | **unchanged** | 0 |

---

## 5. Execution guard-rails (planned for execution phase — NOT today)

When (and only when) you give explicit approval, the execution will be:

1. **Take a full MySQL dump first** (`mysqldump safarakealayna > pre_phase4_20260819.sql`).
2. **Wrap in DB transaction** so we can ROLLBACK on any error:
   ```php
   DB::transaction(function () {
       DB::update("UPDATE transactions SET notes = CONCAT('عكس: ', ...) WHERE id IN (...)");
   });
   ```
3. **Run the SAME audit script** (`audit_flight_orphans_phase_4.php`) — should report:
   - 22 orphans (unchanged — orphans are about FK existence, not notes)
   - 0 ACTIVE Income (was 11 before)
   - 0.00 EGP over-count (was 8,099.99 before)
4. **Print the before/after diff** so you can review.
5. **If anything unexpected** → automatic ROLLBACK + report failure.

If approval is granted, I will write the actual execution script as a
separate file (`execute_phase_4_correction.php`) with these guard-rails.
**No execution script is committed in this branch yet.**

---

## 6. What was NOT touched in Phase 4

- ✅ Read-only queries only — `SELECT` + `LEFT JOIN` exclusively.
- ✅ No `UPDATE`, `DELETE`, or `TRUNCATE` was issued.
- ✅ No migration ran.
- ✅ No seeder ran.
- ✅ Production DB was not touched (safety gate aborted if attempted).
- ✅ HajjUmra / Visa / Bus: confirmed clean (0 orphans).

---

## 7. Files added in Phase 4

```
audit_flight_orphans_phase_4.php         (read-only inventory script)
docs/PHASE_4_HISTORICAL_CORRECTION_PLAN.md (this file)
tests/reports/PHASE_4_FLIGHT_ORPHANS.csv (auto-generated)
tests/reports/PHASE_4_FLIGHT_ORPHANS.md  (auto-generated)
```

The audit script + reports are committed to `phase-4-historical-inventory`
branch. **The execution script is NOT committed** — it will only be
written when you approve Option A/B/C.

---

## 8. Next step — your decision

Please confirm one of:

| Choice | Meaning |
|--------|---------|
| **"Option A"** | Soft-reverse 11 Income rows (recommended) → I'll write `execute_phase_4_correction.php` with guard-rails, present it for review, and **NOT run** until you say "شغّل" |
| **"Option B"** | Hard delete 22 rows → I'll write the guarded delete script + verify cascades first |
| **"Option C"** | Leave as-is → close Phase 4, move to Phase 5 |
| **"More analysis needed"** | Tell me what extra dimension to investigate (e.g., "find the original booking IDs from logs", "check if HajjUmra had similar issue before FC-AUDIT-20260814", etc.) |

Awaiting your decision — **I will not execute anything without explicit approval.**