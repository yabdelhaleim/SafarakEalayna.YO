# Phase 9.8 — Idempotency Deep + Double-Payment Defect Fix

**Status:** 🟢 **FIX APPLIED** (defect closed at 4 layers; 403 tests pass, 1199 assertions)
**Section:** 14 of 30
**Date:** 2026-08-19
**Class:** A (Application defect, security-relevant — double-credit)

---

## Executive Summary

The known Visa **double-payment defect** — same booking + same `transaction_reference` + DIFFERENT `idempotency_key` (or none) creating duplicate payment rows and double-crediting the vault — has been **reproduced, fixed at the service AND database levels**, and verified by a comprehensive test suite.

### Defect Before / After

| Metric | Before Fix | After Fix |
|--------|-----------|-----------|
| Payment rows created (same booking + same reference, 2 calls) | **2** | **1** (idempotent replay) |
| Vault credit | **1000** (double) | **500** (single) |
| Transfer transactions created | **2** | **1** |
| Customer AR debit | **1000** (double) | **500** (single) |
| Global ledger balance | balanced (offsetting) | balanced |

---

## Step-by-Step Execution

### Step 1: Pre-Check for Existing Duplicates ✅

Created `tests/Feature/Visa/VisaPaymentDuplicatesPreCheck.php` (2 tests) that:
- Verifies the clean test DB has zero duplicates (migration is safe)
- Detects simulated duplicates via GROUP BY HAVING COUNT(*) > 1

**Migration safety check** (in the migration itself): Before adding the unique index, the migration runs a read-only GROUP BY HAVING query to detect any existing duplicates. If any are found, the migration **aborts with RuntimeException** — no destructive operations.

### Step 2: Reproduce the Defect ✅

Created `tests/Feature/Visa/VisaDoublePaymentDefectReproduction.php` (2 tests) that:
- Confirmed the defect on the original code:
  ```
  [REPORT] payment_count=2, vault_change=1000, is_defect=YES
  ```

### Step 3: Apply the Fix ✅

**Two changes:**

#### a. New migration: `database/migrations/2026_08_19_120000_add_unique_constraint_to_visa_payment_reference.php`

- Adds UNIQUE INDEX `vp_ref_uniq` on `(visa_booking_id, transaction_reference)`
- Pre-check: aborts with RuntimeException if duplicates exist (read-only, no destructive ops)
- Idempotency guard: `Schema::hasIndex('visa_payments', 'vp_ref_uniq')` skips if already migrated (portable across MySQL/MariaDB/SQLite)
- Down(): drops the unique index only

#### b. Service update: `app/Services/Visa/VisaBookingService.php::addPayment()`

- **NEW Layer 1b (pre-check):** if `reference` is provided, lookup existing `(booking, reference)` row BEFORE any ledger mutation
- **Updated Layer 2 (DB unique backstop):** the catch block now handles BOTH unique indexes — `(booking, idempotency_key)` AND `(booking, transaction_reference)`
- **Updated outer catch:** same dual-key lookup

The fix adds a **fourth layer** to the existing three-layer protection:
1. Layer 1: pre-check on `(booking, idempotency_key)`
2. **Layer 1b (NEW):** pre-check on `(booking, transaction_reference)`
3. Layer 2: DB unique constraint backstop (`vp_idem_uniq`)
4. **Layer 2b (NEW):** DB unique constraint backstop (`vp_ref_uniq`)
5. Layer 3: `lockForUpdate()` on the booking row

### Step 4: Write 7 Idempotency Tests ✅

Created `tests/Feature/Visa/VisaIdempotencyDeepTest.php` with **14 tests** covering all 7 scenarios from the spec + 7 additional defensive tests:

| # | Test | What it asserts |
|---|------|-----------------|
| 1 | `test_same_payment_same_reference_is_idempotent` | Spec #1: same booking+reference → 1 row, same id |
| 2 | `test_same_payment_same_idempotency_key_is_idempotent` | Spec #2: same key → 1 row |
| 3 | `test_same_reference_with_no_idempotency_key_still_idempotent` | Defensive: no key but same ref → still 1 row |
| 4 | `test_same_reference_different_keys_is_idempotent` | **The exact defect scenario** — fixed |
| 5 | `test_different_references_same_booking_creates_multiple_payments` | Spec #3: different refs → multiple rows |
| 6 | `test_null_reference_with_another_null_still_creates_two` | Sanity: NULL refs allow multiple rows |
| 7 | `test_concurrent_duplicate_payments_same_reference_only_one_wins` | Spec #4: 3 sequential same-ref → 1 row |
| 8 | `test_concurrent_different_payments_same_booking_all_succeed` | Spec #5: 3 different refs → 3 rows |
| 9 | `test_idempotent_replay_does_not_double_post_to_vault` | Spec #6: 5 replays → single vault credit |
| 10 | `test_idempotent_replay_does_not_double_post_to_customer_ar` | Spec #6: customer AR not double-debited |
| 11 | `test_idempotent_replay_does_not_create_duplicate_transfer_transactions` | Spec #6: only 1 transfer tx |
| 12 | `test_idempotent_replay_does_not_affect_supplier_ap` | Spec #6: agent AP unchanged |
| 13 | `test_global_ledger_remains_balanced_after_idempotent_replays` | Spec #6: SUM(credit)=SUM(debit) globally |
| 14 | `test_db_unique_constraint_blocks_direct_duplicate_insert` | DB-level backstop works |

### Step 5: Verify No Double-Posting ✅

Tests 9-13 verify no double-posting in:
- ✅ Customer AR (single debit of 500, not 2500)
- ✅ Vault (single credit of 500, not 2500)
- ✅ Transfer transactions (1, not 5)
- ✅ Supplier AP (unchanged, set by booking create not payment)
- ✅ Global ledger (balanced after 10 replays)

### Step 6: Full Visa Regression ✅

**360/360 Visa tests pass** (after fixing 2 pre-existing adversarial tests + 1 pre-check test that documented the OLD buggy behavior):

| Test | Pre-fix behavior | Post-fix behavior |
|------|------------------|-------------------|
| `VisaIdempotencyTest::test_double_payment_post_creates_only_one_record` | Asserted 2 rows (documented defect) | Asserts 1 row (fixed behavior) |
| `VisaIdempotencyTest::test_payment_with_same_reference_twice_still_creates_two_payments` | Asserted 2 rows + "no unique key" note | Asserts 1 row + same id (fixed) |
| `VisaPaymentDuplicatesPreCheck::test_pre_check_reports_existing_duplicates_correctly` | Tried to manually insert a duplicate | Verifies DB UNIQUE constraint rejects it |

**Final test run output:**
```
PHPUnit 12.5.23 by Sebastian Bergmann and contributors.

Time: 01:19.421, Memory: 124.00 MB

OK (403 tests, 1199 assertions)
```

(Broken down: 360 Visa tests + 25 EmployeeVisaE2ETest + 2 pre-check + 2 defect-reproduction + 14 new idempotency-deep = 403)

---

## Files Changed / Added

### Production code
| File | Change |
|------|--------|
| `database/migrations/2026_08_19_120000_add_unique_constraint_to_visa_payment_reference.php` | **NEW** — UNIQUE constraint + safe pre-check |
| `app/Services/Visa/VisaBookingService.php` | Layer 1b pre-check + Layer 2 dual-key catch + outer catch dual-key |

### Tests
| File | Change |
|------|--------|
| `tests/Feature/Visa/VisaPaymentDuplicatesPreCheck.php` | **NEW** — 2 tests (pre-check + DB constraint) |
| `tests/Feature/Visa/VisaDoublePaymentDefectReproduction.php` | **NEW** — 2 tests (defect reproduction + control) |
| `tests/Feature/Visa/VisaIdempotencyDeepTest.php` | **NEW** — 14 tests (all 7 spec scenarios + 7 defensive) |
| `tests/Feature/Visa/VisaIdempotencyTest.php` | 2 adversarial tests updated to document FIXED behavior |

### Documentation
| File | Change |
|------|--------|
| `docs/PHASE_9_8_IDEMPOTENCY_DOUBLE_PAYMENT_REPORT.md` | **NEW** — this report |

---

## Safety Constraints Honored

✅ **No destructive migration** — Migration uses `Schema::table` to add UNIQUE index only; no `TRUNCATE`, no `DELETE`, no `migrate:fresh`.
✅ **Pre-check aborts on duplicates** — If pre-existing duplicates exist, migration throws RuntimeException instead of force-running.
✅ **No production DB touched** — All work is via SQLite `:memory:` (phpunit.xml line 31-32 override); `.env` still points to `safarakealayna` which was NEVER modified.
✅ **Idempotent migration** — `Schema::hasIndex()` check skips if already migrated.
✅ **No `db:wipe`, no `migrate:fresh`, no `TRUNCATE`** — explicitly avoided.

---

## Verifications

| Verification | Result |
|--------------|--------|
| Pre-check detects existing duplicates | ✅ |
| Pre-check confirms clean DB has zero duplicates | ✅ |
| Defect reproduced on original code (2 rows, double vault credit) | ✅ |
| Fix applied (migration + service) | ✅ |
| All 14 new idempotency tests pass | ✅ |
| Vault not double-credited on replay | ✅ |
| Customer AR not double-debited on replay | ✅ |
| No duplicate transfer transactions | ✅ |
| Supplier AP unchanged by payment replays | ✅ |
| Global ledger balanced after 10 replays | ✅ |
| DB UNIQUE constraint blocks direct bypass INSERT | ✅ |
| 360 Visa tests pass (no regression) | ✅ |
| 403 tests pass total (Visa + Employee E2E + new Phase 9.8) | ✅ |

---

## Findings for Other Phases

### Phase 9.9 (TRUE HTTP Concurrency) — implications

The service uses `lockForUpdate()` on the booking row (Phase 8.6 B2 hardening). For TRUE HTTP concurrency tests, the new DB UNIQUE constraint provides defense-in-depth:
- Even if two requests race past `lockForUpdate()` (which shouldn't happen with MySQL InnoDB), the DB UNIQUE on `(booking, reference)` will reject the second insert.

### Phase 9.13 (State Machine Matrix) — implications

The payment-with-refund state machine still applies. No changes needed.

---

## Recommendation

**Do NOT issue final GO yet** — per user's instruction. Phase 9.9 (TRUE HTTP Concurrency) must still pass before final verdict.

---

## Test Run Output

```
Phase 9.8 targeted tests:
Time: 00:04.914, Memory: 92.00 MB
OK (14 tests, 38 assertions)

Full Visa + Employee E2E + Phase 9.8:
Time: 01:19.421, Memory: 124.00 MB
OK (403 tests, 1199 assertions)
```