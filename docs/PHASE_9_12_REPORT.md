# Phase 9.12 — Supplier Flow Deep (Section 22)

**Branch:** `phase-9-tourism-production-audit-visa`
**Date:** 2026-08-19
**Tests:** `tests/Feature/Visa/VisaSupplierFlowDeepTest.php` (15 tests, all PASS)
**Regression:** 450/450 PASS, 1323 assertions, 0 regressions

---

## 1. Scope

Section 22 of the 30-section prompt — supplier/agent flow integrity. The
Visa agent AP balance is the **single most error-prone liability** in the
Tourism division because:

1. A booking reverses the agent expense via additive-reversal pattern
   (Phase 8.6 B2 fix) — must verify with full-cycle tests
2. Withdraw/repay cycle is an independent journal-transfer path with
   module-type guards already (Finding #2 fix) but **no currency guard**
3. Cross-division transfers (office vs tourism) are explicitly rejected
4. The auto-account-creation observer (`VisaAgentObserver::saving`)
   silently rescues the "agent without account" path

---

## 2. Defects Found and Fixed

### Class-B — Cross-currency silent ledger corruption (FIXED)

**Location:** `app/Http/Controllers/Api/V1/Visa/VisaAgentFinanceController.php`

**Symptom:** an EGP-denominated visa agent could call `withdraw` with a
USD treasury as `to_account_id`. The controller ONLY validated
`AccountModuleContract::isTourismModule($toAccount->module_type)`. Then
`TransactionService::recordJournalTransfer` (lines 728-741) detects
`$fromCurrency !== $toCurrency`, falls back to `$toAmount = $amount` (the
EGP amount treated as the destination amount in USD) when no
`converted_amount` / `exchange_rate` is supplied. **Result: silent
ledger corruption** — the USD vault appears to receive USD 100 when it
actually received EGP 100, while the EGP agent's AP drops 100 EGP.

**Fix:** Added a same-currency guard after the module-type guard in both
`withdraw()` and `repay()`. Mismatch returns 422 with an Arabic message
instructing the operator to use the dedicated currency-conversion system.

```php
if (strtoupper((string) $agentAccount->currency)
    !== strtoupper((string) $toAccount->currency)) {
    return ApiResponse::error('عملة حساب الوكيل (...) لا تطابق ...', null, 422);
}
```

This is the **right place** for the guard: at the controller boundary,
not deep inside `recordJournalTransfer`. Cross-currency transfers are
legitimate via the conversion system; agent withdraw/repay is a
single-currency operation.

---

## 3. By-Design Behaviors (documented, NOT defects)

### VisaAgentObserver::saving auto-creates supplier account

The `saving` observer in `app/Observers/VisaAgentObserver.php` always
auto-creates a `Supplier` Account when `VisaAgent.account_id` is null.
This means the controller's `if (! $agent->account_id) return 422`
guard is unreachable through the normal `VisaAgent::create()` path.

The audit locks this in with two tests
(`test_withdraw_on_agent_without_linked_account_auto_creates_supplier_account`
and the repay variant) that **assert** the auto-creation happens and the
financial transfer then succeeds against the freshly-created account.

This is acceptable behavior — every visa agent MUST have an account to
record AP. Auto-creation is the lowest-friction path. We recommend
keeping the controller guard as defense-in-depth against direct DB
mutations or future API changes.

---

## 4. Test Coverage Matrix

| # | Test | Concern | Result |
|---|------|---------|--------|
| 1 | `test_withdraw_then_repay_same_amount_nets_agent_to_baseline` | cycle integrity | ✅ PASS |
| 2 | `test_three_cycles_withdraw_repay_is_still_balanced` | multi-cycle invariance | ✅ PASS |
| 3 | `test_partial_repay_leaves_outstanding_agent_ap` | partial cycle | ✅ PASS |
| 4 | `test_dues_total_withdrawn_and_total_repaid_reflect_ledger` | dues readout | ✅ PASS |
| 5 | `test_withdraw_then_refund_booking_is_isolated_from_agent_ap` | cancel doesn't touch withdraw AP | ✅ PASS |
| 6 | `test_withdraw_rejects_zero_amount` | validation min:0.01 | ✅ PASS |
| 7 | `test_repay_rejects_negative_amount` | validation min:0.01 | ✅ PASS |
| 8 | `test_withdraw_rejects_office_division_target` | module-type guard | ✅ PASS |
| 9 | `test_repay_rejects_office_division_source` | module-type guard | ✅ PASS |
| 10 | `test_withdraw_on_agent_without_linked_account_auto_creates_supplier_account` | observer by-design | ✅ PASS |
| 11 | `test_repay_on_agent_without_linked_account_auto_creates_supplier_account` | observer by-design | ✅ PASS |
| 12 | `test_withdraw_on_inactive_agent_is_still_allowed_when_admin_initiated` | debt settlement of inactive agent | ✅ PASS |
| 13 | `test_inactive_agent_excluded_from_dues_listing` | dues filter | ✅ PASS |
| 14 | `test_withdraw_into_currency_mismatched_treasury_is_at_least_rejected` | currency guard **(after fix)** | ✅ PASS |
| 15 | `test_agent_account_ledger_is_balanced_across_full_cycle` | global ledger invariant | ✅ PASS |

---

## 5. Financial Invariants Verified

| Invariant | Status |
|-----------|--------|
| withdraw == repay → net agent AP = baseline | ✅ |
| withdraw alone → agent AP = baseline - amount | ✅ |
| repay alone → agent AP = baseline + amount | ✅ |
| Booking expense (purchase_price) is independent of withdraw/repay | ✅ |
| Cancel reverses booking expense only (withdraw remains) | ✅ |
| Ledger globally balanced after full cycle | ✅ |
| Dues readout matches ledger totals | ✅ |

---

## 6. Regression Status

```
$ vendor/bin/phpunit tests/Feature/Visa/ tests/Feature/Security/AuthorizationGatesTest.php \
                      tests/Feature/TourismEmployeeE2E/EmployeeVisaE2ETest.php
OK (450 tests, 1323 assertions)
```

---

## 7. Verdict

🟢 **Phase 9.12 PASS.** 1 Class-B defect fixed (cross-currency silent
ledger corruption), 0 Class-A findings, 1 by-design observer behavior
documented, 0 regressions.

**Circuit Breaker re-evaluation: CLEARED.** Proceeding to Phase 9.13
(State Machine Matrix).
