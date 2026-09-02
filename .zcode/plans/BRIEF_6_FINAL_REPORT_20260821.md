# Brief 6 — Final Remediation Report — 2026-08-21

## Status: READY FOR REVIEW (NOT COMMITTED)

Per Brief 6 hard constraints:
- DO NOT commit. DO NOT push. DO NOT modify production DB. (✓)
- Flight FROZEN — no Flight code touched. (✓)
- No edits to Bus / Tourism Edit / Safe FX rules. (✓)

---

## TASK-BY-TASK RESULTS

### TASK A — Hajj/Umra SUPPLIER AP GHOST DEBT — ✓ RESOLVED
**Target test:** `HajjUmraDeleteDeepAuditTest::test_delete_zero_ghost_supplier_debt` — **PASS**
**Fix:** `app/Services/HajjUmra/HajjUmraBookingService.php` lines 257-316
- Added explicit-FX path for cross-currency supplier/company accounts.
- Uses `CurrencyService::convert()` (canonical FX path — NO silent 1.0 fallback).
- When expense-source currency ≠ bookingLedgerCurrency AND expense source ≠ treasury,
  `recordExpense()` is called with explicit `converted_amount` and `exchange_rate`.
- When expense-source = treasury (typical case), the same-currency path is kept
  intact (no FX required, preserves `test_booking_create_with_unknown_currency_is_accepted`).

### TASK B — Hajj/Umra CANCEL-AFTER-REFUND — ✓ RESOLVED
**Target tests (3):** ALL PASS
- `HajjUmraCancelDeepAuditTest::test_cancel_after_refund_rejected` — PASS
- `HajjUmraFailureInjectionTest::test_cancel_after_refund_returns_422` — PASS
- `HajjUmraStateMachineMatrixTest::test_cancel_after_refund_rejected` — PASS

**Fix:** Added `if ($booking->status === HajjUmraStatus::Refunded) throw...` guard at the top of
`HajjUmraBookingService::cancel()`. Refunded is terminal — cancellation must be rejected because
the financial timeline has already been closed by the refund.

### TASK C — VISA PAYMENT REFERENCE / IDEMPOTENCY — ✓ RESOLVED
**Target tests (20/20 across VisaIdempotencyDeepTest + VisaIdempotencyTest):**
- `test_same_payment_same_reference_is_idempotent` — PASS
- `test_same_payment_same_idempotency_key_is_idempotent` — PASS
- `test_same_reference_with_no_idempotency_key_still_idempotent` — PASS
- `test_same_reference_different_keys_is_idempotent` — PASS
- `test_idempotent_replay_does_not_double_post_to_vault` — PASS
- `test_idempotent_replay_does_not_double_post_to_customer_ar` — PASS
- `test_idempotent_replay_does_not_create_duplicate_transfer_transactions` — PASS
- `test_idempotent_replay_does_not_affect_supplier_ap` — PASS
- `test_global_ledger_remains_balanced_after_idempotent_replays` — PASS
- (Plus 11 basic idempotency tests in VisaIdempotencyTest) — PASS

**Fix:** `app/Services/Visa/VisaBookingService.php` payment-create path
1. Pre-check: if a payment exists for the booking with the same `transaction_reference`
   OR same `idempotency_key`, return the existing payment record (canonical idempotent replay).
2. Catch UNIQUE-constraint violation (`visa_booking_id, transaction_reference`) and
   (`visa_booking_id, idempotency_key`) for race-safety on simultaneous requests.
3. Added `idempotency_key` to the `VisaPayment::create()` payload.

### OUT-OF-SCOPE: XXX Currency Regression (Self-Introduced) — ✓ RESOLVED
**Target test:** `HajjUmraFailureInjectionTest::test_booking_create_with_unknown_currency_is_accepted` — **PASS**

**Root cause:** My initial TASK A cross-currency condition compared
`expenseFromAccount.currency` against `$booking->currency` (the free-form label like 'XXX').
This caused an unintended cross-currency path when the AP was EGP but the booking
label was 'XXX' — even though no actual FX was needed.

**Fix:** Compare against `$bookingLedgerCurrency` (the EFFECTIVE settlement currency
resolved through `LedgerClearingAccounts::incomeContraIdForModuleAndCurrency`) instead
of the free-form label. EGP↔EGP no longer triggers FX even if the label is 'XXX';
USD supplier + EGP clearing still triggers the explicit-FX path as TASK A intended.

---

## REGRESSION RESULTS

| Suite | Result | Notes |
|---|---|---|
| **SafeFXRuleRegressionTest** | **9/9 PASS** | Safe FX rule intact, no silent 1.0 fallback |
| **Visa full regression** | **432/432 PASS, 0 FAIL** | TASK C idempotency works, no regressions |
| **Hajj/Umra Brief-6 scope** | **4/4 PASS** | TASK A (1) + TASK B (3) |
| **Hajj/Umra XXX regression** | **1/1 PASS** | Self-introduced, fixed |
| **Hajj/Umra total** | 494 PASS / 1 skipped / 37 FAIL | 37 failures are PRE-EXISTING (see below) |

---

## 37 PRE-EXISTING FAILURES — NOT IN BRIEF 6 SCOPE

**Confirmed by running the same test class with my changes stashed** — these failures
existed BEFORE Brief 6 changes and are caused by the Phase 8.5/12.5 no-edit contract.

### Root cause
`routes/api.php` has NO `put`/`patch` route for `hajj-umra/bookings/{id}` or
`visa/bookings/{id}`. Laravel returns **405 Method Not Allowed** when these endpoints
are hit with PATCH/PUT.

### Affected tests (Phase 4.6 lock-down tests written before the no-edit contract)
- `HajjUmraLockDownTest` ~30 cases (test_4_6_5 ... test_4_6_34) — expect `422` from
  FormRequest validation, but PATCH→405 because route is removed
- `HajjUmraApiTest::test_update_selling_price_locked_returns_422_and_does_not_repost` — same
- `HajjUmraControllerTest::test_update_modifies_selling_price` — same
- `HajjUmraProductionE2ETest` tests 8, 9, 22, 25, 29 — same

### Why these are NOT to be fixed in Brief 6
- Brief 6 says "Do not restore Tourism Edit functionality" — no-edit contract must stand.
- Brief 6 says "Do not rewrite tests simply to make them pass" — Phase 4.6 tests
  pre-date the no-edit contract by design.
- Re-confirming the no-edit contract is functioning: PATCH correctly returns 405
  for both Hajj/Umra and Visa bookings (verified via `routes/api.php` inspection).

---

## NO-EDIT CONTRACT VERIFICATION (2026-08-21)

| Endpoint | PATCH response | Notes |
|---|---|---|
| `PATCH /api/v1/hajj-umra/bookings/{id}` | **405** | No PUT/PATCH route in `routes/api.php:573-650` |
| `PATCH /api/v1/visa/bookings/{id}` | **405** | No PUT/PATCH route in `routes/api.php:635-668` |
| Lock-down state — selling_price / purchase_price updates | **REJECTED** at routing layer | ✓ Contract honored |

---

## SAFE FX RULE VERIFICATION (FIX2026-08-21)

`tests/Feature/Finance/SafeFXRuleRegressionTest.php` — **9/9 PASS, 16 assertions**

Same-currency unchanged; cross-currency requires explicit `converted_amount > 0` OR
`exchange_rate > 0`; else `BusinessLogicException`. No silent 1.0 fallback anywhere.

---

## FILES MODIFIED (Brief 6 scope only)

```
M  app/Services/HajjUmra/HajjUmraBookingService.php    (TASK A + TASK B + XXX fix)
M  app/Services/Visa/VisaBookingService.php            (TASK C)
```

Other modified files in workspace are from prior Brief 5 work and unrelated sessions —
NOT touched by Brief 6.

---

## GIT SAFETY

- ✓ NOT committed (working tree staged for review only)
- ✓ NOT pushed
- ✓ Production DB untouched (only local + test DB via `RefreshDatabase`)
- ✓ `git diff --check` passes — no whitespace errors in modified services
- ✓ No Flight, no Bus, no Tourism Edit changes
- ✓ Safe FX Rule preserved verbatim

---

## SCOPE OF BRIEF 6 — VERIFIED

| Brief 6 Item | Status |
|---|---|
| TASK A — supplier AP ghost debt | ✓ DONE (1/1 PASS) |
| TASK B — cancel after refund | ✓ DONE (3/3 PASS) |
| TASK C — Visa payment reference / idempotency | ✓ DONE (20/20 PASS) |
| Safe FX rule intact | ✓ 9/9 PASS |
| No-edit contract intact | ✓ 405 returned for all booking PATCH/PUT |
| Not committed / pushed / DB not modified | ✓ |
| Did not modify Flight / Bus / Safe FX / Tourism Edit | ✓ |
| Did not weaken/skip/rewrite tests | ✓ (zero tests rewritten) |

## READY FOR COMMIT — AWAITING EXPLICIT APPROVAL
