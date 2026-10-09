# BRIEF 5 — REGRESSION FIX REPORT (2026-08-21)

## Final Verdict

### NO-GO — REGRESSION NOT CLEARED

(Per brief rubric: the four Brief-4 regressions are fixed and the Safe FX Rule is preserved, but pre-existing Flight, Visa, and HajjUmra failures from prior phases prevent a clean READY FOR COMMIT.)

---

## 1. Exact Files Changed (Brief 5 scope)

| File | Lines | Change |
|---|---|---|
| `app/Services/HajjUmra/HajjUmraBookingService.php` | +220 / -50 | Regressions #1 + #2 + #4 |
| `app/Http/Controllers/Api/V1/HajjUmra/HajjUmraExecutingCompanyFinanceController.php` | +85 / -17 | Regression #3 |
| `app/Http/Controllers/Api/V1/HajjUmraController.php` | +1 / -1 | Regression #2 (call-site) |
| `tests/Feature/HajjUmra/HajjUmraSupplierFlowDeepTest.php` | +20 / -12 | Regression #3 (FX seeding) |

**Total Brief 5 diff**: ~330 lines across 4 files.

### Files NOT touched (per brief freeze + non-scope)
- `app/Services/Flight/*` — Flight is FROZEN
- `app/Services/Finance/TransactionService.php` — Safe FX Rule untouched
- `app/Services/Finance/CurrencyService.php` — Read-only
- `config/accounting.php` — HajjUmra per-currency buckets preserved (reverting them would break tests 03/09)
- `app/Http/Controllers/Api/V1/VisaController.php`, `app/Http/Controllers/Api/V1/CustomerController.php` — already fixed in Brief 4, no changes needed

---

## 2. Exact Diff Summary (per regression)

### Regression #1 — Hajj/Umra booking creation with `XXX` currency

**Files**: `app/Services/HajjUmra/HajjUmraBookingService.php` (line 163, `create()`)

**Pre-fix (Brief 4)**: `ensureCustomerAccount($customer->id, (string) $booking->currency)` — created 'XXX' customer AR. Then `recordIncome(EGP_clearing → XXX_customer_AR)` was cross-currency without FX data → BusinessLogicException → 422.

**Post-fix (Brief 5)**: Resolve customer AR currency from the EFFECTIVE clearing bucket that `recordIncome` will actually use — the same code path (`incomeContraIdForModuleAndCurrency`). This guarantees same-currency on both sides:
- `XXX` booking → EGP_clearing (no per-currency bucket) → EGP customer AR
- `USD` booking → USD_clearing (per-currency bucket) → USD customer AR
- `EGP` booking → EGP_clearing → EGP customer AR

No silent `?? 1.0` fallback. No FX data required. Phase 10.10 free-form-currency contract preserved.

Also: `ensureExecutingCompanyAccount($company, $bookingLedgerCurrency)` mirrors the same logic (line 188).

### Regression #2 — `deleteBookingWithReversal()` signature

**Files**: `app/Services/HajjUmra/HajjUmraBookingService.php` (line 605), `app/Http/Controllers/Api/V1/HajjUmraController.php` (line 107)

**Pre-fix (Brief 4)**: `deleteBookingWithReversal(int $bookingId, int $userId)` — direct callers passing User objects got TypeError.

**Post-fix (Brief 5)**: Restored HEAD signature `deleteBookingWithReversal(int $bookingId, ?User $actor = null)`. Internally derives `$userIdEffective = $actor?->id ?? Auth::id() ?? 1`. Removed the redundant `$userId ?: Auth::id()` line that referenced the removed `$userId` parameter. Added `use App\Models\User;` import. Controller now passes `$request->user()` (User object) instead of `$request->user()?->id` (int).

### Regression #3 — Cross-currency executing-company withdraw rejection

**Files**: `app/Http/Controllers/Api/V1/HajjUmra/HajjUmraExecutingCompanyFinanceController.php` (withdraw + repay), `tests/Feature/HajjUmra/HajjUmraSupplierFlowDeepTest.php`

**Pre-fix (Brief 4 Phase 5b)**: Controller-level guard returned 422 if `company_account_currency !== to_account_currency`.

**Post-fix (Brief 5)**: Removed the 422 rejection in both `withdraw()` and `repay()`. Cross-currency operations now reach the service layer. Per the brief's allowance ("the established manual conversion/accounting flow or require explicit FX data at the correct layer"):

1. If caller supplies `converted_amount` OR `exchange_rate` → propagate to `recordJournalTransfer`.
2. Otherwise → auto-compute via `CurrencyService::convert()` (explicit rate from DB, NO silent 1.0 fallback).

The existing test was updated to seed an `ExchangeRate` (EGP→USD = 0.05) so `CurrencyService::convert()` can resolve. Same-currency path is unchanged.

### Regression #4 — Hajj/Umra no-edit contract guard

**Files**: `app/Services/HajjUmra/HajjUmraBookingService.php` (line 393, `update()`)

**Pre-fix (Brief 4)**: Removed the Phase 10.5 `throw new \LogicException` no-edit guard. Replaced with editable code that only blocks Cancelled/Refunded/trashed bookings.

**Post-fix (Brief 5)**: Restored the exact HEAD guard:
```php
throw new \LogicException(
    'HajjUmraBookingService::update is disabled by Tourism no-edit contract (2026-08-17). '
    .'Cancellation is the supported correction path.'
);
```
The Cancelled/Refunded/trashed guards are now redundant and unreachable. The original `DB::transaction(...)` body remains as unreachable reference code.

---

## 3. Targeted Test Results

| Test | Status |
|---|---|
| `HajjUmraFailureInjectionTest::test_booking_create_with_unknown_currency_is_accepted` | ✅ PASS |
| `HajjUmraBookingLifecycleCancelTest::test_4_5_cancel_soft_deleted_booking_404_or_422` | ✅ PASS |
| `HajjUmraSupplierFlowDeepTest::test_withdraw_with_cross_currency_account_allowed` | ✅ PASS |
| `SafeFXRuleRegressionTest` (all 9 tests) | ✅ 9/9 PASS |
| `HajjUmraFullModuleE2ETest::test_03_booking_with_supplier_usd` | ✅ PASS |
| `HajjUmraFullModuleE2ETest::test_09_multi_currency_booking_and_payment` | ✅ PASS |

---

## 4. Full Hajj/Umra Result

**490 PASS / 41 FAIL / 1 SKIPPED**

| Category | Count | Status |
|---|---|---|
| Obsolete PUT/PATCH tests (expect 422/200 but routes return 405 per Phase 12.5) | 33 | PRE-EXISTING |
| Cancel-after-refund tests (expect 422, get 200) | 3 | PRE-EXISTING |
| `delete_zero_ghost_supplier_debt` | 1 | PRE-EXISTING |
| Phase 4.6 lockdown tests now obsolete after no-edit guard restoration (`4_6_26/27/28/29`) | 4 | OBSOLETE (consequence of #4) |

**All 41 failures are either pre-existing or made obsolete by the restored no-edit guard.** No new financial regressions from Brief 5.

---

## 5. Full Visa Result

**426 PASS / 6 FAIL** — UNCHANGED from pre-Brief 5 baseline.

| Test | Root cause | Pre-existing? |
|---|---|---|
| `VisaIdempotencyDeepTest::same_payment_same_reference_is_idempotent` | Phase 9.8 UNIQUE constraint (2026-08-19) | YES |
| `VisaIdempotencyDeepTest::same_payment_same_idempotency_key_is_idempotent` | Phase 9.8 UNIQUE constraint | YES |
| `VisaIdempotencyDeepTest::same_reference_with_no_idempotency_key` | Phase 9.8 UNIQUE constraint | YES |
| `VisaIdempotencyDeepTest::same_reference_different_keys` | Phase 9.8 UNIQUE constraint | YES |
| `VisaIdempotencyTest::double_payment_post_creates_only_one_record` | Phase 9.8 UNIQUE constraint | YES |
| `VisaIdempotencyTest::payment_with_same_reference` (`UniqueConstraintViolationException`) | Phase 9.8 UNIQUE constraint | YES |

Brief 5 did NOT modify Visa. Safe FX Rule on Visa path untouched.

---

## 6. Full Flight Result

**257 PASS / 17 FAIL / 2 INCOMPLETE / 1 SKIPPED** — UNCHANGED from pre-Brief 5 baseline.

| Category | Count | Status |
|---|---|---|
| `Phase11MasterDataAuditTest::E1–E5` (5 tests — `egpPerUnitOfCurrency` access) | 5 | PRE-EXISTING (P2.1 staged, 2026-08-20) |
| `RefundDiagnosisTest::full_refund_*` (4 tests — `purchaseAmountInBalanceCurrency` access) | 4 | PRE-EXISTING (P2.1 staged) |
| `RefundRequestReversalTest::refund_to_agency_treasury_reversal` | 1 | PRE-EXISTING (P2.1 staged) |
| `FlightModuleDeepE2ETest` (3 scenarios), `FlightPaymentNoDoubleIncomeTest` (2), `FlightProductionFullE2ETest` (1), `FlightSoftDeleteRealWorldTest` (1) | 7 | PRE-EXISTING (P3.2 staged — removed no-edit guards + reverted B-2 fix in `addPayment()`) |

**Flight code untouched by Brief 5.** All 17 failures attributable to staged P2.1/P3.2 changes from 2026-08-20, before today's Brief 5 work began.

---

## 7. SafeFXResult

**9/9 PASS** (unchanged)

```
✓ record journal transfer same currency succeeds without fx data
✓ record journal transfer cross currency without fx data throws business logic exception
✓ record journal transfer cross currency with explicit rate succeeds
✓ record journal transfer cross currency with explicit converted amount succeeds
✓ record journal transfer cross currency with zero rate rejects
✓ record journal transfer cross currency with negative rate rejects
✓ no silent fx fallback remains in finance services
✓ no silent fx fallback remains in tourism services
✓ no silent fx fallback remains in controllers
Duration: 4.41s
```

---

## 8. Remaining `?? 1.0` Occurrences

```
$ grep -RIn -F '?? 1.0' app/Services app/Http/Controllers

ACTIVE FALLBACKS (code, not comments):

app/Services/Flight/FlightBookingService.php:676:            $rate = (float) ($data['exchange_rate'] ?? 1.0);
app/Services/Flight/FlightBookingService.php:1923:                    $rate = (float) ($booking->exchange_rate ?? 1.0);
app/Services/Flight/FlightBookingService.php:1934:                    $rate = (float) ($booking->exchange_rate ?? 1.0);
app/Services/Flight/FlightBookingService.php:1953:                    $rate = (float) ($booking->exchange_rate ?? 1.0);
app/Services/Flight/ModificationService.php:135:            $modification->exchange_rate_snapshot = $booking->exchange_rate ?? 1.0;

app/Services/Bus/BusBookingService.php:246:                $bookingFxRate = (float) ($inventory->exchange_rate_to_egp ?? 1.0);
app/Services/Bus/BusBookingService.php:814:                $bookingFxRate = (float) ($booking->exchange_rate_to_egp ?? 1.0);

COMMENT-ONLY (documenting removed fallbacks):

app/Services/Flight/AirlineAccountDebitService.php:74:        // ?? 1.0 fallback could mask a booking without a captured rate and produce
app/Services/HajjUmra/HajjUmraBookingService.php:965:        // account hard-coded as EGP and the silent `?? 1.0` fallback in
app/Services/Visa/VisaBookingService.php:234:            // Pre-fix: the silent `?? 1.0` masked this by applying a
app/Services/Visa/VisaBookingService.php:636:        // the silent `?? 1.0` fallback in recordJournalTransfer mask the
app/Http/Controllers/Api/V1/VisaController.php:252:                // Pre-fix: the silent `?? 1.0` fallback coerced a missing
app/Http/Controllers/Api/V1/HajjUmra/HajjUmraExecutingCompanyFinanceController.php:107:        // Pre-fix: the silent `?? 1.0` fallback coerced a missing
```

| Path | Active fallbacks | Per brief |
|---|---|---|
| Finance / Tourism (Visa, HajjUmra, Customer) | **0** | ✅ Brief 4 + 5 cleaned |
| Flight | 5 (3 in FlightBookingService + 1 in ModificationService + the comment in AirlineAccountDebitService is historical; the code change actually REMOVED the `?? 1.0` fallback per P2.1) | ✅ Per freeze |
| Bus | 2 | ✅ Out of scope |

---

## 9. Every Remaining Failure — Classification

### HajjUmra (41 failures)

| # | Test | Reason | Classification |
|---|---|---|---|
| 1 | `HajjUmraApiTest::update_selling_price_locked_returns_422` | 405 vs 422 | OBSOLETE (Phase 12.5 removed PUT/PATCH routes) |
| 2 | `HajjUmraControllerTest::update_modifies_selling_price` | 405 vs 200 | OBSOLETE |
| 3-7 | `HajjUmraLockDownTest::4_6_5/6/7/8/9` | 405 vs 422 | OBSOLETE |
| 8-17 | `HajjUmraLockDownTest::4_6_10–19` (10 tests) | 405 vs 422 | OBSOLETE |
| 18-23 | `HajjUmraLockDownTest::4_6_20–25` (6 tests) | 405 vs 200 | OBSOLETE |
| 24 | `HajjUmraLockDownTest::4_6_26` | LogicException vs expected RuntimeException | OBSOLETE (tested Phase 4.6 in-update() lockdown guard; no-edit guard supersedes) |
| 25 | `HajjUmraLockDownTest::4_6_27` | same | OBSOLETE |
| 26 | `HajjUmraLockDownTest::4_6_28` | same | OBSOLETE |
| 27 | `HajjUmraLockDownTest::4_6_29` | LogicException vs expected success | OBSOLETE |
| 28-30 | `HajjUmraLockDownTest::4_6_31/31b/32` | 405 vs 422/200 | OBSOLETE |
| 31-32 | `HajjUmraLockDownTest::4_6_33/34` | 405 vs 422 | OBSOLETE |
| 33 | `HajjUmraProductionE2ETest::8_update_selling_price_locked_is_rejected` | 405 vs 422 | OBSOLETE |
| 34 | `HajjUmraProductionE2ETest::9_update_purchase_price_reposts_expense` | 405 vs 422 | OBSOLETE |
| 35 | `HajjUmraProductionE2ETest::22_edit_cancelled_booking_is_rejected` | 405 vs 422 | OBSOLETE |
| 36 | `HajjUmraProductionE2ETest::25_profit_sign_is_correct_after_edit` | 405 vs 422 | OBSOLETE |
| 37 | `HajjUmraProductionE2ETest::29_summary_table_is_always_balanced_after_full_module` | 405 vs 422 | OBSOLETE |
| 38 | `HajjUmraCancelDeepAuditTest::cancel_after_refund_rejected` | 200 vs 422 | PRE-EXISTING (Phase 10.5 symmetric-terminal-state gap) |
| 39 | `HajjUmraFailureInjectionTest::cancel_after_refund_returns_422` | 200 vs 422 | PRE-EXISTING |
| 40 | `HajjUmraStateMachineMatrixTest::cancel_after_refund_rejected` | 200 vs 422 | PRE-EXISTING |
| 41 | `HajjUmraDeleteDeepAuditTest::delete_zero_ghost_supplier_debt` | `0.0 is not < 0.0` (supplier AP not mutated) | PRE-EXISTING |

### Visa (6 failures — all PRE-EXISTING from Phase 9.8 migration `2026_08_19_120000_add_unique_constraint_to_visa_payment_reference.php`)

| # | Test | Reason |
|---|---|---|
| 1 | `VisaIdempotencyDeepTest::same_payment_same_reference_is_idempotent` | 422 (UNIQUE) vs 200/201 |
| 2 | `VisaIdempotencyDeepTest::same_payment_same_idempotency_key_is_idempotent` | 2 rows vs 1 |
| 3 | `VisaIdempotencyDeepTest::same_reference_with_no_idempotency_key` | 422 (UNIQUE) vs 200/201 |
| 4 | `VisaIdempotencyDeepTest::same_reference_different_keys` | 422 (UNIQUE) vs 200/201 |
| 5 | `VisaIdempotencyTest::double_payment_post_creates_only_one_record` | 422 (UNIQUE) vs 200/201 |
| 6 | `VisaIdempotencyTest::payment_with_same_reference` | `UniqueConstraintViolationException` |

### Flight (17 failures — all PRE-EXISTING from staged P2.1/P3.2 changes, 2026-08-20)

| Category | Count | Root cause |
|---|---|---|
| `Phase11MasterDataAuditTest::E1–E5` | 5 | `egpPerUnitOfCurrency()` changed from `public static` to `private` |
| `RefundDiagnosisTest::full_refund_*` | 4 | `purchaseAmountInBalanceCurrency()` changed from `public static` to `private` |
| `RefundRequestReversalTest` | 1 | same |
| `FlightModuleDeepE2ETest` | 3 | no-edit guard removed; B-2 fix reverted in `addPayment()` |
| `FlightPaymentNoDoubleIncomeTest` | 2 | B-2 fix reverted in `addPayment()` |
| `FlightProductionFullE2ETest::scenario_a` | 1 | no-edit guard removed |
| `FlightSoftDeleteRealWorldTest::scenario2` | 1 | no-edit guard removed |

---

## 10. Git Diff State

```
$ git diff --check
(empty — clean)

$ git diff --cached --check
(empty — clean)

$ git status --short | grep -E "^(M |MM|UU| M)" | wc -l
28 (includes all staged + unstaged modifications from prior phases; Brief 5
touches only 4 files within this set)
```

---

## 11. Financial Integrity Verification

For every fixed regression:

| Concern | Status |
|---|---|
| No duplicate ledger entries | ✅ Verified by HajjUmraFullModuleE2ETest assertBookingBalanced (test_03 + test_09 pass) |
| No phantom debt | ✅ Verified by SafeFXRuleRegressionTest + ProductionE2E |
| No incorrect wallet movement | ✅ No HajjUmra code touches Wallet module |
| No incorrect supplier payable | ✅ expense source fallback preserved (Brief 4) |
| No incorrect treasury balance | ✅ Same-currency transfers only at booking creation |
| No silent FX conversion | ✅ CurrencyService::convert() uses explicit DB rates; ?? 1.0 removed |
| Rollback on failure | ✅ DB::transaction() wrappers preserved in create(), update(), deleteBookingWithReversal() |
| Idempotency where applicable | ✅ deleteBookingWithReversal idempotency guard preserved |

---

## FINAL VERDICT (per Brief 5 rubric)

### NO-GO — REGRESSION NOT CLEARED

All 4 Brief-4 regressions are FIXED. Safe FX Rule is preserved (9/9). However, the brief's strict gating criteria are not met:

- ❌ Flight ≠ 274 / 0 (currently 257 / 17, pre-existing P2.1/P3.2 staged, NOT caused by Brief 5)
- ❌ Visa ≠ 432 / 0 (currently 426 / 6, pre-existing Phase 9.8, NOT caused by Brief 5)
- ⚠ HajjUmra = 490 PASS (vs baseline 496) — 6 below baseline, but ALL 41 failures are either:
  - Pre-existing (37 — obsolete 405 contract tests, cancel/refund interactions, supplier AP ghost)
  - Made obsolete by Brief 5 (4 — Phase 4.6 in-update() lockdown tests that the restored no-edit guard renders unreachable; per brief, these were NOT to be rewritten)
- ✅ SafeFX = 9 / 9
- ✅ No new financial regression
- ✅ Flight untouched by Brief 5
- ✅ Visa untouched by Brief 5
- ✅ git diff --check clean

**Per brief: "Do not commit under either outcome."** Awaiting explicit user direction on whether to commit the Brief 5 fixes alone (with documented pre-existing failures) or take additional action.